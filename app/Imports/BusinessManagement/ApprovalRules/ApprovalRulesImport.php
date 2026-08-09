<?php

namespace App\Imports\BusinessManagement\ApprovalRules;

use App\Models\ApprovalRule;
use App\Models\ApproverRole;
use App\Models\Country;
use App\Models\WorkType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Alta masiva de reglas del flujo desde .xlsx/.csv.
 *
 * Columnas: `name` (cómo se llama la firma en obra, opcional), `country`
 * (código ISO), `work_type` (código del tipo; vacío = todos los tipos),
 * `approver_role` (código del catálogo), `priority_level`, `is_required`.
 *
 * Se referencia todo por código y no por id: un archivo con ids solo sirve en
 * la base de datos de la que salió. Una regla se identifica por la terna
 * país + tipo + rol, que es la misma clave con la que el motor resuelve el
 * flujo, así que reimportar el mismo archivo actualiza en vez de duplicar.
 *
 * Una regla BLOQUEADA (Lockable) no se pisa: el candado vale también por esta
 * puerta, que es justo por donde se colaría una hoja de cálculo mal copiada.
 */
class ApprovalRulesImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;

    /** @var array<int, array{row:int, message:string, value?:string}> */
    public array $errors = [];

    /** @var array<int, array{row:int, name:string, is_active:bool, action:string}> */
    public array $preview = [];

    protected int $maxRecords;
    protected int $currentCount;

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
    ) {
        $user = Auth::user();
        $this->maxRecords = $user?->tenant ? $user->tenant->maxRecordsPerModule() : PHP_INT_MAX;
        $this->currentCount = ApprovalRule::count();
    }

    public function collection(Collection $rows): void
    {
        DB::beginTransaction();

        try {
            $paises = Country::pluck('id', 'iso_code')->mapWithKeys(fn ($id, $iso) => [mb_strtoupper((string) $iso) => $id]);
            $tipos  = WorkType::pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtolower((string) $code) => $id]);
            $roles  = array_keys(ApproverRole::opciones());

            $vistas = [];
            $nuevas = 0;

            foreach ($rows as $i => $row) {
                $fila = $i + 2;

                $iso = mb_strtoupper(trim((string) ($row['country'] ?? '')));
                if ($iso === '' || ! isset($paises[$iso])) {
                    $this->errors[] = ['row' => $fila, 'message' => __('approval_rules.import_country_unknown'), 'value' => $iso ?: '—'];
                    continue;
                }
                $countryId = (int) $paises[$iso];

                // Tipo vacío = la regla vale para todos los tipos.
                $tipoCodigo = mb_strtolower(trim((string) ($row['work_type'] ?? '')));
                $workTypeId = null;
                if ($tipoCodigo !== '') {
                    if (! isset($tipos[$tipoCodigo])) {
                        $this->errors[] = ['row' => $fila, 'message' => __('approval_rules.import_work_type_unknown'), 'value' => $tipoCodigo];
                        continue;
                    }
                    $workTypeId = (int) $tipos[$tipoCodigo];
                }

                $rol = mb_strtolower(trim((string) ($row['approver_role'] ?? '')));
                if (! in_array($rol, $roles, true)) {
                    $this->errors[] = ['row' => $fila, 'message' => __('approval_rules.approver_role_unknown'), 'value' => $rol ?: '—'];
                    continue;
                }

                $nivel = (int) ($row['priority_level'] ?? 0);
                if ($nivel < 1 || $nivel > 20) {
                    $this->errors[] = ['row' => $fila, 'message' => __('approval_rules.import_level_invalid'), 'value' => (string) $nivel];
                    continue;
                }

                $obligatoria = filter_var($row['is_required'] ?? true, FILTER_VALIDATE_BOOLEAN);

                // Como se llama esa firma en obra. Sin nombre, la regla se
                // queda con el rol genérico, que es lo que se enseñaba antes.
                $nombre = trim((string) ($row['name'] ?? ''));
                $nombre = $nombre === '' ? null : mb_substr($nombre, 0, 255);

                // El mismo rol no firma dos veces el mismo flujo.
                $clave = $countryId . '|' . ($workTypeId ?? '*') . '|' . $rol;
                if (isset($vistas[$clave])) {
                    $this->errors[] = ['row' => $fila, 'message' => __('imports.err_duplicate_in_file', ['row' => $vistas[$clave]]), 'value' => $rol];
                    continue;
                }
                $vistas[$clave] = $fila;

                $existente = ApprovalRule::query()
                    ->where('country_id', $countryId)
                    ->where('approver_role', $rol)
                    ->when($workTypeId === null, fn ($q) => $q->whereNull('work_type_id'), fn ($q) => $q->where('work_type_id', $workTypeId))
                    ->first();

                $etiqueta = ($nombre ?: $rol) . ' · ' . $iso . ' · ' . ($tipoCodigo ?: __('approval_rules.all_work_types'));

                if ($existente) {
                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = ['row' => $fila, 'name' => $etiqueta, 'is_active' => (bool) $existente->is_active, 'action' => 'skipped'];
                        continue;
                    }

                    // Bloqueada: no se pisa. Se cuenta como saltada y se dice
                    // por qué, en vez de sobrescribir en silencio.
                    if ($existente->is_locked) {
                        $this->skipped++;
                        $this->errors[] = ['row' => $fila, 'message' => __('locks.cannot_edit_locked'), 'value' => $etiqueta];
                        continue;
                    }

                    $parche = [];
                    if ($nombre !== null && $existente->name !== $nombre)     $parche['name']           = $nombre;
                    if ((int) $existente->priority_level !== $nivel)          $parche['priority_level'] = $nivel;
                    if ((bool) $existente->is_required !== $obligatoria)      $parche['is_required']    = $obligatoria;
                    if (! empty($parche)) {
                        $existente->fill($parche)->save();
                    }

                    $this->updated++;
                    $this->preview[] = ['row' => $fila, 'name' => $etiqueta, 'is_active' => (bool) $existente->is_active, 'action' => 'updated'];
                    continue;
                }

                if ($this->maxRecords > 0 && $this->maxRecords !== PHP_INT_MAX
                    && ($this->currentCount + $nuevas) >= $this->maxRecords) {
                    $this->errors[] = ['row' => $fila, 'message' => __('plans.limit_records_reached', ['max' => $this->maxRecords]), 'value' => $etiqueta];
                    continue;
                }

                ApprovalRule::create([
                    'slug'           => Str::random(22),
                    'name'           => $nombre,
                    'country_id'     => $countryId,
                    'work_type_id'   => $workTypeId,
                    'approver_role'  => $rol,
                    'priority_level' => $nivel,
                    'is_required'    => $obligatoria,
                    'is_active'      => true,
                    'tenant_id'      => Auth::user()?->tenant_id,
                    'created_by'     => Auth::id(),
                ]);

                $nuevas++;
                $this->created++;
                $this->preview[] = ['row' => $fila, 'name' => $etiqueta, 'is_active' => true, 'action' => 'created'];
            }

            $this->dryRun ? DB::rollBack() : DB::commit();
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
}
