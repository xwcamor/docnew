<?php

namespace Tests\Feature\Migration;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La migracion completa, de punta a punta, contra una v1 de mentira.
 *
 * No hace falta MySQL: `LegacyDatabaseFixture` levanta el mismo esquema sobre
 * sqlite en memoria con datos inventados. Lo que se comprueba no es que el
 * comando corra, sino las decisiones que toma cuando el origen no encaja: los
 * codigos de plan repetidos, la misma persona en dos empresas, el aprobador que
 * ya no existe y —sobre todo— las firmas de la v1 que no son un archivo.
 */
class MigrateLegacyDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        // Los paises que hacen falta. Venezuela no es decorado: la nacionalidad
        // de una persona ES un pais, y sin el, el extranjero del volcado se
        // quedaria sin ella y saldria con DNI en vez de con carne.
        DB::table('countries')->insertOrIgnore([
            ['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Venezuela', 'iso_code' => 'VE', 'currency' => 'VES', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        // Los roles a los que la migracion mapea los perfiles de la v1.
        foreach (['super', 'admin', 'Supervisor de obra', 'Usuario de campo'] as $rol) {
            Role::firstOrCreate(
                ['name' => $rol, 'guard_name' => 'web'],
                ['slug' => Str::random(22), 'description' => $rol],
            );
        }

        LegacyDatabaseFixture::levantar();
        LegacyDatabaseFixture::sembrar();
    }

    /** Las plantillas del motor y despues todos los datos. */
    protected function migrarTodo(): void
    {
        $this->artisan('docufiz:migrate-formats')->assertSuccessful();
        $this->artisan('docufiz:migrate-data', ['paso' => 'todo'])->assertSuccessful();
    }

    /**
     * Con la base completa se aprovechan el correo, la contrasena y el perfil.
     *
     * El primer volcado vino con `users` vacia y solo se pudo reconstruir el
     * nombre. Con la base entera no hay que inventar nada: la gente entra con
     * su correo y su contrasena de siempre, y con el perfil que ya tenia.
     */
    public function test_con_la_base_completa_se_migran_correo_contrasena_y_perfil(): void
    {
        LegacyDatabaseFixture::conBaseCompleta();
        $this->migrarTodo();

        $porLegacy = fn (int $id) => User::withoutGlobalScopes()->where('legacy_id', $id)->sole();

        $this->assertSame('jefe@empresa.test', $porLegacy(1)->email);
        $this->assertSame('supervisor@empresa.test', $porLegacy(2)->email);

        // El hash de Devise (`$2a$`) lo entiende PHP tal cual: no hay que
        // resetear la contrasena de nadie.
        $this->assertTrue(password_verify('secreto123', $porLegacy(1)->password));

        // Y cada perfil de la v1 cae en su rol de aqui.
        $this->assertTrue($porLegacy(1)->hasRole('admin'));
        $this->assertTrue($porLegacy(2)->hasRole('Supervisor de obra'));
        $this->assertTrue($porLegacy(3)->hasRole('Usuario de campo'));

        // `is_hidden` en la v1 es un usuario dado de baja.
        $this->assertFalse((bool) $porLegacy(3)->is_active);
    }

    /** Sin `users`, el comportamiento de antes: nombre y poco mas. */
    public function test_sin_la_tabla_users_los_correos_son_provisionales(): void
    {
        $this->migrarTodo();

        $usuario = User::withoutGlobalScopes()->where('legacy_id', 1)->sole();

        $this->assertSame('usuario1@pendiente.local', $usuario->email);
        $this->assertTrue($usuario->hasRole('Usuario de campo'));
    }

    /** La contrasena en claro de la v1 no se copia, y se avisa de que existe. */
    public function test_avisa_de_las_contrasenas_en_claro_y_no_las_migra(): void
    {
        LegacyDatabaseFixture::conBaseCompleta();

        $this->artisan('docufiz:migrate-formats')->assertSuccessful();
        $this->artisan('docufiz:migrate-data', ['paso' => 'usuarios'])
            ->expectsOutputToContain('EN CLARO')
            ->assertSuccessful();

        $usuario = User::withoutGlobalScopes()->where('legacy_id', 1)->sole();

        $this->assertNotSame('secreto123', $usuario->password);
        $this->assertStringStartsWith('$2', $usuario->password);
    }

    // ── usuarios ─────────────────────────────────────────────────────────────

    public function test_los_usuarios_se_reconstruyen_desde_user_details(): void
    {
        $this->migrarTodo();

        $usuarios = User::withoutGlobalScopes()->whereNotNull('legacy_id')->orderBy('legacy_id')->get();

        $this->assertCount(2, $usuarios);
        // El legacy_id es lo que permite resolver quien registro cada plan.
        $this->assertSame([1, 2], $usuarios->pluck('legacy_id')->all());
        $this->assertSame('usuario1@pendiente.local', $usuarios->first()->email);
        $this->assertTrue($usuarios->first()->hasRole('Usuario de campo'));

        // La contrasena no se migra (la v1 no la dio) y no es adivinable.
        $this->assertNotNull($usuarios->first()->password);
        $this->assertFalse(\Hash::check('password', $usuarios->first()->password));
    }

    // ── planes ───────────────────────────────────────────────────────────────

    public function test_los_planes_se_migran_con_sus_llaves_resueltas(): void
    {
        $this->migrarTodo();

        $planes = DB::table('work_plans')->whereNotNull('legacy_id')->orderBy('legacy_id')->get();

        // El plan 4 es de otro pais: no se migra.
        $this->assertSame([1, 2, 3], $planes->pluck('legacy_id')->all());

        $primero = $planes->first();
        $this->assertNotNull($primero->company_id);
        $this->assertNotNull($primero->work_type_id);
        $this->assertNotNull($primero->work_location_id);
        $this->assertNotNull($primero->workstation_id);
        $this->assertNotNull($primero->work_area_id);
        $this->assertSame('2026-01-15', substr((string) $primero->date_start, 0, 10));

        // Quien lo registro sale del legacy_id del usuario, no de un id suelto.
        $this->assertSame(
            User::withoutGlobalScopes()->where('legacy_id', 2)->value('id'),
            $primero->user_id,
        );

        // El plan borrado en la v1 llega borrado.
        $this->assertNotNull($planes->firstWhere('legacy_id', 3)->deleted_at);
    }

    /**
     * Los codigos se rehacen enteros, no se parchean.
     *
     * En la v1 el ultimo bloque era la hora de creacion, asi que dos planes del
     * mismo minuto salian identicos (3 722 planes, 3 526 codigos). Aqui ese
     * bloque es el correlativo del dia del trabajo, que no puede chocar.
     */
    public function test_los_codigos_de_plan_se_rehacen_como_correlativo_del_dia(): void
    {
        $this->migrarTodo();

        $codigos = DB::table('work_plans')->whereNotNull('legacy_id')->orderBy('legacy_id')->pluck('code', 'legacy_id');

        // Los dos planes que en la v1 compartian el codigo PE26-1501-0800.
        $this->assertSame('PE26-1501-0001', $codigos[1]);
        $this->assertSame('PE26-1501-0002', $codigos[2]);

        // Y ninguno se repite, que es lo que se venia a arreglar.
        $this->assertSame($codigos->count(), $codigos->unique()->count());

        // El plan original se recupera siempre por el legacy_id.
        $this->assertSame(2, DB::table('work_plans')->where('code', 'PE26-1501-0002')->value('legacy_id'));
    }

    public function test_solo_se_migran_los_catalogos_del_pais_que_usan_los_planes(): void
    {
        $this->migrarTodo();

        $this->assertSame(1, DB::table('work_types')->whereNotNull('legacy_id')->count());
        $this->assertSame(1, DB::table('work_locations')->whereNotNull('legacy_id')->count());
        $this->assertSame(1, DB::table('workstations')->whereNotNull('legacy_id')->count());

        // Dos, no las tres de la v1: la regla del «Ejecutante» no se trae. Ese
        // rol dejo de firmar aprobaciones —quien responde por la gente de la
        // obra es una columna del plan, no una fila del flujo— y traerla
        // recrearia el rol que la migracion de esquema acaba de borrar.
        $this->assertSame(2, DB::table('approval_rules')->whereNotNull('legacy_id')->count());
        $this->assertSame(0, DB::table('approval_rules')->where('approver_role', 'worker')->count());

        // Los formatos que exige cada tipo de trabajo tambien vienen de la v1.
        $this->assertSame(2, DB::table('work_type_form_templates')->count());
    }

    /**
     * Lo que trae la migracion llega bloqueado.
     *
     * Un catalogo no es como un plan: renombrar un tipo de trabajo cambia de
     * golpe lo que dicen todos los planes que lo citan, cerrados y firmados
     * incluidos. El candado no impide corregirlo, obliga a quitarlo primero.
     *
     * Nivel `super` porque el que bloquea es el sistema, no una persona: un
     * admin de workspace no deshace la referencia del sistema anterior desde su
     * panel.
     */
    public function test_los_catalogos_que_trae_la_migracion_llegan_bloqueados(): void
    {
        $this->migrarTodo();

        foreach (['work_types', 'work_locations', 'workstations', 'work_areas', 'positions', 'approval_rules'] as $tabla) {
            $filas = DB::table($tabla)->whereNotNull('legacy_id')->get();

            $this->assertNotEmpty($filas, "{$tabla} deberia haber traido algo de la v1");
            foreach ($filas as $fila) {
                $this->assertNotNull($fila->locked_at, "{$tabla} #{$fila->id} deberia llegar bloqueada");
                $this->assertSame('super', $fila->lock_scope);
            }
        }

        // Los planes NO: tienen su propio cierre, que es otra cosa y la pone el
        // supervisor cuando termina la jornada.
        $this->assertSame(0, DB::table('work_plans')->whereNotNull('locked_at')->count());
    }

    /**
     * Una fila que ya existia a mano y la migracion reconoce como suya tambien
     * se bloquea.
     *
     * No es un caso raro: es lo que paso de verdad. `Position::$fillable` no
     * incluia `legacy_id`, asi que los cargos llegaban de la v1 pero sin de
     * donde venian; al volver a migrar se les reconoce por el codigo y es
     * entonces cuando se les pone la marca. Ese momento —el primero en que la
     * fila se declara «viene de la v1»— es el que tiene que bloquearla, o esas
     * filas se quedan sueltas para siempre.
     */
    public function test_una_fila_que_ya_existia_se_bloquea_al_reconocerla_como_de_la_v1(): void
    {
        // Alguien dio de alta el mismo tipo de trabajo a mano, antes de migrar.
        $aMano = \App\Models\WorkType::create([
            'slug' => \Illuminate\Support\Str::random(22), 'country_id' => 1,
            'tenant_id' => 1, 'created_by' => 1, 'code' => 'Estandar', 'is_active' => true,
        ]);

        $this->assertFalse($aMano->is_locked);
        $this->assertNull($aMano->legacy_id);

        $this->migrarTodo();

        $aMano->refresh();
        $this->assertNotNull($aMano->legacy_id, 'la migracion deberia reconocerla por el codigo');
        $this->assertTrue($aMano->is_locked);
        $this->assertSame('super', $aMano->lock_scope);
    }

    /** Volver a migrar no le vuelve a poner el candado a lo que se desbloqueo. */
    public function test_al_repetir_la_migracion_no_se_rebloquea_lo_que_alguien_solto(): void
    {
        $this->migrarTodo();

        $tipo = \App\Models\WorkType::whereNotNull('legacy_id')->firstOrFail();
        $tipo->unlock();

        $this->migrarTodo();

        $this->assertFalse($tipo->fresh()->is_locked, 'el candado que se quito a proposito no vuelve solo');
    }

    public function test_la_misma_persona_en_dos_empresas_es_una_sola_en_la_cuadrilla(): void
    {
        $this->migrarTodo();

        // Los plan_workers 3 y 4 son el mismo documento en dos empresas.
        $personas = DB::table('work_plan_people as wpp')
            ->join('work_plans as p', 'p.id', '=', 'wpp.work_plan_id')
            ->where('p.legacy_id', 2)->pluck('wpp.person_id');

        $this->assertCount(1, $personas->unique());
        $this->assertSame(3, DB::table('work_plan_people')->count());
    }

    /**
     * La nacionalidad de la v1 decide el tipo de documento, y no se guarda.
     *
     * La v1 no tiene tipo de documento: `workers.num_doc` es texto pelado y
     * aqui se escribia «DNI» para los 391. Para 380 es cierto; para los 11
     * extranjeros no, porque un extranjero no puede tener DNI. `nationality_id`
     * es NOT NULL alli —el reparto real es 380 Peru, 9 Venezuela, 1 Chile y 1
     * Argentina— y de ahi sale el tipo.
     *
     * Lo que NO pasa es guardarla: `people.nationality_id` se borro por
     * redundante. Ya estan el pais del documento y el tipo, que dicen lo mismo.
     * Se lee del origen, se usa para deducir, y se tira.
     */
    public function test_la_nacionalidad_de_la_v1_decide_el_tipo_de_documento(): void
    {
        $this->migrarTodo();

        $peruano = DB::table('people')->where('num_doc', '10000001')->first();
        $extranjero = DB::table('people')->where('num_doc', '10000002')->first();

        $this->assertSame('DNI', $peruano->doc_type);
        $this->assertSame('CE', $extranjero->doc_type);

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('people', 'nationality_id'),
            'La persona vuelve a guardar una nacionalidad que el documento ya dice.',
        );
    }

    /**
     * A quien se migro con el tipo mal se le corrige al repetir la migracion.
     *
     * Los 391 ya estaban en la base como «DNI», de la pasada anterior, cuando
     * el tipo todavia no se deducia. Si solo se decidiera al CREAR la persona,
     * los once extranjeros se quedarian con un DNI que no pueden tener y habria
     * que borrarlos y empezar de nuevo.
     */
    public function test_al_repetir_la_migracion_se_corrige_el_tipo_que_estaba_mal(): void
    {
        $this->migrarTodo();

        // Se simula el estado en que quedo la base con la migracion vieja.
        DB::table('people')->update(['doc_type' => 'DNI']);

        $this->migrarTodo();

        $this->assertSame('CE', DB::table('people')->where('num_doc', '10000002')->value('doc_type'));
    }

    /**
     * El representante de la cuadrilla llega del volcado.
     *
     * En la v1 era una fila de `plan_approvals` con `approver_type = 'Worker'`,
     * y aqui es una columna del plan: esa fila nunca recogia una firma propia,
     * apuntaba a alguien que ya habia firmado como trabajador. Si el migrador
     * se limitara a NO importarla, los planes migrados se quedarian sin
     * representante y sin poder cerrarse, porque el cierre lo exige.
     */
    public function test_el_representante_del_plan_llega_del_volcado(): void
    {
        $this->migrarTodo();

        $conRepresentante = DB::table('work_plans')
            ->whereNotNull('crew_representative_person_id')
            ->count();

        $this->assertGreaterThan(0, $conRepresentante,
            'Ningun plan migrado tiene representante: no se podran cerrar.');

        // Y no queda ninguna aprobacion de las que eran del ejecutante.
        $this->assertSame(0, DB::table('approval_rules')->where('approver_role', 'worker')->count());
    }

    /**
     * El cargo del trabajador llega, y llega en el vinculo con su empresa.
     *
     * Se habia quedado fuera por completo: la migracion **capturaba**
     * `workers.position_id` y luego lo tiraba, porque el `firstOrCreate` del
     * vinculo no lo escribia. Resultado: 370 vinculos, 0 con cargo, y el
     * catalogo `positions` vacio. En la v1 esa columna es NOT NULL —los 372
     * trabajadores tienen cargo— y la ficha del plan lo enseñaba bajo el nombre.
     */
    public function test_el_cargo_del_trabajador_llega_con_su_vinculo(): void
    {
        $this->migrarTodo();

        // Solo los cargos del pais que usan los planes, como el resto de
        // catalogos: el mecanico del pais 6 no se trae.
        $this->assertSame(2, DB::table('positions')->count());
        $this->assertSame(0, DB::table('positions')->where('code', 'Mecanico')->count());

        $persona = DB::table('people')->where('num_doc', '10000001')->first();
        $vinculo = DB::table('person_company_links')->where('person_id', $persona->id)->first();

        $this->assertNotNull($vinculo->position_id, 'el vinculo llego sin cargo');
        $this->assertSame('Tecnico', DB::table('positions')->where('id', $vinculo->position_id)->value('code'));
    }

    public function test_una_aprobacion_con_aprobador_inexistente_se_migra_sin_persona(): void
    {
        $this->migrarTodo();

        // Dos de las tres de la v1: la del «Ejecutante» se queda fuera con su
        // regla, porque el representante ya no es una aprobacion.
        $this->assertSame(2, DB::table('work_plan_approvals')->count());
        $this->assertSame(0, DB::table('work_plan_approvals')->where('legacy_id', 1)->count());

        // La aprobacion 3 apuntaba al supervisor 99, que no existe en la v1.
        $this->assertNull(DB::table('work_plan_approvals')->where('legacy_id', 3)->value('person_id'));
        // Y la 2 nunca tuvo aprobador: sigue pendiente y obligatoria.
        $huerfana = DB::table('work_plan_approvals')->where('legacy_id', 2)->first();
        $this->assertNull($huerfana->person_id);
        $this->assertTrue((bool) $huerfana->is_required);
        $this->assertFalse((bool) $huerfana->is_approved);
    }

    public function test_el_comando_avisa_de_lo_que_descarta(): void
    {
        $this->artisan('docufiz:migrate-formats')->assertSuccessful();
        $this->artisan('docufiz:migrate-data', ['paso' => 'empresas'])->assertSuccessful();
        $this->artisan('docufiz:migrate-data', ['paso' => 'usuarios'])->assertSuccessful();
        $this->artisan('docufiz:migrate-data', ['paso' => 'personas'])->assertSuccessful();

        $this->artisan('docufiz:migrate-data', ['paso' => 'planes'])
            ->expectsOutputToContain('codigo(s) rehechos')
            ->expectsOutputToContain('el plan es de otro pais')
            ->assertSuccessful();
    }

    // ── formatos llenados ────────────────────────────────────────────────────

    public function test_cada_formato_de_la_v1_es_una_entrega_del_motor(): void
    {
        $this->migrarTodo();

        $entregas = DB::table('form_submissions as s')
            ->join('form_templates as t', 't.id', '=', 's.form_template_id')
            ->whereNotNull('s.legacy_id')
            ->pluck('s.legacy_table', 't.code');

        $this->assertSame([
            'AST' => 'f1_documents', 'PTF' => 'f2_documents',
            'EPP' => 'f3_documents', 'IHM' => 'f4_documents',
        ], $entregas->all());

        // Lo que estaba confirmado en la v1 llega confirmado.
        $this->assertSame(4, DB::table('form_submissions')->where('status', 'confirmed')->count());
    }

    public function test_el_ast_llega_como_matriz_de_riesgo_con_una_fila_por_peligro(): void
    {
        $this->migrarTodo();

        $filas = $this->respuestasDe('AST', 'matriz_de_riesgo');

        // Un peligro mas la actividad que no tenia ninguno.
        $this->assertCount(2, $filas);
        $this->assertSame('Izaje de carga', $filas[0]['actividad']);
        $this->assertSame('c3', $filas[0]['severidad']);
        $this->assertSame('p2', $filas[0]['probabilidad']);
        $this->assertSame('Sin peligros', $filas[1]['actividad']);

        $this->assertSame(['Grua'], $this->respuestasDe('AST', 'equipos')[0]);
        $this->assertSame(['Fin de trabajo'], $this->respuestasDe('AST', 'objetivos')[0]);

        // Los dos textos que la plantilla nueva no reproduce como campo propio.
        $observaciones = DB::table('form_answers as a')
            ->join('form_fields as f', 'f.id', '=', 'a.form_field_id')
            ->join('form_submissions as s', 's.id', '=', 'a.form_submission_id')
            ->where('s.legacy_table', 'f1_documents')->where('f.code', 'observaciones')
            ->value('a.value_text');

        $this->assertStringContainsString('Permiso de altura', $observaciones);
        $this->assertStringContainsString('Arnes', $observaciones);
    }

    public function test_el_ptf_llega_como_banco_de_preguntas_en_una_sola_respuesta(): void
    {
        $this->migrarTodo();

        $filas = $this->respuestasDe('PTF', 'preguntas');

        $this->assertCount(1, $filas);
        $this->assertSame('Si', $filas[0][0]['answer']);
        $this->assertSame('No', $filas[0][1]['answer']);
        $this->assertSame('Tienes permiso?', $filas[0][0]['question']);
    }

    public function test_el_epp_llega_por_trabajador_y_apunta_a_la_persona_de_destino(): void
    {
        $this->migrarTodo();

        $filas = $this->respuestasDe('EPP', 'epp_por_trabajador');

        $this->assertCount(1, $filas);
        $this->assertSame('Conforme', $filas[0]['items'][0]['answer']);
        // Sin respuesta en la v1 se queda sin respuesta.
        $this->assertNull($filas[0]['items'][1]['answer']);
        $this->assertSame('Cambiar casco', $filas[0]['correction_measure']);

        $persona = DB::table('work_plan_people')->where('legacy_id', 1)->value('person_id');
        $this->assertSame($persona, $filas[0]['person_id']);
    }

    public function test_el_ihm_llega_por_herramienta_con_las_etiquetas_correctas(): void
    {
        $this->migrarTodo();

        $filas = $this->respuestasDe('IHM', 'inspeccion_de_herramientas');

        $this->assertCount(1, $filas);
        $this->assertSame('Amoladora', $filas[0]['tool']);
        $this->assertFalse($filas[0]['habilitada']);
        // Los tres valores de la v1 de extremo a extremo: el check, la equis y
        // la raya. Esta prueba afirmaba que el 2 era «No cumple» —y lo daba por
        // bueno— porque el fixture no traia ningun 0 con el que contrastarlo.
        // Ver LegacyFormMapperTest::test_el_cero_es_no_conforme_y_el_dos_es_no_aplica.
        $this->assertSame('Cumple', $filas[0]['items'][0]['answer']);
        $this->assertSame('No cumple', $filas[0]['items'][1]['answer']);
        $this->assertSame('No aplica', $filas[0]['items'][2]['answer']);
    }

    // ── etiquetas de la matriz de riesgo ─────────────────────────────────────

    /**
     * Los nombres reales de severidad y probabilidad llegan a la config.
     *
     * La v1 nunca enseño c1..c5: el formulario hacia `I18n.t("severities.#{id}")`
     * contra su tabla `translations` y en pantalla salia «Temporal» o «Podría
     * suceder». `migrate-formats` leia solo `severities.name` —las claves— y
     * los selectores de aqui quedaron enseñando la tripa. Ahora los textos
     * viajan como mapas clave interna → texto, aparte de los valores.
     */
    public function test_los_nombres_reales_de_severidad_y_probabilidad_llegan_a_la_config(): void
    {
        $this->artisan('docufiz:migrate-formats')->assertSuccessful();

        $config = $this->configDeLaMatriz('AST');

        $this->assertSame(['c1' => 'Catastrófico', 'c3' => 'Temporal'], $config['severity_labels']);
        // `probabilities.2` no tiene traduccion en es: p2 no entra en el mapa
        // y la pantalla cae a la clave interna, como siempre hizo.
        $this->assertSame(['p1' => 'Podría suceder'], $config['probability_labels']);
        // El en llega aparte, y puede ser parcial.
        $this->assertSame(['c1' => 'Catastrophic'], $config['severity_labels_en']);
        // El pt existe en la v1 pero nadie lo pide: no se cuela en ningun mapa.
        $this->assertArrayNotHasKey('probability_labels_en', $config);

        // Y los VALORES siguen siendo las claves internas: las etiquetas son
        // solo presentacion.
        $this->assertSame(['c1', 'c3'], $config['severities']);
        $this->assertSame(['p1', 'p2'], $config['probabilities']);

        // El PTF comparte la misma matriz, con las mismas etiquetas.
        $this->assertSame($config['severity_labels'], $this->configDeLaMatriz('PTF')['severity_labels']);
    }

    /**
     * Los dos nombres que el dueño retoco llegan retocados.
     *
     * «Raro que suceda» → «Raro de suceder» e «Prácticamente imposible que
     * suceda» → «Imposible de suceder» (y su espejo en en). La base vieja es
     * de solo lectura, asi que el retoque vive en la migracion
     * (ETIQUETAS_RETOCADAS): por texto exacto ya limpio, no por clave.
     */
    public function test_los_nombres_que_el_dueño_retoco_llegan_retocados(): void
    {
        $viejo = DB::connection('legacy');

        // Con la mugre real encima: el retoque corre DESPUES de la limpieza.
        $viejo->table('translations')->where('locale', 'es')->where('key', 'probabilities.1')
            ->update(['value' => "--- Raro que suceda\n"]);
        $viejo->table('translations')->where('locale', 'es')->where('key', 'severities.3')
            ->update(['value' => "--- Prácticamente imposible que suceda\n"]);
        $viejo->table('translations')->where('locale', 'en')->where('key', 'severities.1')
            ->update(['value' => "--- Practically impossible to happen\n"]);

        $this->artisan('docufiz:migrate-formats')->assertSuccessful();

        $config = $this->configDeLaMatriz('AST');

        $this->assertSame('Raro de suceder', $config['probability_labels']['p1']);
        $this->assertSame('Imposible de suceder', $config['severity_labels']['c3']);
        $this->assertSame('Impossible to happen', $config['severity_labels_en']['c1']);
    }

    /** Sin filas en `translations` no hay mapas, y nada revienta. */
    public function test_sin_traducciones_la_config_no_lleva_mapas(): void
    {
        DB::connection('legacy')->table('translations')->delete();

        $this->artisan('docufiz:migrate-formats')->assertSuccessful();

        $config = $this->configDeLaMatriz('AST');

        $this->assertArrayNotHasKey('severity_labels', $config);
        $this->assertArrayNotHasKey('probability_labels', $config);
        $this->assertSame(['c1', 'c3'], $config['severities']);

        // Y el refresco de migrate-data tampoco los inventa ni se cae.
        $this->artisan('docufiz:migrate-data', ['paso' => 'documentos'])->assertSuccessful();

        $this->assertArrayNotHasKey('severity_labels', $this->configDeLaMatriz('AST'));
    }

    /**
     * El agujero por el que las etiquetas no llegaban nunca: en
     * `setup:project --datos` el sembrador crea las plantillas desde el JSON
     * congelado ANTES de `migrate-formats`, y este al verlas se aparta. El
     * refresco de `migrate-data` pisa los catalogos de la matriz EN SITIO
     * sobre la plantilla ya sembrada: misma fila, misma version, mismas
     * entregas colgando.
     */
    public function test_una_plantilla_ya_sembrada_se_refresca_en_sitio_sin_cambiar_version_ni_perder_entregas(): void
    {
        $this->seed(\Database\Seeders\FormTemplatesSeeder::class);

        $antes = \App\Models\FormTemplate::where('code', 'AST')->sole();
        $camposAntes = $antes->fields()->count();
        $congelada = $this->configDeLaMatriz('AST');

        // El JSON congelado trae las cinco claves internas y ningun mapa: es el
        // respaldo para cuando no hay base vieja delante.
        $this->assertSame(['c1', 'c2', 'c3', 'c4', 'c5'], $congelada['severities']);
        $this->assertArrayNotHasKey('severity_labels', $congelada);

        $this->migrarTodo();

        $despues = \App\Models\FormTemplate::where('code', 'AST')->sole();
        $config = $this->configDeLaMatriz('AST');

        // La plantilla es la misma fila, con la misma version.
        $this->assertSame($antes->id, $despues->id);
        $this->assertSame((int) $antes->version, (int) $despues->version);
        $this->assertSame($camposAntes, $despues->fields()->count());

        // Pero sus catalogos ya son los de la base vieja, etiquetas incluidas.
        $this->assertSame(['c1', 'c3'], $config['severities']);
        $this->assertSame(['c1' => 'Catastrófico', 'c3' => 'Temporal'], $config['severity_labels']);

        // Las entregas migradas cuelgan de ella y ahi siguen.
        $entregas = DB::table('form_submissions')->where('form_template_id', $despues->id)->count();
        $this->assertSame(1, $entregas);

        // Repetir el paso vuelve a refrescar —y lo dice— sin perder nada.
        $this->artisan('docufiz:migrate-data', ['paso' => 'documentos'])
            ->expectsOutputToContain('Catálogos de la matriz refrescados desde la base anterior')
            ->assertSuccessful();

        $this->assertSame($entregas, DB::table('form_submissions')->where('form_template_id', $despues->id)->count());
    }

    /**
     * La de oro: las respuestas guardan la clave interna, con o sin etiquetas.
     *
     * Hay 3 657 AST cuyos `severidad`/`probabilidad` son c1..c5 y la tabla
     * `config.matrix` se indexa por su posicion en el catalogo. Si alguien
     * cede a la tentacion de guardar el texto bonito, todo eso deja de cruzar.
     */
    public function test_las_respuestas_siguen_guardando_la_clave_interna_aunque_haya_etiquetas(): void
    {
        $this->migrarTodo();

        // La etiqueta esta en la config, lista para pintarse...
        $this->assertSame('Temporal', $this->configDeLaMatriz('AST')['severity_labels']['c3']);

        // ...pero lo guardado sigue siendo la clave interna.
        $filas = $this->respuestasDe('AST', 'matriz_de_riesgo');
        $this->assertSame('c3', $filas[0]['severidad']);
        $this->assertSame('p2', $filas[0]['probabilidad']);
    }

    /** La config del unico campo risk_matrix de esa plantilla. */
    protected function configDeLaMatriz(string $codigo): array
    {
        return \App\Models\FormTemplate::where('code', $codigo)->sole()
            ->fields()->where('field_type', 'risk_matrix')->sole()->config;
    }

    // ── evidencias ───────────────────────────────────────────────────────────

    public function test_las_firmas_de_la_v1_llegan_como_migradas_y_las_que_no_tienen_archivo_se_marcan(): void
    {
        $this->migrarTodo();

        $eventos = DB::table('signature_events')->whereNotNull('legacy_source')->get();

        // 2 eventos de trabajador validos (el tercero apunta a un plan_worker
        // que no existe) y 1 firma sin evento.
        //
        // Eran 4: el que falta es el de la aprobacion del «Ejecutante», que ya
        // no se migra porque ese rol dejo de firmar aprobaciones. Su firma no
        // se pierde —es la que dio como trabajador del plan, y esa si esta—:
        // lo que desaparece es la copia de la misma firma en el flujo, que era
        // justo el motivo de sacarlo de ahi.
        $this->assertSame(3, $eventos->count());
        $this->assertSame(['migrated'], $eventos->pluck('method')->unique()->values()->all());

        // Solo el trabajador 1 traia nombres de fichero.
        $this->assertCount(1, $eventos->where('evidence_missing', false));

        $archivos = DB::table('evidence_files')->orderBy('kind')->get();
        $this->assertSame(['face', 'signature'], $archivos->pluck('kind')->all());
        $this->assertSame('legacy/images_uploads/foto-1.webp', $archivos->firstWhere('kind', 'face')->file_path);

        // Lo migrado no entra en la cola de revision: se marca, no se revisa.
        $this->assertSame(0, DB::table('signature_events')->where('pending_review', true)->count());
    }

    public function test_los_marcadores_de_ia_no_generan_evidencia_y_se_cuentan_aparte(): void
    {
        $this->artisan('docufiz:migrate-formats')->assertSuccessful();
        $this->artisan('docufiz:migrate-data', ['paso' => 'todo'])->assertSuccessful();

        // 2 referencias reales (foto-1 y firma-1) y 5 marcadores. La tercera
        // era firma-apro-1, la de la aprobacion del «Ejecutante», que ya no se
        // migra: el representante no es una aprobacion.
        $this->assertSame(2, DB::table('evidence_files')->count());

        $this->artisan('docufiz:migrate-data', ['paso' => 'evidencias'])
            ->expectsOutputToContain('marcador de IA')
            ->expectsOutputToContain('no tiene archivo detras')
            ->assertSuccessful();
    }

    public function test_una_firma_sin_aprobador_no_se_atribuye_a_nadie(): void
    {
        $this->migrarTodo();

        // La aprobacion 3 tenia marcador de firma pero su aprobador no existe:
        // no hay a quien atribuirla, asi que no se crea evento.
        $this->assertSame(0, DB::table('signature_events')
            ->where('legacy_source', 'plan_approvals')->where('legacy_id', 3)->count());
    }

    // ── archivos ─────────────────────────────────────────────────────────────

    public function test_el_paso_archivos_copia_lo_que_encuentra_y_marca_lo_que_falta(): void
    {
        Storage::fake('local');
        $this->migrarTodo();

        // Una carpeta como la del servidor viejo, con solo una de las imagenes.
        $carpeta = sys_get_temp_dir() . '/v1-' . Str::random(8);
        mkdir($carpeta);
        $imagen = $this->pngDeUnPixel();
        file_put_contents($carpeta . '/foto-1.webp', $imagen);

        $this->artisan('docufiz:migrate-data', ['paso' => 'archivos', '--desde' => $carpeta])
            ->assertSuccessful();

        $copiada = DB::table('evidence_files')->where('kind', 'face')->first();
        $this->assertSame(hash('sha256', $imagen), $copiada->sha256);
        $this->assertSame(strlen($imagen), (int) $copiada->byte_size);
        Storage::disk('local')->assertExists($copiada->file_path);
        $this->assertFalse((bool) DB::table('signature_events')->where('id', $copiada->signature_event_id)->value('evidence_missing'));

        // La firma del trabajador 1 tampoco aparecio, pero su evento si tiene
        // la foto: un evento con dos archivos no queda perdido porque falte uno.
        $suFirma = DB::table('evidence_files')->where('file_path', 'legacy/images_uploads/firma-1.webp')->first();
        $this->assertSame($copiada->signature_event_id, $suFirma->signature_event_id);
        $this->assertFalse((bool) DB::table('signature_events')->where('id', $suFirma->signature_event_id)->value('evidence_missing'));

        // Y los eventos que llegaron sin ningun archivo detras —los marcadores
        // «signed_by_IA» de la v1— siguen marcados como evidencia perdida: el
        // paso de archivos no rescata lo que nunca fue un fichero.
        //
        // Aqui se miraba la firma de la aprobacion del «Ejecutante», que era el
        // unico evento con un solo archivo. Ya no se migra —el representante no
        // es una aprobacion— y en estos datos de la v1 no queda ningun otro
        // caso igual; la marca del evento se sigue comprobando, que es lo que
        // decide si la evidencia esta completa.
        $sinArchivos = DB::table('signature_events')
            ->whereNotNull('legacy_source')
            ->whereNotIn('id', DB::table('evidence_files')->select('signature_event_id'))
            ->get();

        $this->assertNotEmpty($sinArchivos);
        $this->assertTrue($sinArchivos->every(fn ($e) => (bool) $e->evidence_missing));

        array_map('unlink', glob($carpeta . '/*'));
        rmdir($carpeta);
    }

    public function test_el_paso_archivos_no_rompe_si_la_carpeta_no_esta(): void
    {
        $this->migrarTodo();

        // Los ficheros de la v1 no viven en el repositorio: que falten no puede
        // tumbar la migracion.
        $this->artisan('docufiz:migrate-data', ['paso' => 'archivos', '--desde' => '/no/existe'])
            ->expectsOutputToContain('No existe la carpeta')
            ->assertSuccessful();
    }

    // ── idempotencia ─────────────────────────────────────────────────────────

    public function test_correr_la_migracion_dos_veces_no_duplica_nada(): void
    {
        $this->migrarTodo();

        $antes = $this->fotografia();

        $this->artisan('docufiz:migrate-data', ['paso' => 'todo'])->assertSuccessful();

        $this->assertSame($antes, $this->fotografia());
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    /** @return array<int, mixed> el value_json de cada fila de ese campo, en orden */
    protected function respuestasDe(string $codigo, string $campo): array
    {
        return DB::table('form_answers as a')
            ->join('form_fields as f', 'f.id', '=', 'a.form_field_id')
            ->join('form_submissions as s', 's.id', '=', 'a.form_submission_id')
            ->join('form_templates as t', 't.id', '=', 's.form_template_id')
            ->where('t.code', $codigo)->where('f.code', $campo)
            ->orderBy('a.row_index')
            ->pluck('a.value_json')
            ->map(fn ($j) => json_decode((string) $j, true))
            ->all();
    }

    /**
     * Una empresa dada de baja en la v1 no puede llegar viva.
     *
     * El mapeo no copiaba `is_deleted` y el `get()` del origen tampoco filtra,
     * asi que todas las bajas del sistema viejo reaparecian activas en el
     * listado — con su RUC ocupando el indice unico y sin forma de saber que
     * alguien ya las habia dado de baja.
     */
    public function test_una_empresa_borrada_en_la_v1_llega_borrada(): void
    {
        $this->artisan('docufiz:migrate-data', ['paso' => 'empresas'])->assertSuccessful();

        $gamma = \App\Models\Company::withTrashed()->where('legacy_id', 3)->first();

        $this->assertNotNull($gamma);
        $this->assertTrue($gamma->trashed(), 'GAMMA estaba de baja en la v1 y llego viva.');
        $this->assertSame('Ya no trabaja con nosotros', $gamma->deleted_description);
        $this->assertNull(\App\Models\Company::where('legacy_id', 3)->first(),
            'La borrada no debe salir en el listado normal.');
    }

    /** Las vivas siguen vivas, que el filtro no se pase de listo. */
    public function test_las_empresas_vivas_de_la_v1_no_se_borran(): void
    {
        $this->artisan('docufiz:migrate-data', ['paso' => 'empresas'])->assertSuccessful();

        $this->assertSame(3, \App\Models\Company::whereNotNull('legacy_id')->count());
        $this->assertFalse(\App\Models\Company::where('legacy_id', 1)->first()->trashed());
    }

    /**
     * La antiguedad se respeta. Antes todo el catalogo nacia con la fecha de la
     * carga y los filtros «creado entre X e Y» no servian sobre lo migrado.
     */
    public function test_se_conserva_la_fecha_de_alta_original(): void
    {
        $this->artisan('docufiz:migrate-data', ['paso' => 'empresas'])->assertSuccessful();

        $alfa = \App\Models\Company::where('legacy_id', 1)->first();

        $this->assertSame('2019-03-04', $alfa->created_at->format('Y-m-d'));
    }

    /**
     * El RUC llega normalizado.
     *
     * En la v1 se podia teclear con guiones y espacios; el formulario y el
     * buscador de la v2 los quitan, asi que un RUC migrado tal cual no lo
     * encontraba nadie.
     */
    public function test_el_ruc_con_guiones_de_la_v1_llega_normalizado(): void
    {
        $this->artisan('docufiz:migrate-data', ['paso' => 'empresas'])->assertSuccessful();

        $delta = \App\Models\Company::where('legacy_id', 4)->first();

        $this->assertSame('20100000004', $delta->num_doc);
    }

    protected function fotografia(): array
    {
        $tablas = ['work_plans', 'work_plan_people', 'work_plan_approvals', 'form_submissions',
                   'form_answers', 'signature_events', 'evidence_files', 'users',
                   'work_types', 'work_locations', 'workstations', 'work_areas', 'approval_rules'];

        return collect($tablas)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    protected function pngDeUnPixel(): string
    {
        $img = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);

        return $png;
    }
}
