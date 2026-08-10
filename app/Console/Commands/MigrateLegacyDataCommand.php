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
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkArea;
use App\Models\WorkPlanApproval;
use App\Models\WorkPlanPerson;
use App\Models\WorkLocation;
use App\Models\Workstation;
use App\Models\WorkType;
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
 *   php artisan docufiz:migrate-data archivos --desde=/ruta/v1/public/images_uploads
 *   php artisan docufiz:migrate-data todo
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
        {--desde= : Carpeta con el public/images_uploads de la v1, para el paso archivos}
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

        try {
            DB::connection('legacy')->getPdo();
        } catch (\Throwable $e) {
            $this->error('No se pudo conectar a la base anterior. Revisa LEGACY_DB_* en .env');

            return self::FAILURE;
        }

        $paso = $this->argument('paso');

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

        // Los archivos solo si se dice de donde: no estan en el repositorio y
        // el paso no puede fallar por su ausencia.
        if ($paso === 'archivos' || ($paso === 'todo' && $this->option('desde'))) {
            if (! $this->option('desde')) {
                $this->error('El paso archivos necesita --desde=/ruta/al/public/images_uploads de la v1.');

                return self::FAILURE;
            }

            $this->copiarArchivos((string) $this->option('desde'));
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

        // Y las nacionalidades, por lo mismo: `nationality_id` tambien es NOT
        // NULL en la v1 y tampoco se traia.
        $nacionalidades = $this->catalogoNacionalidades($viejo);

        $fuentes = [
            'workers'         => PersonRole::WORKER,
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
                $p['roles'][$rol] = true;
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
            $persona = Person::withTrashed()->where('country_id', $this->countryId)
                ->where('num_doc', $doc)->first();

            $nacionalidadId = $d['nacionalidad'] ? ($nacionalidades[$d['nacionalidad']] ?? null) : null;

            if (! $persona) {
                $persona = Person::create([
                    'slug' => Str::random(22), 'country_id' => $this->countryId,
                    'doc_type' => $this->tipoDeDocumento($nacionalidadId), 'num_doc' => $doc,
                    'name' => $d['name'], 'lastname' => $d['lastname'],
                    'nationality_id' => $nacionalidadId,
                    'tenant_id' => $this->tenantId, 'created_by' => 1,
                    'legacy_table' => implode(', ', $d['legacy']),
                ]);
                $creadas++;
            } elseif ($nacionalidadId && $persona->nationality_id === null) {
                // Ya existia de una pasada anterior, cuando la nacionalidad no
                // se traia: se le pone ahora, junto con su tipo de documento.
                $persona->update([
                    'nationality_id' => $nacionalidadId,
                    'doc_type'       => $this->tipoDeDocumento($nacionalidadId),
                ]);
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

        $rolMinimo = Role::where('guard_name', 'web')->where('name', self::ROL_MINIMO)->first();
        $localeId = Country::find($this->countryId)?->default_locale_id ?? DB::table('locales')->min('id');

        $creados = $actualizados = $conPerfil = 0;
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
        }

        $destino = User::withTrashed()->withoutGlobalScopes()->whereNotNull('legacy_id')->count();
        $this->linea('usuarios', $viejos->count(), $destino, "{$creados} nuevos, {$actualizados} actualizados");

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
        $descartados = ['plan' => 0, 'regla' => 0, 'aprobador' => 0, 'repetido' => 0];
        $vistos = [];

        $viejo->table('plan_approvals')->orderBy('id')->chunkById($this->lote, function ($filas) use (
            $planes, $personas, $reglas, &$migrados, &$descartados, &$vistos
        ) {
            $nuevos = [];

            foreach ($filas as $f) {
                $planId = $planes[$f->plan_id] ?? null;
                $reglaId = $reglas[$f->approval_rule_id] ?? null;

                if (! $planId)  { $descartados['plan']++;  continue; }
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

        $this->linea('aprobac.', $origen, $destino, "{$migrados} aprobaciones · {$pendientes} obligatorias sin firmar");

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
        $porDocumento = DB::table('people')
            ->where('country_id', $this->countryId)
            ->pluck('id', 'num_doc');

        $cache = [];

        foreach (['workers', 'supervisors', 'hse_supervisors'] as $tabla) {
            $cache[$tabla] = [];

            foreach ($viejo->table($tabla)->select('id', 'num_doc')->get() as $f) {
                $id = $porDocumento[trim((string) $f->num_doc)] ?? null;

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
            // En destino el nombre traducible vive en los archivos de idioma:
            // la tabla solo guarda el codigo, que aqui es el nombre en espanol.
            return WorkType::withTrashed()
                ->where('country_id', $this->countryId)
                ->whereRaw('lower(code) = ?', [mb_strtolower($f->name_es)])
                ->first();
        }, fn ($f) => ['country_id' => $this->countryId, 'code' => $f->name_es, 'is_active' => (bool) $f->is_active]);
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
     * `is_signature_approver` viene con ellos: marca los cargos que pueden
     * firmar como aprobadores del plan (en la v1 solo Supervisor).
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
                'is_signature_approver' => (bool) $f->is_signature_approver,
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
     * **Es una deduccion, no un dato del origen**, y conviene saberlo. La
     * sostiene el propio volcado: los 11 extranjeros tienen documento de nueve
     * caracteres y los peruanos de ocho, sin una sola excepcion en cinco años.
     * Aun asi, si alguno lleva PTP o pasaporte en vez de carne, hay que
     * corregirlo a mano (queda anotado en docs/MIGRACION.md).
     *
     * Sin nacionalidad no se deduce nada: se deja el DNI de siempre.
     */
    protected function tipoDeDocumento(?int $nacionalidadId): string
    {
        if ($nacionalidadId === null) {
            return 'DNI';
        }

        $codigo = \App\Models\Nationality::withTrashed()->whereKey($nacionalidadId)->value('code');

        return $this->esDelPaisDeTrabajo((string) $codigo) ? 'DNI' : 'CE';
    }

    /** ¿La nacionalidad es la del pais donde se trabaja? */
    protected function esDelPaisDeTrabajo(string $codigo): bool
    {
        $pais = \App\Models\Country::whereKey($this->countryId)->value('name');

        $limpio = fn (string $t) => mb_strtolower(preg_replace('/\p{Mn}/u',
            '', \Normalizer::normalize($t, \Normalizer::FORM_D) ?: $t) ?? $t);

        return $limpio($codigo) === $limpio((string) $pais);
    }

    /**
     * Nacionalidades. Se habia quedado sin migrar entera.
     *
     * En la v1 `workers.nationality_id` es NOT NULL: los 391 trabajadores traen
     * una, y la ficha del plan la enseñaba con una banderita al lado del
     * nombre. Aqui la tabla estaba vacia y la columna de la persona en nulo.
     *
     * El reparto real de esos 391: 380 Peru, 9 Venezuela, 1 Chile, 1 Argentina.
     * Son once personas, pero son once que llevan carne de extranjeria en vez
     * de DNI, y eso es justo lo que el supervisor necesita saber en la puerta.
     *
     * @return array<int, int> legacy_id => nationalities.id
     */
    protected function catalogoNacionalidades($viejo): array
    {
        return $this->catalogo($viejo, 'nationalities', fn ($f) => \App\Models\Nationality::withTrashed()
            ->where('country_id', $this->countryId)
            ->whereRaw('lower(code) = ?', [mb_strtolower($f->name)])
            ->first(),
            fn ($f) => [
                'country_id' => $this->countryId,
                'code'       => $f->name,
                'is_active'  => (bool) $f->is_active,
            ]);
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
        return $this->catalogo($viejo, 'approval_rules', function ($f) {
            return ApprovalRule::withTrashed()
                ->where('country_id', $this->countryId)
                ->where('approver_role', $this->rolAprobador($f->approver_type))
                ->where('priority_level', $f->priority_level)
                ->first();
        }, fn ($f) => [
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
    }

    protected function rolAprobador(string $tipo): string
    {
        return match ($tipo) {
            'Worker'        => PersonRole::WORKER,
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
            'nationalities'  => \App\Models\Nationality::class,
        ][$tabla];

        $mapa = [];

        $consulta = $viejo->table($tabla)->orderBy('id');

        // Los catalogos de la v1 son multipais; aqui solo se trae el que usan
        // los planes. Workstations cuelga de la sede, y `nationalities` alli es
        // una tabla plana sin pais —«Peru», «Venezuela»— porque no dice donde se
        // trabaja sino de donde es la persona.
        if (! in_array($tabla, ['workstations', 'nationalities'], true)) {
            $consulta->where('country_id', self::PAIS_LEGACY);
        }

        foreach ($consulta->get() as $f) {
            $fila = $modelo::withTrashed()->where('legacy_id', $f->id)->first() ?? $buscar($f);
            $columnas = $datos($f);

            if ($columnas === null) {
                continue;
            }

            $columnas['legacy_id'] = $f->id;

            // ¿Es la primera vez que esta fila se declara «viene de la v1»? Lo
            // es si acaba de crearse, y tambien si ya existia a mano y es ahora
            // cuando se le pone el `legacy_id`. En los dos casos hay que
            // bloquearla; en ninguno mas.
            $esNuevaDeLaV1 = ! $fila || $fila->legacy_id === null;

            if ($fila) {
                $fila->update($columnas);
            } else {
                $fila = $modelo::create($columnas + [
                    'slug' => Str::random(22), 'tenant_id' => $this->tenantId, 'created_by' => 1,
                ]);
            }

            if ($esNuevaDeLaV1) {
                // Nace bloqueada. Un catalogo no es como un plan: renombrar una
                // fila de aqui cambia de golpe lo que dicen los 3.712 planes que
                // la citan, cerrados y firmados incluidos. El candado no impide
                // corregirla, obliga a quitarlo primero, que es la pausa que
                // faltaba.
                //
                // Nivel 'super' porque el que bloquea es el sistema, no una
                // persona: es el mismo caso que «Bloqueado por el sistema» del
                // resto de modulos. Y solo la primera vez — si alguien la
                // desbloqueo a proposito, volver a migrar no se lo deshace.
                $fila->forceFill([
                    'locked_at'  => now(),
                    'locked_by'  => 1,
                    'lock_scope' => 'super',
                ])->saveQuietly();
            }

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

        return true;
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
        $severidades = $viejo->table('severities')->pluck('name', 'id')->all();
        $probabilidades = $viejo->table('probabilities')->pluck('name', 'id')->all();
        $equipos = $viejo->table('ast_equipments')->pluck('name_es', 'id')->all();
        $objetivos = $viejo->table('ast_objetives')->pluck('name_es', 'id')->all();

        $this->recorrerFormato($viejo, 'f1_documents', $plantilla, function ($docs, $entregas) use (
            $viejo, $campos, $severidades, $probabilidades, $equipos, $objetivos
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

                $filas = $this->mapa->matrizDeRiesgo($suyas, $suyos, $severidades, $probabilidades, 'f1_document_activity_id');

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
        $preguntas = $viejo->table('ptf_questions')->pluck('name_es', 'id')->all();
        $severidades = $viejo->table('severities')->pluck('name', 'id')->all();
        $probabilidades = $viejo->table('probabilities')->pluck('name', 'id')->all();

        $this->recorrerFormato($viejo, 'f2_documents', $plantilla, function ($docs, $entregas) use (
            $viejo, $campos, $preguntas, $severidades, $probabilidades
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

                foreach ($this->mapa->matrizDeRiesgo($act, $pel, $severidades, $probabilidades, 'f2_document_activity_id') as $i => $fila) {
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

        $this->line('  Los ficheros no estan en este repositorio. Cuando los tengas:');
        $this->line('    php artisan docufiz:migrate-data archivos --desde=/ruta/v1/public/images_uploads');
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
     * El metodo es siempre 'migrated': lo que la v1 llamaba reconocimiento
     * facial lo decidia el navegador y no dejo prueba, asi que aqui no puede
     * llamarse igual que una firma verificada por el servidor. El used_ai del
     * origen si se conserva, porque es lo que la v1 creia.
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

        return [
            'signable_type'   => $tipo,
            'signable_id'     => $id,
            'person_id'       => $personaId,
            'role_signed'     => $rol ?: PersonRole::WORKER,
            'signed_at'       => $firmadaEn,
            'method'          => 'migrated',
            'used_ai'         => (bool) ($extra['used_ai'] ?? false),
            'manual_override' => (bool) ($extra['manual_override'] ?? false),
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

        DB::table('signature_events')->upsert($eventos, ['legacy_source', 'legacy_id'], [
            'signable_type', 'signable_id', 'person_id', 'role_signed', 'signed_at',
            'method', 'used_ai', 'evidence_missing', 'updated_at',
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
     * Copia las imagenes de la v1 al almacenamiento nuevo.
     *
     * Se ejecuta aparte porque los ficheros no viven en la base ni en el
     * repositorio: hay que traerse el `public/images_uploads` del servidor
     * viejo. Hasta entonces las filas de evidencia apuntan a un archivo que no
     * esta, con su sha256 provisional; este paso lo copia, lo pesa y lo vuelve a
     * hashear de verdad. Lo que no aparezca se marca como evidencia perdida.
     */
    protected function copiarArchivos(string $carpeta): void
    {
        $this->info('── Archivos de imagen ──');

        if (! is_dir($carpeta)) {
            $this->error("  No existe la carpeta {$carpeta}. Nada que copiar.");

            return;
        }

        $copiados = $perdidos = $yaEstaban = 0;

        DB::table('evidence_files')->where('file_path', 'like', 'legacy/images_uploads/%')
            ->orderBy('id')->chunkById(500, function ($filas) use ($carpeta, &$copiados, &$perdidos, &$yaEstaban) {
                foreach ($filas as $f) {
                    $nombre = basename($f->file_path);
                    $fuente = rtrim($carpeta, '/') . '/' . $nombre;

                    if (! is_file($fuente)) {
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

        // Las firmas y las fotos de referencia salen de la misma carpeta.
        $firmas = $this->copiarDeReferencia($carpeta, 'person_signatures', 'legacy/firmas/', 'firmas/legacy/');
        $fotos  = $this->copiarDeReferencia($carpeta, 'person_photos', 'legacy/fotos/', 'fotos/legacy/');

        $this->line(sprintf('  evidencias: %d copiadas · %d ya estaban · %d no aparecieron', $copiados, $yaEstaban, $perdidos));
        $this->line(sprintf('  firmas de persona: %d copiadas · %d no aparecieron', $firmas[0], $firmas[1]));
        $this->line(sprintf('  fotos de persona: %d copiadas · %d no aparecieron', $fotos[0], $fotos[1]));

        if ($perdidos > 0) {
            $this->warn('  Las que no aparecieron quedan marcadas como evidencia perdida (evidence_missing).');
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

    /**
     * Copia las firmas o las fotos de referencia desde la carpeta de la v1.
     *
     * Las dos tablas tienen la misma forma —`file_path` + `sha256`— y hasta
     * ahora esto solo existia para las firmas. El nombre del fichero en la v1
     * no dice nada del contenido, asi que al copiarlo se renombra por su hash:
     * dos personas con la misma imagen comparten archivo y no se duplica.
     *
     * @return array{0:int,1:int} copiadas, perdidas
     */
    protected function copiarDeReferencia(string $carpeta, string $tabla, string $prefijoViejo, string $prefijoNuevo): array
    {
        $copiadas = $perdidas = 0;

        DB::table($tabla)->where('file_path', 'like', $prefijoViejo . '%')
            ->orderBy('id')->chunkById(500, function ($filas) use ($carpeta, $tabla, $prefijoNuevo, &$copiadas, &$perdidas) {
                foreach ($filas as $f) {
                    $fuente = rtrim($carpeta, '/') . '/' . basename($f->file_path);

                    if (! is_file($fuente)) {
                        $perdidas++;

                        continue;
                    }

                    $contenido = file_get_contents($fuente);
                    $hash = hash('sha256', $contenido);
                    $destino = $prefijoNuevo . substr($hash, 0, 2) . '/' . $hash . '.' . pathinfo($f->file_path, PATHINFO_EXTENSION);

                    if (! Storage::disk('local')->exists($destino)) {
                        Storage::disk('local')->put($destino, $contenido);
                    }

                    DB::table($tabla)->where('id', $f->id)
                        ->update(['file_path' => $destino, 'sha256' => $hash]);
                    $copiadas++;
                }
            });

        return [$copiadas, $perdidas];
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
