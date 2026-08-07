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
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        Role::firstOrCreate(
            ['name' => 'Usuario de campo', 'guard_name' => 'web'],
            ['slug' => Str::random(22), 'description' => 'Rol de menos privilegios'],
        );

        LegacyDatabaseFixture::levantar();
        LegacyDatabaseFixture::sembrar();
    }

    /** Las plantillas del motor y despues todos los datos. */
    protected function migrarTodo(): void
    {
        $this->artisan('docufiz:migrate-formats')->assertSuccessful();
        $this->artisan('docufiz:migrate-data', ['paso' => 'todo'])->assertSuccessful();
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

    public function test_los_codigos_de_plan_repetidos_se_desambiguan_sin_perder_el_original(): void
    {
        $this->migrarTodo();

        $codigos = DB::table('work_plans')->whereNotNull('legacy_id')->orderBy('legacy_id')->pluck('code', 'legacy_id');

        $this->assertSame('PE26-1501-0800', $codigos[1]);
        $this->assertSame('PE26-1501-0800-2', $codigos[2]);

        // El codigo original se recupera siempre por el legacy_id.
        $this->assertSame(2, DB::table('work_plans')->where('code', 'PE26-1501-0800-2')->value('legacy_id'));
    }

    public function test_solo_se_migran_los_catalogos_del_pais_que_usan_los_planes(): void
    {
        $this->migrarTodo();

        $this->assertSame(1, DB::table('work_types')->whereNotNull('legacy_id')->count());
        $this->assertSame(1, DB::table('work_locations')->whereNotNull('legacy_id')->count());
        $this->assertSame(1, DB::table('workstations')->whereNotNull('legacy_id')->count());
        $this->assertSame(3, DB::table('approval_rules')->whereNotNull('legacy_id')->count());

        // Los formatos que exige cada tipo de trabajo tambien vienen de la v1.
        $this->assertSame(2, DB::table('work_type_form_templates')->count());
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

    public function test_una_aprobacion_con_aprobador_inexistente_se_migra_sin_persona(): void
    {
        $this->migrarTodo();

        $this->assertSame(3, DB::table('work_plan_approvals')->count());

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
            ->expectsOutputToContain('codigo repetido')
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
        $this->assertSame('Si', $filas[0][0]['respuesta']);
        $this->assertSame('No', $filas[0][1]['respuesta']);
        $this->assertSame('Tienes permiso?', $filas[0][0]['pregunta']);
    }

    public function test_el_epp_llega_por_trabajador_y_apunta_a_la_persona_de_destino(): void
    {
        $this->migrarTodo();

        $filas = $this->respuestasDe('EPP', 'epp_por_trabajador');

        $this->assertCount(1, $filas);
        $this->assertSame('Conforme', $filas[0]['items'][0]['respuesta']);
        // Sin respuesta en la v1 se queda sin respuesta.
        $this->assertNull($filas[0]['items'][1]['respuesta']);
        $this->assertSame('Cambiar casco', $filas[0]['correction_measure']);

        $persona = DB::table('work_plan_people')->where('legacy_id', 1)->value('person_id');
        $this->assertSame($persona, $filas[0]['person_id']);
    }

    public function test_el_ihm_llega_por_herramienta_con_las_etiquetas_correctas(): void
    {
        $this->migrarTodo();

        $filas = $this->respuestasDe('IHM', 'inspeccion_de_herramientas');

        $this->assertCount(1, $filas);
        $this->assertSame('Amoladora', $filas[0]['herramienta']);
        $this->assertFalse($filas[0]['habilitada']);
        $this->assertSame('Cumple', $filas[0]['items'][0]['respuesta']);
        $this->assertSame('No cumple', $filas[0]['items'][1]['respuesta']);
    }

    // ── evidencias ───────────────────────────────────────────────────────────

    public function test_las_firmas_de_la_v1_llegan_como_migradas_y_las_que_no_tienen_archivo_se_marcan(): void
    {
        $this->migrarTodo();

        $eventos = DB::table('signature_events')->whereNotNull('legacy_source')->get();

        // 2 eventos de trabajador validos (el tercero apunta a un plan_worker
        // que no existe), 1 firma sin evento y 1 aprobacion con aprobador.
        $this->assertSame(4, $eventos->count());
        $this->assertSame(['migrated'], $eventos->pluck('method')->unique()->values()->all());

        // Solo el trabajador 1 y la aprobacion 1 traian nombres de fichero.
        $this->assertCount(2, $eventos->where('evidence_missing', false));

        $archivos = DB::table('evidence_files')->orderBy('kind')->get();
        $this->assertSame(['face', 'signature', 'signature'], $archivos->pluck('kind')->all());
        $this->assertSame('legacy/images_uploads/foto-1.webp', $archivos->firstWhere('kind', 'face')->file_path);

        // Lo migrado no entra en la cola de revision: se marca, no se revisa.
        $this->assertSame(0, DB::table('signature_events')->where('pending_review', true)->count());
    }

    public function test_los_marcadores_de_ia_no_generan_evidencia_y_se_cuentan_aparte(): void
    {
        $this->artisan('docufiz:migrate-formats')->assertSuccessful();
        $this->artisan('docufiz:migrate-data', ['paso' => 'todo'])->assertSuccessful();

        // 3 referencias reales (foto-1, firma-1, firma-apro-1) y 5 marcadores.
        $this->assertSame(3, DB::table('evidence_files')->count());

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

        // La firma de la aprobacion es el unico archivo de su evento y no
        // aparecio: ese si queda marcado como evidencia perdida.
        $perdida = DB::table('evidence_files')->where('file_path', 'legacy/images_uploads/firma-apro-1.webp')->first();
        $this->assertSame(0, (int) $perdida->byte_size);
        $this->assertTrue((bool) DB::table('signature_events')->where('id', $perdida->signature_event_id)->value('evidence_missing'));

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
