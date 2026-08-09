<?php

namespace App\Imports\BusinessManagement\Companies;

use App\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports Companies from .xlsx/.csv.
 *
 * Columns:
 *   - name          (required, max 255, unico per-tenant case/accent-insensitive)
 *   - num_doc       (required, max 20 — el RUC; unico por pais dentro del workspace)
 *   - complete_name (optional, max 255 — la razon social del documento)
 *
 * El pais de las altas es el del usuario que importa: `companies.country_id`
 * es NOT NULL y el fichero no lo trae.
 *
 * El import NO maneja is_active: toda alta nace activa (coherente con clientes). El estado se gestiona desde la UI / bulk actions.
 *
 * Modes: 'create_only' | 'update_or_create'
 *
 * companies es PER-TENANT: el import scope-a por tenant_id via el global scope de
 * BelongsToTenant (Company::create autorellena el tenant del actor).
 *
 * 3-layer duplicate protection (per-tenant):
 *   1. In-file: normalizado (trim+lower+iconv) catchea dupes en el mismo upload
 *   2. App: lookup case + accent insensitive contra toda la tabla
 *   3. DB: unique constraint de `slug` (auto-generado en el modelo)
 *
 * Enforce `Tenant::maxRecordsPerModule()`:
 *   - Si el plan del usuario tiene limite, contamos cuantos companies hay HOY +
 *     cuantos vamos a CREAR. Si supera, marcamos las filas excedentes como
 *     errores (no se crean). Las filas que actualizan existentes no cuentan
 *     contra el limite. El conteo es global (catalogo unico).
 *
 * Todo va en transaccion. dryRun=true â†’ rollback al final (preview UI).
 */
