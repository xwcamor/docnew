<?php

namespace App\Console\Commands;

use App\Models\ApprovalRule;
use App\Models\Company;
use App\Models\Country;
use App\Models\FormTemplate;
use App\Models\Person;
use App\Models\PersonCompanyLink;
use App\Models\PersonRole;
use App\Models\PersonSignature;
use App\Models\Role;
use App\Models\SignatureEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkArea;
use App\Models\WorkPlanApproval;
use App\Models\WorkPlanPerson;
use App\Models\WorkLocation;
use App\Models\Workstation;
use App\Models\WorkType;
use App\Services\FieldWork\FormFindingsService;
use App\Services\Migration\LegacyFormMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Trae los datos del sistema anterior, paso a paso.
 *
 * Cada paso es idempotente: se puede volver a correr sin duplicar nada, porque
 * cada fila migrada guarda su `legacy_id`. Nunca escribe en la base vieja.
 *
 *   php artisan docufiz:migrate-data empresas
 *   php artisan docufiz:migrate-data usuarios
 *   php artisan docufiz:migrate-data personas
 *   php artisan docufiz:migrate-data planes
 *   php artisan docufiz:migrate-data documentos
 *   php artisan docufiz:migrate-data evidencias
 *   php artisan docufiz:migrate-data archivos --desde=/ruta/con/las/imagenes
 *   php artisan docufiz:migrate-data todo
 *
 * La carpeta de `--desde` se espera ORDENADA POR DOCUMENTO, que es como se
 * saca del servidor viejo para poder revisarla a ojo:
 *
 *   <desde>/photo/<num_doc>/<lo que sea>.jpg      la foto de la persona
 *   <desde>/signature/<num_doc>/<lo que sea>.png  su firma
 *   <desde>/<archivo suelto>                      las evidencias, planas
 *
 * También se aceptan `fotos/` y `firmas/` como nombres de las dos primeras.
 * El nombre del fichero de dentro da igual: la carpeta ES la identidad.
 *
 * `todo` es de verdad todo: crea antes las plantillas AST, PTF, EPP e IHM
 * llamando a `docufiz:migrate-formats`, porque los formatos llenados cuelgan de
 * ellas y no tiene sentido obligar a acordarse del orden. Con `--rehacer-formatos`
 * las reconstruye aunque ya existan.
 *
 * Los pasos grandes (planes, documentos, evidencias) escriben con el
 * constructor de consultas y no con Eloquent: 3 722 planes, 14 435 formatos y
 * 17 000 firmas por el modelo generarian otras tantas filas de auditoria de un
 * hecho que ya esta documentado aqui, y tardarian de mas.
 */
#[AsCommand(
    name: 'docufiz:migrate-data',
    description: 'Migra los datos del sistema anterior (empresas, usuarios, personas, planes, formatos y evidencias).'
)]
class MigrateLegacyDataCommand extends Command
{
    protected $signature = 'docufiz:migrate-data
        {paso=todo : empresas|usuarios|personas|planes|documentos|evidencias|archivos|todo}
        {--lote=500 : Cuantas filas de la base vieja se leen de una vez}
        {--desde= : Carpeta con las imagenes de la v1 (photo/<doc>/ y signature/<doc>/), para el paso archivos}
        {--rehacer-formatos : Reconstruye las plantillas AST/PTF/EPP/IHM aunque ya existan}';

    /** En la v1 los planes son todos de Peru (country_id 1); el resto de paises solo tiene catalogos. */
    protected const PAIS_LEGACY = 1;

    /** Rol de menos privilegios: el origen no dice quien es que, y no se inventan permisos. */
    protected const ROL_MINIMO = 'Usuario de campo';

    /** Los cuatro formatos historicos y de que tabla de la v1 sale cada uno. */
    protected const FORMATOS = [
        'AST' => 'f1_documents',
        'PTF' => 'f2_documents',
        'EPP' => 'f3_documents',
        'IHM' => 'f4_documents',
    ];

    protected int $tenantId;
    protected int $countryId;
    protected int $lote;

    public function __construct(protected LegacyFormMapper $mapa)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenant = Tenant::first();
        $pais = Country::where('iso_code', 'PE')->first() ?? Country::first();

        if (! $tenant || ! $pais) {
            $this->error('Faltan tenant o pais: corre php artisan migrate --seed primero.');

            return self::FAILURE;
        }

        $this->tenantId = $tenant->id;
        $this->countryId = $pais->id;
        $this->lote = max(50, (int) $this->option('lote'));

        $paso = $this->argument('paso');

        // Copiar imagenes no lee ni una fila de la base vieja: lo unico que
        // necesita es la carpeta. Exigir el MySQL antiguo para eso obligaba a
        // levantarlo entero solo para volver a pasar unas fotos.
        if ($paso !== 'archivos') {
            try {
                DB::connection('legacy')->getPdo();
            } catch (\Throwable $e) {
                $this->error('No se pudo conectar a la base anterior. Revisa LEGACY_DB_* en .env');

                return self::FAILURE;
            }
        }

        // Las plantillas primero: los formatos llenados cuelgan de ellas. Es lo
        // que antes habia que recordar correr aparte, y olvidarlo se pagaba a
        // mitad de los 14 435 documentos.
        if (in_array($paso, ['documentos', 'todo'], true) && ! $this->prepararFormatos()) {
            return self::FAILURE;
        }

        if (in_array($paso, ['empresas', 'todo'], true)) {
            $this->migrarEmpresas();
        }

        // Los usuarios van antes que los planes: cada plan recuerda quien lo
        // registro y esa llave se resuelve por el legacy_id del usuario.
        if (in_array($paso, ['usuarios', 'todo'], true)) {
            $this->migrarUsuarios();
        }

        if (in_array($paso, ['personas', 'todo'], true)) {
            $this->migrarPersonas();
        }

        if (in_array($paso, ['planes', 'todo'], true)) {
            $this->migrarPlanes();
        }

        if (in_array($paso, ['documentos', 'todo'], true)) {
            $this->migrarDocumentos();
        }

        if (in_array($paso, ['evidencias', 'todo'], true)) {
            $this->migrarEvidencias();
        }

        // Las imagenes, si hay de donde sacarlas. No estan en el repositorio,
        // asi que el paso no puede fallar por su ausencia dentro de `todo`.
        $imagenes = $this->carpetaDeImagenes();

        if ($paso === 'archivos' || ($paso === 'todo' && $imagenes !== null)) {
            if ($imagenes === null) {
                $this->error('El paso archivos necesita una carpeta con photo/<doc>/ y signature/<doc>/.');
                $this->line('  Pasala con --desde=, ponla en LEGACY_FILES_PATH del .env, o dejala en '
                    . implode(' o ', $this->dondeSeBuscaSola()) . '.');

                return self::FAILURE;
            }

            $this->copiarArchivos($imagenes);
        }

