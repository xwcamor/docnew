<?php

namespace App\Imports\BusinessManagement\ApproverRoles;

use App\Models\ApproverRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Alta masiva del catalogo de roles aprobadores desde .xlsx/.csv.
 *
 * Columnas: `code` (obligatoria), `name_es`, `name_en`, `sort_order`.
 *
 * La fila se identifica por su **codigo**, no por el nombre: el codigo es la
 * clave con la que las reglas de flujo nombran al rol, y es lo unico que no
 * cambia cuando alguien decide que «Supervisor HSE» ahora se llama «Jefe HSE».
 * Se normaliza igual que en el formulario (minusculas, sin acentos, guion bajo)
 * para que importar y teclear den el mismo resultado.
 *
 * Toda alta nace activa; el estado se gestiona desde la pantalla.
 * `dryRun` deja la transaccion sin confirmar: es la vista previa del import.
 */
class ApproverRolesImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;

    /** @var array<int, array{row:int, message:string, value?:string}> */
    public array $errors = [];

    /** @var array<int, array{row:int, name:string, is_active:bool, action:string}> */
    public array $preview = [];

    /** Tope de registros del plan (PHP_INT_MAX = sin tope). */
    protected int $maxRecords;

    protected int $currentCount;

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
    ) {
        $user = Auth::user();
        $this->maxRecords = $user?->tenant ? $user->tenant->maxRecordsPerModule() : PHP_INT_MAX;
        $this->currentCount = ApproverRole::count();
    }

    public function collection(Collection $rows): void
    {
        DB::beginTransaction();

        try {
            $vistosEnElArchivo = [];
            $nuevos = 0;

            foreach ($rows as $i => $row) {
                $fila = $i + 2; // +2 = cabecera + indice base 0

                $code = $this->normalizeCode($row['code'] ?? null);
                if ($code === null) {
                    $this->errors[] = ['row' => $fila, 'message' => __('approver_roles.import_code_required'), 'value' => '—'];
                    continue;
                }
                if (mb_strlen($code) > 30) {
                    $this->errors[] = ['row' => $fila, 'message' => __('imports.err_code_too_long'), 'value' => mb_substr($code, 0, 30) . '…'];
                    continue;
                }

                if (isset($vistosEnElArchivo[$code])) {
                    $this->errors[] = [
                        'row'     => $fila,
                        'message' => __('imports.err_duplicate_in_file', ['row' => $vistosEnElArchivo[$code]]),
                        'value'   => $code,
                    ];
                    continue;
                }
                $vistosEnElArchivo[$code] = $fila;

                $nameEs = $this->texto($row['name_es'] ?? null);
                $nameEn = $this->texto($row['name_en'] ?? null);
                $orden  = is_numeric($row['sort_order'] ?? null) ? (int) $row['sort_order'] : null;

                $existente = ApproverRole::whereRaw('lower(code) = ?', [$code])->first();

                // Sin nombre no se puede dar de alta: un rol sin etiqueta no se
                // puede elegir en el selector del flujo. Al actualizar si vale
                // dejarlo vacio (se conserva el que ya tenia).
                if (! $existente && ($nameEs === null || $nameEn === null)) {
                    $this->errors[] = ['row' => $fila, 'message' => __('approver_roles.import_names_required'), 'value' => $code];
                    continue;
                }

                if ($existente) {
                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = ['row' => $fila, 'name' => $code, 'is_active' => (bool) $existente->is_active, 'action' => 'skipped'];
                        continue;
                    }

                    // Solo se tocan los campos que cambian: un update vacio deja
                    // una entrada de bitacora que no cuenta nada.
                    $parche = [];
                    if ($nameEs !== null && $existente->name_es !== $nameEs) $parche['name_es'] = $nameEs;
                    if ($nameEn !== null && $existente->name_en !== $nameEn) $parche['name_en'] = $nameEn;
                    if ($orden !== null && (int) $existente->sort_order !== $orden) $parche['sort_order'] = $orden;

                    if (! empty($parche)) {
                        $existente->fill($parche)->save();
                    }

                    $this->updated++;
                    $this->preview[] = ['row' => $fila, 'name' => $code, 'is_active' => (bool) $existente->is_active, 'action' => 'updated'];

                    continue;
                }

                if ($this->maxRecords > 0 && $this->maxRecords !== PHP_INT_MAX
                    && ($this->currentCount + $nuevos) >= $this->maxRecords) {
                    $this->errors[] = ['row' => $fila, 'message' => __('plans.limit_records_reached', ['max' => $this->maxRecords]), 'value' => $code];
                    continue;
                }

                ApproverRole::create([
                    'slug'       => Str::random(22),
                    'code'       => $code,
                    'name_es'    => $nameEs,
                    'name_en'    => $nameEn,
                    'sort_order' => $orden ?? ((int) (ApproverRole::max('sort_order') ?? 0) + 1),
                    'is_active'  => true,
                    'created_by' => Auth::id(),
                ]);

                $nuevos++;
                $this->created++;
                $this->preview[] = ['row' => $fila, 'name' => $code, 'is_active' => true, 'action' => 'created'];
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

    /** Misma normalizacion que el formulario: el codigo es una clave. */
    protected function normalizeCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $code = Str::of((string) $value)->trim()->ascii()->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        return $code === '' ? null : $code;
    }

    protected function texto(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $t = trim((string) $value);

        return $t === '' ? null : $t;
    }
}
