<?php

namespace App\Imports\BusinessManagement\WorkPlans;

use App\Models\Company;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Importa planes de trabajo desde .xlsx/.csv.
 *
 * Columnas:
 *   - code           (obligatorio, identifica el plan — unico per-tenant)
 *   - num_os         (opcional)
 *   - description    (obligatorio al crear)
 *   - company        (RUC o nombre exacto de la empresa contratista)
 *   - work_type      (codigo del tipo de trabajo)
 *   - work_location  (nombre de la sede)
 *   - date_start     (YYYY-MM-DD, opcional)
 *
 * Un plan NO nace terminado: is_done queda en false y las firmas se levantan
 * desde la app. Por eso el import no acepta esa columna.
 *
 * Modes: 'create_only' | 'update_or_create'
 *
 * work_plans es PER-TENANT: el import scope-a por tenant_id via el global scope
 * de BelongsToTenant (WorkPlan::create autorellena el tenant del actor).
 *
 * 3 capas de proteccion contra duplicados (per-tenant):
 *   1. En el archivo: el codigo normalizado catchea dupes del mismo upload
 *   2. App: lookup case-insensitive contra toda la tabla
 *   3. DB: indice unico parcial UPPER(code) + unique de `slug`
 *
 * Todo va en transaccion. dryRun=true → rollback al final (preview UI).
 */
class WorkPlansImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;

    /** @var array<int, array{row:int, message:string, value?:string}> */
    public array $errors = [];

    /** @var array<int, array{row:int, name:string, action:string}> */
    public array $preview = [];

    /** Limite de records del plan (>0 = aplica; 0 o PHP_INT_MAX = ilimitado). */
    protected int $maxRecords;

    /** Count de planes del tenant del actor (pre-import). */
    protected int $currentCount;

    /** Catalogos cacheados: resolver por fila seria una query por celda. */
    protected array $companiesByKey = [];
    protected array $workTypesByCode = [];
    protected array $workLocationsByName = [];

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
    ) {
        $user = Auth::user();

        if ($user && $user->tenant) {
            $this->maxRecords = $user->tenant->maxRecordsPerModule();
        } else {
            $this->maxRecords = PHP_INT_MAX;
        }

        $this->currentCount = WorkPlan::count();

        // Una empresa se identifica por RUC o por nombre: en los Excel de obra
        // aparecen las dos formas indistintamente.
        foreach (Company::query()->get(['id', 'name', 'num_doc']) as $c) {
            $this->companiesByKey[$this->key($c->num_doc)] = $c->id;
            $this->companiesByKey[$this->key($c->name)]    = $c->id;
        }
        foreach (WorkType::query()->get(['id', 'code']) as $wt) {
            $this->workTypesByCode[$this->key($wt->code)] = $wt->id;
        }
        foreach (WorkLocation::query()->get(['id', 'name']) as $wl) {
            $this->workLocationsByName[$this->key($wl->name)] = $wl->id;
        }
    }

    public function collection(Collection $rows): void
    {
        DB::beginTransaction();

        try {
            $seenInFile = [];
            $newRecordsCount = 0;

            foreach ($rows as $i => $row) {
                $absoluteRow = $i + 2; // +2 = header (1) + indexacion desde 0.

                $code = $this->trimOrNull($row['code'] ?? null);
                if ($code === null) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('work_plans.code_required'),
                        'value'   => '—',
                    ];
                    continue;
                }
                if (mb_strlen($code) > 255) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_code_too_long'),
                        'value'   => mb_substr($code, 0, 30) . '…',
                    ];
                    continue;
                }

                $codeKey = $this->key($code);
                if (isset($seenInFile[$codeKey])) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_duplicate_in_file', ['row' => $seenInFile[$codeKey]]),
                        'value'   => $code,
                    ];
                    continue;
                }
                $seenInFile[$codeKey] = $absoluteRow;

                $existing = WorkPlan::query()
                    ->whereRaw('UPPER(work_plans.code) = UPPER(?)', [$code])
                    ->first();

                if ($existing) {
                    // Registro BLOQUEADO (Lockable): el import no lo pisa.
                    if ($existing->is_locked) {
                        $this->skipped++;
                        $this->preview[] = ['row' => $absoluteRow, 'name' => $code, 'action' => 'skipped', 'reason' => 'locked'];
                        continue;
                    }
                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = ['row' => $absoluteRow, 'name' => $code, 'action' => 'skipped'];
                        continue;
                    }

                    // Solo se tocan los campos que cambian: evita audit logs vacios.
                    $patch = [];
                    foreach (['num_os', 'description'] as $field) {
                        $value = $this->trimOrNull($row[$field] ?? null);
                        if ($value !== null && (string) $existing->{$field} !== $value) {
                            $patch[$field] = $value;
                        }
                    }
                    $dateStart = $this->trimOrNull($row['date_start'] ?? null);
                    if ($dateStart !== null && optional($existing->date_start)->format('Y-m-d') !== $dateStart) {
                        $patch['date_start'] = $dateStart;
                    }
                    if (!empty($patch)) {
                        $existing->fill($patch)->save();
                    }

                    $this->updated++;
                    $this->preview[] = ['row' => $absoluteRow, 'name' => $code, 'action' => 'updated'];
                    continue;
                }

                // ── Alta: hacen falta empresa, tipo de trabajo y sede ────────
                $description = $this->trimOrNull($row['description'] ?? null);
                if ($description === null) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('work_plans.description_required'), 'value' => $code];
                    continue;
                }

                $companyId = $this->companiesByKey[$this->key($row['company'] ?? '')] ?? null;
                if ($companyId === null) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('work_plans.company_required'), 'value' => (string) ($row['company'] ?? '')];
                    continue;
                }
                $workTypeId = $this->workTypesByCode[$this->key($row['work_type'] ?? '')] ?? null;
                if ($workTypeId === null) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('work_plans.work_type_required'), 'value' => (string) ($row['work_type'] ?? '')];
                    continue;
                }
                $workLocationId = $this->workLocationsByName[$this->key($row['work_location'] ?? '')] ?? null;
                if ($workLocationId === null) {
                    $this->errors[] = ['row' => $absoluteRow, 'message' => __('work_plans.work_location_required'), 'value' => (string) ($row['work_location'] ?? '')];
                    continue;
                }

                if ($this->maxRecords > 0 && $this->maxRecords !== PHP_INT_MAX) {
                    if (($this->currentCount + $newRecordsCount) >= $this->maxRecords) {
                        $this->errors[] = [
                            'row'     => $absoluteRow,
                            'message' => __('plans.limit_records_reached', ['max' => $this->maxRecords]),
                            'value'   => $code,
                        ];
                        continue;
                    }
                }

                WorkPlan::create([
                    'code'             => $code,
                    'num_os'           => $this->trimOrNull($row['num_os'] ?? null),
                    'description'      => $description,
                    'country_id'       => Auth::user()?->country_id,
                    'company_id'       => $companyId,
                    'work_type_id'     => $workTypeId,
                    'work_location_id' => $workLocationId,
                    'date_start'       => $this->trimOrNull($row['date_start'] ?? null),
                    // Un plan importado nace pendiente: las firmas se levantan
                    // en obra, nunca desde un Excel.
                    'is_done'          => false,
                    'user_id'          => Auth::id(),
                    'created_by'       => Auth::id(),
                    // tenant_id lo autorellena BelongsToTenant; el slug lo
                    // auto-genera el modelo en `creating`.
                ]);

                $newRecordsCount++;
                $this->created++;
                $this->preview[] = ['row' => $absoluteRow, 'name' => $code, 'action' => 'created'];
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

    protected function trimOrNull(mixed $value): ?string
    {
        if ($value === null) return null;
        $v = trim((string) $value);
        return $v === '' ? null : $v;
    }

    /** Lowercase + sin tildes: los catalogos de obra vienen escritos a mano. */
    protected function key(mixed $value): string
    {
        $lower    = mb_strtolower(trim((string) $value));
        $stripped = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        return $stripped !== false ? $stripped : $lower;
    }
}
