<?php

namespace App\Imports\SystemManagement\Locales;

use App\Models\Language;
use App\Models\Locale;
use App\Services\SystemManagement\LocaleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports Locales from .xlsx/.csv.
 *
 * Columns:
 *   - name      (required, max 255)
 *   - code      (required, BCP-47: ll[_CC] — es, es_PE)
 *   - language  (required, ISO code of master Language: es, en, pt…)
 *   - is_active (optional, boolean-ish)
 *
 * Modes:    'create_only' | 'update_or_create'
 *
 * 3-layer duplicate protection (aplica a name Y code).
 */
class LocalesImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;
    public array $errors = [];
    public array $preview = [];

    protected ?array $languageMap = null;

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
        protected ?LocaleService $service = null,
    ) {
        $this->service ??= app(LocaleService::class);
    }

    public function collection(Collection $rows): void
    {
        $this->loadLookups();

        DB::beginTransaction();

        try {
            $seenNames = [];
            $seenCodes = [];

            foreach ($rows as $i => $row) {
                $absoluteRow = $i + 2;

                $name = $this->normalizeName($row['name'] ?? null);
                if ($name === null) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('imports.err_name_required'), 'value' => '—'];
                    continue;
                }
                if (mb_strlen($name) > 255) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('imports.err_name_too_long'), 'value' => mb_substr($name, 0, 60) . '…'];
                    continue;
                }

                $normName = $this->normalizeKey($name);
                if (isset($seenNames[$normName])) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('imports.err_duplicate_in_file', ['row' => $seenNames[$normName]]), 'value' => $name];
                    continue;
                }
                $seenNames[$normName] = $absoluteRow;

                $codeRaw = trim((string) ($row['code'] ?? ''));
                $code    = $this->canonicalizeCode($codeRaw);
                if ($code === null || !preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $code)) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('locales.code_regex'), 'value' => $codeRaw ?: '—'];
                    continue;
                }

                $normCode = strtolower($code);
                if (isset($seenCodes[$normCode])) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('imports.err_duplicate_in_file', ['row' => $seenCodes[$normCode]]), 'value' => $code];
                    continue;
                }
                $seenCodes[$normCode] = $absoluteRow;

                $langRaw    = trim((string) ($row['language'] ?? ''));
                $languageId = $this->resolveLanguageId($langRaw);
                if ($languageId === null) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('locales.language_invalid'), 'value' => $langRaw ?: '—'];
                    continue;
                }

                $isActive = $this->normalizeBool($row['is_active'] ?? null, default: true);

                $existing = $this->findExistingByNameInsensitive($name);

                $codeClash = $this->findExistingByCode($code);
                if ($codeClash && (!$existing || $codeClash->id !== $existing->id)) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('locales.code_unique'), 'value' => $code];
                    continue;
                }

                $payload = [
                    'name'        => $name,
                    'code'        => $code,
                    'language_id' => $languageId,
                    'is_active'   => $isActive,
                ];

                // Preview: mostramos el valor crudo del archivo para que el user
                // valide el match (el ID resuelto ya queda en $payload).
                $previewBase = [
                    'row'       => $absoluteRow,
                    'name'      => $name,
                    'code'      => $code,
                    'language'  => $langRaw,
                    'is_active' => $isActive,
                ];

                if ($existing) {
                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = $previewBase + ['action' => 'skipped'];
                        continue;
                    }
                    $patch = [];
                    foreach ($payload as $k => $v) {
                        if ((string) $existing->{$k} !== (string) $v) $patch[$k] = $v;
                    }
                    if (!empty($patch)) {
                        $this->service->update($existing, $patch);
                    }
                    $this->updated++;
                    $this->preview[] = $previewBase + ['action' => 'updated'];
                } else {
                    $this->service->create($payload);
                    $this->created++;
                    $this->preview[] = $previewBase + ['action' => 'created'];
                }
            }

            if ($this->dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function summary(): array
    {
        return [
            'created'     => $this->created,
            'updated'     => $this->updated,
            'skipped'     => $this->skipped,
            'error_count' => count($this->errors),
            'total_rows'  => $this->created + $this->updated + $this->skipped + count($this->errors),
            'errors'      => array_slice($this->errors, 0, 50),
            'preview'     => array_slice($this->preview, 0, 100),
            'dry_run'     => $this->dryRun,
        ];
    }

    protected function loadLookups(): void
    {
        if ($this->languageMap === null) {
            $langs = Language::query()->whereNull('deleted_at')->get(['id', 'iso_code', 'name']);
            $this->languageMap = [];
            foreach ($langs as $l) {
                $this->languageMap[strtolower($l->iso_code)] = $l->id;
                $this->languageMap[$this->normalizeKey($l->name)] = $l->id;
            }
        }
    }

    protected function resolveLanguageId(string $raw): ?int
    {
        if ($raw === '') return null;
        $lower = strtolower(trim($raw));
        if (isset($this->languageMap[$lower])) {
            return $this->languageMap[$lower];
        }
        $norm = $this->normalizeKey($raw);
        return $this->languageMap[$norm] ?? null;
    }

    protected function canonicalizeCode(string $raw): ?string
    {
        if ($raw === '') return null;
        if (str_contains($raw, '_')) {
            [$lang, $regn] = explode('_', $raw, 2);
            return strtolower($lang) . '_' . strtoupper($regn);
        }
        return strtolower($raw);
    }

    protected function normalizeName(mixed $value): ?string
    {
        if ($value === null) return null;
        $name = trim((string) $value);
        return $name === '' ? null : $name;
    }

    protected function normalizeKey(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $stripped = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        return $stripped !== false ? $stripped : $lower;
    }

    protected function findExistingByNameInsensitive(string $name): ?Locale
    {
        $isPgsql = DB::getDriverName() === 'pgsql';
        $query = Locale::query();

        if ($isPgsql) {
            $query->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$name]);
        } else {
            $query->whereRaw('LOWER(name) = LOWER(?)', [$name]);
        }

        return $query->first();
    }

    protected function findExistingByCode(string $code): ?Locale
    {
        return Locale::query()
            ->whereRaw('LOWER(code) = ?', [strtolower($code)])
            ->first();
    }

    protected function normalizeBool(mixed $value, bool $default = true): bool
    {
        if ($value === null || $value === '') return $default;
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return ((int) $value) === 1;

        $normalized = mb_strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'sí', 'si', 's', 'activo', 'active', 'x'], true);
    }
}