class CompaniesImport implements ToCollection, WithHeadingRow
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

    /** Count de companies del tenant del actor (pre-import). */
    protected int $currentCount;

    /** Pais de las altas: el del actor. NOT NULL en la tabla. */
    protected ?int $countryId = null;

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
    ) {
        $user = Auth::user();

        $this->countryId = $user?->country_id;

        // Limite del plan del usuario. Sin tenant/plan â†’ sin limite.
        if ($user && $user->tenant) {
            $this->maxRecords = $user->tenant->maxRecordsPerModule();
        } else {
            $this->maxRecords = PHP_INT_MAX;
        }

        // Snapshot del count actual del tenant (global scope de BelongsToTenant).
        $this->currentCount = Company::count();
    }

    public function collection(Collection $rows): void
    {
        DB::beginTransaction();

        try {
            // Layer 1: dedup in-file por nombre y por code normalizados.
            $seenInFileByName = [];
            $seenInFileByCode = [];
            $newRecordsCount = 0; // contador de filas que crearian un nuevo company

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

                // La razon social del documento (opcional en el Excel).
                $completeName = $this->normalizeName($row['complete_name'] ?? null);
                if ($completeName !== null && mb_strlen($completeName) > 255) {
                    $completeName = mb_substr($completeName, 0, 255);
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

                // El RUC es OBLIGATORIO: la columna es NOT NULL en la tabla. Se
                // trataba como opcional (resto del clon, donde `code` si lo
                // era) y una celda vacia mandaba un NULL a la base: Postgres
                // tumbaba la transaccion entera y las 200 filas buenas del
                // fichero se perdian con un "el proceso fallo" sin decir donde.
                $code = $this->normalizeCode($row['num_doc'] ?? null);
                if ($code === null) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('companies.import_err_num_doc_required'),
                        'value'   => $name,
                    ];
                    continue;
                }
                // 20 es lo que aguanta la columna y lo que valida el formulario.
                if (mb_strlen($code) > 20) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('companies.import_err_num_doc_too_long'),
                        'value'   => mb_substr($code, 0, 30) . '…',
                    ];
                    continue;
                }
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

                // Layer 2: DB lookup case + accent insensitive (per-tenant).
                $existing = $this->findExistingByNameInsensitive($name);

                // RUC unico per-tenant: si choca con OTRO registro (no el
                // matcheado por name), se rechaza la fila.
                if ($this->codeTakenByOther($code, $existing?->id)) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_code_duplicate', ['value' => $code]),
                        'value'   => $code,
                    ];
                    continue;
                }

                if ($existing) {
                    // Registro GLOBAL del catálogo (tenant_id null) y quien importa
                    // no es super: el guard de BelongsToTenantOrGlobal lanza al
                    // guardar. Si se le deja llegar, la excepción sale del `save()`,
                    // la transacción entera hace rollback y el usuario pierde TODAS
                    // las filas buenas del fichero con un 422 que no dice cuál falló.
                    // Se aparta esta fila y el resto del fichero sigue, igual que se
                    // hace con las bloqueadas justo debajo.
                    if ($existing->tenant_id === null && ! $this->actorEsSuper()) {
                        $this->skipped++;
                        $this->preview[] = [
                            'row'       => $absoluteRow,
                            'name'      => $name,
                            'is_active' => (bool) $existing->is_active,
                            'action'    => 'skipped',
                            'reason'    => 'global',
                        ];
                        continue;
                    }

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
                    // import NO gestiona el estado (eso va por la UI / bulk);
                    // refresca el RUC y la razon social si vinieron distintos.
                    $patch = [];
                    if ((string) $existing->num_doc !== $code) $patch['num_doc'] = $code;
                    if ($completeName !== null && (string) $existing->complete_name !== $completeName) {
                        $patch['complete_name'] = $completeName;
                    }
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

                    // El pais es NOT NULL. Si el actor no tiene ninguno asignado
                    // el alta reventaba contra la base; ahora se rechaza la fila
                    // con un mensaje que dice que hacer.
                    if ($this->countryId === null) {
                        $this->errors[] = [
                            'row'     => $absoluteRow,
                            'message' => __('companies.import_err_no_country'),
                            'value'   => $name,
                        ];
                        continue;
                    }

                    // Las altas nacen activas. El import no importa registros inactivos (coherente con clientes/oil_types): el estado se gestiona desde la UI / bulk actions.
                    Company::create([
                        'name'          => $name,
                        'num_doc'       => $code,
                        // La razon social del documento: si no viene en el Excel
                        // se usa el nombre corto hasta que alguien la complete.
                        'complete_name' => $completeName ?? $name,
                        'country_id'    => $this->countryId,
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

    /**
     * El RUC, limpio. Se quitan espacios y guiones igual que hace el formulario
     * (StoreCompanyRequest): si el Excel trae «20-5123 45678» y la pantalla
     * guarda «20512345678», la misma empresa entraba dos veces.
     */
    protected function normalizeCode(mixed $value): ?string
    {
        if ($value === null) return null;
        $code = preg_replace('/[\s-]/', '', trim((string) $value));
        return $code === '' ? null : $code;
    }

    /**
     * ¿El RUC ya está en OTRO registro (no $exceptId)? Per-tenant (el global
     * scope de BelongsToTenant limita al tenant del actor).
     */
    /** ¿Quien está importando es super? (los globales solo los toca él). */
    protected function actorEsSuper(): bool
    {
        $user = auth()->user();

        return $user !== null && method_exists($user, 'hasRole') && $user->hasRole('super');
    }

    protected function codeTakenByOther(string $code, ?int $exceptId): bool
    {
        return Company::query()
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where('num_doc', trim($code))
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
    protected function findExistingByNameInsensitive(string $name): ?Company
    {
        $isPgsql = DB::getDriverName() === 'pgsql';
        $query   = Company::query();

        if ($isPgsql) {
            $query->whereRaw('unaccent(LOWER(companies.name)) = unaccent(LOWER(?))', [$name]);
        } else {
            $query->whereRaw('LOWER(companies.name) = LOWER(?)', [$name]);
        }

        return $query->first();
    }
}