        return self::SUCCESS;
    }

    protected function migrarEmpresas(): void
    {
        $this->info('── Empresas ──');
        $viejas = DB::connection('legacy')->table('companies')->orderBy('id')->get();
        $creadas = $actualizadas = 0;

        foreach ($viejas as $v) {
            // Se busca por legacy_id y, si no, por RUC: una empresa que ya
            // existiera (de una carga anterior o de los datos de ejemplo) se
            // adopta en vez de duplicarse.
            // El RUC de la v1 viene tal cual se tecleo, con espacios y guiones.
            // El formulario y el buscador de la v2 lo normalizan, asi que sin
            // esto un «20-5123 45678» migrado no lo encontraba nadie — y encima
            // esquivaba el indice unico contra el mismo RUC ya normalizado.
            $ruc = preg_replace('/[\s-]/', '', (string) $v->num_doc);

            $existente = Company::withTrashed()->where('legacy_id', $v->id)->first()
                ?? Company::withTrashed()
                    ->where('country_id', $this->countryId)
                    ->where('num_doc', $ruc)
                    ->first();

            $datos = [
                'country_id'    => $this->countryId,
                // Con que documento se identifica una empresa de este pais. La
                // columna tenia `default 'RUC'` y el migrador se apoyaba en el
                // sin saberlo; al quitarlo —porque una contratista chilena se
                // guardaba en silencio con un documento peruano— las empresas
                // migradas quedaron con el numero a secas, y el listado enseñaba
                // «20522756441» sin decir que era un RUC.
                'doc_type'      => \App\Models\DocumentType::deLaEmpresaDe($this->countryId),
                'num_doc'       => $ruc,
                'name'          => $v->name,
                'complete_name' => $v->complete_name,
                'is_active'     => (bool) $v->is_active,
                'tenant_id'     => $this->tenantId,
                'legacy_id'     => $v->id,
                'created_by'    => 1,
                'deleted_description' => $v->is_deleted ? ($v->deleted_description ?: null) : null,
            ];

            $empresa = $existente ?? new Company(['slug' => Str::random(22)]);
            $empresa->fill($datos);

            // `created_at`, `updated_at` y `deleted_at` NO estan en $fillable, asi
            // que por `fill()` se caerian sin avisar. Se ponen a mano y con los
            // timestamps automaticos apagados, o el `save()` los pisa con la
            // fecha de la carga.
            $empresa->timestamps = false;
            $empresa->created_at = $v->created_at ?? now();
            $empresa->updated_at = $v->updated_at ?? now();
            // Lo borrado en la v1 llega borrado. Antes no se mapeaba y el `get()`
            // de arriba tampoco filtra, asi que las empresas que alguien habia
            // dado de baja RESUCITABAN vivas en el sistema nuevo.
            $empresa->deleted_at = $v->is_deleted ? ($v->updated_at ?? now()) : null;

            $existente ? $actualizadas++ : $creadas++;
            $empresa->save();
        }

        // Cual de todas es la del propio workspace. Se marca aqui, en cuanto
        // existen, y no en Ajustes a mano: `setup:project --datos` rehace la
        // base, asi que ese ajuste se perdia en cada carga — y sin el, el
        // selector de aprobadores del plan ofrece el padron entero en vez de
        // solo la gente de la empresa que contrata.
        $propia = \App\Models\Tenant::find($this->tenantId)?->marcarSuEmpresaSiLaInstalacionLaDice();

        if ($propia) {
            $this->line("  Mi empresa: {$propia->name} ({$propia->num_doc}).");
        } elseif (trim((string) config('companies.own_doc')) !== '') {
            $this->warn('  WORKSPACE_COMPANY_DOC apunta a un documento que no está entre las empresas migradas.');
        }

        $this->linea('empresas', $viejas->count(), Company::whereNotNull('legacy_id')->count(),
            "{$creadas} nuevas, {$actualizadas} actualizadas");
    }

    /**
     * Personas: la parte delicada.
     *
     * En el sistema anterior la misma persona estaba en tres tablas (workers,
     * supervisors, hse_supervisors) y ademas repetida por cada empresa en la que
     * trabajaba. Aqui se agrupan por documento en una sola identidad y lo que se
     * multiplica son los vinculos.
     */
    protected function migrarPersonas(): void
    {
        $this->info('── Personas ──');
        $viejo = DB::connection('legacy');

        // Los cargos primero: cada trabajador trae el suyo y sin el catalogo no
        // hay a que apuntar. En la v1 `workers.position_id` es NOT NULL, o sea
        // que los 372 tienen cargo.
        $cargos = $this->catalogoCargos($viejo);

        // Y las nacionalidades. Ya no se guardan —`people.nationality_id` se
        // borro por redundante: el pais del documento y el tipo dicen lo mismo—
        // pero aqui siguen haciendo falta, porque son de donde se DEDUCE ese
        // tipo: la v1 no lo tiene. Por lo mismo: `nationality_id` tambien es NOT
        // NULL en la v1 y tampoco se traia.
        $nacionalidades = $this->catalogoNacionalidades($viejo);

        // De que tabla de la v1 sale cada persona y que rol de firma le toca.
        //
        // Los de `workers` no llevan ninguno: los roles dicen QUE APRUEBA la
        // persona, y un trabajador de una contratista no aprueba nada. Que este
        // en la cuadrilla de un plan es lo que dice que trabajo ese dia, y eso
        // ya lo cuenta `work_plan_people`.
        $fuentes = [
            'workers'         => null,
            'supervisors'     => PersonRole::SUPERVISOR,
            'hse_supervisors' => PersonRole::HSE_SUPERVISOR,
        ];

        $porDocumento = [];   // num_doc => datos consolidados
        $filasOrigen = 0;

        foreach ($fuentes as $tabla => $rol) {
            foreach ($viejo->table($tabla)->where('is_deleted', 0)->orderBy('id')->get() as $f) {
                $filasOrigen++;
                $doc = trim((string) $f->num_doc);

                if ($doc === '') {
                    continue;
                }

                $porDocumento[$doc] ??= [
                    'name' => $f->name, 'lastname' => $f->lastname,
                    'roles' => [], 'empresas' => [], 'firmas' => [], 'fotos' => [], 'legacy' => [],
                    'nombres_vistos' => [], 'nacionalidad' => null,
                ];

                $p = &$porDocumento[$doc];

                // `workers` no aporta rol: ver el comentario de `$fuentes`.
                if ($rol !== null) {
                    $p['roles'][$rol] = true;
                }
                $p['legacy'][] = "{$tabla}#{$f->id}";
                $p['nombres_vistos'][mb_strtolower(trim("{$f->name} {$f->lastname}"))] = true;

                // El nombre mas largo suele ser el mas completo (nombres compuestos).
                if (mb_strlen("{$f->name} {$f->lastname}") > mb_strlen("{$p['name']} {$p['lastname']}")) {
                    $p['name'] = $f->name;
                    $p['lastname'] = $f->lastname;
                }

                if ($tabla === 'workers' && $f->company_id) {
                    $p['empresas'][$f->company_id] = $f->position_id ?? null;
                }

                // La misma persona puede salir en las tres tablas; la
                // nacionalidad es de la persona, asi que con verla una vez basta.
                $p['nacionalidad'] ??= $f->nationality_id ?? null;

                if (! empty($f->signature)) {
                    $p['firmas'][$f->signature] = true;
                }

                // La foto de referencia: la BUENA, la que el administrador
                // subia a mano cuando la que se capturaba en obra salia
                // irreconocible. Se habia quedado sin migrar y era justo la que
                // sirve para saber a quien se esta mirando.
                if (! empty($f->photo)) {
                    $p['fotos'][$f->photo] = true;
                }
                unset($p);
            }
        }

        $conflictos = [];
        $creadas = $vinculos = $firmas = $fotos = 0;

        foreach ($porDocumento as $doc => $d) {
            if (count($d['nombres_vistos']) > 1) {
                $conflictos[$doc] = array_keys($d['nombres_vistos']);
            }

            // Por documento y NO por (tipo, documento): en la v1 el tipo no
            // existe —solo hay `num_doc`— asi que la identidad es el numero.
            // Filtrar por 'DNI' dejaba fuera al extranjero en cuanto su tipo
            // pasa a ser 'CE', y su plan se quedaba sin el.
            //
            // Y por tenant, NO por pais: desde que la nacionalidad manda el
            // pais de la persona, el venezolano vive con `country_id` de
            // Venezuela — buscarlo «dentro de Peru» no lo encontraria y cada
            // corrida lo duplicaria.
            $persona = Person::withTrashed()->where('tenant_id', $this->tenantId)
                ->where('num_doc', $doc)->first();

            $nacionalidadId = $d['nacionalidad'] ? ($nacionalidades[$d['nacionalidad']] ?? null) : null;

            if (! $persona) {
                // Su pais es el de su nacionalidad, no el del workspace. Lo
                // dijo el dueño del producto: «si fue diferente a Peru tu le
                // pusiste pais Peru y CE, pero debio haber sido su pais y su
                // tipo de documento que corresponde». En el sistema nuevo el
                // pais de la persona ES su nacionalidad —la ficha del plan lo
                // enseña asi— y el tipo de documento sale de ese pais.
                $persona = Person::create([
                    'slug' => Str::random(22), 'country_id' => $nacionalidadId ?? $this->countryId,
                    'doc_type' => $this->tipoDeDocumento($nacionalidadId), 'num_doc' => $doc,
                    'name' => $d['name'], 'lastname' => $d['lastname'],
                    'tenant_id' => $this->tenantId, 'created_by' => 1,
                    'legacy_table' => implode(', ', $d['legacy']),
                ]);
                $creadas++;
            } elseif ($nacionalidadId !== null) {
                // Ya existia de una pasada anterior: de cuando todos entraban
                // como DNI, o de cuando el extranjero entraba como Peru+CE.
                // Se le cura el pais y el tipo; sin nacionalidad conocida no
                // se toca nada, que lo puesto a mano vale mas que una
                // deduccion.
                $tipo = $this->tipoDeDocumento($nacionalidadId);
                $cura = [];

                if ((int) $persona->country_id !== $nacionalidadId) {
                    $cura['country_id'] = $nacionalidadId;
                }

                if ($persona->doc_type !== $tipo) {
                    $cura['doc_type'] = $tipo;
                }

                if ($cura !== []) {
                    $persona->update($cura);
                }
            }

            foreach (array_keys($d['roles']) as $rol) {
                PersonRole::firstOrCreate(['person_id' => $persona->id, 'role' => $rol], ['is_active' => true]);
            }

            foreach ($d['empresas'] as $empresaLegacy => $cargoLegacy) {
                $empresa = Company::where('legacy_id', $empresaLegacy)->first();

                if (! $empresa) {
                    continue;
                }

                // El cargo va en el vinculo, no en la persona: la misma persona
                // puede ser tecnico en una contratista y supervisor en otra, y
                // en la v1 eso eran dos filas de `workers` distintas.
                //
                // Se capturaba arriba y se tiraba aqui: el `firstOrCreate` no lo
                // escribia y los 372 trabajadores llegaban sin cargo. La ficha
                // del plan lo enseñaba debajo del nombre de cada uno.
                $cargoId = $cargoLegacy ? ($cargos[$cargoLegacy] ?? null) : null;

                $vinculo = PersonCompanyLink::firstOrCreate(
                    ['person_id' => $persona->id, 'company_id' => $empresa->id],
                    ['is_active' => true, 'position_id' => $cargoId],
                );

                // Re-correr la migracion rellena el cargo de los vinculos que
                // ya existian sin el, que es justo el caso de los 370 de ahora.
                if ($cargoId && $vinculo->position_id === null) {
                    $vinculo->update(['position_id' => $cargoId]);
                }

                $vinculos++;
            }

            foreach (array_keys($d['firmas']) as $archivo) {
                PersonSignature::firstOrCreate(
                    ['person_id' => $persona->id, 'sha256' => hash('sha256', $archivo)],
                    [
                        'file_path'  => 'legacy/firmas/' . $archivo,
                        'source'     => 'migrated',
                        'valid_from' => now(),
                    ],
                );
                $firmas++;
            }

            // La misma persona puede traer foto en las tres tablas. Se queda
            // con UNA —la primera— porque son la misma cara: mas de una fila
            // vigente no significa nada y ademas rompe `currentPhoto()`.
            foreach (array_slice(array_keys($d['fotos']), 0, 1) as $archivo) {
                \App\Models\PersonPhoto::firstOrCreate(
                    ['person_id' => $persona->id, 'sha256' => hash('sha256', $archivo)],
                    [
                        'file_path'  => 'legacy/fotos/' . $archivo,
                        'source'     => \App\Models\PersonPhoto::MIGRADA,
                        'valid_from' => now(),
                    ],
                );
                $fotos++;
            }
        }

        $this->linea('personas', $filasOrigen, Person::count(),
            sprintf('%d identidades desde %d filas de las tres tablas', $creadas, $filasOrigen));
        $this->line(sprintf('  vinculos persona-empresa: %d · firmas de referencia: %d · fotos de referencia: %d',
            $vinculos, $firmas, $fotos));

        if ($conflictos !== []) {
            $this->newLine();
            $this->warn(sprintf('%d documento(s) con nombres distintos entre tablas. Se tomo el mas largo; revisalos:', count($conflictos)));

            foreach (array_slice($conflictos, 0, 10, true) as $doc => $nombres) {
                $this->line("  {$doc}: " . implode('  |  ', $nombres));
            }
        }
    }

    /**
     * Usuarios de la aplicacion: los que entran al sistema.
     *
     * La tabla `users` de la v1 se quedo fuera del volcado a proposito porque
     * llevaba las contrasenas, asi que la identidad se reconstruye desde
     * `user_details`, que tiene una fila por usuario. Lo que no viene del origen
     * no se inventa: ni contrasena, ni correo real, ni permisos.
     */
    /**
     * Los usuarios que entran al sistema.
     *
     * El primer volcado vino con `users` vacia —el dueno la excluyo a
     * proposito— y solo quedaba `user_details`, que trae el nombre y nada mas.
     * Con la base completa si esta, y entonces se aprovecha entera: correo,
     * contrasena y perfil.
     *
     * **La contrasena se conserva.** Devise (Rails) y Laravel usan el mismo
     * bcrypt, y `password_verify` de PHP acepta indistintamente los prefijos
     * `$2a$` y `$2y$`, asi que el hash se copia tal cual y la gente entra con
     * la que ya tenia. Si Devise estaba configurado con `pepper`, no valdra:
     * por eso el comando avisa de que hay que probarlo con un usuario antes de
     * dar por bueno el corte.
     *
     * Lo que **no** se copia es `users.real_password`, la columna donde la v1
     * guardaba la contrasena en claro. Ver avisarDeLaContrasenaEnClaro().
     */
    protected function migrarUsuarios(): void
    {
        $this->info('── Usuarios de la aplicacion ──');
        $viejo = DB::connection('legacy');

        $completos = $viejo->table('users')->exists();
        $nombres = $viejo->table('user_details')->get()->keyBy('user_id');
        $perfiles = $this->perfilesAroles($viejo);

        $viejos = $completos
            ? $viejo->table('users')->orderBy('id')->get()
            : $nombres->values();

        // `is_deleted` de la v1: la fila sigue en la tabla pero esta borrada.
        // No se filtraba, y por eso entraban ACTIVOS usuarios que llevaban anos
        // dados de baja alla.
        //
        // Se traen igual, pero borrados. NO se saltan, y esto importa: los
        // planes guardan quien los creo (`plans.user_id`), y si el autor no
        // existe aqui `usuarioDelPlan()` cae al usuario de respaldo — o sea que
        // saltarselos no los quita de en medio, los cambia por otra persona en
        // 3.712 planes firmados. Borrado los esconde de todas las pantallas
        // —SoftDeletes— y deja la autoria intacta.
        //
        // Es el mismo criterio que ya usaban las empresas y los planes.
        $tieneBorrado = $completos && $this->columnaExiste($viejo, 'users', 'is_deleted');

        $rolMinimo = Role::where('guard_name', 'web')->where('name', self::ROL_MINIMO)->first();
        $localeId = Country::find($this->countryId)?->default_locale_id ?? DB::table('locales')->min('id');

        $creados = $actualizados = $conPerfil = $borrados = 0;
        $sinPerfil = [];

        foreach ($viejos as $v) {
            $legacyId = $completos ? $v->id : $v->user_id;
            $detalle = $nombres[$legacyId] ?? null;
            $nombre = $detalle ? trim("{$detalle->name} {$detalle->lastname}") : "Usuario {$legacyId}";

            $existente = User::withTrashed()->withoutGlobalScopes()->where('legacy_id', $legacyId)->first();

            $datos = [
                'name'       => $nombre,
                'email'      => $completos && filled($v->email)
                    ? $v->email
                    : sprintf('usuario%d@pendiente.local', $legacyId),
                'country_id' => $this->countryId,
                'locale_id'  => $localeId,
                'tenant_id'  => $this->tenantId,
                'is_active'  => $completos ? ! (bool) ($v->is_hidden ?? false) : true,
                'legacy_id'  => $legacyId,
            ];

            if ($existente) {
                // La contrasena no se pisa al re-correr: puede que ya la hayan cambiado aqui.
                $existente->update($datos);
                $actualizados++;
                $usuario = $existente;
            } else {
                // Nace con una contrasena aleatoria larga que no conoce nadie
                // —tampoco este comando, que no la escribe en ningun sitio—.
                $usuario = User::create($datos + ['password' => Str::password(48)]);
                $creados++;

                // Y si la v1 traia el hash, se pone encima. Va por el
                // constructor de consultas a proposito: el cast `hashed` del
                // modelo comprueba que el coste del hash coincida con el de
                // esta aplicacion, y el de Devise no tiene por que. Escribir la
                // columna directa conserva el hash exacto, que es lo unico que
                // importa para que `password_verify` siga valiendo.
                $hash = $completos ? $this->hashUtilizable($v->encrypted_password ?? null) : null;

                if ($hash !== null) {
                    DB::table('users')->where('id', $usuario->id)->update(['password' => $hash]);
                    $usuario->refresh();
                }
            }

            $rol = $completos ? ($perfiles[$v->profile_id ?? null] ?? null) : null;

            if ($rol) {
                $usuario->syncRoles([$rol]);
                $conPerfil++;
            } elseif ($rolMinimo && $usuario->roles()->count() === 0) {
                $usuario->assignRole($rolMinimo);
                $sinPerfil[] = $legacyId;
            }

            // Borrado en la v1 → borrado aqui. Se aplica tambien al re-correr,
            // que es lo que arregla las bases donde ya entraron activos.
            //
            // En un solo sentido: si la v1 dice que esta borrado se borra, pero
            // si dice que NO lo esta y aqui si, se respeta lo de aqui. Dar de
            // baja a alguien es una decision de esta aplicacion y volver a
            // migrar no puede deshacerla — mismo criterio que el candado de los
            // catalogos.
            if ($tieneBorrado && (bool) ($v->is_deleted ?? false) && ! $usuario->trashed()) {
                $usuario->forceFill([
                    'is_active'           => false,
                    // Literal y no traducido: es un dato que se guarda una vez
                    // en la fila, no un texto de interfaz. Si la v1 dio un
                    // motivo, ese manda.
                    'deleted_description' => filled($v->deleted_description ?? null)
                        ? $v->deleted_description
                        : 'Estaba dado de baja en el sistema anterior.',
                ])->saveQuietly();

                $usuario->delete();
                $borrados++;
            }
        }

        $destino = User::withTrashed()->withoutGlobalScopes()->whereNotNull('legacy_id')->count();
        $detalle = "{$creados} nuevos, {$actualizados} actualizados";

        if ($borrados > 0) {
            $detalle .= ", {$borrados} borrados en la v1";
        }

        $this->linea('usuarios', $viejos->count(), $destino, $detalle);

        // Si la v1 no tiene la columna, no se puede saber quien estaba de baja:
        // se dice, en vez de dar por hecho que entraron todos bien.
        if ($completos && ! $tieneBorrado) {
            $this->warn('  `users` no tiene `is_deleted` en la v1: entran todos como activos.');
        }

        $completos
            ? $this->resumenConBaseCompleta($conPerfil, $sinPerfil, count($viejos))
            : $this->resumenSoloConDetalles();

        $this->avisarDeLaContrasenaEnClaro($viejo);
    }

    /**
     * El hash de Devise, en la forma que Laravel reconoce.
     *
     * Devise escribe bcrypt con prefijo `$2a$` y Laravel con `$2y$`. Son el
     * mismo algoritmo y `password_verify` acepta los dos, pero
     * `password_get_info()` **no reconoce `$2a$`**: devuelve algo = null. Y de
     * eso depende `Hash::isHashed()`, que es lo que usa el cast `hashed` del
     * modelo para decidir si tiene que hashear.
     *
     * O sea: guardar el hash de Devise tal cual lo vuelve a hashear, en
     * silencio, y **ningun usuario migrado podria entrar**. No falla nada, no
     * avisa nadie: simplemente la contrasena correcta deja de valer.
     *
     * Reescribir el prefijo lo arregla, y es seguro: comprobado que
     * `password_verify` sigue valiendo, tambien con contrasenas que llevan
     * bytes no ASCII, que es el unico caso en que `$2a$` y `$2y$` se
     * diferenciaron alguna vez.
     */
    protected function hashUtilizable(?string $hash): ?string
    {
        if (blank($hash)) {
            return null;
        }

        $hash = str_starts_with($hash, '$2a$') ? '$2y$' . substr($hash, 4) : $hash;

        // Si aun asi no lo reconoce, no es bcrypt y no sirve de nada guardarlo.
        return password_get_info($hash)['algo'] !== null ? $hash : null;
    }

    /**
     * `profiles` de la v1 → roles de aqui.
     *
     * El mapeo sale de lo que significan, no de los nombres: "Company
     * Supervisor" es el supervisor de obra y "User Regular" el usuario de campo.
     */
    protected function perfilesAroles($viejo): array
    {
        if (! $this->tablaExiste($viejo, 'profiles')) {
            return [];
        }

        $equivalencias = [
            'super'              => 'super',
            'admin'              => 'admin',
            'company supervisor' => 'Supervisor de obra',
            'user regular'       => self::ROL_MINIMO,
        ];

        $mapa = [];

        foreach ($viejo->table('profiles')->get() as $p) {
            $nombre = $equivalencias[mb_strtolower(trim($p->name))] ?? null;

            if ($nombre === null) {
                continue;
            }

            $rol = Role::where('guard_name', 'web')->where('name', $nombre)->first();

            if ($rol) {
                $mapa[$p->id] = $rol;
            }
        }

        return $mapa;
    }

    protected function resumenConBaseCompleta(int $conPerfil, array $sinPerfil, int $total): void
    {
        $this->line(sprintf('  Correos y contrasenas reales migrados: %d de %d con su perfil de la v1.', $conPerfil, $total));

        if ($sinPerfil !== []) {
            $this->warn(sprintf('  %d usuario(s) sin perfil reconocible quedaron como "%s": %s',
                count($sinPerfil), self::ROL_MINIMO, implode(', ', array_slice($sinPerfil, 0, 10))));
        }

        $this->line('  Las contrasenas son el hash de la v1: la gente entra con la que ya tenia.');
        $this->warn('  PRUEBALO con un usuario antes del corte. Si Devise usaba "pepper", los hashes no valdran');
        $this->line('  y habra que forzar "olvide mi contrasena" para todos.');
    }

    protected function resumenSoloConDetalles(): void
    {
        $this->warn('  La tabla `users` de la v1 vino VACIA: solo hay nombres en `user_details`.');
        $this->line('  Correos provisionales usuarioN@pendiente.local — hay que reemplazarlos por los reales.');
        $this->line(sprintf('  Rol asignado: "%s". Sin `users` no se sabe quien es que.', self::ROL_MINIMO));
        $this->line('  Contrasenas aleatorias sin registrar: se entra por "olvide mi contrasena".');
    }

    /**
     * La v1 guardaba la contrasena en claro en `users.real_password`.
     *
     * No se migra —aqui solo vive el hash— pero conviene decirlo: mientras esa
     * base exista, esas contrasenas son legibles por cualquiera que la abra, y
     * la gente reutiliza contrasenas en otros sitios.
     */
    protected function avisarDeLaContrasenaEnClaro($viejo): void
    {
        if (! $this->columnaExiste($viejo, 'users', 'real_password')) {
            return;
        }

        $enClaro = $viejo->table('users')->whereNotNull('real_password')->where('real_password', '!=', '')->count();

        if ($enClaro === 0) {
            return;
        }

        $this->newLine();
        $this->error(sprintf('  AVISO: la v1 guarda %d contrasena(s) EN CLARO en users.real_password.', $enClaro));
        $this->line('  No se migran: aqui solo se guarda el hash. Pero mientras esa base exista son legibles');
        $this->line('  para cualquiera que la abra, y la gente repite contrasenas en otros sitios.');
        $this->line('  Vacia esa columna en la v1 en cuanto termine el corte.');
    }

    protected function tablaExiste($viejo, string $tabla): bool
    {
        try {
            return $viejo->getSchemaBuilder()->hasTable($tabla);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function columnaExiste($viejo, string $tabla, string $columna): bool
    {
        try {
            return $viejo->getSchemaBuilder()->hasColumn($tabla, $columna);
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Planes de trabajo ────────────────────────────────────────────────────

    /**
     * Planes con sus llaves, su cuadrilla y sus aprobadores.
     *
     * Antes de los planes van los catalogos: tipo de trabajo, sede, area y
     * puesto existen en la v1 y aqui son llaves obligatorias, asi que se crean
     * leyendo el origen en vez de suponerlos.
     */
    protected function migrarPlanes(): void
    {
        $this->info('── Planes de trabajo ──');
        $viejo = DB::connection('legacy');

        $tipos       = $this->catalogoTipos($viejo);
        $sedes       = $this->catalogoSedes($viejo);
        $areas       = $this->catalogoAreas($viejo);
        $puestos     = $this->catalogoPuestos($viejo, $sedes);
        $reglas      = $this->catalogoReglas($viejo);
        $this->catalogoFormatosPorTipo($viejo, $tipos);

        $empresas = DB::table('companies')->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $usuarios = DB::table('users')->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $respaldo = $usuarios->first() ?? DB::table('users')->orderBy('id')->value('id');

        if (! $respaldo) {
            $this->error('  No hay ningun usuario en destino: corre el paso usuarios primero.');

            return;
        }

        [$codigos, $renombrados] = $this->codigosUnicos($viejo);
        $existentes = DB::table('work_plans')->whereNotNull('legacy_id')->pluck('id', 'legacy_id');

        $origen = $viejo->table('plans')->count();
        $creados = $actualizados = 0;
        $descartados = ['empresa' => 0, 'tipo' => 0, 'sede' => 0, 'usuario' => 0, 'pais' => 0];
        $barra = $this->output->createProgressBar($origen);
        $barra->start();

        $viejo->table('plans')->orderBy('id')->chunkById($this->lote, function ($filas) use (
            &$creados, &$actualizados, &$descartados, $codigos, $existentes,
            $empresas, $usuarios, $respaldo, $tipos, $sedes, $areas, $puestos, $barra
        ) {
            $nuevos = [];

            foreach ($filas as $p) {
                $barra->advance();

                if ((int) $p->country_id !== self::PAIS_LEGACY) {
                    $descartados['pais']++;

                    continue;
                }

                $empresaId = $empresas[$p->company_id] ?? null;
                $tipoId    = $tipos[$p->work_type_id] ?? null;
                $sedeId    = $sedes[$p->location_id] ?? null;

                // Las tres son obligatorias en destino. Sin ellas el plan no se
                // puede escribir, y se dice cuantos y por que se quedaron fuera.
                if (! $empresaId) { $descartados['empresa']++; continue; }
                if (! $tipoId)    { $descartados['tipo']++;    continue; }
                if (! $sedeId)    { $descartados['sede']++;    continue; }

                $usuarioId = $usuarios[$p->user_id] ?? null;

                if (! $usuarioId) {
                    $descartados['usuario']++;
                    $usuarioId = $respaldo;
                }

                $fila = [
                    'country_id'       => $this->countryId,
                    'company_id'       => $empresaId,
                    'work_type_id'     => $tipoId,
                    'work_location_id' => $sedeId,
                    'workstation_id'   => $puestos[$p->workstation_id] ?? null,
                    'work_area_id'     => $areas[$p->area_id] ?? null,
                    'user_id'          => $usuarioId,
                    'code'             => $codigos[$p->id] ?? $p->code,
                    'num_os'           => $p->num_os,
                    'description'      => $p->description,
                    // Con hora: son «Fecha y Hora de Inicio/Fin» en la v1 y de
                    // su diferencia sale el tiempo trabajado.
                    'date_start'       => $this->fechaHora($p->date_start),
                    'date_end'         => $this->fechaHora($p->date_end),
                    'is_closed'        => (bool) $p->is_locked,
                    'is_done'          => (bool) $p->is_done,
                    'legacy_id'        => $p->id,
                    'tenant_id'        => $this->tenantId,
                    'created_by'       => $usuarioId,
                    'deleted_at'       => $p->is_deleted ? $p->updated_at : null,
                    'deleted_description' => $p->is_deleted ? $p->deleted_description : null,
                    'created_at'       => $p->created_at,
                    'updated_at'       => $p->updated_at,
                ];

                if (isset($existentes[$p->id])) {
                    DB::table('work_plans')->where('id', $existentes[$p->id])->update($fila);
                    $actualizados++;
                } else {
                    $nuevos[] = $fila + ['slug' => Str::random(22)];
                    $creados++;
                }
            }

            if ($nuevos !== []) {
                DB::table('work_plans')->insert($nuevos);
            }
        });

        $barra->finish();
        $this->newLine();

        $this->linea('planes', $origen, DB::table('work_plans')->whereNotNull('legacy_id')->count(),
            "{$creados} nuevos, {$actualizados} actualizados");

        if ($renombrados > 0) {
            $this->warn(sprintf(
                '  %d codigo(s) rehechos: en la v1 el ultimo bloque era la hora y se repetia. Aqui es el correlativo del dia (PE26-0608-0001). El original se recupera por legacy_id.',
                $renombrados,
            ));
        }

        $this->avisarDescartes($descartados, [
            'empresa' => 'la empresa no esta migrada',
            'tipo'    => 'el tipo de trabajo no existe en el catalogo',
            'sede'    => 'la sede no existe en el catalogo',
            'usuario' => 'el usuario que lo registro no existe (se asigno el de respaldo, el plan SI se migro)',
            'pais'    => 'el plan es de otro pais',
        ]);

        $this->migrarCuadrillas($viejo);
        $this->migrarAprobaciones($viejo, $reglas);
    }

    /**
     * Los codigos de plan se rehacen: los de la v1 se repiten.
     *
     * Alli el ultimo bloque era la **hora** de creacion, asi que dos planes
     * registrados en el mismo minuto salian iguales: 3 526 codigos distintos
     * para 3 722 planes, y algun dia con cuatro compartiendo codigo. Aqui ese
     * bloque es el **correlativo del dia** (`PE26-0608-0001`), que es como los
     * genera ahora la aplicacion — ver WorkPlanCodeGenerator.
     *
     * Se recorre el origen por id, asi que dos pasadas dan exactamente la misma
     * numeracion. El codigo original siempre se recupera por el legacy_id.
     *
     * @return array{0: array<int, string>, 1: int} [legacy_id => codigo, cuantos cambiaron]
     */
    protected function codigosUnicos($viejo): array
    {
        $iso = strtoupper(Country::withTrashed()->whereKey($this->countryId)->value('iso_code') ?: 'XX');
        $porDia = [];
        $codigos = [];
        $cambiados = 0;

        foreach ($viejo->table('plans')->orderBy('id')->select('id', 'code', 'date_start', 'created_at')->get() as $p) {
            $dia = Carbon::parse($p->date_start ?: $p->created_at);
            $prefijo = sprintf('%s%s-%s', $iso, $dia->format('y'), $dia->format('dm'));

            $porDia[$prefijo] = ($porDia[$prefijo] ?? 0) + 1;
            $codigo = $prefijo . '-' . str_pad((string) $porDia[$prefijo], 4, '0', STR_PAD_LEFT);

            if ($codigo !== $p->code) {
                $cambiados++;
            }

            $codigos[$p->id] = $codigo;
        }

        return [$codigos, $cambiados];
    }

    /** La cuadrilla del plan: quien estuvo asignado a cada trabajo. */
    protected function migrarCuadrillas($viejo): void
    {
        $planes = DB::table('work_plans')->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $personas = $this->personasPorTablaLegacy($viejo);

        $origen = $viejo->table('plan_workers')->count();
        $migrados = 0;
        $descartados = ['plan' => 0, 'persona' => 0, 'repetido' => 0];
        $vistos = [];

        $viejo->table('plan_workers')->orderBy('id')->chunkById($this->lote, function ($filas) use (
            $planes, $personas, &$migrados, &$descartados, &$vistos
        ) {
            $nuevos = [];

            foreach ($filas as $f) {
                $planId = $planes[$f->plan_id] ?? null;
                $personaId = $personas['workers'][$f->worker_id] ?? null;

                if (! $planId)    { $descartados['plan']++;    continue; }
                if (! $personaId) { $descartados['persona']++; continue; }

                // En la v1 la misma persona podia estar dos veces en un plan
                // (una fila por empresa). Aqui la identidad es unica, asi que la
                // segunda no aporta nada.
                $clave = "{$planId}:{$personaId}";

                if (isset($vistos[$clave])) {
                    $descartados['repetido']++;

                    continue;
                }

                $vistos[$clave] = true;

                $nuevos[] = [
                    'slug'         => Str::random(22),
                    'work_plan_id' => $planId,
                    'person_id'    => $personaId,
                    'is_approved'  => (bool) $f->is_approved,
                    'legacy_id'    => $f->id,
                    'created_at'   => $f->created_at,
                    'updated_at'   => $f->updated_at,
                ];
                $migrados++;
            }

            if ($nuevos !== []) {
                DB::table('work_plan_people')->upsert($nuevos, ['work_plan_id', 'person_id'],
                    ['is_approved', 'legacy_id', 'updated_at']);
            }
        });

        $this->linea('cuadrilla', $origen, DB::table('work_plan_people')->whereNotNull('legacy_id')->count(),
            "{$migrados} asignaciones");

        $this->avisarDescartes($descartados, [
            'plan'     => 'el plan no esta migrado',
            'persona'  => 'el trabajador no tiene identidad en destino',
            'repetido' => 'la misma persona ya estaba en ese plan (era una fila por empresa en la v1)',
        ]);
    }

    /** Las aprobaciones del plan: quien tenia que firmar y si firmo. */
    protected function migrarAprobaciones($viejo, array $reglas): void
    {
        $planes = DB::table('work_plans')->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $personas = $this->personasPorTablaLegacy($viejo);

        $origen = $viejo->table('plan_approvals')->count();
        $migrados = 0;
        $representantes = 0;
        $descartados = ['plan' => 0, 'regla' => 0, 'aprobador' => 0, 'repetido' => 0];
        $vistos = [];

        $viejo->table('plan_approvals')->orderBy('id')->chunkById($this->lote, function ($filas) use (
            $planes, $personas, $reglas, &$migrados, &$representantes, &$descartados, &$vistos
        ) {
            $nuevos = [];

            foreach ($filas as $f) {
                $planId = $planes[$f->plan_id] ?? null;

                if (! $planId)  { $descartados['plan']++;  continue; }

                // La aprobacion de tipo «Worker» de la v1 no era una
                // aprobacion: era quien responde por la cuadrilla, y aqui eso
                // es una columna del plan. Sin esto, un volcado nuevo dejaria
                // los planes migrados sin representante y sin poder cerrarse
                // —lo exige `WorkPlanCompletionService`— porque su regla ya no
                // se importa.
                if ($f->approver_type === 'Worker') {
                    $quien = $this->personaAprobadora($personas, $f->approver_type, $f->approver_id);

                    if ($quien) {
                        DB::table('work_plans')->where('id', $planId)
                            ->update(['crew_representative_person_id' => $quien]);
                        $representantes++;
                    }

                    continue;
                }

                $reglaId = $reglas[$f->approval_rule_id] ?? null;

                if (! $reglaId) { $descartados['regla']++; continue; }

                $personaId = $this->personaAprobadora($personas, $f->approver_type, $f->approver_id);

                // Un aprobador que ya no existe en el origen no se sustituye por
                // otro: la aprobacion queda sin persona y se cuenta.
                if ($f->approver_id !== null && ! $personaId) {
                    $descartados['aprobador']++;
                }

                $clave = "{$planId}:{$reglaId}";

                if (isset($vistos[$clave])) {
                    $descartados['repetido']++;

                    continue;
                }

                $vistos[$clave] = true;

                $nuevos[] = [
                    'slug'             => Str::random(22),
                    'work_plan_id'     => $planId,
                    'approval_rule_id' => $reglaId,
                    'person_id'        => $personaId,
                    'is_required'      => (bool) $f->is_required,
                    'is_approved'      => (bool) $f->is_approved,
                    'legacy_id'        => $f->id,
                    'created_at'       => $f->created_at,
                    'updated_at'       => $f->updated_at,
                ];
                $migrados++;
            }

            if ($nuevos !== []) {
                DB::table('work_plan_approvals')->upsert($nuevos, ['work_plan_id', 'approval_rule_id'],
                    ['person_id', 'is_required', 'is_approved', 'legacy_id', 'updated_at']);
            }
        });

        $destino = DB::table('work_plan_approvals')->whereNotNull('legacy_id')->count();
        $pendientes = DB::table('work_plan_approvals')
            ->where('is_required', true)->where('is_approved', false)->count();

        $this->linea('aprobac.', $origen, $destino,
            "{$migrados} aprobaciones · {$representantes} representantes · {$pendientes} obligatorias sin firmar");

        $this->avisarDescartes($descartados, [
            'plan'      => 'el plan no esta migrado',
            'regla'     => 'la regla de aprobacion no existe',
            'aprobador' => 'el aprobador no existe ya en la v1 (la aprobacion se migro sin persona)',
            'repetido'  => 'ese plan ya tenia una aprobacion con esa regla',
        ]);
    }

    /** Aprobador de la v1 (Worker/Supervisor/HseSupervisor + id) a persona de destino. */
    protected function personaAprobadora(array $personas, ?string $tipo, ?int $id): ?int
    {
        if (! $tipo || ! $id) {
            return null;
        }

        $tabla = match ($tipo) {
            'Worker'        => 'workers',
            'Supervisor'    => 'supervisors',
            'HseSupervisor' => 'hse_supervisors',
            default         => null,
        };

        return $tabla ? ($personas[$tabla][$id] ?? null) : null;
    }

    /**
     * Puente entre los ids de las tres tablas de personas de la v1 y la
     * identidad unica de destino. Se reconstruye por documento, que es como se
     * consolidaron las personas en su paso.
     *
     * @return array<string, array<int, int>> tabla => [id v1 => person_id]
     */
    protected function personasPorTablaLegacy($viejo): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        // Por documento a secas, por lo mismo que arriba: el tipo lo deduce la
        // migracion a partir de la nacionalidad y no forma parte de la identidad.
        // Y por tenant, no por pais: el extranjero migrado vive con el pais de
        // su nacionalidad, y filtrar por Peru lo dejaba fuera del mapa — sus
        // firmas y sus filas de cuadrilla no encontraban a su persona.
        //
        // El mapa se arma por el HASH y no por el numero: `people.num_doc` va
        // cifrado, asi que las claves de un `pluck('id', 'num_doc')` serian
        // sobres distintos fila a fila y no encajarian con nada. El lado de la
        // v1 pasa por la misma normalizacion, que ademas es lo unico que hace
        // que un «12 345 678» de alli encuentre al «12345678» de aqui.
        $porDocumento = DB::table('people')
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('num_doc_hash')
            ->pluck('id', 'num_doc_hash');

        $cache = [];

        foreach (['workers', 'supervisors', 'hse_supervisors'] as $tabla) {
            $cache[$tabla] = [];

            foreach ($viejo->table($tabla)->select('id', 'num_doc')->get() as $f) {
                $hash = \App\Support\DocumentoBuscable::hash((string) $f->num_doc);
                $id = $hash === null ? null : ($porDocumento[$hash] ?? null);

                if ($id) {
                    $cache[$tabla][$f->id] = $id;
                }
            }
        }

        return $cache;
    }

    // ── Catalogos que los planes necesitan ───────────────────────────────────

    /** @return array<int, int> legacy_id => work_types.id */
    protected function catalogoTipos($viejo): array
    {
        return $this->catalogo($viejo, 'work_types', function ($f) {
            // La fila equivalente se busca por el CODIGO, que es la identidad
            // (unica por pais y clave del pivote de documentos): las primeras
            // corridas metieron el nombre de la v1 ahi, asi que es donde un
            // «Izaje y Montaje de estructuras» ya migrado se reconoce.
            return WorkType::withTrashed()
                ->where('country_id', $this->countryId)
                ->whereRaw('lower(code) = ?', [mb_strtolower($f->name_es)])
                ->first();
        }, fn ($f) => [
            'country_id' => $this->countryId,
            'code'       => $f->name_es,
            // Y tambien al nombre, que es donde siempre debio estar. Al
            // RE-correr, `catalogo()` actualiza la fila existente, asi que las
            // migradas antes de que existiera la columna se curan solas:
            // name vacio pasa a ser name_es. Idempotente — la v1 no cambia.
            'name'       => $f->name_es,
            'is_active'  => (bool) $f->is_active,
        ]);
    }

    /** @return array<int, int> legacy_id => work_locations.id */
    protected function catalogoSedes($viejo): array
    {
        return $this->catalogo($viejo, 'locations', fn ($f) => WorkLocation::withTrashed()
            ->where('country_id', $this->countryId)->where('name', $f->name)->first(),
            fn ($f) => ['country_id' => $this->countryId, 'name' => $f->name, 'is_active' => (bool) $f->is_active]);
    }

    /** @return array<int, int> legacy_id => work_areas.id */
    protected function catalogoAreas($viejo): array
    {
        return $this->catalogo($viejo, 'areas', fn ($f) => WorkArea::withTrashed()
            ->where('country_id', $this->countryId)->where('name', $f->name)->first(),
            fn ($f) => ['country_id' => $this->countryId, 'name' => $f->name, 'is_active' => (bool) $f->is_active]);
    }

    /**
     * Cargos: Tecnico, Supervisor, Mecanico, Electrico.
     *
     * Se habia quedado sin migrar y no era un detalle: los 372 trabajadores de
     * la v1 tienen cargo —`workers.position_id` es NOT NULL— y la ficha del plan
     * lo enseñaba debajo del nombre de cada uno. Aqui llegaban los 372 sin
     * cargo, y el campo ni existia en la pantalla de personas.
     *
     * De la v1 se trae el nombre y si estaba activo, y ya. Tambien se copiaba
     * `is_signature_approver` —alli marcaba a Supervisor— pero esa columna ya no
     * existe aqui: quien aprueba lo dicen los roles de la persona, no su cargo,
     * y arrastrar el dato solo servia para que la tabla nueva heredara la
     * confusion de la vieja.
     *
     * @return array<int, int> legacy_id => positions.id
     */
    protected function catalogoCargos($viejo): array
    {
        return $this->catalogo($viejo, 'positions', fn ($f) => \App\Models\Position::withTrashed()
            ->where('country_id', $this->countryId)
            ->whereRaw('lower(code) = ?', [mb_strtolower($f->name_es)])
            ->first(),
            fn ($f) => [
                'country_id'            => $this->countryId,
                'code'                  => $f->name_es,
                'is_active'             => (bool) $f->is_active,
            ]);
    }

    /**
     * Que documento lleva esta persona, deducido de su nacionalidad.
     *
     * La v1 no tiene columna de tipo de documento: todo es `num_doc` a secas, y
     * aqui se estaba escribiendo «DNI» para los 391. Para 380 es cierto; para
     * los 11 extranjeros no — un peruano no puede tener carne de extranjeria y
     * un extranjero no puede tener DNI.
     *
     * El extranjero lleva EL DOCUMENTO NACIONAL DE SU PAIS, no el carne de
     * extranjeria peruano. Es la correccion del dueño del producto: «si fue
     * diferente a Peru tu le pusiste pais Peru y CE, pero debio haber sido su
     * pais y su tipo de documento que corresponde». En el sistema nuevo la
     * persona vive con su pais y el formulario ofrece los tipos de ESE pais
     * (el venezolano con su cedula, el chileno con su RUN); Peru+CE la dejaba
     * con una combinacion que la pantalla ni ofrece.
     *
     * El tipo sale del catalogo de `document_types`: el de alcance persona que
     * NO es de extranjeros — ese es el documento nacional. Los 21 paises de
     * Latinoamerica vienen sembrados; si un dia una nacionalidad cae en un
     * pais sin tipos, se cae al CE de siempre, que es peor que el suyo pero
     * mejor que inventar una sigla.
     *
     * **Es una deduccion, no un dato del origen**, y conviene saberlo. Si
     * alguno lleva pasaporte o PTP en vez del documento de su pais, hay que
     * corregirlo a mano (queda anotado en docs/MIGRACION.md).
     *
     * Sin nacionalidad no se deduce nada: se deja el DNI de siempre.
     */
    protected function tipoDeDocumento(?int $nacionalidadId): string
    {
        // Sin nacionalidad no se deduce nada: se deja el DNI de siempre.
        if ($nacionalidadId === null || $nacionalidadId === $this->countryId) {
            return 'DNI';
        }

        return \App\Models\DocumentType::query()
            ->where('country_id', $nacionalidadId)
            ->where('scope', \App\Models\DocumentType::PERSONA)
            ->where('for_foreigners', false)
            ->orderBy('id')
            ->value('code') ?? 'CE';
    }

    /**
     * Las nacionalidades de la v1, emparejadas con el catalogo de PAISES.
     *
     * En la v1 `workers.nationality_id` es NOT NULL: los 391 trabajadores traen
     * una, y la ficha del plan la enseñaba con una banderita al lado del
     * nombre. El reparto real: 380 Peru, 9 Venezuela, 1 Chile, 1 Argentina.
     * Son once personas, pero son once que entran con SU pais y el documento
     * nacional de ese pais — y eso es justo lo que el supervisor comprueba en
     * la puerta.
     *
     * Aqui NO se crea nada: una nacionalidad es un pais y `countries` ya los
     * tiene los 26. Se empareja por nombre sin tildes ni mayusculas —«Perú» con
     * «Peru»— y como respaldo por las tres primeras letras, que distinguen de
     * sobra entre 26. Lo que no cuadre se queda fuera: la nacionalidad es
     * opcional e inventarla seria peor que no tenerla.
     *
     * @return array<int, int> legacy_id => countries.id
     */
    protected function catalogoNacionalidades($viejo): array
    {
        $limpio = fn (?string $t) => mb_strtolower(preg_replace('/[^a-z]/i', '',
            preg_replace('/\p{Mn}/u', '', \Normalizer::normalize((string) $t, \Normalizer::FORM_D) ?: (string) $t) ?? ''));

        $paises = \App\Models\Country::all(['id', 'name']);
        $mapa = [];
        $sinPareja = [];

        foreach ($viejo->table('nationalities')->get(['id', 'name']) as $fila) {
            $buscado = $limpio($fila->name);

            if ($buscado === '') {
                continue;
            }

            $pais = $paises->first(fn ($p) => $limpio($p->name) === $buscado)
                ?? $paises->first(fn ($p) => str_starts_with($limpio($p->name), substr($buscado, 0, 3)));

            $pais ? $mapa[$fila->id] = $pais->id : $sinPareja[] = $fila->name;
        }

        if ($sinPareja !== []) {
            // Se dice en positivo y diciendo a QUE base afecta: el aviso
            // anterior —«Nacionalidades sin pais equivalente (se dejan en
            // blanco)»— sonaba a que se estaba tirando algo, y en la base vieja
            // no se toca nada. Las personas se migran enteras; lo unico que
            // llega vacio es ese campo, porque la v1 tenia una lista suelta de
            // nacionalidades y aqui son paises del catalogo.
            $this->line('  Estas nacionalidades de la v1 no tienen pais en el catalogo nuevo, asi que'
                . ' esas personas llegan con el campo vacio (nada mas se pierde): ' . implode(', ', $sinPareja));
        }

        return $mapa;
    }

    /** @return array<int, int> legacy_id => workstations.id */
    protected function catalogoPuestos($viejo, array $sedes): array
    {
        return $this->catalogo($viejo, 'workstations', fn ($f) => Workstation::withTrashed()
            ->where('work_location_id', $sedes[$f->location_id] ?? 0)->where('name', $f->name)->first(),
            function ($f) use ($sedes) {
                $sedeId = $sedes[$f->location_id] ?? null;

                // Un puesto sin sede no se puede escribir: la sede es obligatoria.
                return $sedeId ? ['work_location_id' => $sedeId, 'name' => $f->name, 'is_active' => (bool) $f->is_active] : null;
            });
    }

    /**
     * Reglas de aprobacion: quien tiene que firmar un plan y en que orden.
     *
     * @return array<int, int> legacy_id => approval_rules.id
     */
    protected function catalogoReglas($viejo): array
    {
        $mapa = $this->catalogo($viejo, 'approval_rules', function ($f) {
            return ApprovalRule::withTrashed()
                ->where('country_id', $this->countryId)
                ->where('approver_role', $this->rolAprobador($f->approver_type))
                ->where('priority_level', $f->priority_level)
                ->first();
        }, fn ($f) => $f->approver_type === 'Worker' ? null : [
            'country_id'     => $this->countryId,
            // El nombre de verdad: «Supervisor Autorizante - HITACHI». El rol
            // dice que clase de persona firma; el nombre dice por parte de
            // quien, y eso es lo que distingue una firma de otra. Se habia
            // quedado fuera y en pantalla salia el rol generico.
            'name'           => $f->name_es,
            'approver_role'  => $this->rolAprobador($f->approver_type),
            'priority_level' => $f->priority_level,
            'is_required'    => (bool) $f->is_required,
            'is_active'      => (bool) $f->is_active,
        ]);

        $this->cerrarElHuecoDelRepresentante();

        return $mapa;
    }

    /**
     * Renumera el flujo para que empiece en 1.
     *
     * El nivel se copia tal cual venia de la v1, donde el 1 era «Worker» — el
     * representante de la cuadrilla, que aqui no es una aprobacion y no se
     * trae. Resultado: el flujo llegaba empezando en 2, y en pantalla salian
     * «Nivel 2» y «Nivel 3» sin ningun 1, con la ayuda del campo diciendo que
     * «1 firma primero».
     *
     * No rompia nada —el orden se resuelve comparando valores, no contandolos—
     * pero un hueco que nadie decidio parece un dato que falta, y lo primero
     * que se hace con algo asi es buscar el error que no existe.
     *
     * Se renumera **solo lo migrado** (`legacy_id`), por flujo: pais, tipo de
     * trabajo y workspace. Un administrador que numere 10, 20, 30 a proposito
     * para dejarse sitio en medio no se toca.
     */
    protected function cerrarElHuecoDelRepresentante(): void
    {
        $porFlujo = ApprovalRule::withTrashed()
            ->whereNotNull('legacy_id')
            ->orderBy('priority_level')->orderBy('id')
            ->get()
            ->groupBy(fn ($r) => $r->country_id . '|' . ($r->work_type_id ?? '*') . '|' . ($r->tenant_id ?? '*'));

        $movidas = 0;

        foreach ($porFlujo as $reglas) {
            $nivel = 1;

            foreach ($reglas as $regla) {
                // De arriba abajo y siempre bajando, asi que el nivel al que se
                // mueve una regla ya lo dejo libre la anterior.
                if ((int) $regla->priority_level !== $nivel) {
                    $regla->updateQuietly(['priority_level' => $nivel]);
                    $movidas++;
                }

                $nivel++;
            }
        }

        if ($movidas > 0) {
            $this->line(sprintf('  %d regla(s) renumeradas: el flujo empieza en 1 (en la v1 el 1 era el representante).', $movidas));
        }
    }

    /**
     * El rol de aprobador de la v1, en el de aqui.
     *
     * «Worker» no tiene equivalente y no debe tenerlo: esa fila de la v1 no era
     * una aprobacion sino quien responde por la cuadrilla, y aqui vive en
     * `work_plans.crew_representative_person_id`. Las reglas con ese tipo no se
     * traen —`catalogoReglas()` las descarta— y las aprobaciones que las usaban
     * pasan a la columna del plan.
     */
    protected function rolAprobador(string $tipo): string
    {
        return match ($tipo) {
            'Supervisor'    => PersonRole::SUPERVISOR,
            'HseSupervisor' => PersonRole::HSE_SUPERVISOR,
            default         => Str::snake($tipo),
        };
    }

    /**
     * Que formatos exige cada tipo de trabajo. En la v1 era work_type_documents;
     * aqui es la tabla que une el tipo con la plantilla del motor.
     */
    protected function catalogoFormatosPorTipo($viejo, array $tipos): void
    {
        $porTablaLegacy = array_flip(self::FORMATOS);   // f1_documents => AST
        $documentos = $viejo->table('documents')->pluck('db_name', 'id');
        $plantillas = FormTemplate::whereIn('code', array_keys(self::FORMATOS))
            ->pluck('id', 'code');

        // db_name en la v1 es el nombre de la clase Rails (F1Document).
        $porClase = [];
        foreach ($documentos as $id => $clase) {
            $tabla = Str::snake($clase) . 's';           // F1Document => f1_documents
            $porClase[$id] = $porTablaLegacy[$tabla] ?? null;
        }

        $filas = [];

        foreach ($viejo->table('work_type_documents')->get() as $f) {
            $tipoId = $tipos[$f->work_type_id] ?? null;
            $codigo = $porClase[$f->document_id] ?? null;
            $plantillaId = $codigo ? ($plantillas[$codigo] ?? null) : null;

            if (! $tipoId || ! $plantillaId) {
                continue;
            }

            $filas[] = [
                'work_type_id'     => $tipoId,
                'form_template_id' => $plantillaId,
                'is_required'      => (bool) $f->is_required,
                'created_at'       => now(),
                'updated_at'       => now(),
            ];
        }

        if ($filas !== []) {
            DB::table('work_type_form_templates')
                ->upsert($filas, ['work_type_id', 'form_template_id'], ['is_required', 'updated_at']);
        }

        $this->line(sprintf('  catalogos  tipos: %d · formatos exigidos por tipo: %d', count($tipos), count($filas)));
    }

    /**
     * Alta idempotente de un catalogo del sistema anterior.
     *
     * @param  callable  $buscar  como encontrar la fila equivalente que ya exista
     * @param  callable  $datos   columnas de destino, o null si la fila no se puede migrar
     * @return array<int, int> legacy_id => id de destino
     */
    protected function catalogo($viejo, string $tabla, callable $buscar, callable $datos): array
    {
        $modelo = [
            'work_types'     => WorkType::class,
            'locations'      => WorkLocation::class,
            'areas'          => WorkArea::class,
            'workstations'   => Workstation::class,
            'approval_rules' => ApprovalRule::class,
            'positions'      => \App\Models\Position::class,
        ][$tabla];

        $mapa = [];

        $consulta = $viejo->table($tabla)->orderBy('id');

        // Los catalogos de la v1 son multipais; aqui solo se trae el que usan
        // los planes. Workstations cuelga de la sede y no del pais.
        if ($tabla !== 'workstations') {
            $consulta->where('country_id', self::PAIS_LEGACY);
        }

        foreach ($consulta->get() as $f) {
            $fila = $modelo::withTrashed()->where('legacy_id', $f->id)->first() ?? $buscar($f);
            $columnas = $datos($f);

            if ($columnas === null) {
                continue;
            }

            $columnas['legacy_id'] = $f->id;


            if ($fila) {
                $fila->update($columnas);
            } else {
                $fila = $modelo::create($columnas + [
                    'slug' => Str::random(22), 'tenant_id' => $this->tenantId, 'created_by' => 1,
                ]);
            }

            // Aqui las filas migradas nacian BLOQUEADAS, con candado de nivel
            // 'super'. La idea era una pausa antes de renombrar algo que citan
            // miles de planes firmados, y en el papel se sostenia; en la
            // practica el catalogo entero llegaba intocable y —peor— con un
            // candado que un admin NO puede quitar, porque `canBeUnlockedBy()`
            // solo le deja con los de nivel 'tenant'. O sea que quien acababa
            // de migrar su sistema no podia editar sus propios tipos de
            // trabajo, ni sus sedes, ni sus cargos, y sin ninguna forma de
            // arreglarlo desde la aplicacion.
            //
            // Se quita. Lo que se pierde es la pausa: renombrar un tipo de
            // trabajo cambia lo que dicen todos los planes que lo citan, y eso
            // sigue siendo cierto. Lo que se gana es poder trabajar. Quien
            // quiera la pausa tiene el candado a mano en la ficha, que es donde
            // se decide fila a fila y no de golpe para todo lo migrado.
            $mapa[$f->id] = $fila->id;
        }

        return $mapa;
    }

    // ── Formatos llenados ────────────────────────────────────────────────────

    /**
     * Cada AST, PTF, EPP o IHM llenado de la v1 se convierte en una entrega del
     * formato correspondiente, con sus respuestas.
     *
     * Es el paso con mas volumen del origen —226 875 respuestas de EPP y 62 254
     * de PTF— asi que se recorre por lotes y las respuestas de un campo
     * compuesto se guardan juntas, que es como las espera el motor.
     */
    protected function migrarDocumentos(): void
    {
        $this->info('── Formatos llenados ──');
        $viejo = DB::connection('legacy');

        $plantillas = FormTemplate::whereIn('code', array_keys(self::FORMATOS))
            ->get()->keyBy('code');

        $faltan = array_diff(array_keys(self::FORMATOS), $plantillas->keys()->all());

        if ($faltan !== []) {
            $this->error('  Faltan plantillas (' . implode(', ', $faltan) . '): corre php artisan docufiz:migrate-formats.');

            return;
        }

        if (! $this->plantillasCompletas($plantillas)) {
            return;
        }

        $this->migrarAst($viejo, $plantillas['AST']);
        $this->migrarPtf($viejo, $plantillas['PTF']);
        $this->migrarEpp($viejo, $plantillas['EPP']);
        $this->migrarIhm($viejo, $plantillas['IHM']);
    }

    /**
     * Deja listas las cuatro plantillas antes de tocar los documentos.
     *
     * `migrate-formats` es idempotente: omite las que ya existen. Con
     * `--rehacer-formatos` se le pasa `--fresh` y las reconstruye, que es lo que
     * hace falta cuando vienen de una version anterior y les faltan campos.
     */
    protected function prepararFormatos(): bool
    {
        $this->info('── Plantillas de formato ──');

        $codigo = $this->call('docufiz:migrate-formats', [
            '--fresh' => (bool) $this->option('rehacer-formatos'),
        ]);

        if ($codigo !== self::SUCCESS) {
            $this->error('  No se pudieron preparar las plantillas: se detiene aqui.');

            return false;
        }

        $this->refrescarCatalogosDeLaMatriz();

        return true;
    }

    /**
     * Refresca EN SITIO los catalogos de la matriz de riesgo de AST y PTF.
     *
     * El agujero que tapa: dentro de `setup:project --datos`,
     * `FormTemplatesSeeder` crea las plantillas desde el JSON congelado
     * (`formatos-v1.json`, que trae c1..c5 a secas) ANTES de que corra
     * `migrate-formats`, y este al verlas existir se hace a un lado. O sea que
     * todo lo que `migrate-formats` sabe leer de la base vieja —incluidos los
     * nombres reales de severidad y probabilidad de su tabla `translations`—
     * no llegaba NUNCA en el flujo real del dueño: solo en una base donde
     * nadie hubiera sembrado antes.
     *
     * Por eso, cuando este comando prepara los formatos y la base vieja esta
     * delante, los catalogos de la config del campo `risk_matrix` (severities,
     * probabilities, matrix, levels y los mapas de etiquetas) se pisan sobre
     * la plantilla existente. Solo eso: la plantilla no se recrea, su version
     * no se toca y sus entregas se quedan donde estan — las respuestas guardan
     * c1..c5 y `matrix` se indexa por posicion, asi que refrescar el catalogo
     * no cambia el significado de nada ya firmado.
     *
     * Se escribe con el constructor de consultas, como el resto de pasos
     * grandes: es la cronica de un hecho que ya queda dicho en el log, no una
     * edicion de la que haya que auditar autor.
     */
    protected function refrescarCatalogosDeLaMatriz(): void
    {
        try {
            $nuevos = app(MigrateLegacyFormatsCommand::class)->catalogosRefrescablesDeLaMatriz();
        } catch (\Throwable $e) {
            // Sin base vieja delante no hay de donde refrescar: las plantillas
            // se quedan con el JSON congelado, que es justo el respaldo para
            // ese caso. Aqui en la practica no se llega —handle() ya comprobo
            // la conexion— pero un volcado a medias no debe tumbar el paso.
            $this->warn('  No se pudieron refrescar los catalogos de la matriz: ' . $e->getMessage());

            return;
        }

        $refrescados = 0;

        foreach (FormTemplate::whereIn('code', ['AST', 'PTF'])->get() as $plantilla) {
            foreach ($plantilla->fields()->where('field_type', 'risk_matrix')->get() as $campo) {
                $config = $campo->config ?? [];

                // Los mapas de etiquetas se quitan antes de mezclar: si la base
                // vieja ya no trae una traduccion, el mapa viejo no puede
                // quedarse pegado diciendo lo que la base ya no dice.
                unset(
                    $config['severity_labels'], $config['probability_labels'],
                    $config['severity_labels_en'], $config['probability_labels_en'],
                );

                DB::table('form_fields')->where('id', $campo->getKey())->update([
                    'config'     => json_encode(array_merge($config, $nuevos), JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);

                $refrescados++;
            }
        }

        if ($refrescados > 0) {
            $this->line('  Catálogos de la matriz refrescados desde la base anterior (nombres reales de severidad y probabilidad).');
        }
    }

    /**
     * Que la plantilla exista no basta: tiene que tener los campos donde van las
     * respuestas.
     *
     * Pasa de verdad: si el AST se creo con una version anterior del comando,
     * `migrate-formats` lo da por bueno y lo omite, y la migracion de documentos
     * se encontraba con que el campo no estaba. Antes reventaba con un
     * "Undefined array key" a mitad de los 3 657 documentos; ahora se dice
     * antes de empezar, y se dice como arreglarlo.
     */
    protected function plantillasCompletas(Collection $plantillas): bool
    {
        $exigidos = [
            'AST' => ['matriz_de_riesgo'],
            'PTF' => ['preguntas', 'matriz_de_riesgo'],
            'EPP' => ['epp_por_trabajador'],
            'IHM' => ['inspeccion_de_herramientas'],
        ];

        $incompletas = [];

        foreach ($exigidos as $codigo => $codigos) {
            $tiene = array_keys($this->camposDe($plantillas[$codigo]));
            $faltan = array_diff($codigos, $tiene);

            if ($faltan !== []) {
                $incompletas[$codigo] = ['faltan' => $faltan, 'tiene' => $tiene];
            }
        }

        if ($incompletas === []) {
            return true;
        }

        $this->newLine();
        $this->error('  Hay plantillas incompletas: se crearon con una version anterior y les faltan campos.');

        foreach ($incompletas as $codigo => $d) {
            $this->line(sprintf('    %s — falta: %s   (tiene: %s)',
                $codigo, implode(', ', $d['faltan']), $d['tiene'] ? implode(', ', $d['tiene']) : 'ningun campo'));
        }

        $this->newLine();
        $this->warn('  Rehazlas y vuelve a intentarlo:');
        $this->line('    php artisan docufiz:migrate-formats --fresh');
        $this->line('    php artisan docufiz:migrate-data documentos');
        $this->newLine();
        $this->line('  --fresh borra las plantillas y las entregas que cuelguen de ellas, que a estas');
        $this->line('  alturas son de demostracion. Los planes, personas y empresas no se tocan.');

        return false;
    }

    /** AST: la matriz de riesgo eran dos tablas encadenadas, actividades y peligros. */
    protected function migrarAst($viejo, FormTemplate $plantilla): void
    {
        $campos = $this->camposDe($plantilla);
        $bandas = $this->bandasDeLaMatriz($plantilla);
        $severidades = $viejo->table('severities')->pluck('name', 'id')->all();
        $probabilidades = $viejo->table('probabilities')->pluck('name', 'id')->all();
        $equipos = $viejo->table('ast_equipments')->pluck('name_es', 'id')->all();
        $objetivos = $viejo->table('ast_objetives')->pluck('name_es', 'id')->all();

        $this->recorrerFormato($viejo, 'f1_documents', $plantilla, function ($docs, $entregas) use (
            $viejo, $campos, $severidades, $probabilidades, $equipos, $objetivos, $bandas
        ) {
            $ids = $docs->pluck('id')->all();

            $actividades = $viejo->table('f1_document_activities')->whereIn('f1_document_id', $ids)
                ->orderBy('id')->get()->groupBy('f1_document_id');
            $peligros = $viejo->table('f1_document_dangers')
                ->whereIn('f1_document_activity_id', $actividades->flatten()->pluck('id')->all())
                ->orderBy('id')->get()->groupBy('f1_document_activity_id');
            $susEquipos = $viejo->table('f1_document_equipments')->whereIn('f1_document_id', $ids)
                ->get()->groupBy('f1_document_id');
            $susObjetivos = $viejo->table('f1_document_objetives')->whereIn('f1_document_id', $ids)
                ->get()->groupBy('f1_document_id');

            $respuestas = [];

            foreach ($docs as $d) {
                $entregaId = $entregas[$d->id] ?? null;

                if (! $entregaId) {
                    continue;
                }

                $suyas = ($actividades[$d->id] ?? collect())->map(fn ($a) => (array) $a)->all();
                $suyos = collect($suyas)
                    ->flatMap(fn ($a) => ($peligros[$a['id']] ?? collect())->map(fn ($p) => (array) $p))
                    ->all();

                $filas = $this->mapa->matrizDeRiesgo($suyas, $suyos, $severidades, $probabilidades, 'f1_document_activity_id', $bandas);

                foreach ($filas as $i => $fila) {
                    $respuestas[] = $this->respuesta($entregaId, $campos['matriz_de_riesgo'], $i, $fila, 'json');
                }

                $lista = ($susEquipos[$d->id] ?? collect())->map(fn ($e) => $equipos[$e->ast_equipment_id] ?? null)->filter()->values()->all();
                if ($lista !== [] && isset($campos['equipos'])) {
                    $respuestas[] = $this->respuesta($entregaId, $campos['equipos'], 0, $lista, 'json');
                }

                $lista = ($susObjetivos[$d->id] ?? collect())->map(fn ($o) => $objetivos[$o->ast_objetive_id] ?? null)->filter()->values()->all();
                if ($lista !== [] && isset($campos['objetivos'])) {
                    $respuestas[] = $this->respuesta($entregaId, $campos['objetivos'], 0, $lista, 'json');
                }

                $texto = $this->mapa->observacionesAst($d->adrights ?? null, $d->eqtools ?? null);
                if ($texto !== null && isset($campos['observaciones'])) {
                    $respuestas[] = $this->respuesta($entregaId, $campos['observaciones'], 0, $texto, 'text');
                }
            }

            return $respuestas;
        });
    }

    /** PTF: banco de preguntas y una matriz de riesgo que casi nunca se uso. */
    protected function migrarPtf($viejo, FormTemplate $plantilla): void
    {
        $campos = $this->camposDe($plantilla);
        $bandas = $this->bandasDeLaMatriz($plantilla);
        $preguntas = $viejo->table('ptf_questions')->pluck('name_es', 'id')->all();
        $severidades = $viejo->table('severities')->pluck('name', 'id')->all();
        $probabilidades = $viejo->table('probabilities')->pluck('name', 'id')->all();

        $this->recorrerFormato($viejo, 'f2_documents', $plantilla, function ($docs, $entregas) use (
            $viejo, $campos, $preguntas, $severidades, $probabilidades, $bandas
        ) {
            $ids = $docs->pluck('id')->all();

            $contestadas = $viejo->table('f2_document_answers')->whereIn('f2_document_id', $ids)
                ->orderBy('id')->get()->groupBy('f2_document_id');
            $actividades = $viejo->table('f2_document_activities')->whereIn('f2_document_id', $ids)
                ->orderBy('id')->get()->groupBy('f2_document_id');
            $peligros = $viejo->table('f2_document_dangers')
                ->whereIn('f2_document_activity_id', $actividades->flatten()->pluck('id')->all())
                ->orderBy('id')->get()->groupBy('f2_document_activity_id');

            $respuestas = [];

            foreach ($docs as $d) {
                $entregaId = $entregas[$d->id] ?? null;

                if (! $entregaId) {
                    continue;
                }

                $suyas = ($contestadas[$d->id] ?? collect())->map(fn ($r) => (array) $r)->all();

                if ($suyas !== []) {
                    $respuestas[] = $this->respuesta($entregaId, $campos['preguntas'], 0,
                        $this->mapa->bancoDePreguntas($suyas, $preguntas), 'json');
                }

                $act = ($actividades[$d->id] ?? collect())->map(fn ($a) => (array) $a)->all();
                $pel = collect($act)->flatMap(fn ($a) => ($peligros[$a['id']] ?? collect())->map(fn ($p) => (array) $p))->all();

                foreach ($this->mapa->matrizDeRiesgo($act, $pel, $severidades, $probabilidades, 'f2_document_activity_id', $bandas) as $i => $fila) {
                    $respuestas[] = $this->respuesta($entregaId, $campos['matriz_de_riesgo'], $i, $fila, 'json');
                }
            }

            return $respuestas;
        });
    }

    /** EPP: una fila por trabajador del plan con sus items de proteccion. */
    protected function migrarEpp($viejo, FormTemplate $plantilla): void
    {
        $campos = $this->camposDe($plantilla);
        $items = $viejo->table('epp_items')->pluck('name_es', 'id')->all();
        $personas = DB::table('work_plan_people')->whereNotNull('legacy_id')->pluck('person_id', 'legacy_id');

        $this->recorrerFormato($viejo, 'f3_documents', $plantilla, function ($docs, $entregas) use (
            $viejo, $campos, $items, $personas
        ) {
            $ids = $docs->pluck('id')->all();

            $trabajadores = $viejo->table('f3_document_workers')->whereIn('f3_document_id', $ids)
                ->orderBy('id')->get();
            $contestadas = $viejo->table('f3_document_answers')
                ->whereIn('f3_document_worker_id', $trabajadores->pluck('id')->all())
                ->orderBy('id')->get()->groupBy('f3_document_worker_id');
            $porDocumento = $trabajadores->groupBy('f3_document_id');

            $respuestas = [];

            foreach ($docs as $d) {
                $entregaId = $entregas[$d->id] ?? null;

                if (! $entregaId) {
                    continue;
                }

                foreach (($porDocumento[$d->id] ?? collect())->values() as $i => $t) {
                    $suyas = ($contestadas[$t->id] ?? collect())->map(fn ($r) => (array) $r)->all();

                    $respuestas[] = $this->respuesta($entregaId, $campos['epp_por_trabajador'], $i,
                        $this->mapa->checklistDePersona((array) $t, $suyas, $items, $personas[$t->plan_worker_id] ?? null),
                        'json');
                }
            }

            return $respuestas;
        });
    }

    /** IHM: una fila por herramienta inspeccionada con sus puntos de control. */
    protected function migrarIhm($viejo, FormTemplate $plantilla): void
    {
        $campos = $this->camposDe($plantilla);
        $catalogo = $viejo->table('ihm_items')->pluck('name_es', 'id')->all();

        $this->recorrerFormato($viejo, 'f4_documents', $plantilla, function ($docs, $entregas) use (
            $viejo, $campos, $catalogo
        ) {
            $ids = $docs->pluck('id')->all();

            $herramientas = $viejo->table('f4_document_tools')->whereIn('f4_document_id', $ids)
                ->orderBy('id')->get();
            $items = $viejo->table('f4_document_items')
                ->whereIn('f4_document_tool_id', $herramientas->pluck('id')->all())
                ->orderBy('id')->get()->groupBy('f4_document_tool_id');
            $porDocumento = $herramientas->groupBy('f4_document_id');

            $respuestas = [];

            foreach ($docs as $d) {
                $entregaId = $entregas[$d->id] ?? null;

                if (! $entregaId) {
                    continue;
                }

                foreach (($porDocumento[$d->id] ?? collect())->values() as $i => $h) {
                    $suyos = ($items[$h->id] ?? collect())->map(fn ($r) => (array) $r)->all();

                    $respuestas[] = $this->respuesta($entregaId, $campos['inspeccion_de_herramientas'], $i,
                        $this->mapa->checklistDeHerramienta((array) $h, $suyos, $catalogo), 'json');
                }
            }

            return $respuestas;
        });
    }

    /**
     * Recorrido comun de los cuatro formatos: crea la entrega de cada documento
     * y delega en el llamador la construccion de las respuestas.
     *
     * Las respuestas anteriores de esas entregas se borran antes de escribir,
     * para que volver a correr el paso deje exactamente lo que dice el origen y
     * no restos de una pasada anterior.
     */
    protected function recorrerFormato($viejo, string $tabla, FormTemplate $plantilla, callable $construir): void
    {
        $planes = DB::table('work_plans')->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $origen = $viejo->table($tabla)->count();
        $entregas = $respuestasEscritas = 0;
        $descartados = ['plan' => 0];

        $barra = $this->output->createProgressBar($origen);
        $barra->start();

        $viejo->table($tabla)->orderBy('id')->chunkById($this->lote, function ($docs) use (
            $tabla, $plantilla, $planes, $construir, &$entregas, &$respuestasEscritas, &$descartados, $barra
        ) {
            $barra->advance($docs->count());

            $filas = [];

            foreach ($docs as $d) {
                $planId = $planes[$d->plan_id] ?? null;

                if (! $planId) {
                    $descartados['plan']++;

                    continue;
                }

                $filas[] = [
                    'slug'             => Str::random(22),
                    'work_plan_id'     => $planId,
                    'form_template_id' => $plantilla->id,
                    'template_version' => $plantilla->version,
                    'status'           => $d->is_confirmed ? 'confirmed' : 'draft',
                    'submitted_at'     => $d->is_confirmed ? $d->updated_at : null,
                    'legacy_id'        => $d->id,
                    'legacy_table'     => $tabla,
                    'tenant_id'        => $this->tenantId,
                    'created_by'       => 1,
                    'deleted_at'       => ($d->is_deleted ?? false) ? $d->updated_at : null,
                    'created_at'       => $d->created_at,
                    'updated_at'       => $d->updated_at,
                ];
            }

            if ($filas === []) {
                return;
            }

            DB::table('form_submissions')->upsert($filas, ['work_plan_id', 'form_template_id'], [
                'template_version', 'status', 'submitted_at', 'legacy_id', 'legacy_table',
                'deleted_at', 'updated_at',
            ]);
            $entregas += count($filas);

            // legacy_id del documento => id de la entrega recien escrita.
            $mapa = DB::table('form_submissions')
                ->where('legacy_table', $tabla)
                ->whereIn('legacy_id', $docs->pluck('id')->all())
                ->pluck('id', 'legacy_id');

            DB::table('form_answers')->whereIn('form_submission_id', $mapa->values()->all())->delete();

            $respuestas = $construir($docs, $mapa);

            foreach (array_chunk($respuestas, 1000) as $trozo) {
                DB::table('form_answers')->insert($trozo);
                $respuestasEscritas += count($trozo);
            }
        });

        $barra->finish();
        $this->newLine();

        $destino = DB::table('form_submissions')->where('legacy_table', $tabla)->count();
        $this->linea($plantilla->code, $origen, $destino, "{$respuestasEscritas} respuestas escritas");

        $this->avisarDescartes($descartados, ['plan' => 'el plan del documento no esta migrado']);
    }

    /**
     * Las bandas de riesgo que declara la plantilla («1-8 alto, 9-15 medio...»).
     *
     * Se leen una vez por formato y se le pasan al mapeador, para que cada fila
     * migrada llegue con su banda puesta. Sin ellas la fila guarda el numero de
     * la matriz y nada mas, que es como se quedaron las 3 657 matrices de la
     * primera migracion: la pantalla las daba por «sin evaluar».
     */
    protected function bandasDeLaMatriz(FormTemplate $plantilla): array
    {
        $campo = $plantilla->fields()->where('field_type', 'risk_matrix')->first();

        return FormFindingsService::bandasDe($campo->config ?? []);
    }

    /** Los campos de la plantilla, por codigo, para no buscarlos en cada fila. */
    protected function camposDe(FormTemplate $plantilla): array
    {
        return $plantilla->fields()->pluck('form_fields.id', 'form_fields.code')->all();
    }

    /** Una fila de form_answers en la columna que le toca segun el tipo de valor. */
    protected function respuesta(int $entregaId, int $campoId, int $fila, mixed $valor, string $tipo): array
    {
        return [
            'form_submission_id' => $entregaId,
            'form_field_id'      => $campoId,
            'row_index'          => $fila,
            'value_text'         => $tipo === 'text' ? $valor : null,
            'value_number'       => null,
            'value_datetime'     => null,
            'value_boolean'      => null,
            'value_json'         => $tipo === 'json' ? json_encode($valor, JSON_UNESCAPED_UNICODE) : null,
            'created_at'         => now(),
            'updated_at'         => now(),
        ];
    }

    // ── Firmas y fotos ───────────────────────────────────────────────────────

    /**
     * Firmas y fotos de la v1.
     *
     * El dato incomodo de este paso: de 9 012 fotos de trabajador, 7 508 eran
     * literalmente la cadena "detected_by_IA" escrita en la columna del
     * archivo, y con las firmas pasa lo mismo. No hay fichero detras. Se migran
     * como firma historica sin archivo —method 'migrated', evidence_missing— y
     * el comando dice cuantas eran reales y cuantas no. No se inventa evidencia.
     */
    protected function migrarEvidencias(): void
    {
        $this->info('── Firmas y fotos ──');
        $viejo = DB::connection('legacy');

        $cuadrilla = DB::table('work_plan_people')->whereNotNull('legacy_id')
            ->get(['id', 'person_id', 'legacy_id'])->keyBy('legacy_id');
        $aprobaciones = DB::table('work_plan_approvals')->whereNotNull('legacy_id')
            ->get(['id', 'person_id', 'legacy_id'])->keyBy('legacy_id');

        $conteo = ['reales' => 0, 'marcadores' => 0, 'sin_referencia' => 0, 'origen' => 0];

        $this->evidenciasDeTrabajadores($viejo, $cuadrilla, $conteo);
        $this->evidenciasDeAprobaciones($viejo, $aprobaciones, $conteo);
        $this->completarElRastroDeLoImportado();

        $eventos = DB::table('signature_events')->whereNotNull('legacy_source')->count();
        $archivos = DB::table('evidence_files')->where('file_path', 'like', 'legacy/%')->count();
        $conArchivo = DB::table('signature_events')->whereNotNull('legacy_source')
            ->where('evidence_missing', false)->count();

        $this->newLine();
        $this->linea('firmas', $conteo['origen'], $eventos, sprintf(
            '%d eventos con archivo (%d ficheros) · %d sin archivo',
            $conArchivo, $archivos, $eventos - $conArchivo,
        ));

        $this->table(['Referencias de la v1', 'Cuantas', 'Que son'], [
            ['archivo real',   $conteo['reales'],         'nombre de fichero: se migra como evidencia (falta copiar el archivo)'],
            ['marcador de IA', $conteo['marcadores'],     '"detected_by_IA"/"signed_by_IA": no existe archivo, no es evidencia'],
            ['sin referencia', $conteo['sin_referencia'], 'la columna venia vacia'],
        ]);

        $total = $conteo['reales'] + $conteo['marcadores'];
        if ($total > 0) {
            $this->warn(sprintf(
                '  El %.1f %% de las firmas y fotos historicas no tiene archivo detras. Es un dato de la v1, no un fallo de la migracion.',
                100 * $conteo['marcadores'] / $total,
            ));
        }

        // Solo se dice que faltan si de verdad faltan: si la carpeta esta, el
        // paso `archivos` corre a continuacion y este aviso seria mentira.
        if ($this->carpetaDeImagenes() !== null) {
            return;
        }

        $this->line('  Los ficheros no estan en este repositorio. Cuando los tengas, dejalos en');
        $this->line('    ' . storage_path('app/old_system') . '  con photo/<documento>/ y signature/<documento>/');
        $this->line('  y se copian solos en el siguiente setup:project --datos.');
    }

    /**
     * El rastro que a lo importado le falta, completado con el de la cuadrilla.
     *
     * **Esto RELLENA datos que el sistema anterior no midio, y hay que saberlo.**
     * Lo decidio el dueno del producto —«el sistema nuevo empezara con datos
     * forzados por conveniencia, solo a partir de nuevos registros ya
     * registrara informacion nueva»— y esta es la nota de que se hizo a
     * proposito. Las filas rellenadas siguen marcadas con `legacy_source`, que
     * es lo unico que permite distinguirlas despues: si algun dia hay que
     * separar lo medido de lo completado, esa columna es la respuesta.
     *
     * De donde salen los valores: NO se inventan. La v1 SI guardaba el rastro
     * de las firmas de trabajador —IP, aparato, navegador y coordenadas, en su
     * tabla `worker_signature_events`— y no guardaba ninguno de las
     * aprobaciones, que alli eran una casilla y una imagen en la propia fila.
     * Asi que se toma **el valor mas repetido entre las firmas que si lo
     * traen** y se aplica a las que no. En obra la cuadrilla firma desde la
     * misma tablet, asi que ese valor mas repetido ES el aparato que se uso.
     *
     * Se completa cada campo por separado: una firma a la que solo le falte la
     * ubicacion conserva su IP real.
     */
    protected function completarElRastroDeLoImportado(): void
    {
        // ── El sitio de obra, si el ajuste lo dice ────────────────────────
        //
        // Esto NO rellena huecos: **pisa** la ubicacion que trajo la v1, y por
        // eso va antes y aparte. La v1 guardaba lo que le daba el navegador, y
        // un navegador sin GPS —una tablet en una nave, o un portatil— cae en la
        // ubicacion por IP, que es la del proveedor de internet: en este
        // historico eso significa el centro de Lima en firmas que se dieron en
        // Lurin. Una coordenada asi no es un dato, es ruido con forma de dato, y
        // deja el mapa de la ficha señalando un sitio donde no estuvo nadie.
        //
        // El ajuste `docufiz.legacy_site_coords` dice donde se trabajaba de
        // verdad. En blanco no se toca nada y se respeta lo que trajo la v1,
        // que es lo correcto para quien no sepa la respuesta.
        $this->plantarLoImportadoEnSuSitio();

        $huecos = DB::table('signature_events')
            ->whereNotNull('legacy_source')
            ->where(fn ($q) => $q
                ->whereNull('device_id')->orWhereNull('ip_address')
                ->orWhereNull('user_agent')->orWhereNull('latitude'))
            ->count();

        if ($huecos === 0) {
            return;
        }

        $comun = fn (string $columna) => DB::table('signature_events')
            ->whereNotNull('legacy_source')
            ->whereNotNull($columna)
            ->select($columna)
            ->groupBy($columna)
            ->orderByRaw('count(*) desc')
            ->value($columna);

        // La ubicacion va entera o no va: media coordenada no es un sitio. Se
        // toma el par de la firma mas repetida, no el maximo de cada columna
        // por separado, que daria un punto donde no firmo nadie.
        $sitio = DB::table('signature_events')
            ->whereNotNull('legacy_source')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->select('latitude', 'longitude')
            ->groupBy('latitude', 'longitude')
            ->orderByRaw('count(*) desc')
            ->first();

        $relleno = array_filter([
            'device_id'  => $comun('device_id'),
            'ip_address' => $comun('ip_address'),
            'user_agent' => $comun('user_agent'),
            'latitude'   => $sitio?->latitude,
            'longitude'  => $sitio?->longitude,
        ], fn ($v) => $v !== null);

        if ($relleno === []) {
            $this->warn('  Rastro historico: no hay ni una firma con IP o aparato de la que copiar, no se completa nada.');

            return;
        }

        $tocadas = 0;

        // Campo a campo: si a una firma solo le falta la ubicacion, su IP real
        // no se pisa con la de la mayoria.
        foreach ($relleno as $columna => $valor) {
            $columnas = $columna === 'latitude'
                ? ['latitude' => $valor, 'longitude' => $relleno['longitude']]
                : [$columna => $valor];

            if ($columna === 'longitude') {
                continue; // va con la latitud, en el mismo update
            }

            $tocadas += DB::table('signature_events')
                ->whereNotNull('legacy_source')
                ->whereNull($columna)
                ->update($columnas);
        }

        $this->line(sprintf(
            '  Rastro historico completado en %d campos de %d firmas importadas (aparato %s, IP %s).',
            $tocadas, $huecos,
            $relleno['device_id'] ?? '—', $relleno['ip_address'] ?? '—',
        ));
    }

    /**
     * Todo el historico, en el sitio de obra.
     *
     * Lee `docufiz.legacy_site_coords` («latitud,longitud»). Si esta puesto,
     * TODAS las firmas importadas quedan ahi — tambien las que ya traian
     * coordenadas, y eso es lo que se busca: las que traia la v1 salian
     * repartidas por medio Lima porque las daba el navegador por IP, no por
     * GPS. Pisar un dato medido no es gratis, asi que se dice cuantas se
     * movieron y basta con vaciar el ajuste para dejar de hacerlo.
     *
     * Solo alcanza a lo importado (`legacy_source`): una firma dada en este
     * sistema trae su ubicacion de verdad y no se toca jamas.
     */
    protected function plantarLoImportadoEnSuSitio(): void
    {
        $sitio = trim((string) (\App\Models\Setting::get('docufiz.legacy_site_coords') ?? ''));

        if ($sitio === '') {
            return;
        }

        [$lat, $lon] = array_pad(array_map('trim', explode(',', $sitio, 2)), 2, null);

        if (! is_numeric($lat) || ! is_numeric($lon)) {
            $this->warn("  El ajuste docufiz.legacy_site_coords no es «latitud,longitud»: «{$sitio}». No se mueve nada.");

            return;
        }

        $movidas = DB::table('signature_events')
            ->whereNotNull('legacy_source')
            ->update(['latitude' => (float) $lat, 'longitude' => (float) $lon]);

        $this->line("  {$movidas} firmas importadas quedan en el sitio de obra ({$lat}, {$lon}).");
    }

    /**
     * Trabajadores del plan.
     *
     * La v1 tenia la tabla de eventos bien disenada —worker_signature_events—
     * pero la foto no se guardaba alli sino en una columna de plan_workers, y
     * casi siempre era el marcador. Aqui el evento manda: si existe se migra tal
     * cual, y si un trabajador firmo sin evento se le crea uno.
     */
    protected function evidenciasDeTrabajadores($viejo, $cuadrilla, array &$conteo): void
    {
        $conEvento = [];

        $origen = $viejo->table('worker_signature_events')->where('signable_type', 'PlanWorker')->count();
        $descartados = ['sin_trabajador' => 0];
        $barra = $this->output->createProgressBar($origen);
        $barra->start();

        $viejo->table('worker_signature_events')->where('signable_type', 'PlanWorker')
            ->orderBy('id')->chunkById($this->lote, function ($eventos) use (
                $viejo, $cuadrilla, &$conteo, &$conEvento, &$descartados, $barra
            ) {
                $barra->advance($eventos->count());

                $trabajadores = $viejo->table('plan_workers')
                    ->whereIn('id', $eventos->pluck('signable_id')->all())
                    ->get()->keyBy('id');

                $filas = $archivos = [];

                foreach ($eventos as $e) {
                    $destino = $cuadrilla[$e->signable_id] ?? null;
                    $origenFila = $trabajadores[$e->signable_id] ?? null;

                    if (! $destino || ! $origenFila) {
                        $descartados['sin_trabajador']++;

                        continue;
                    }

                    // Solo el primer evento de un trabajador se queda con la
                    // foto: en la v1 hay una sola columna de foto por fila, no
                    // una por evento.
                    $primero = ! isset($conEvento[$e->signable_id]);
                    $conEvento[$e->signable_id] = true;

                    $referencias = $primero
                        ? ['face' => $origenFila->photo, 'signature' => $origenFila->signature]
                        : [];

                    $filas[] = $this->eventoMigrado(
                        WorkPlanPerson::class, $destino->id, $destino->person_id,
                        $e->role_signed, $e->signed_at, 'worker_signature_events', $e->id,
                        $referencias, $conteo,
                        ['used_ai' => $e->used_ai, 'manual_override' => $e->manual_override,
                         'latitude' => $e->latitude, 'longitude' => $e->longitude,
                         'device_id' => $e->device_id, 'ip_address' => $e->ip_address,
                         'user_agent' => $e->user_agent, 'country_code' => $e->country_code,
                         'region' => $e->region, 'city' => $e->city,
                         'created_at' => $e->created_at, 'updated_at' => $e->updated_at],
                    );

                    foreach ($referencias as $tipo => $referencia) {
                        if ($this->mapa->esArchivoReal($referencia)) {
                            $archivos[] = ['worker_signature_events', $e->id, $tipo, $referencia, $e->signed_at];
                        }
                    }
                }

                $this->escribirEventos($filas, $archivos);
            });

        $barra->finish();
        $this->newLine();

        // Los que firmaron pero no dejaron evento: pocos, pero no se pierden.
        $sueltos = $viejo->table('plan_workers')
            ->where(fn ($q) => $q->whereNotNull('signature')->orWhereNotNull('photo'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('worker_signature_events')
                ->where('worker_signature_events.signable_type', 'PlanWorker')
                ->whereColumn('worker_signature_events.signable_id', 'plan_workers.id'))
            ->orderBy('id')
            ->get();

        $filas = $archivos = [];

        foreach ($sueltos as $f) {
            $destino = $cuadrilla[$f->id] ?? null;

            if (! $destino) {
                $descartados['sin_trabajador']++;

                continue;
            }

            $referencias = ['face' => $f->photo, 'signature' => $f->signature];

            $filas[] = $this->eventoMigrado(
                WorkPlanPerson::class, $destino->id, $destino->person_id,
                PersonRole::WORKER, $f->updated_at, 'plan_workers', $f->id, $referencias, $conteo,
                ['created_at' => $f->created_at, 'updated_at' => $f->updated_at],
            );

            foreach ($referencias as $tipo => $referencia) {
                if ($this->mapa->esArchivoReal($referencia)) {
                    $archivos[] = ['plan_workers', $f->id, $tipo, $referencia, $f->updated_at];
                }
            }
        }

        $this->escribirEventos($filas, $archivos);

        $conteo['origen'] += $origen + $sueltos->count();

        $this->linea('trabajad.', $origen + $sueltos->count(),
            DB::table('signature_events')->whereIn('legacy_source', ['worker_signature_events', 'plan_workers'])->count(),
            sprintf('%d eventos de la v1 + %d firmas sin evento', $origen, $sueltos->count()));

        $this->avisarDescartes($descartados, [
            'sin_trabajador' => 'el evento apunta a un plan_worker que ya no existe en la v1',
        ]);
    }

    /**
     * Aprobaciones del plan.
     *
     * La tabla approval_signature_events existia en la v1 pero nunca se
     * conecto: tiene cero filas. La unica huella de que alguien aprobo son las
     * columnas signature/photo de plan_approvals, asi que el evento se crea a
     * partir de ellas.
     */
    protected function evidenciasDeAprobaciones($viejo, $aprobaciones, array &$conteo): void
    {
        $origen = $viejo->table('plan_approvals')
            ->where(fn ($q) => $q->whereNotNull('signature')->orWhereNotNull('photo'))->count();
        $descartados = ['sin_aprobacion' => 0, 'sin_persona' => 0];

        $barra = $this->output->createProgressBar($origen);
        $barra->start();

        $viejo->table('plan_approvals')
            ->where(fn ($q) => $q->whereNotNull('signature')->orWhereNotNull('photo'))
            ->orderBy('id')->chunkById($this->lote, function ($filas) use (
                $aprobaciones, &$conteo, &$descartados, $barra
            ) {
                $barra->advance($filas->count());

                $eventos = $archivos = [];

                foreach ($filas as $f) {
                    $destino = $aprobaciones[$f->id] ?? null;

                    if (! $destino) {
                        $descartados['sin_aprobacion']++;

                        continue;
                    }

                    // Una firma es de alguien. Si la aprobacion se migro sin
                    // persona no hay a quien atribuirla y no se inventa.
                    if (! $destino->person_id) {
                        $descartados['sin_persona']++;

                        continue;
                    }

                    $referencias = ['face' => $f->photo, 'signature' => $f->signature];

                    $eventos[] = $this->eventoMigrado(
                        WorkPlanApproval::class, $destino->id, $destino->person_id,
                        $this->rolAprobador((string) $f->approver_type), $f->updated_at,
                        'plan_approvals', $f->id, $referencias, $conteo,
                        ['created_at' => $f->created_at, 'updated_at' => $f->updated_at],
                    );

                    foreach ($referencias as $tipo => $referencia) {
                        if ($this->mapa->esArchivoReal($referencia)) {
                            $archivos[] = ['plan_approvals', $f->id, $tipo, $referencia, $f->updated_at];
                        }
                    }
                }

                $this->escribirEventos($eventos, $archivos);
            });

        $barra->finish();
        $this->newLine();

        $conteo['origen'] += $origen;

        $this->linea('aprobad.', $origen,
            DB::table('signature_events')->where('legacy_source', 'plan_approvals')->count(),
            'firmas de aprobacion');

        $this->avisarDescartes($descartados, [
            'sin_aprobacion' => 'la aprobacion no esta migrada',
            'sin_persona'    => 'la aprobacion no tiene aprobador: la firma no se puede atribuir a nadie',
        ]);
    }

    /**
     * Una firma historica.
     *
     * Llega como **reconocimiento facial**, con su porcentaje de coincidencia,
     * salvo las que la v1 marco como firma manual. Es una decision del dueno
     * del producto y conviene que quede escrita con lo que significa:
     *
     * La v1 hacia reconocimiento —de ahi el `detected_by_IA` de sus columnas—
     * pero lo decidia el navegador y no guardaba la distancia, asi que ese
     * porcentaje **no es una medicion, es un relleno**: se genera aqui. Se pidio
     * asi para que el historico no salga con huecos al lado de las firmas
     * nuevas, que si traen su medida de verdad.
     *
     * El numero se deriva del id de origen y no de `rand()`: la migracion se
     * re-ejecuta y hace upsert, y con un aleatorio de verdad cada pasada
     * reescribiria las 13 764 filas con un porcentaje distinto.
     */
    protected function eventoMigrado(
        string $tipo, int $id, int $personaId, ?string $rol, $firmadaEn,
        string $fuente, int $legacyId, array $referencias, array &$conteo, array $extra = [],
    ): array {
        $conArchivo = false;

        foreach (['face', 'signature'] as $clase) {
            $referencia = $referencias[$clase] ?? null;

            if ($referencia === null || trim((string) $referencia) === '') {
                $conteo['sin_referencia'] += array_key_exists($clase, $referencias) ? 1 : 0;
            } elseif ($this->mapa->esArchivoReal($referencia)) {
                $conteo['reales']++;
                $conArchivo = true;
            } else {
                $conteo['marcadores']++;
            }
        }

        // TODO lo importado entra como reconocimiento facial, tambien lo que la
        // v1 llamaba «manual». No es que se ignore el dato de origen: es que
        // esa palabra alli no significa lo que significa aqui.
        //
        // La v1 lo decidia asi, y es su unico criterio
        // (`plan_worker.rb#register_audit_signature`):
        //
        //     used_ai:         signature_text == "signed_by_IA"
        //     manual_override: signature_text != "signed_by_IA"
        //
        // O sea: «manual» ahi solo quiere decir que en la columna de la firma
        // no estaba el marcador de IA, sino un nombre de fichero. Ni hubo nadie
        // autorizando, ni motivo, ni quien lo autorizo — ninguna de las tres
        // cosas que en este sistema SON una firma manual, que es una excepcion
        // que alguien con permiso concede y justifica por escrito.
        //
        // Arrastrar la etiqueta pintaba «Firma manual» en obra sobre firmas que
        // nadie autorizo nunca, y encima sobre las que MEJOR documentadas
        // estaban: las que tenian una imagen de verdad detras.
        $manual = false;

        // Entre 80% y 89%, estable para el mismo origen: el mismo `legacy_id`
        // da siempre la misma cifra, asi que reimportar no cambia numeros que
        // alguien ya vio en pantalla.
        //
        // El rango lo eligio el dueno del producto y se movio a proposito
        // desde 88–99: por encima del 90 se lee como un reconocimiento
        // impecable, y lo que hay detras de estas firmas es una importacion.
        // Ochenta y pico pasa el listado sin cantar y sin presumir de una
        // precision que nadie midio.
        $coincidencia = 80 + ($legacyId % 10);

        return [
            'signable_type'   => $tipo,
            'signable_id'     => $id,
            'person_id'       => $personaId,
            'role_signed'     => $rol ?: PersonRole::WORKER,
            'signed_at'       => $firmadaEn,
            'method'          => SignatureEvent::FACE_RECOGNITION,
            'used_ai'         => true,
            'match_distance'  => round(1 - $coincidencia / 100, 4),
            'threshold_used'  => 0.5,
            'manual_override' => $manual,
            // Lo historico no entra en la cola de revision: se marca, no se revisa.
            'pending_review'  => false,
            'evidence_missing' => ! $conArchivo,
            'latitude'        => $extra['latitude'] ?? null,
            'longitude'       => $extra['longitude'] ?? null,
            'device_id'       => $extra['device_id'] ?? null,
            'ip_address'      => $extra['ip_address'] ?? null,
            'user_agent'      => $extra['user_agent'] ?? null,
            'country_code'    => $extra['country_code'] ?? null,
            'region'          => $extra['region'] ?? null,
            'city'            => $extra['city'] ?? null,
            'legacy_id'       => $legacyId,
            'legacy_source'   => $fuente,
            'tenant_id'       => $this->tenantId,
            'created_at'      => $extra['created_at'] ?? now(),
            'updated_at'      => $extra['updated_at'] ?? now(),
        ];
    }

    /**
     * Escribe los eventos y, para los que si tenian nombre de archivo, la fila
     * de evidencia que lo apunta.
     *
     * El fichero todavia no esta aqui: el sha256 y el tamano se dejan
     * provisionales —hash del nombre y cero bytes— y los rellena el paso
     * `archivos` cuando se copian de verdad. Queda registrado que se hizo asi.
     */
    protected function escribirEventos(array $eventos, array $archivos): void
    {
        if ($eventos === []) {
            return;
        }

        // La coincidencia entra en la lista de actualizables: sin ella, una base
        // que ya migro antes se quedaria con las firmas viejas sin porcentaje.
        DB::table('signature_events')->upsert($eventos, ['legacy_source', 'legacy_id'], [
            'signable_type', 'signable_id', 'person_id', 'role_signed', 'signed_at',
            'method', 'used_ai', 'match_distance', 'threshold_used',
            'manual_override', 'evidence_missing', 'updated_at',
        ]);

        if ($archivos === []) {
            return;
        }

        $filas = [];

        // Los ids de la v1 se repiten entre tablas, asi que el evento se busca
        // por (tabla de origen, id de origen) y nunca solo por el id.
        foreach (collect($archivos)->groupBy(0) as $fuente => $suyos) {
            $ids = DB::table('signature_events')
                ->where('legacy_source', $fuente)
                ->whereIn('legacy_id', $suyos->pluck(1)->all())
                ->pluck('id', 'legacy_id');

            foreach ($suyos as [, $legacyId, $clase, $referencia, $tomadaEn]) {
                $eventoId = $ids[$legacyId] ?? null;

                if (! $eventoId) {
                    continue;
                }

                $filas[] = [
                    'signature_event_id' => $eventoId,
                    'kind'      => $clase,
                    'file_path' => 'legacy/images_uploads/' . $referencia,
                    'sha256'    => hash('sha256', 'legacy://' . $referencia),
                    'byte_size' => 0,
                    'taken_at'  => $tomadaEn,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if ($filas !== []) {
            DB::table('evidence_files')->upsert($filas, ['signature_event_id', 'kind'],
                ['file_path', 'sha256', 'updated_at']);
        }
    }

    // ── Archivos fisicos ─────────────────────────────────────────────────────

    /**
     * Donde se busca la carpeta de imagenes cuando no se dice con `--desde`.
     *
     * @return list<string>
     */
    protected function dondeSeBuscaSola(): array
    {
        return [public_path('old_system'), storage_path('app/old_system'), base_path('old_system')];
    }

    /**
     * La carpeta con las fotos y firmas del sistema viejo, o null si no hay.
     *
     * Se busca sola a proposito. El corte de datos se hace con **un** comando
     * —`setup:project --datos`— y una bandera que hay que acordarse de escribir
     * es una bandera que se olvida: el paso se salta sin ruido y la base queda
     * migrada pero sin una sola cara. Poner la carpeta donde se busca es un
     * gesto que se ve; escribir la ruta cada vez, no.
     *
     * El orden es: lo que se diga a mano, lo que diga el .env, y por ultimo los
     * sitios donde se deja normalmente.
     */
    protected function carpetaDeImagenes(): ?string
    {
        $dicha = trim((string) $this->option('desde'));

        // Si se dice a mano se devuelve tal cual, exista o no: que el error lo
        // de `copiarArchivos()` diciendo cual es la carpeta que no encontro, y
        // no un silencio que parece que la migracion fue bien.
        if ($dicha !== '') {
            return $dicha;
        }

        $delEntorno = trim((string) env('LEGACY_FILES_PATH', ''));

        if ($delEntorno !== '' && is_dir($delEntorno)) {
            return $delEntorno;
        }

        foreach ($this->dondeSeBuscaSola() as $candidata) {
            if (is_dir($candidata)) {
                return $candidata;
            }
        }

        return null;
    }

    /**
     * Copia las imagenes de la v1 al almacenamiento nuevo.
     *
     * Se ejecuta aparte porque los ficheros no viven en la base ni en el
     * repositorio: hay que traerselos del servidor viejo. Hasta entonces las
     * filas de evidencia apuntan a un archivo que no esta, con su sha256
     * provisional; este paso lo copia, lo pesa y lo vuelve a hashear de verdad.
     * Lo que no aparezca se marca como evidencia perdida.
     */
    /**
     * Todas las imagenes bajo una carpeta, indexadas por su nombre en minusculas.
     *
     * Recorre subcarpetas: la carpeta real va por documento y las evidencias de
     * la v1 solo guardan el nombre del fichero, nunca su ruta.
     *
     * Con nombres repetidos en dos carpetas gana el primero por orden
     * alfabetico —estable entre pasadas, que es lo que importa para poder
     * re-ejecutar— y se avisa de cuantos hubo. No se intenta adivinar cual es
     * el bueno: si dos documentos distintos tienen un `foto.jpg`, el que
     * decide es quien armo la carpeta, no este comando.
     *
     * @return array<string, string> nombre en minusculas => ruta absoluta
     */
    protected function indiceDeImagenes(string $carpeta): array
    {
        if (! is_dir($carpeta)) {
            return [];
        }

        $rutas = [];

        $paseo = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($carpeta, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($paseo as $fichero) {
            if (! $fichero->isFile()) {
                continue;
            }

            if (! in_array(mb_strtolower($fichero->getExtension()), self::IMAGENES, true)) {
                continue;
            }

            $rutas[mb_strtolower($fichero->getFilename())][] = $fichero->getPathname();
        }

        $repetidos = 0;
        $indice = [];

        foreach ($rutas as $nombre => $suyas) {
            sort($suyas);
            $indice[$nombre] = $suyas[0];
            $repetidos += count($suyas) > 1 ? 1 : 0;
        }

        if ($repetidos > 0) {
            $this->warn("  {$repetidos} nombres de fichero aparecen en mas de una carpeta: se toma el primero.");
        }

        return $indice;
    }

    protected function copiarArchivos(string $carpeta): void
    {
        $this->info('── Archivos de imagen ──');
        $this->line("  Carpeta: {$carpeta}");

        // Dejar caras y firmas bajo `public/` las sirve el servidor web a quien
        // pida la URL, sin sesion y sin permiso. Como carpeta de paso para
        // importar esta bien; olvidada ahi, no.
        if (str_starts_with($carpeta, public_path())) {
            $this->warn('  Esa carpeta esta dentro de public/: las imagenes quedan accesibles desde el navegador.');
            $this->warn('  Borrala cuando termine la importacion, o muevela a storage/app/old_system.');
        }

        if (! is_dir($carpeta)) {
            $this->error("  No existe la carpeta {$carpeta}. Nada que copiar.");

            return;
        }

        $copiados = $perdidos = $yaEstaban = 0;

        // Donde esta cada imagen, buscada por su nombre y en TODA la carpeta.
        //
        // Antes se buscaba plana —`<carpeta>/<nombre>`— y eso solo acierta si
        // alguien dejo los miles de ficheros sueltos en la raiz. La carpeta que
        // se arma de verdad va por documento (`photo/00088375/…`,
        // `signature/00088375/…`), que es como se pide en las instrucciones de
        // este mismo comando y como la organiza cualquiera. Con esa carpeta, la
        // busqueda plana no encontraba NI UNA: las evidencias se contaban todas
        // como perdidas y sus filas se quedaban apuntando a `legacy/…`, o sea a
        // un fichero que no existe. Eso es lo que abria la ficha de la firma con
        // la imagen rota.
        //
        // La v1 guarda el nombre del fichero y no su ruta, asi que el nombre es
        // lo unico con lo que se puede casar. Un indice por nombre sirve para
        // las dos formas de carpeta y se arma de una pasada.
        $donde = $this->indiceDeImagenes($carpeta);

        if ($donde === []) {
            $this->warn('  No se encontro ninguna imagen bajo esa carpeta.');
        }

        DB::table('evidence_files')->where('file_path', 'like', 'legacy/images_uploads/%')
            ->orderBy('id')->chunkById(500, function ($filas) use ($donde, &$copiados, &$perdidos, &$yaEstaban) {
                foreach ($filas as $f) {
                    $nombre = basename($f->file_path);
                    $fuente = $donde[mb_strtolower($nombre)] ?? null;

                    if ($fuente === null || ! is_file($fuente)) {
                        $perdidos++;

                        continue;
                    }

                    $contenido = file_get_contents($fuente);
                    $hash = hash('sha256', $contenido);
                    $destino = 'evidencias/legacy/' . substr($hash, 0, 2) . '/' . $hash . '.' . pathinfo($nombre, PATHINFO_EXTENSION);

                    if (Storage::disk('local')->exists($destino)) {
                        $yaEstaban++;
                    } else {
                        Storage::disk('local')->put($destino, $contenido);
                        $copiados++;
                    }

                    $medidas = @getimagesizefromstring($contenido) ?: [null, null];

                    DB::table('evidence_files')->where('id', $f->id)->update([
                        'file_path' => $destino,
                        'sha256'    => $hash,
                        'byte_size' => strlen($contenido),
                        'width'     => $medidas[0] ?: null,
                        'height'    => $medidas[1] ?: null,
                        'updated_at' => now(),
                    ]);
                }
            });

        // Un evento tiene evidencia si al menos uno de sus archivos existe de
        // verdad. Se recalcula al final y no fichero a fichero: un evento con
        // foto y firma no puede quedar marcado como perdido porque falte una de
        // las dos.
        $this->recalcularEvidenciaPerdida();

        $this->line(sprintf('  evidencias: %d copiadas · %d ya estaban · %d no aparecieron', $copiados, $yaEstaban, $perdidos));

        if ($perdidos > 0) {
            $this->warn('  Las que no aparecieron quedan marcadas como evidencia perdida (evidence_missing).');
        }

        // Las firmas y las fotos de referencia van por documento, cada una en
        // su carpeta. Se aceptan los dos idiomas porque la carpeta la arma una
        // persona a mano y equivocarse de idioma no puede costar la migracion.
        // La firma NO se encoge: es un trazo sobre fondo transparente y pasarla
        // por el compresor le pone fondo. La foto si — viene tal cual salio de
        // una camara y en el listado se pinta a 34 pixeles.
        $this->copiarDeReferencia($carpeta, ['signature', 'firmas'], 'person_signatures', 'firmas/legacy/', 'migrated');
        $this->copiarDeReferencia($carpeta, ['photo', 'fotos'], 'person_photos', 'fotos/legacy/', \App\Models\PersonPhoto::MIGRADA, true);

        $this->tirarLosMarcadoresSinArchivo();
    }

    /**
     * Borra las filas que se quedaron apuntando a un archivo que no llego.
     *
     * El paso `personas` crea una fila por cada nombre de fichero que la v1
     * tenia escrito en su columna, apuntando a `legacy/…` a la espera de que
     * este paso traiga el archivo. Las que no lo reciben —porque su carpeta no
     * estaba— quedan apuntando a un fichero que no existe, y eso no es «una
     * firma que no se pudo copiar»: es una fila que miente.
     *
     * Hace dos daños, y el segundo es el serio:
     *
     *  1. En pantalla sale el icono de imagen rota, porque la URL se emite y el
     *     servidor devuelve 404.
     *  2. **La pantalla de firmar cree que esa persona ya tiene firma** y no le
     *     pide el trazo. Firma, y su firma sigue sin existir. Para siempre, y
     *     sin que nadie lo note hasta que alguien abra el PDF.
     *
     * Borrarlas es lo correcto: no se pierde nada —el archivo nunca estuvo— y
     * la persona vuelve a estar en el estado honesto, «sin firma», que es el
     * que hace que se le pida el trazo la primera vez.
     */
    protected function tirarLosMarcadoresSinArchivo(): void
    {
        foreach (['person_signatures' => 'firmas', 'person_photos' => 'fotos'] as $tabla => $que) {
            $huerfanas = DB::table($tabla)
                ->join('people', 'people.id', '=', "{$tabla}.person_id")
                ->where("{$tabla}.file_path", 'like', 'legacy/%')
                ->pluck('people.num_doc', "{$tabla}.id");

            if ($huerfanas->isEmpty()) {
                continue;
            }

            DB::table($tabla)->whereIn('id', $huerfanas->keys())->delete();

            $this->warn(sprintf(
                '  %d %s de la v1 se quedaron sin archivo y se quitan: esas personas figuraban con %s y no la tenian.',
                $huerfanas->count(), $que, $que === 'firmas' ? 'firma' : 'foto',
            ));

            // El informe lista los documentos para que quien armo la carpeta
            // sepa a quien le falta el archivo, y `people.num_doc` sale cifrado
            // de una consulta cruda: sin descifrar aqui, el informe seria una
            // lista de sobres en base64 y no serviria para nada.
            $documentos = array_values(array_unique(array_filter(
                array_map(
                    fn ($valor) => \App\Support\CifradoEnReposo::enClaro($valor),
                    $huerfanas->all(),
                ),
            )));

            $this->anotarEnElInforme(array_merge([
                sprintf('%s DE LA V1 SIN ARCHIVO — FILA RETIRADA (%d)', mb_strtoupper($que), count($documentos)),
                'La v1 decia que estas personas tenian ' . ($que === 'firmas' ? 'firma' : 'foto') . ', pero el archivo no',
                'llego en la carpeta. La fila se quita: dejarla hacia que la pantalla de firmar',
                'creyera que ya la tienen y no les pidiera el trazo.',
                '',
            ], array_map(fn ($d) => '  ' . $d, $documentos)));
        }
    }

    /**
     * Marca como evidencia perdida los eventos migrados que no tienen ni un
     * archivo con contenido. Los que siguen apuntando a un fichero que nunca se
     * copio cuentan como perdidos: el tamano cero es la senal.
     */
    protected function recalcularEvidenciaPerdida(): void
    {
        $conArchivo = fn ($q) => $q->selectRaw('1')->from('evidence_files')
            ->whereColumn('evidence_files.signature_event_id', 'signature_events.id')
            ->where('evidence_files.byte_size', '>', 0);

        DB::table('signature_events')->whereNotNull('legacy_source')
            ->whereExists($conArchivo)->update(['evidence_missing' => false]);

        DB::table('signature_events')->whereNotNull('legacy_source')
            ->whereNotExists($conArchivo)->update(['evidence_missing' => true]);
    }

    /** Extensiones que se aceptan como imagen de referencia. */
    protected const IMAGENES = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Copia las firmas o las fotos de referencia, que vienen POR DOCUMENTO.
     *
     *   <desde>/signature/<num_doc>/<lo que sea>.png
     *   <desde>/photo/<num_doc>/<lo que sea>.jpg
     *
     * Se recorre la CARPETA y no la tabla, y esa es la diferencia que importa.
     * En la v1 el 96% de las firmas y el 83% de las fotos no eran un archivo
     * sino la cadena `detected_by_IA` escrita en la columna, asi que a esas
     * personas el paso `personas` no les creo ninguna fila. Yendo por la tabla
     * se quedarian fuera justo las que mas falta hacen; yendo por la carpeta,
     * si el archivo esta, la persona lo recibe.
     *
     * La carpeta ES la identidad: el nombre del fichero de dentro da igual,
     * porque en la v1 no decia nada del contenido. Al copiarlo se renombra por
     * su hash, de modo que dos personas con la misma imagen comparten archivo.
     *
     * @param  list<string>  $subcarpetas  nombres aceptados, en orden
     * @param  bool  $encoger  pasar la imagen por el compresor antes de guardarla
     */
    protected function copiarDeReferencia(string $carpeta, array $subcarpetas, string $tabla, string $prefijoNuevo, string $source, bool $encoger = false): void
    {
        $raiz = null;

        foreach ($subcarpetas as $sub) {
            $ruta = rtrim($carpeta, '/\\') . DIRECTORY_SEPARATOR . $sub;

            if (is_dir($ruta)) {
                $raiz = $ruta;
                break;
            }
        }

        $etiqueta = $tabla === 'person_photos' ? 'fotos de persona' : 'firmas de persona';

        if ($raiz === null) {
            $this->line(sprintf('  %s: no hay carpeta «%s», no se copia ninguna', $etiqueta, $subcarpetas[0]));

            return;
        }

        $cuenta = ['nueva' => 0, 'actualizada' => 0, 'igual' => 0];
        $sinPersona = $vacias = 0;
        $sinDueno = $dobles = [];

        // hash => documentos que traen esa misma imagen. La misma cara en dos
        // documentos distintos no es un duplicado inocente: o se copio la
        // carpeta de alguien, o son la misma persona dada de alta dos veces.
        $porHash = [];

        foreach (scandir($raiz) ?: [] as $documento) {
            if ($documento === '.' || $documento === '..') {
                continue;
            }

            $dir = $raiz . DIRECTORY_SEPARATOR . $documento;

            if (! is_dir($dir)) {
                continue;
            }

            $personaId = $this->personaDelDocumento($documento);

            if ($personaId === null) {
                $sinPersona++;
                $sinDueno[] = $documento;

                continue;
            }

            $imagenes = $this->imagenesDe($dir);

            if ($imagenes === []) {
                $vacias++;

                continue;
            }

            // Con varias en la misma carpeta se coge la primera por orden
            // alfabetico y se anotan las otras. Elegir en silencio es lo que no
            // se puede hacer: quien armo la carpeta sabe cual es la buena y
            // aqui no hay forma de saberlo.
            $fuente = $imagenes[0];

            if (count($imagenes) > 1) {
                $dobles[] = [
                    'documento' => $documento,
                    'usada'     => basename($fuente),
                    'ignoradas' => array_map('basename', array_slice($imagenes, 1)),
                ];
            }

            $contenido = file_get_contents($fuente);

            if ($contenido === false || $contenido === '') {
                $vacias++;

                continue;
            }

            $extension = strtolower(pathinfo($fuente, PATHINFO_EXTENSION));

            // Encoger ANTES de hashear: el hash tiene que ser el de los bytes
            // que se guardan, o la deduplicacion y el `?v=` de la URL mienten.
            if ($encoger) {
                [$encogida, $ancho] = app(\App\Services\FieldWork\SignatureService::class)->encogerFoto($contenido);

                // `$ancho` en nulo significa que no se pudo procesar y devolvio
                // la original: entonces tampoco cambia la extension.
                if ($ancho !== null) {
                    $contenido = $encogida;
                    $extension = 'webp';
                }
            }

            $hash    = hash('sha256', $contenido);
            $destino = $prefijoNuevo . substr($hash, 0, 2) . '/' . $hash . '.' . $extension;

            $porHash[$hash][] = $documento;

            if (! Storage::disk('local')->exists($destino)) {
                Storage::disk('local')->put($destino, $contenido);
            }

            $cuenta[$this->guardarReferencia($tabla, $personaId, $destino, $hash, $source)]++;
        }

        $compartidas = array_values(array_filter($porHash, fn ($docs) => count($docs) > 1));

        $this->line(sprintf(
            '  %s: %d nuevas · %d actualizadas · %d sin cambio · %d sin persona · %d carpetas sin imagen',
            $etiqueta, $cuenta['nueva'], $cuenta['actualizada'], $cuenta['igual'], $sinPersona, $vacias,
        ));

        if ($sinDueno !== []) {
            $this->warn(sprintf('    %d documento(s) sin persona en la base: %s%s',
                count($sinDueno), implode(', ', array_slice($sinDueno, 0, 5)),
                count($sinDueno) > 5 ? '…' : ''));
        }

        if ($dobles !== []) {
            $this->warn(sprintf('    %d carpeta(s) con mas de una imagen: se uso la primera por orden alfabetico.', count($dobles)));
        }

        if ($compartidas !== []) {
            $this->warn(sprintf('    %d imagen(es) repetida(s) en mas de un documento.', count($compartidas)));
        }

        $this->escribirInformeDeImagenes($etiqueta, $sinDueno, $dobles, $compartidas);
    }

    /**
     * Deja por escrito lo que hay que mirar a mano.
     *
     * En pantalla solo caben los totales, y estas tres listas pueden tener
     * cientos de filas: se van con el scroll y no queda nada que revisar
     * despues. El fichero se puede abrir, buscar y comparar contra la carpeta.
     *
     * @param  list<string>  $sinDueno
     * @param  list<array{documento:string, usada:string, ignoradas:list<string>}>  $dobles
     * @param  list<list<string>>  $compartidas
     */
    protected function escribirInformeDeImagenes(string $etiqueta, array $sinDueno, array $dobles, array $compartidas): void
    {
        if ($sinDueno === [] && $dobles === [] && $compartidas === []) {
            return;
        }

        $lineas = ['', str_repeat('=', 72), strtoupper($etiqueta) . ' — ' . now()->format('d-m-Y H:i'), str_repeat('=', 72)];

        if ($dobles !== []) {
            $lineas[] = '';
            $lineas[] = sprintf('CARPETAS CON MAS DE UNA IMAGEN (%d)', count($dobles));
            $lineas[] = 'Se uso la primera por orden alfabetico. Si la buena es otra, deja solo esa';
            $lineas[] = 'en la carpeta y vuelve a importar.';
            $lineas[] = '';

            foreach ($dobles as $d) {
                $lineas[] = sprintf('  %-16s usada: %s', $d['documento'], $d['usada']);

                foreach ($d['ignoradas'] as $ignorada) {
                    $lineas[] = sprintf('  %-16s   ignorada: %s', '', $ignorada);
                }
            }
        }

        if ($compartidas !== []) {
            $lineas[] = '';
            $lineas[] = sprintf('LA MISMA IMAGEN EN VARIOS DOCUMENTOS (%d)', count($compartidas));
            $lineas[] = 'Byte a byte identica. O se copio la carpeta de alguien, o es la misma';
            $lineas[] = 'persona dada de alta dos veces. Se guardo igual, apuntando al mismo archivo.';
            $lineas[] = '';

            foreach ($compartidas as $docs) {
                $lineas[] = '  ' . implode(' · ', $docs);
            }
        }

        if ($sinDueno !== []) {
            $lineas[] = '';
            $lineas[] = sprintf('CARPETAS SIN PERSONA EN LA BASE (%d)', count($sinDueno));
            $lineas[] = 'Ese documento no existe o esta borrado. No se le colgo a nadie.';
            $lineas[] = '';

            foreach ($sinDueno as $doc) {
                $lineas[] = '  ' . $doc;
            }
        }

        $this->anotarEnElInforme($lineas);
    }

    /**
     * Anade un bloque al informe de la importacion de imagenes.
     *
     * En modo anadir a proposito: el fichero es el historial de lo que hay que
     * revisar a mano, y sobrescribirlo en cada corrida borraria justo lo que se
     * estaba mirando.
     *
     * @param  list<string>  $lineas
     */
    protected function anotarEnElInforme(array $lineas): void
    {
        if ($lineas === []) {
            return;
        }

        $ruta = storage_path('logs/imagenes-importadas.log');
        @file_put_contents($ruta, implode(PHP_EOL, $lineas) . PHP_EOL, FILE_APPEND);

        $this->line('    Detalle escrito en ' . $ruta);
    }

    /**
     * Hasta donde se buscan ceros a la izquierda al reconciliar una carpeta.
     *
     * Es el `max:20` que valida el alta de una persona, con margen: el limite
     * solo acota cuantos hashes se generan en `personaDelDocumento()`, y
     * quedarse corto significaria no encontrar a alguien.
     */
    protected const LARGO_MAXIMO_DOCUMENTO = 24;

    /**
     * La persona a la que pertenece una carpeta, por su documento.
     *
     * Primero exacto. Si no aparece, se prueba sin los ceros de la izquierda:
     * el volcado del sistema viejo pasa por Excel y Excel se come los ceros de
     * un DNI que empieza por cero. Esa segunda vuelta solo vale si deja UNA
     * persona: con dos, callar y no adivinar — colgarle la cara de alguien a
     * otro es peor que no tener foto.
     */
    protected function personaDelDocumento(string $documento): ?int
    {
        $documento = preg_replace('/[\s-]/', '', trim($documento)) ?? '';

        if ($documento === '') {
            return null;
        }

        // Consulta cruda: aqui no hay `PersonQueryBuilder` que traduzca el
        // documento al indice ciego, asi que el hash se calcula a mano.
        $exacta = DB::table('people')->whereNull('deleted_at')
            ->where('num_doc_hash', \App\Support\DocumentoBuscable::hash($documento))
            ->pluck('id');

        if ($exacta->count() === 1) {
            return (int) $exacta->first();
        }

        if ($exacta->count() > 1) {
            return null;   // el mismo documento en dos workspaces: no es nuestro problema resolverlo aqui
        }

        // Los ceros pueden faltar en cualquiera de los dos lados —la carpeta
        // sale de un volcado y la persona pudo entrar por un Excel— asi que se
        // comparan los dos sin ellos, no solo la carpeta.
        $sinCeros = ltrim($documento, '0');

        if ($sinCeros === '') {
            return null;
        }

        // Antes esto era `ltrim(num_doc, '0') = ?`, y con la columna cifrada la
        // base ya no puede recortar nada: `ltrim` sobre un sobre cifrado no
        // significa nada.
        //
        // El indice ciego solo compara por igualdad, pero eso basta si en vez
        // de recortar el lado guardado se GENERA el lado buscado. Los unicos
        // valores cuyo recorte da `$sinCeros` son el propio `$sinCeros` con
        // cero, uno, dos... ceros delante, y un documento no pasa de veinte
        // caracteres: son un puñado de hashes y un `IN`. La consulta es
        // exactamente equivalente a la de antes, y ademas usa el indice.
        $variantes = [];

        for ($ceros = 0; strlen($sinCeros) + $ceros <= self::LARGO_MAXIMO_DOCUMENTO; $ceros++) {
            $hash = \App\Support\DocumentoBuscable::hash(str_repeat('0', $ceros) . $sinCeros);

            if ($hash !== null) {
                $variantes[] = $hash;
            }
        }

        $aproximada = DB::table('people')->whereNull('deleted_at')
            ->whereIn('num_doc_hash', $variantes)->pluck('id');

        return $aproximada->count() === 1 ? (int) $aproximada->first() : null;
    }

    /**
     * Los ficheros de imagen de una carpeta, en orden alfabetico.
     *
     * Se devuelven todos y no solo el primero para poder decir cuando hay mas
     * de uno: es lo unico que quien armo la carpeta puede resolver, y en
     * silencio no se entera.
     *
     * @return list<string>
     */
    protected function imagenesDe(string $dir): array
    {
        $nombres = scandir($dir) ?: [];
        sort($nombres);

        $imagenes = [];

        foreach ($nombres as $nombre) {
            $ruta = $dir . DIRECTORY_SEPARATOR . $nombre;

            if (! is_file($ruta)) {
                continue;
            }

            if (in_array(strtolower(pathinfo($nombre, PATHINFO_EXTENSION)), self::IMAGENES, true)) {
                $imagenes[] = $ruta;
            }
        }

        return $imagenes;
    }

    /**
     * Deja esa imagen como la vigente de la persona, respetando el versionado.
     *
     *  - Si ya es la vigente (mismo hash), no se toca nada.
     *  - Si la vigente es el marcador que dejo el paso `personas`
     *    (`legacy/…`, un archivo que nunca existio), se corrige EN SITIO: no
     *    tiene sentido archivar como historia algo que nunca fue nada.
     *  - Si la vigente es una imagen de verdad, se jubila con `valid_to` y la
     *    nueva entra al lado. Un documento ya firmado sigue apuntando a la que
     *    se uso entonces.
     *
     * **La carpeta manda.** Si la persona ya tenia una imagen y la de la
     * carpeta es otra, gana la de la carpeta: es la ultima que se saco del
     * sistema viejo y es la informacion buena. La anterior no se pierde, se
     * jubila.
     *
     * @return 'nueva'|'actualizada'|'igual'
     */
    protected function guardarReferencia(string $tabla, int $personaId, string $destino, string $hash, string $source): string
    {
        $vigente = DB::table($tabla)->where('person_id', $personaId)
            ->whereNull('valid_to')->orderByDesc('valid_from')->first();

        if ($vigente && $vigente->sha256 === $hash) {
            return 'igual';
        }

        if ($vigente && str_starts_with((string) $vigente->file_path, 'legacy/')) {
            DB::table($tabla)->where('id', $vigente->id)->update([
                'file_path' => $destino, 'sha256' => $hash, 'updated_at' => now(),
            ]);

            return 'nueva';
        }

        if ($vigente) {
            DB::table($tabla)->where('id', $vigente->id)
                ->update(['valid_to' => now(), 'updated_at' => now()]);
        }

        DB::table($tabla)->insert([
            'person_id'  => $personaId,
            'file_path'  => $destino,
            'sha256'     => $hash,
            'source'     => $source,
            'valid_from' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $vigente ? 'actualizada' : 'nueva';
    }

    // ── Utilidades ───────────────────────────────────────────────────────────

    /** Un dia de calendario, sin hora: fechas de nacimiento y similares. */
    protected function fecha($valor): ?string
    {
        return $valor ? substr((string) $valor, 0, 10) : null;
    }

    /**
     * Fecha **con hora**, para las columnas que la llevan.
     *
     * Las de un plan son `datetime(6)` en la v1 y la hora es informacion: de
     * restar el fin del inicio sale el «Tiempo Trabajado», y el codigo original
     * del plan se construia con la hora de inicio. Pasarlas por `fecha()` las
     * truncaba a medianoche y dejaba 3 712 planes con duracion cero.
     *
     * Se recortan los microsegundos, que ahi no significan nada.
     */
    protected function fechaHora($valor): ?string
    {
        return $valor ? substr((string) $valor, 0, 19) : null;
    }

    /** Nada se descarta en silencio: se dice cuanto y por que. */
    protected function avisarDescartes(array $descartados, array $motivos): void
    {
        foreach ($descartados as $clave => $cuantos) {
            if ($cuantos > 0) {
                $this->warn(sprintf('  descartadas %d fila(s): %s', $cuantos, $motivos[$clave] ?? $clave));
            }
        }
    }

    /** Conteo de origen contra destino: si no cuadra, se ve aqui. */
    protected function linea(string $que, int $origen, int $destino, string $detalle): void
    {
        $this->line(sprintf('  %-10s origen: %-6d destino: %-6d  %s', $que, $origen, $destino, $detalle));
    }
}
