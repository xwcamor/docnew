<?php

namespace App\Imports\BusinessManagement\People;

use App\Http\Controllers\BusinessManagement\PersonController;
use App\Models\Company;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Person;
use App\Models\PersonRole;
use App\Models\Position;
use App\Services\BusinessManagement\PersonService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Importa personas desde .xlsx/.csv.
 *
 * Columnas:
 *   - doc_type    (opcional — DNI si no viene)
 *   - num_doc     (obligatorio: identifica a la persona)
 *   - name        (obligatorio en el alta)
 *   - lastname    (obligatorio en el alta)
 *   - company     (obligatorio en el alta — RUC o nombre de la empresa)
 *   - position    (obligatorio en el alta — el cargo, tal como esta en el catalogo)
 *   - roles       (opcional — trabajador / supervisor / supervisor hse; por defecto trabajador)
 *   - nationality (opcional — nombre o ISO de un pais)
 *   - birthdate   (opcional — AAAA-MM-DD o una fecha de Excel)
 *
 * La empresa y el cargo no estaban, y la persona que entraba por aqui nacia
 * inservible: `person_company_links` vacio significa que no se la puede meter
 * en ningun plan, y ese vinculo es lo unico que dice para quien trabaja. En la
 * v1 las dos columnas son NOT NULL en `workers` y el formulario tambien las
 * exige, asi que aqui son obligatorias en el alta — una regla que solo vale en
 * una de las dos puertas no es una regla. En la actualizacion son opcionales:
 * la columna en blanco deja el vinculo como estaba.
 *
 * Tampoco creaba roles, y sin rol la persona no entra en un plan
 * (WorkPlanSetupService lo comprueba). Ahora nace trabajadora, igual que por
 * pantalla.
 *
 * La identidad es el DOCUMENTO, no el nombre: en el sistema v1 el match por
 * nombre fusionaba homónimos y partía en dos a la misma persona escrita de dos
 * formas. Aquí una fila actualiza a alguien solo si coincide el documento
 * DENTRO DEL MISMO PAIS, que es como esta declarada la unicidad en la base
 * (tenant, pais, tipo, numero).
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
 *   2. App: lookup por pais + doc_type + num_doc contra toda la tabla
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

    /** Pais de las altas y del lookup: el del actor. */
    protected ?int $countryId;

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

    /**
     * El tipo de documento entero, por sigla, para validar el numero.
     *
     * El formulario ya no deja teclear un numero fuera de rango, pero el Excel
     * si entraba: por esa puerta se colaba el celular en el campo del documento
     * que hubo que limpiar a mano en la base maestra. Una regla que solo vale
     * en una de las dos puertas no es una regla.
     *
     * @var array<string, DocumentType>
     */
    protected array $tiposPorSigla = [];

    /**
     * Catalogos por los que busca el fichero, resueltos una vez.
     *
     * Son pocos cientos de filas cada uno y la alternativa es una consulta por
     * fila del Excel.
     *
     * @var array<string, int>
     */
    protected array $empresas = [];
    protected array $cargos = [];
    protected array $paises = [];

    protected PersonService $personas;

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
    ) {
        $user = Auth::user();

        $this->personas = app(PersonService::class);
        $this->countryId = $user?->country_id;

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
            ->where('country_id', $this->countryId)
            ->where('is_active', true)
            ->get();

        $this->docTypes = $delPais->pluck('code')->all() ?: PersonController::docTypesPorDefecto();
        $this->tiposPorSigla = $delPais->keyBy('code')->all();

        $this->cargarCatalogos();
    }

    /**
     * Los tres catalogos que el fichero nombra en texto, indexados por como se
     * escriben una vez normalizados (sin tildes, sin mayusculas, sin dobles
     * espacios): asi «S.A.C. HITACHI» y «hitachi» no son dos empresas.
     */
    protected function cargarCatalogos(): void
    {
        foreach (Company::query()->select('id', 'name', 'complete_name', 'num_doc')->get() as $empresa) {
            foreach ([$empresa->num_doc, $empresa->name, $empresa->complete_name] as $comoSeLlama) {
                $clave = $this->normalizar((string) $comoSeLlama);
                if ($clave !== '' && ! isset($this->empresas[$clave])) {
                    $this->empresas[$clave] = $empresa->id;
                }
            }
        }

        foreach (Position::query()->select('id', 'code')->get() as $cargo) {
            $clave = $this->normalizar((string) $cargo->code);
            if ($clave !== '' && ! isset($this->cargos[$clave])) {
                $this->cargos[$clave] = $cargo->id;
            }
        }

        foreach (Country::query()->select('id', 'name', 'iso_code')->get() as $pais) {
            foreach ([$pais->name, $pais->iso_code] as $comoSeLlama) {
                $clave = $this->normalizar((string) $comoSeLlama);
                if ($clave !== '' && ! isset($this->paises[$clave])) {
                    $this->paises[$clave] = $pais->id;
                }
            }
        }
    }

    public function collection(Collection $rows): void
    {
        // `people.country_id` es NOT NULL y el fichero no lo trae: sale del
        // usuario que importa. Sin el, cada alta reventaria con un error de la
        // base a mitad del fichero y se perderia todo lo bueno de antes.
        if ($this->countryId === null) {
            foreach ($rows as $i => $row) {
                $this->errors[] = [
                    'row'     => $i + 2,
                    'message' => __('people.country_required'),
                    'value'   => (string) ($row['num_doc'] ?? '—'),
                ];
            }

            return;
        }

        DB::beginTransaction();

        try {
            $seenInFile = [];
            $newRecordsCount = 0;

            foreach ($rows as $i => $row) {
                $absoluteRow = $i + 2; // +2 = header (1) + indexacion desde 0.

                $crudo  = $row['num_doc'] ?? null;
                $numDoc = $this->trimOrNull($crudo);
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

                $comoVino = $numDoc;
                $numDoc   = $this->devolverElCeroQueExcelSeComio($docType, $numDoc, $crudo);
                // Cambiar un documento por tu cuenta y no decirlo no vale: el
                // aviso sale en la fila del preview, antes de confirmar.
                $aviso = $numDoc === $comoVino
                    ? null
                    : __('people.import_padded_zero', ['value' => $numDoc]);

                if ($problema = $this->noEncajaConSuTipo($docType, $numDoc)) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => $problema,
                        'value'   => $numDoc,
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

                // ── Lo que el fichero nombra en texto ───────────────────────
                $problemas = [];

                $companyId  = $this->buscarEnCatalogo($row['company'] ?? null, $this->empresas, 'people.company', $problemas);
                $positionId = $this->buscarEnCatalogo($row['position'] ?? null, $this->cargos, 'people.position', $problemas);
                $nacionalidadId = $this->buscarEnCatalogo($row['nationality'] ?? null, $this->paises, 'people.nationality', $problemas);
                $roles      = $this->leerRoles($row['roles'] ?? null, $problemas);
                $nacimiento = $this->leerFecha($row['birthdate'] ?? null, $problemas);

                if ($problemas !== []) {
                    foreach ($problemas as $problema) {
                        $this->errors[] = ['row' => $absoluteRow, 'message' => $problema, 'value' => $numDoc];
                    }
                    continue;
                }

                // El documento identifica DENTRO de un pais: el indice unico es
                // (tenant, pais, tipo, numero). Sin filtrar por pais, un
                // pasaporte venezolano con el mismo numero que uno peruano
                // actualizaba al peruano en vez de crear a nadie.
                $existing = Person::query()
                    ->where('country_id', $this->countryId)
                    ->where('doc_type', $docType)
                    ->where('num_doc', $numDoc)
                    ->first();

                if ($existing) {
                    // Registro GLOBAL del catálogo (tenant_id null) y quien
                    // importa no es super: el guard de BelongsToTenantOrGlobal
                    // lanza al guardar. Si se le deja llegar, la excepción sale
                    // del save(), la transacción entera hace rollback y el
                    // usuario pierde TODAS las filas buenas del fichero con un
                    // 422 que no dice cuál falló. Se aparta y el resto sigue,
                    // igual que se hace con las bloqueadas justo debajo.
                    if ($existing->tenant_id === null && ! $this->actorEsSuper()) {
                        $this->skipped++;
                        $this->preview[] = [
                            'row'       => $absoluteRow,
                            'name'      => $existing->full_name,
                            'is_active' => (bool) $existing->is_active,
                            'action'    => 'skipped',
                            'reason'    => 'global',
                        ];
                        continue;
                    }

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
                    if ($nacionalidadId !== null && $existing->nationality_id !== $nacionalidadId) {
                        $patch['nationality_id'] = $nacionalidadId;
                    }
                    if ($nacimiento !== null && $existing->birthdate?->toDateString() !== $nacimiento) {
                        $patch['birthdate'] = $nacimiento;
                    }
                    // La empresa en blanco deja el vinculo como estaba: el
                    // fichero de correccion de nombres no tiene por que traer
                    // la plantilla entera otra vez.
                    if ($companyId !== null) {
                        $patch['company_id']  = $companyId;
                        $patch['position_id'] = $positionId;
                    }
                    if ($roles !== null) {
                        $patch['roles'] = $roles;
                    }

                    if ($patch !== []) {
                        $this->personas->update($existing, $patch);
                    }

                    $this->updated++;
                    $this->preview[] = [
                        'row' => $absoluteRow, 'name' => $existing->fresh()->full_name,
                        'is_active' => (bool) $existing->is_active, 'action' => 'updated',
                        'notice' => $aviso,
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

                // Sin empresa la persona no puede entrar en ningun plan, y sin
                // cargo no se sabe que hace en obra: las dos son obligatorias
                // por pantalla y aqui tambien.
                if ($companyId === null || $positionId === null) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('people.import_company_position_required'),
                        'value'   => $numDoc,
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

                // Por el servicio, no por Person::create: es el que escribe el
                // vinculo con la empresa y el rol en obra. Antes el import
                // creaba la fila a pelo y dejaba las dos cosas vacias.
                $this->personas->create([
                    'name'           => $name,
                    'lastname'       => $lastname,
                    'doc_type'       => $docType,
                    'num_doc'        => $numDoc,
                    'country_id'     => $this->countryId,
                    'nationality_id' => $nacionalidadId,
                    'birthdate'      => $nacimiento,
                    'is_active'      => true,
                    'company_id'     => $companyId,
                    'position_id'    => $positionId,
                    'roles'          => $roles ?? [PersonRole::WORKER],
                    // tenant_id lo autorellena BelongsToTenant (tenant del actor);
                    // el slug lo auto-genera el modelo en `creating`.
                ]);

                $newRecordsCount++;
                $this->created++;
                $this->preview[] = [
                    'row' => $absoluteRow, 'name' => trim("$name $lastname"),
                    'is_active' => true, 'action' => 'created',
                    'notice' => $aviso,
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

    /**
     * Un DNI escrito en una celda de tipo numero pierde el cero de delante:
     * Excel guarda 6842865 y ese documento no existe. Es un caso real —
     * `06842865` es de una de las personas de la base maestra.
     *
     * Solo se rellena cuando la celda llego como NUMERO (que es justo cuando
     * Excel se lo comio) y el tipo lleva solo cifras y una longitud fija. Un
     * «6842865» escrito como texto se queda como esta y la fila se rechaza:
     * ahi el que se equivoco fue quien lo tecleo, y adivinar seria inventarse
     * un documento.
     */
    protected function devolverElCeroQueExcelSeComio(string $docType, string $numDoc, mixed $crudo): string
    {
        if (! is_int($crudo) && ! is_float($crudo)) {
            return $numDoc;
        }

        $tipo = $this->tiposPorSigla[$docType] ?? null;

        if ($tipo === null
            || $tipo->allowed_chars !== DocumentType::SOLO_CIFRAS
            || ! $tipo->min_length
            || ! preg_match('/^[0-9]+$/', $numDoc)
            || mb_strlen($numDoc) >= $tipo->min_length) {
            return $numDoc;
        }

        return str_pad($numDoc, $tipo->min_length, '0', STR_PAD_LEFT);
    }

    /**
     * El mensaje si el numero no encaja con su tipo, o null si va bien.
     *
     * Un tipo sin longitudes ni caracteres declarados no valida nada: el
     * catalogo los deja en blanco a proposito para los paises donde no se sabe.
     */
    protected function noEncajaConSuTipo(string $docType, string $numDoc): ?string
    {
        $tipo = $this->tiposPorSigla[$docType] ?? null;

        if ($tipo === null) {
            return null;
        }

        $largo = mb_strlen($numDoc);

        if ($tipo->min_length && $largo < $tipo->min_length) {
            return __('people.num_doc_too_short', ['type' => $docType, 'min' => $tipo->min_length]);
        }

        if ($tipo->max_length && $largo > $tipo->max_length) {
            return __('people.num_doc_too_long', ['type' => $docType, 'max' => $tipo->max_length]);
        }

        if (! $tipo->admiteElNumero($numDoc)) {
            return $tipo->porQueNoAdmite();
        }

        return null;
    }

    /**
     * Busca un valor de texto en uno de los catalogos ya cargados.
     *
     * Celda vacia → null sin protestar (la columna es opcional o el alta se
     * quejara despues, con su propio mensaje). Celda con algo que no esta en el
     * catalogo → error con el nombre del campo, para que se vea cual de las
     * columnas hay que arreglar.
     *
     * @param  array<string, int>   $catalogo
     * @param  array<int, string>   $problemas  se le añaden los errores
     */
    protected function buscarEnCatalogo(mixed $valor, array $catalogo, string $campo, array &$problemas): ?int
    {
        $texto = $this->trimOrNull($valor);

        if ($texto === null) {
            return null;
        }

        $id = $catalogo[$this->normalizar($texto)] ?? null;

        if ($id === null) {
            $problemas[] = __('imports.err_fk_not_found', [
                'value' => $texto,
                'field' => mb_strtolower(__($campo)),
            ]);
        }

        return $id;
    }

    /**
     * Los roles en obra que trae la fila, o null si la columna venia vacia.
     *
     * Se admiten separados por coma, punto y coma o barra, y escritos como los
     * ve el usuario en pantalla («Supervisor HSE») o como los guarda la base
     * («hse_supervisor»): quien rellena el Excel copia lo que ve.
     *
     * @param  array<int, string>  $problemas
     * @return array<int, string>|null
     */
    protected function leerRoles(mixed $valor, array &$problemas): ?array
    {
        $texto = $this->trimOrNull($valor);

        if ($texto === null) {
            return null;
        }

        $comoSeEscriben = [
            'worker' => PersonRole::WORKER,
            'trabajador' => PersonRole::WORKER,
            'trabajadora' => PersonRole::WORKER,
            'obrero' => PersonRole::WORKER,
            'supervisor' => PersonRole::SUPERVISOR,
            'supervisora' => PersonRole::SUPERVISOR,
            'hse_supervisor' => PersonRole::HSE_SUPERVISOR,
            'supervisor hse' => PersonRole::HSE_SUPERVISOR,
            'hse supervisor' => PersonRole::HSE_SUPERVISOR,
            'supervisor de hse' => PersonRole::HSE_SUPERVISOR,
            'supervisor sso' => PersonRole::HSE_SUPERVISOR,
        ];

        $roles = [];

        foreach (preg_split('/[,;\/]+/', $texto) as $trozo) {
            $clave = $this->normalizar($trozo);
            if ($clave === '') {
                continue;
            }
            $rol = $comoSeEscriben[$clave] ?? null;
            if ($rol === null) {
                $problemas[] = __('people.import_role_unknown', [
                    'value' => trim($trozo),
                    'roles' => implode(', ', [__('people.role_worker'), __('people.role_supervisor'), __('people.role_hse_supervisor')]),
                ]);
                continue;
            }
            $roles[$rol] = $rol;
        }

        return $roles === [] ? null : array_values($roles);
    }

    /**
     * La fecha de nacimiento: un AAAA-MM-DD, un DD/MM/AAAA o el numero de serie
     * que guarda Excel cuando la celda esta formateada como fecha.
     *
     * @param  array<int, string>  $problemas
     */
    protected function leerFecha(mixed $valor, array &$problemas): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            if (is_int($valor) || is_float($valor)) {
                $fecha = \Carbon\Carbon::instance(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $valor)
                );
            } else {
                $texto = trim((string) $valor);
                $fecha = preg_match('#^\d{1,2}[/-]\d{1,2}[/-]\d{4}$#', $texto)
                    ? \Carbon\Carbon::createFromFormat('!d/m/Y', str_replace('-', '/', $texto))
                    : \Carbon\Carbon::parse($texto);
            }
        } catch (\Throwable) {
            $problemas[] = __('imports.err_bad_date', ['value' => (string) $valor]);

            return null;
        }

        if ($fecha->isFuture()) {
            $problemas[] = __('imports.err_bad_date', ['value' => (string) $valor]);

            return null;
        }

        return $fecha->toDateString();
    }

    /** Sin tildes, sin mayusculas, sin puntuacion suelta y sin dobles espacios. */
    protected function normalizar(string $texto): string
    {
        $sinTildes = @iconv('UTF-8', 'ASCII//TRANSLIT', $texto);
        $texto = $sinTildes === false ? $texto : $sinTildes;
        $texto = preg_replace('/[^A-Za-z0-9]+/', ' ', $texto);

        return trim(mb_strtolower(preg_replace('/\s+/', ' ', (string) $texto)));
    }

    /** ¿Quien está importando es super? (los globales solo los toca él). */
    protected function actorEsSuper(): bool
    {
        $user = auth()->user();

        return $user !== null && method_exists($user, 'hasRole') && $user->hasRole('super');
    }
}
