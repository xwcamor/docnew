<?php

namespace App\Services\SystemManagement;

use App\Jobs\SystemManagement\Languages\BulkLanguagesActionJob;
use App\Models\AuditLog;
use App\Models\Language;
use App\Models\Locale;
use App\Rules\UniqueNormalizedName;
use Illuminate\Support\Facades\DB;

class LanguageService
{
    public function create(array $data): Language
    {
        $data['created_by'] = auth()->id();
        return Language::create($data);
    }

    public function update(Language $language, array $data): Language
    {
        $language->update($data);
        return $language;
    }

    /**
     * saveQuietly evita un audit log 'updated' duplicado justo antes del 'deleted'.
     */
    public function delete(Language $language, string $reason): void
    {
        $language->deleted_description = $reason;
        $language->deleted_by          = auth()->id();
        $language->is_active           = false;
        $language->saveQuietly();
        $language->delete();
    }

    public function restore(Language $language): Language
    {
        $language->deleted_description = null;
        $language->deleted_by          = null;
        $language->restore();
        return $language;
    }

    /**
     * Hard delete físico. Audit log ANTES del delete (sobrevive al borrado);
     * todo en transacción + lockForUpdate para evitar race con restore concurrente.
     */
    public function forceDelete(Language $language, string $reason): void
    {
        DB::transaction(function () use ($language, $reason) {
            $locked = Language::onlyTrashed()->where('id', $language->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Language {$language->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => Language::class,
                'auditable_id'   => $locked->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'name'      => $locked->name,
                    'iso_code'  => $locked->iso_code,
                    'is_active' => $locked->is_active,
                    'slug'      => $locked->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => request()?->userAgent(),
                'note'           => $reason,
                'module'         => 'languages',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    // ─── Bulk ops ─────────────────────────────────────────────────────────

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkLanguagesActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted_ids?: int[], blocked?: bool}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkLanguagesActionJob::dispatch(auth()->id(), 'delete', $ids, ['reason' => $reason]);
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $languages = Language::whereIn('id', $ids)->get();

            $blockConfig = (new Language)->dependents()['locales']['block'] ?? false;
            if ($blockConfig) {
                $hasBlocker = Locale::whereIn('language_id', $languages->pluck('id'))->exists();
                if ($hasBlocker) {
                    return ['queued' => false, 'count' => 0, 'blocked' => true];
                }
            }

            foreach ($languages as $language) {
                $this->delete($language, $reason);
            }

            return [
                'queued'      => false,
                'count'       => $languages->count(),
                'deleted_ids' => $languages->pluck('id')->all(),
            ];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkLanguagesActionJob::dispatch(auth()->id(), 'set_active', $ids, ['is_active' => $isActive]);
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $languages = Language::whereIn('id', $ids)->get();
            $changed   = 0;
            foreach ($languages as $language) {
                if ((bool) $language->is_active === $isActive) continue;
                $this->update($language, ['is_active' => $isActive]);
                $changed++;
            }

            return ['queued' => false, 'count' => $count, 'changed' => $changed];
        });
    }

    /**
     * @return array{queued: bool, count: int, restored?: int}
     */
    public function bulkRestore(array $ids): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkLanguagesActionJob::dispatch(auth()->id(), 'restore', $ids, []);
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $languages = Language::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($languages as $language) {
                $this->restore($language);
            }

            return ['queued' => false, 'count' => $count, 'restored' => $languages->count()];
        });
    }

    // ─── Duplicate ─────────────────────────────────────────────────────────

    public function duplicate(Language $language): ?Language
    {
        $base      = $language->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql   = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($language, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = Language::query()
                    ->when($isPgsql,
                        fn ($q) => $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$candidate]),
                        fn ($q) => $q->whereRaw('LOWER(name) = LOWER(?)', [$candidate]),
                    )
                    ->lockForUpdate()
                    ->exists();

                if (!$exists) break;
                $candidate = $base . ' ' . $i;
                $i++;

                if ($i > 100) return null;
            }

            // iso_code es único — al duplicar generamos uno tentativo con sufijo
            // numérico para que el usuario lo edite. Si el patrón no matchea
            // regex, el usuario lo arregla en el form de edit del duplicado.
            return $this->create([
                'name'      => $candidate,
                'iso_code'  => $language->iso_code . '_2',
                'is_active' => $language->is_active,
            ]);
        });
    }

    // ─── Edit-All batch ────────────────────────────────────────────────────

    /**
     * @return array{errors: array<string, string[]>, touched: int}
     */
    public function editAllBatch(array $changes): array
    {
        $errors = [];
        $seen   = [];

        foreach ($changes as $idx => $change) {
            if (!isset($change['name']) || $change['name'] === '') continue;

            $normalized = mb_strtolower(trim($change['name']));
            $stripped   = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized) ?: $normalized;

            if (isset($seen[$stripped]) && $seen[$stripped] !== $change['id']) {
                $errors["changes.{$idx}.name"] = [__('languages.name_duplicate_in_batch')];
                continue;
            }
            $seen[$stripped] = $change['id'];

            $rule = new UniqueNormalizedName('languages', 'name', ignoreId: (int) $change['id']);
            $rule->validate("changes.{$idx}.name", $change['name'], function ($msg) use (&$errors, $idx) {
                $errors["changes.{$idx}.name"] = [$msg];
            });
        }

        if (!empty($errors)) {
            return ['errors' => $errors, 'touched' => 0];
        }

        $touched = 0;
        DB::transaction(function () use ($changes, &$touched) {
            $ids     = array_column($changes, 'id');
            $allByPk = Language::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $language = $allByPk[$change['id']] ?? null;
                if (!$language) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'iso_code', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasRealChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $language->{$k} !== (string) $v) { $hasRealChange = true; break; }
                }
                if (!$hasRealChange) continue;

                $this->update($language, $patch);
                $touched++;
            }
        });

        return ['errors' => [], 'touched' => $touched];
    }

    // ─── Import ────────────────────────────────────────────────────────────

    /**
     * @return array{ok: bool, dry_run: bool, summary?: array, message?: string}
     */
    public function processImport(\Illuminate\Http\UploadedFile $file, string $mode, bool $dryRun): array
    {
        $importer = new \App\Imports\SystemManagement\Languages\LanguagesImport(
            mode:   $mode,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importer, $file);
        } catch (\Throwable $e) {
            \Log::error('LanguagesImport failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => $this->humanizeImportError($e),
            ];
        }

        return [
            'ok'      => true,
            'dry_run' => $dryRun,
            'summary' => $importer->summary(),
        ];
    }

    /**
     * Convierte una excepción de import en mensaje legible para el usuario.
     * Reconoce constraint violations comunes (unique, not null, foreign key)
     * y mapea a copy localizado. Cualquier otra excepción cae al genérico
     * — el detalle técnico queda en el log, no llega al cliente.
     */
    protected function humanizeImportError(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if ($e instanceof \Illuminate\Database\QueryException) {
            if (str_contains($msg, 'unique') || str_contains($msg, 'duplicate')) {
                return __('imports.err_unique_violation');
            }
            if (str_contains($msg, 'NOT NULL') || str_contains($msg, 'null value')) {
                return __('imports.err_not_null_violation');
            }
            if (str_contains($msg, 'foreign key') || str_contains($msg, 'violates foreign')) {
                return __('imports.err_foreign_key_violation');
            }
        }

        return __('imports.process_failed');
    }

    // ─── Export helpers ────────────────────────────────────────────────────

    public function countForExport(array $options): int
    {
        $scope = $options['scope'] ?? 'filtered';

        if ($scope === 'selected') {
            return count($options['selected_ids'] ?? []);
        }
        if ($scope === 'all') {
            return Language::query()->count();
        }
        return Language::query()->filter($options['filters'] ?? [])->count();
    }

    public function recordExportAudit(string $format, array $options): void
    {
        AuditLog::create([
            'user_id'        => auth()->id(),
            'event'          => 'export_queued',
            'auditable_type' => Language::class,
            'auditable_id'   => null,
            'module'         => 'languages',
            'old_values'     => null,
            'new_values'     => [
                'format'                  => $format,
                'scope'                   => $options['scope']        ?? 'filtered',
                'columns'                 => $options['columns']      ?? [],
                'title'                   => $options['title']        ?? null,
                'orientation'             => $format === 'pdf'   ? ($options['orientation']    ?? null) : null,
                'paper_size'              => $format === 'pdf'   ? ($options['paper_size']     ?? null) : null,
                'autofilter'              => $format === 'excel' ? ($options['autofilter']     ?? null) : null,
                'freeze_header'           => $format === 'excel' ? ($options['freeze_header']  ?? null) : null,
                'include_filters_summary' => $options['include_filters_summary'] ?? false,
                'filters'                 => $options['filters']      ?? [],
                'selected_ids_count'      => count($options['selected_ids'] ?? []),
            ],
            'url'        => route('system_management.languages.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
