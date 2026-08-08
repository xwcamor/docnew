<?php

namespace App\Imports\BusinessManagement\People;

use App\Models\Person;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Importa personas desde .xlsx/.csv.
 *
 * Columnas:
 *   - doc_type (opcional — DNI si no viene)
 *   - num_doc  (obligatorio: identifica a la persona)
 *   - name     (obligatorio)
 *   - lastname (obligatorio)
 *
 * La identidad es el DOCUMENTO, no el nombre: en el sistema v1 el match por
 * nombre fusionaba homónimos y partía en dos a la misma persona escrita de dos
 * formas. Aquí una fila actualiza a alguien solo si coincide el documento.
 *
 * El import NO maneja is_active: toda alta nace activa. Tampoco enrola la cara
 * ni carga firmas: eso se hace en obra, con la persona delante.
 *
 * Modes: 'create_only' | 'update_or_create'
 *
 * people es PER-TENANT: el import scope-a por tenant_id via el global scope de
 * BelongsToTenant (Person::create autorellena el tenant del actor).
 *
 * 3 capas contra duplicados (per-tenant):
 *   1. En el archivo: documento normalizado catchea dupes del mismo upload
 *   2. App: lookup por doc_type + num_doc contra toda la tabla
 *   3. DB: indice unico parcial (tenant, pais, doc_type, num_doc)
 *
 * Enforce `Tenant::maxRecordsPerModule()`: las filas que crean cuentan contra
 * el limite del plan; las que actualizan, no.
 *
 * Todo va en transaccion. dryRun=true → rollback al final (preview UI).
 */
class PeopleImport implements ToCollection, WithHeadingRow
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

    /** Count de people del tenant del actor (pre-import). */
    protected int $currentCount;

    /**
     * Tipos de documento admitidos, del catalogo del pais del actor.
     *
     * Era una lista escrita aqui —`['DNI', 'CE', 'PASAPORTE']`— que sobrevivio
     * al paso del tipo de documento a catalogo: dar de alta el PTP desde la
     * pantalla funcionaba y por Excel se rechazaba. Se resuelve una vez, en el
     * constructor, porque si no se consulta la tabla una vez por fila.
     *
     * @var array<int, string>
     */
    protected array $docTypes;

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
    ) {
        $user = Auth::user();

        // Limite del plan del usuario. Sin tenant/plan → sin limite.
        if ($user && $user->tenant) {
            $this->maxRecords = $user->tenant->maxRecordsPerModule();
        } else {
            $this->maxRecords = PHP_INT_MAX;
        }

        // Snapshot del count actual del tenant (global scope de BelongsToTenant).
        $this->currentCount = Person::count();

        // Mismo criterio que `ReglasDelDocumento`: manda el catalogo y, si el
        // pais no tiene ninguno sembrado, los tres de siempre.
        $delPais = DocumentType::query()
            ->where('country_id', $user?->country_id)
            ->where('is_active', true)
            ->pluck('code')
            ->all();

        $this->docTypes = $delPais ?: PersonController::docTypesPorDefecto();
    }

    public function collection(Collection $rows): void
    {
        DB::beginTransaction();

        try {
            $seenInFile = [];
            $newRecordsCount = 0;

            foreach ($rows as $i => $row) {
                $absoluteRow = $i + 2; // +2 = header (1) + indexacion desde 0.

                $numDoc = $this->trimOrNull($row['num_doc'] ?? null);
                if ($numDoc !== null) {
                    $numDoc = preg_replace('/[\s-]/', '', $numDoc);
                }
                if ($numDoc === null || $numDoc === '') {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('people.num_doc_required'),
                        'value'   => '—',
                    ];
                    continue;
                }

                $docType = strtoupper((string) ($this->trimOrNull($row['doc_type'] ?? null) ?? 'DNI'));
                if (! in_array($docType, $this->docTypes, true)) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('people.doc_type_invalid', ['types' => implode(', ', $this->docTypes)]),
                        'value'   => $docType,
                    ];
                    continue;
                }

                $clave = $docType . '|' . mb_strtolower($numDoc);
                if (isset($seenInFile[$clave])) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_duplicate_in_file', ['row' => $seenInFile[$clave]]),
                        'value'   => $numDoc,
                    ];
                    continue;
                }
                $seenInFile[$clave] = $absoluteRow;

                $name     = $this->trimOrNull($row['name'] ?? null);
                $lastname = $this->trimOrNull($row['lastname'] ?? null);

                $existing = Person::query()
                    ->where('doc_type', $docType)
                    ->where('num_doc', $numDoc)
                    ->first();

                if ($existing) {
                    // Registro BLOQUEADO (Lockable): el import no lo pisa.
                    if ($existing->is_locked) {
                        $this->skipped++;
                        $this->preview[] = [
                            'row' => $absoluteRow, 'name' => $existing->full_name,
                            'is_active' => (bool) $existing->is_active, 'action' => 'skipped', 'reason' => 'locked',
                        ];
                        continue;
                    }
                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = [
                            'row' => $absoluteRow, 'name' => $existing->full_name,
                            'is_active' => (bool) $existing->is_active, 'action' => 'skipped',
                        ];
                        continue;
                    }

                    // Solo se tocan los campos que cambian: evita audit logs vacios.
                    $patch = [];
                    if ($name !== null && $existing->name !== $name)         $patch['name'] = $name;
                    if ($lastname !== null && $existing->lastname !== $lastname) $patch['lastname'] = $lastname;
                    if (!empty($patch)) {
                        $existing->fill($patch)->save();
                    }

                    $this->updated++;
                    $this->preview[] = [
                        'row' => $absoluteRow, 'name' => $existing->full_name,
                        'is_active' => (bool) $existing->is_active, 'action' => 'updated',
                    ];
                    continue;
                }

                // ── Alta ────────────────────────────────────────────────────
                if ($name === null || $lastname === null) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('people.name_and_lastname_required'),
                        'value'   => $numDoc,
                    ];
                    continue;
                }
                if (mb_strlen($name) > 255 || mb_strlen($lastname) > 255) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_name_too_long'),
                        'value'   => mb_substr($name . ' ' . $lastname, 0, 60) . '…',
                    ];
                    continue;
                }

                if ($this->maxRecords > 0 && $this->maxRecords !== PHP_INT_MAX) {
                    if (($this->currentCount + $newRecordsCount) >= $this->maxRecords) {
                        $this->errors[] = [
                            'row'     => $absoluteRow,
                            'message' => __('plans.limit_records_reached', ['max' => $this->maxRecords]),
                            'value'   => $numDoc,
                        ];
                        continue;
                    }
                }

                Person::create([
                    'name'       => $name,
                    'lastname'   => $lastname,
                    'doc_type'   => $docType,
                    'num_doc'    => $numDoc,
                    'country_id' => Auth::user()?->country_id,
                    'is_active'  => true,
                    'created_by' => Auth::id(),
                    // tenant_id lo autorellena BelongsToTenant (tenant del actor);
                    // el slug lo auto-genera el modelo en `creating`.
                ]);

                $newRecordsCount++;
                $this->created++;
                $this->preview[] = [
                    'row' => $absoluteRow, 'name' => trim("$name $lastname"),
                    'is_active' => true, 'action' => 'created',
                ];
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
}
