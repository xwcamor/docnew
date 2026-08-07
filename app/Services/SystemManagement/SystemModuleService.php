<?php

namespace App\Services\SystemManagement;

use App\Jobs\SystemManagement\SystemModules\BulkSystemModulesActionJob;
use App\Models\AuditLog;
use App\Models\Country;
use App\Models\SystemModule;
use App\Rules\UniqueNormalizedName;
use Illuminate\Support\Facades\DB;

class SystemModuleService
{
    public function create(array $data): SystemModule
    {
        $data['created_by'] = auth()->id();
        return SystemModule::create($data);
    }

    public function update(SystemModule $system_module, array $data): SystemModule
    {
        $system_module->update($data);
        return $system_module;
    }

    /**
     * saveQuietly evita un audit log 'updated' duplicado justo antes del 'deleted'.
     */
    public function delete(SystemModule $system_module, string $reason): void
    {
        $system_module->deleted_description = $reason;
        $system_module->deleted_by          = auth()->id();
        $system_module->is_active           = false;
        $system_module->saveQuietly();
        $system_module->delete();
    }

    /**
     * Mutamos antes de restore() para que todo persista en un solo save —
     * 1 audit log "Restaurado", no varios.
     */
    public function restore(SystemModule $system_module): SystemModule
    {
        $system_module->deleted_description = null;
        $system_module->deleted_by          = null;
        $system_module->restore();
        return $system_module;
    }

