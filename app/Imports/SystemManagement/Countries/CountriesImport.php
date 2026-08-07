<?php

namespace App\Imports\SystemManagement\Countries;

use App\Models\Country;
use App\Models\Region;
use App\Models\Locale;
use App\Services\SystemManagement\CountryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports Countries from .xlsx/.csv.
 *
 * Columns:
 *   - name           (required, max 255)
 *   - iso_code       (required, 2 chars ISO 3166-1 alpha-2)
 *   - currency       (required, 3 chars ISO 4217 alpha-3)
 *   - timezone       (required, IANA tz like "America/Lima")
 *   - region         (required, NAME of an existing Region — accent-insensitive)
 *   - default_locale (required, CODE of an existing Locale — e.g. "es_PE")
 *   - is_active      (optional, boolean-ish; default true)
 *
 * Modes:  'create_only' | 'update_or_create'
 *
 * 3-layer duplicate protection (same as Regions):
 *   1. In-file: normalizado por name catchea dupes en el mismo upload
 *   2. App: lookup case+accent insensitive contra DB
 *   3. DB: partial unique index `unaccent(lower(name)) WHERE deleted_at IS NULL`
 *
 * Region y Locale se resuelven una vez al inicio del import (mapas en memoria)
 * para evitar N queries por fila.
 */
class CountriesImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;
    public array $errors = [];
    public array $preview = [];

    /** Lookup tables cargadas en cache al primer uso. */
    protected ?array $regionMap = null;
    protected ?array $localeMap = null;

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
        protected ?CountryService $service = null,
    ) {
        $this->service ??= app(CountryService::class);
    }

    public function collection(Collection $rows): void
    {
        $this->loadLookups();

        DB::beginTransaction();

        try {
            // Layer 1: dedup in-file. Mapas separados para name y iso_code.
            $seenNames = [];
            $seenIsos  = [];

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

                // ── iso_code, currency, timezone ─────────────────────────────
                $isoCode = strtoupper(trim((string) ($row['iso_code'] ?? '')));
                if (!preg_match('/^[A-Z]{2}$/', $isoCode)) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('countries.iso_code_regex'), 'value' => $isoCode ?: '—'];
                    continue;
                }

                $normIso = strtolower($isoCode);
                if (isset($seenIsos[$normIso])) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('imports.err_duplicate_in_file', ['row' => $seenIsos[$normIso]]), 'value' => $isoCode];
                    continue;
                }
                $seenIsos[$normIso] = $absoluteRow;

                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('countries.currency_regex'), 'value' => $currency ?: '—'];
                    continue;
                }

                $timezone = trim((string) ($row['timezone'] ?? ''));
                if ($timezone === '' || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('countries.timezone_invalid'), 'value' => $timezone ?: '—'];
                    continue;
                }

                // ── Region (resuelve por nombre, accent-insensitive) ─────────
                $regionRaw = trim((string) ($row['region'] ?? ''));
                $regionId  = $this->resolveRegionId($regionRaw);
                if ($regionId === null) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('countries.region_invalid'), 'value' => $regionRaw ?: '—'];
                    continue;
                }

                // ── Locale (resuelve por code) ───────────────────────────────
                $localeRaw = trim((string) ($row['default_locale'] ?? ''));
                $localeId  = $this->resolveLocaleId($localeRaw);
                if ($localeId === null) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('countries.default_locale_invalid'), 'value' => $localeRaw ?: '—'];
                    continue;
                }

                $isActive = $this->normalizeBool($row['is_active'] ?? null, default: true);

                $existing = $this->findExistingByNameInsensitive($name);

                // Layer 2 bis: si el ISO ya existe en otro registro activo
                // distinto al que matchea por name, devolvemos error legible
                // antes de que el unique partial index de Postgres explote.
                $isoClash = $this->findExistingByIsoCode($isoCode);
                if ($isoClash && (!$existing || $isoClash->id !== $existing->id)) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('countries.iso_code_unique'),
                        'value'   => $isoCode,
                    ];
                    continue;
                }

                $payload = [
                    'name'              => $name,
                    'iso_code'          => $isoCode,
                    'currency'          => $currency,
                    'timezone'          => $timezone,
                    'region_id'         => $regionId,
                    'default_locale_id' => $localeId,
                    'is_active'         => $isActive,
                ];

                $previewBase = [
                    'row'            => $absoluteRow,
                    'name'           => $name,
                    'iso_code'       => $isoCode,
                    'currency'       => $currency,
                    'timezone'       => $timezone,
                    // Mostramos el valor crudo que vino en el xlsx para que el user
                    // pueda revisar el match. El ID resuelto ya queda en $payload.
                    'region'         => $regionRaw,
                    'default_locale' => $localeRaw,
                    'is_active'      => $isActive,
                ];

                if ($existing) {
                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = $previewBase + ['action' => 'skipped'];
                        continue;
                    }
                    // Solo actualizar campos que realmente cambiaron (evita audit noise).
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

    /** Cargar mapas de region/locale al primer uso. */
    protected function loadLookups(): void
    {
        if ($this->regionMap === null) {
            $this->regionMap = Region::query()
                ->whereNull('deleted_at')
                ->get(['id', 'name'])
                ->mapWithKeys(fn($r) => [$this->normalizeKey($r->name) => $r->id])
                ->toArray();
        }
        if ($this->localeMap === null) {
            $this->localeMap = Locale::query()
                ->whereNull('deleted_at')
                ->get(['id', 'code'])
                ->mapWithKeys(fn($l) => [mb_strtolower(trim($l->code)) => $l->id])
                ->toArray();
        }
    }

    protected function resolveRegionId(string $name): ?int
    {
        if ($name === '') return null;
        $key = $this->normalizeKey($name);
        return $this->regionMap[$key] ?? null;
    }

    protected function resolveLocaleId(string $code): ?int
    {
        if ($code === '') return null;
        $key = mb_strtolower(trim($code));
        return $this->localeMap[$key] ?? null;
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

    protected function findExistingByNameInsensitive(string $name): ?Country
    {
        $isPgsql = DB::getDriverName() === 'pgsql';
        $query = Country::query();

        if ($isPgsql) {
            $query->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$name]);
        } else {
            $query->whereRaw('LOWER(name) = LOWER(?)', [$name]);
        }

        return $query->first();
    }

    /** Layer-2 lookup por iso_code (case-insensitive, ignora soft-deleted). */
    protected function findExistingByIsoCode(string $code): ?Country
    {
        return Country::query()
            ->whereRaw('LOWER(iso_code) = ?', [strtolower($code)])
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
