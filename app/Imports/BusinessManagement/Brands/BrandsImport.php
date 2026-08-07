<?php

namespace App\Imports\BusinessManagement\Brands;

use App\Models\Brand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports Brands from .xlsx/.csv.
 *
 * Columns:
 *   - name  (required, max 255, unico per-tenant case/accent-insensitive)
 *   - code  (optional, max 40, identificador tecnico unico per-tenant)
 *
 * El import NO maneja is_active: toda alta nace activa (coherente con clientes). El estado se gestiona desde la UI / bulk actions.
 *
 * Modes: 'create_only' | 'update_or_create'
 *
 * brands es PER-TENANT: el import scope-a por tenant_id via el global scope de
 * BelongsToTenant (Brand::create autorellena el tenant del actor).
 *
 * 3-layer duplicate protection (per-tenant):
 *   1. In-file: normalizado (trim+lower+iconv) catchea dupes en el mismo upload
 *   2. App: lookup case + accent insensitive contra toda la tabla
 *   3. DB: unique constraint de `slug` (auto-generado en el modelo)
 *
 * Enforce `Tenant::maxRecordsPerModule()`:
 *   - Si el plan del usuario tiene limite, contamos cuantos brands hay HOY +
 *     cuantos vamos a CREAR. Si supera, marcamos las filas excedentes como
 *     errores (no se crean). Las filas que actualizan existentes no cuentan
 *     contra el limite. El conteo es global (catalogo unico).
 *
 * Todo va en transaccion. dryRun=true â†’ rollback al final (preview UI).
 */
class BrandsImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;

    /** @var array<int, array{row:int, message:string, value?:string}> */
    public array $errors = [];

    /** @var array<int, array{row:int, name:string, is_active:bool, action:string}> */
    public array $preview = [];
    /** Limite de records del plan (>0 = aplica; 0 o PHP_INT_MAX = ilimitado). */
    protected int $maxRecords;

    /** Count de brands del tenant del actor (pre-import). */
    protected int $currentCount;

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
    ) {
        $user = Auth::user();

        // Limite del plan del usuario. Sin tenant/plan â†’ sin limite.
        if ($user && $user->tenant) {
            $this->maxRecords = $user->tenant->maxRecordsPerModule();
        } else {
            $this->maxRecords = PHP_INT_MAX;
        }

        // Snapshot del count actual del tenant (global scope de BelongsToTenant).
        $this->currentCount = Brand::count();
    }

    public function collection(Collection $rows): void
    {
        DB::beginTransaction();

        try {
            // Layer 1: dedup in-file por nombre y por code normalizados.
            $seenInFileByName = [];
            $seenInFileByCode = [];
            $newRecordsCount = 0; // contador de filas que crearian un nuevo brand

            foreach ($rows as $i => $row) {
                $absoluteRow = $i + 2; // +2 = header (1) + indexacion desde 0.

                $name = $this->normalizeName($row['name'] ?? null);
                if ($name === null) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_name_required'),
                        'value'   => 'â€”',
                    ];
                    continue;
                }
                if (mb_strlen($name) > 255) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_name_too_long'),
                        'value'   => mb_substr($name, 0, 60) . 'â€¦',
                    ];
                    continue;
                }

                $normNameKey = $this->normalizeKey($name);
                if (isset($seenInFileByName[$normNameKey])) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_duplicate_in_file', ['row' => $seenInFileByName[$normNameKey]]),
                        'value'   => $name,
                    ];
                    continue;
                }
                $seenInFileByName[$normNameKey] = $absoluteRow;

                // code (opcional): identificador tecnico unico per-tenant.
                $code = $this->normalizeCode($row['code'] ?? null);
                if ($code !== null && mb_strlen($code) > 40) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_code_too_long'),
                        'value'   => mb_substr($code, 0, 30) . '…',
                    ];
                    continue;
                }
                if ($code !== null) {
                    $codeKey = mb_strtolower($code);
                    if (isset($seenInFileByCode[$codeKey])) {
                        $this->errors[] = [
                            'row'     => $absoluteRow,
                            'message' => __('imports.err_duplicate_in_file', ['row' => $seenInFileByCode[$codeKey]]),
                            'value'   => $code,
                        ];
                        continue;
                    }
                    $seenInFileByCode[$codeKey] = $absoluteRow;
                }

                // Layer 2: DB lookup case + accent insensitive (per-tenant).
                $existing = $this->findExistingByNameInsensitive($name);

                // code unico per-tenant: si choca con OTRO registro (no el matcheado
                // por name), se rechaza la fila.
                if ($code !== null && $this->codeTakenByOther($code, $existing?->id)) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_code_duplicate', ['value' => $code]),
                        'value'   => $code,
                    ];
                    continue;
                }

                if ($existing) {
                    // Registro BLOQUEADO (Lockable): el import no lo pisa. Se reporta
                    // como saltado para que el usuario sepa que existe pero está
                    // congelado (hay que desbloquearlo para actualizarlo).
                    if ($existing->is_locked) {
                        $this->skipped++;
                        $this->preview[] = [
                            'row'         => $absoluteRow,
                            'name'        => $name,
                            'is_active'   => (bool) $existing->is_active,
                            'action'      => 'skipped',
                            'reason'      => 'locked',
                        ];
                        continue;
                    }

                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = [
                            'row'         => $absoluteRow,
                            'name'        => $name,
                            'is_active'   => (bool) $existing->is_active,
                            'action'      => 'skipped',
                        ];
                        continue;
                    }

                    // Solo tocar campos que cambian (evita audit logs vacios). El
                    // import NO gestiona el estado (eso va por la UI / bulk); solo
                    // refresca el code técnico si cambió.
                    $patch = [];
                    if ($code !== null && (string) $existing->code !== $code) $patch['code'] = $code;
                    if (!empty($patch)) {
                        $existing->fill($patch)->save();
                    }

                    $this->updated++;
                    $this->preview[] = [
                        'row'         => $absoluteRow,
                        'name'        => $name,
                        'is_active'   => (bool) $existing->is_active,
                        'action'      => 'updated',
                    ];
                } else {
                    // Antes de crear, validar limite del plan.
                    if ($this->maxRecords > 0 && $this->maxRecords !== PHP_INT_MAX) {
                        if (($this->currentCount + $newRecordsCount) >= $this->maxRecords) {
                            $this->errors[] = [
                                'row'     => $absoluteRow,
                                'message' => __('plans.limit_records_reached', ['max' => $this->maxRecords]),
                                'value'   => $name,
                            ];
                            continue;
                        }
                    }

                    // Las altas nacen activas. El import no importa registros inactivos (coherente con clientes/oil_types): el estado se gestiona desde la UI / bulk actions.
                    Brand::create([
                        'name'        => $name,
                        'code'        => $code,
                        'is_active'   => true,
                        'created_by'  => Auth::id(),
                        // tenant_id lo autorellena BelongsToTenant (tenant del actor);
                        // el slug lo auto-genera el modelo en `creating`.
                    ]);

                    $newRecordsCount++;
                    $this->created++;
                    $this->preview[] = [
                        'row'         => $absoluteRow,
                        'name'        => $name,
                        'is_active'   => true,
                        'action'      => 'created',
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

    /** Trim → null si vacío. El code es el identificador técnico. */
    protected function normalizeCode(mixed $value): ?string
    {
        if ($value === null) return null;
        $code = trim((string) $value);
        return $code === '' ? null : $code;
    }

    /**
     * ¿El code ya existe en OTRO registro (no $exceptId)? Case-insensitive,
     * per-tenant (el global scope de BelongsToTenant limita al tenant del actor).
     */
    protected function codeTakenByOther(string $code, ?int $exceptId): bool
    {
        return Brand::query()
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereRaw('LOWER(code) = LOWER(?)', [trim($code)])
            ->exists();
    }
    /** Lowercase + strip accents (iconv) â€” mismo pattern que el DB-level layer 2. */
    protected function normalizeKey(string $name): string
    {
        $lower    = mb_strtolower(trim($name));
        $stripped = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        return $stripped !== false ? $stripped : $lower;
    }

    /**
     * Lookup case + accent insensitive (Postgres unaccent / fallback LOWER).
     * Per-tenant: el global scope de BelongsToTenant limita al tenant del actor.
     */
    protected function findExistingByNameInsensitive(string $name): ?Brand
    {
        $isPgsql = DB::getDriverName() === 'pgsql';
        $query   = Brand::query();

        if ($isPgsql) {
            $query->whereRaw('unaccent(LOWER(brands.name)) = unaccent(LOWER(?))', [$name]);
        } else {
            $query->whereRaw('LOWER(brands.name) = LOWER(?)', [$name]);
        }

        return $query->first();
    }
}