    /**
     * Hard delete físico, no recuperable. El audit log se escribe ANTES del
     * delete (sobrevive al borrado) y todo va en una transacción para que
     * no quede un registro borrado sin audit ni un audit sin registro.
     *
     * El controller valida super antes de llamar — no re-validamos
     * aquí para no acoplar el service a HTTP.
     */
    public function forceDelete(SystemModule $system_module, string $reason): void
    {
        \DB::transaction(function () use ($system_module, $reason) {
            // lockForUpdate: previene race con un restore concurrente. Sin
            // esto, el escenario es: thread A entra a forceDelete, thread B
            // hace restore al mismo registro entre nuestro fetch y delete,
            // y terminamos hard-deleting un registro que ya no estaba trashed.
            $locked = SystemModule::onlyTrashed()->where('id', $system_module->id)->lockForUpdate()->first();
            if (!$locked) {
                // Otro proceso ya lo restauró o force-eliminó. Abortamos sin
                // tocar nada, el rollback de la transacción cubre.
                throw new \RuntimeException("SystemModule {$system_module->id} no longer available for force-delete");
            }

            \App\Models\AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => SystemModule::class,
                'auditable_id'   => $locked->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'name'      => $locked->name,
                    'is_active' => $locked->is_active,
                    'slug'      => $locked->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => request()?->userAgent(),
                'note'           => $reason,
                'module'         => 'system_modules',
                'created_at'     => now(),
            ]);

            // El model event `deleted` con isForceDeleting() limpia
            // user_favorites y user_recent_views asociados.
            $locked->forceDelete();
        });
    }

    // ─── Bulk ops (compartidas por web/API) ───────────────────────────────
    //
    // Cada método devuelve un payload neutro que el caller traduce a
    // HTTP response (Inertia redirect / JSON 200 / JSON 202). El threshold
    // de async vive en un solo lugar (BulkSystemModulesActionJob::asyncThreshold).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkSystemModulesActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted_ids?: int[], blocked?: bool}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkSystemModulesActionJob::dispatch(
                auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $system_modules = SystemModule::whereIn('id', $ids)->get();

            // Dependency check con 1 query agrupada (vs N de hasBlockingDependents).
            $blockConfig = (new SystemModule)->dependents()['countries']['block'] ?? false;
            if ($blockConfig) {
                $hasBlocker = Country::whereIn('system_module_id', $system_modules->pluck('id'))->exists();
                if ($hasBlocker) {
                    return ['queued' => false, 'count' => 0, 'blocked' => true];
                }
            }

            foreach ($system_modules as $system_module) {
                $this->delete($system_module, $reason);
            }

            return [
                'queued'      => false,
                'count'       => $system_modules->count(),
                'deleted_ids' => $system_modules->pluck('id')->all(),
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
            BulkSystemModulesActionJob::dispatch(
                auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $system_modules = SystemModule::whereIn('id', $ids)->get();
            $changed = 0;
            foreach ($system_modules as $system_module) {
                // Skip filas ya en el estado deseado: evita audit-log noise.
                if ((bool) $system_module->is_active === $isActive) continue;
                $this->update($system_module, ['is_active' => $isActive]);
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
            BulkSystemModulesActionJob::dispatch(
                auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $system_modules = SystemModule::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($system_modules as $system_module) {
                $this->restore($system_module);
            }

            return ['queued' => false, 'count' => $count, 'restored' => $system_modules->count()];
        });
    }

    // ─── Duplicate ─────────────────────────────────────────────────────────
    //
    // Genera un nombre único agregando sufijo "(copia)", "(copia) 2", etc.
    // Iterativo con sanity guard de 100 intentos. Devuelve el SystemModule creado
    // o `null` si excede los intentos (caller debe manejar como error UX).

    public function duplicate(SystemModule $system_module): ?SystemModule
    {
        $base      = $system_module->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql   = DB::getDriverName() === 'pgsql';

        // Transaccional: previene race con un duplicate concurrente del mismo
        // base. La DB tiene UNIQUE INDEX en (unaccent_immutable(LOWER(name)))
        // como red de seguridad, pero la TX nos deja recuperar elegantemente
        // si dos requests pidieran el mismo sufijo al mismo tiempo.
        return DB::transaction(function () use ($system_module, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = SystemModule::query()
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

            return $this->create([
                'name'      => $candidate,
                'is_active' => $system_module->is_active,
            ]);
        });
    }

    // ─── Edit-All batch ────────────────────────────────────────────────────
    //
    // Aplica cambios masivos a múltiples system_modulees. Valida dedupe intra-batch
    // (case + accent insensitive, mismo pattern que UniqueNormalizedName) y
    // unicidad contra DB para cada change. Devuelve:
    //   - `errors`: map de field→messages si hay duplicados (caller flashea
    //               con withErrors()->withInput())
    //   - `touched`: cuántos registros realmente cambiaron (skip si patch
    //                no modifica nada — evita audit logs vacíos)
    //
    // Transaccional: si una validation falla post-loop, no toca nada. Si
    // todas pasan, persiste atómicamente.

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
                $errors["changes.{$idx}.name"] = [__('system_modules.name_duplicate_in_batch')];
                continue;
            }
            $seen[$stripped] = $change['id'];

            $rule = new UniqueNormalizedName('system_modules', 'name', ignoreId: (int) $change['id']);
            $rule->validate("changes.{$idx}.name", $change['name'], function ($msg) use (&$errors, $idx) {
                $errors["changes.{$idx}.name"] = [$msg];
            });
        }

        if (!empty($errors)) {
            return ['errors' => $errors, 'touched' => 0];
        }

        $touched = 0;
        DB::transaction(function () use ($changes, &$touched) {
            // Preload todos los system_modules con 1 query (en lugar de N findOrFail
            // en el loop). Acceptamos hasta `edit_all_max=200` cambios — sin
            // preload eran 200 queries solo de fetch.
            $ids       = array_column($changes, 'id');
            $allByPk   = SystemModule::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $system_module = $allByPk[$change['id']] ?? null;
                if (!$system_module) continue;  // raro: id no resolvió (debió fallar la validation)

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                // Skip si el patch no cambia nada — evita audit log noise.
                $hasRealChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $system_module->{$k} !== (string) $v) { $hasRealChange = true; break; }
                }
                if (!$hasRealChange) continue;

                $this->update($system_module, $patch);
                $touched++;
            }
        });

        return ['errors' => [], 'touched' => $touched];
    }

    // ─── Import ────────────────────────────────────────────────────────────
    //
    // Procesa el upload via SystemModulesImport. dryRun=true hace rollback al final
    // (preview). Captura excepciones de parsing/IO y devuelve un payload
    // estándar — el controller solo formatea la response HTTP.

    /**
     * @return array{ok: bool, dry_run: bool, summary?: array, message?: string}
     */
    public function processImport(\Illuminate\Http\UploadedFile $file, string $mode, bool $dryRun): array
    {
        $importer = new \App\Imports\SystemManagement\SystemModules\SystemModulesImport(
            mode:   $mode,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importer, $file);
        } catch (\Throwable $e) {
            // El detalle técnico (incluido SQL crudo) va SOLO al log.
            // Al usuario le devolvemos un mensaje legible — los errores por fila
            // los expone `summary['errors']` cuando layer 1/2 los atrapan.
            \Log::error('SystemModulesImport failed', [
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

    /** Cuenta filas a exportar según scope+filters. Barato (usa los índices). */
    public function countForExport(array $options): int
    {
        $scope = $options['scope'] ?? 'filtered';

        if ($scope === 'selected') {
            return count($options['selected_ids'] ?? []);
        }
        if ($scope === 'all') {
            return SystemModule::query()->count();
        }
        return SystemModule::query()->filter($options['filters'] ?? [])->count();
    }

    /**
     * Escribe audit log manual del export. El trait Auditable solo dispara
     * en created/updated/deleted/restored del modelo; export no modifica
     * ningún record, así que el audit es manual.
     *
     * Event = 'export_queued': el audit registra la INTENCIÓN del usuario
     * al disparar el export. El estado final (ready/failed) vive en la tabla
     * `downloads`. Patrón ERP estándar — log de acción + tabla de estado.
     */
    public function recordExportAudit(string $format, array $options): void
    {
        AuditLog::create([
            'user_id'        => auth()->id(),
            'event'          => 'export_queued',
            'auditable_type' => SystemModule::class,
            'auditable_id'   => null,
            'module'         => 'system_modules',
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
            'url'        => route('system_management.system_modules.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
