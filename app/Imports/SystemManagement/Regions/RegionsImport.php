<?php

namespace App\Imports\SystemManagement\Regions;

use App\Models\Region;
use App\Services\SystemManagement\RegionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports Regions from .xlsx/.csv.
 *
 * Columns:  name (required, max 255), is_active (optional, boolean-ish)
 * Modes:    'create_only' | 'update_or_create'
 *
 * 3-layer duplicate protection:
 *   1. In-file: normalizado (trim+lower+iconv) catchea dupes en el mismo upload
 *   2. App: lookup case + accent insensitive contra DB (mismo pattern que scopeFilter)
 *   3. DB: partial unique index `unaccent(lower(name)) WHERE deleted_at IS NULL`
 *
 * Todo va en transacción. dryRun=true → rollback al final (preview UI).
 */
class RegionsImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;

    /** @var array<int, array{row:int, message:string, value?:string}> */
    public array $errors = [];

    /** @var array<int, array{row:int, name:string, is_active:bool, action:string}> */
    public array $preview = [];

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
        protected ?RegionService $service = null,
    ) {
        $this->service ??= app(RegionService::class);
    }

    public function collection(Collection $rows): void
    {
        DB::beginTransaction();

        try {
            // Layer 1: dedup in-file. Map normalized name → row donde apareció.
            $seenInFile = [];

            // +2 = header (fila 1) + indexación desde 0.
            foreach ($rows as $i => $row) {
                $absoluteRow = $i + 2;

                $name = $this->normalizeName($row['name'] ?? null);
                if ($name === null) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_name_required'),
                        'value'   => '—',
                    ];
                    continue;
                }
                if (mb_strlen($name) > 255) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_name_too_long'),
                        'value'   => mb_substr($name, 0, 60) . '…',
                    ];
                    continue;
                }

                $normKey = $this->normalizeKey($name);
                if (isset($seenInFile[$normKey])) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_duplicate_in_file', ['row' => $seenInFile[$normKey]]),
                        'value'   => $name,
                    ];
                    continue;
                }
                $seenInFile[$normKey] = $absoluteRow;

                $isActive = $this->normalizeBool($row['is_active'] ?? null, default: true);

                // Layer 2: DB lookup case + accent insensitive (ignora soft-deleted).
                $existing = $this->findExistingByNameInsensitive($name);

                if ($existing) {
                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = [
                            'row'       => $absoluteRow,
                            'name'      => $name,
                            'is_active' => $isActive,
                            'action'    => 'skipped',
                        ];
                        continue;
                    }
                    // Solo tocar is_active si cambió (evita audit logs vacíos).
                    if ($existing->is_active !== $isActive) {
                        $this->service->update($existing, ['is_active' => $isActive]);
                    }
                    $this->updated++;
                    $this->preview[] = [
                        'row'       => $absoluteRow,
                        'name'      => $name,
                        'is_active' => $isActive,
                        'action'    => 'updated',
                    ];
                } else {
                    $this->service->create([
                        'name'      => $name,
                        'is_active' => $isActive,
                    ]);
                    $this->created++;
                    $this->preview[] = [
                        'row'       => $absoluteRow,
                        'name'      => $name,
                        'is_active' => $isActive,
                        'action'    => 'created',
                    ];
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
            'created'      => $this->created,
            'updated'      => $this->updated,
            'skipped'      => $this->skipped,
            'error_count'  => count($this->errors),
            'total_rows'   => $this->created + $this->updated + $this->skipped + count($this->errors),
            'errors'       => array_slice($this->errors, 0, 50),
            'preview'      => array_slice($this->preview, 0, 100),
            'dry_run'      => $this->dryRun,
        ];
    }

    protected function normalizeName(mixed $value): ?string
    {
        if ($value === null) return null;
        $name = trim((string) $value);
        return $name === '' ? null : $name;
    }

    /** Lowercase + strip accents (iconv) — mismo pattern que el DB-level layer 2. */
    protected function normalizeKey(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $stripped = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        return $stripped !== false ? $stripped : $lower;
    }

    /** Lookup case + accent insensitive (Postgres unaccent / fallback LOWER). */
    protected function findExistingByNameInsensitive(string $name): ?Region
    {
        $isPgsql = DB::getDriverName() === 'pgsql';
        $query = Region::query();

        if ($isPgsql) {
            $query->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$name]);
        } else {
            $query->whereRaw('LOWER(name) = LOWER(?)', [$name]);
        }

        return $query->first();
    }

    /** Acepta 1/0, true/false, sí/no, activo/inactivo, yes/no, active/inactive, x. */
    protected function normalizeBool(mixed $value, bool $default = true): bool
    {
        if ($value === null || $value === '') return $default;
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return ((int) $value) === 1;

        $normalized = mb_strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'sí', 'si', 's', 'activo', 'active', 'x'], true);
    }
}
