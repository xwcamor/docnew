<?php

namespace Tests\Feature\FieldWork;

use App\Models\Company;
use App\Models\FormAttachment;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;
use App\Services\FieldWork\FormSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Los adjuntos del formato: la HOJA X, en plural.
 *
 * El caso real es un permiso de trabajo de tres hojas que se fotografia en la
 * obra. Antes esto subia de uno en uno —un `<input type="file">` y un boton—,
 * la pantalla no enseñaba lo que ya habia entrado, y no habia manera de quitar
 * el que se subio por error.
 *
 * Lo que se comprueba aqui es lo que aguanta el servidor, que es lo unico que
 * cuenta: el `accept` del arrastrar-y-soltar es una comodidad del navegador y
 * se salta con un `curl`.
 */
class FormAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('es');

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['form_submissions.view', 'form_submissions.edit', 'work_plans.view'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
    }

    /** Tres hojas de una vez, que es como llega un permiso de trabajo. */
    public function test_se_suben_varios_archivos_en_una_sola_peticion(): void
    {
        Storage::fake('local');
        $entrega = $this->entrega();

        $this->actingAs($this->actor())
            ->post(route('field_work.forms.attach', $entrega->slug), [
                'files' => [
                    UploadedFile::fake()->image('hoja-1.jpg'),
                    UploadedFile::fake()->image('hoja-2.png'),
                    UploadedFile::fake()->create('anexo.pdf', 120, 'application/pdf'),
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $entrega->attachments()->count());

        // El nombre con el que llegaron: sin esto la pantalla no puede decir
        // cual es cual, porque la ruta en disco es una cadena al azar.
        $this->assertEqualsCanonicalizing(
            ['hoja-1.jpg', 'hoja-2.png', 'anexo.pdf'],
            $entrega->attachments()->pluck('original_name')->all(),
        );
    }

    /** Un archivo suelto sigue entrando: la ruta es tambien la de integraciones. */
    public function test_el_envio_de_un_solo_archivo_sigue_funcionando(): void
    {
        Storage::fake('local');
        $entrega = $this->entrega();

        $this->actingAs($this->actor())
            ->post(route('field_work.forms.attach', $entrega->slug), [
                'file' => UploadedFile::fake()->image('hoja.jpg'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $entrega->attachments()->count());
    }

    /**
     * Solo imagenes y PDF, y lo dice el SERVIDOR.
     *
     * El `accept` del arrastrar-y-soltar filtra en el navegador por comodidad,
     * pero es una cortesia: esto entra por `curl` sin despeinarse.
     */
    public function test_una_hoja_de_calculo_no_pasa(): void
    {
        Storage::fake('local');
        $entrega = $this->entrega();

        $this->actingAs($this->actor())
            ->post(route('field_work.forms.attach', $entrega->slug), [
                'files' => [UploadedFile::fake()->create('cuadro.xlsx', 20)],
            ])
            ->assertSessionHasErrors('files.0');

        $this->assertSame(0, $entrega->attachments()->count());
    }

    /** Y si en el lote va uno malo, no entra ninguno: el lote es uno solo. */
    public function test_un_archivo_malo_tumba_el_lote_entero(): void
    {
        Storage::fake('local');
        $entrega = $this->entrega();

        $this->actingAs($this->actor())
            ->post(route('field_work.forms.attach', $entrega->slug), [
                'files' => [
                    UploadedFile::fake()->image('buena.jpg'),
                    UploadedFile::fake()->create('cuadro.xlsx', 20),
                ],
            ])
            ->assertSessionHasErrors('files.1');

        $this->assertSame(0, $entrega->attachments()->count());
    }

    /** El que se colo se quita. */
    public function test_se_quita_un_adjunto(): void
    {
        Storage::fake('local');
        $entrega = $this->entrega();
        $adjunto = app(FormSubmissionService::class)->adjuntar($entrega, 'la-foto', 'image/png', null, 'hoja.png');

        $this->actingAs($this->actor())
            ->delete(route('field_work.forms.detach', [$entrega->slug, $adjunto->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $entrega->attachments()->count());
        Storage::disk('local')->assertMissing($adjunto->file_path);
    }

    /**
     * Lo confirmado no se toca, tampoco por aqui.
     *
     * Para quitar una foto de un formato cerrado hay que reabrirlo, que es una
     * accion que deja rastro. Sin esta comprobacion el borrado seria la puerta
     * de atras para alterar evidencia firmada.
     */
    public function test_no_se_quita_un_adjunto_de_una_entrega_confirmada(): void
    {
        Storage::fake('local');
        $entrega = $this->entrega();
        $adjunto = app(FormSubmissionService::class)->adjuntar($entrega, 'la-foto', 'image/png');

        $entrega->update(['status' => 'confirmed']);

        $this->actingAs($this->actor())
            ->delete(route('field_work.forms.detach', [$entrega->slug, $adjunto->id]));

        $this->assertSame(1, $entrega->fresh()->attachments()->count());
    }

    /** El adjunto viaja por id: el de otra entrega no se borra desde aqui. */
    public function test_no_se_quita_el_adjunto_de_otra_entrega(): void
    {
        Storage::fake('local');
        $entrega = $this->entrega();
        $otra    = $this->entrega('PTF');
        $ajeno   = app(FormSubmissionService::class)->adjuntar($otra, 'otra-foto', 'image/png');

        // JSON: en HTML el manejador global convierte el 404 en una redirección
        // con su aviso, igual que hace con el 403.
        $this->actingAs($this->actor())
            ->deleteJson(route('field_work.forms.detach', [$entrega->slug, $ajeno->id]))
            ->assertNotFound();

        $this->assertSame(1, $otra->fresh()->attachments()->count());
    }

    /**
     * El fichero del disco se comparte por hash entre entregas.
     *
     * `adjuntar()` no vuelve a escribir un contenido que ya estaba subido:
     * reutiliza la ruta. Asi que borrar una fila NO puede borrar el fichero
     * mientras otra siga apuntandolo, o la otra entrega se queda con un
     * adjunto que no abre.
     */
    public function test_quitar_un_adjunto_no_borra_el_fichero_que_otra_entrega_comparte(): void
    {
        Storage::fake('local');
        $servicio = app(FormSubmissionService::class);

        $entrega = $this->entrega();
        $otra    = $this->entrega('PTF');

        $mio      = $servicio->adjuntar($entrega, 'el-mismo-papel', 'image/png');
        $delOtro  = $servicio->adjuntar($otra, 'el-mismo-papel', 'image/png');

        $this->assertSame($mio->file_path, $delOtro->file_path, 'La deduplicacion por hash comparte la ruta.');

        $this->actingAs($this->actor())
            ->delete(route('field_work.forms.detach', [$entrega->slug, $mio->id]));

        $this->assertSame(0, $entrega->fresh()->attachments()->count());
        $this->assertSame(1, $otra->fresh()->attachments()->count());
        Storage::disk('local')->assertExists($delOtro->file_path);
    }

    /** La pantalla recibe lo que ya esta subido: antes no lo recibia. */
    public function test_la_pantalla_de_llenado_recibe_los_adjuntos(): void
    {
        Storage::fake('local');
        $entrega = $this->entrega();
        app(FormSubmissionService::class)->adjuntar($entrega, 'la-foto', 'image/png', null, 'hoja.png');

        $resp = $this->actingAs($this->actor())
            ->get(route('field_work.forms.open', [$entrega->workPlan->slug, $entrega->formTemplate->slug]))
            ->assertOk();

        $adjuntos = $resp->viewData('page')['props']['attachments'];

        $this->assertCount(1, $adjuntos);
        $this->assertSame('hoja.png', $adjuntos[0]['name']);
    }

    // ── Andamiaje ────────────────────────────────────────────────────────────

    private function actor(): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo(['form_submissions.view', 'form_submissions.edit', 'work_plans.view']);

        return $u;
    }

    /** Una entrega en borrador de un formato de solo subida. */
    private function entrega(string $codigo = 'HOJA'): FormSubmission
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        $plan = WorkPlan::firstOrCreate(['code' => 'OT-1'], $base + [
            'company_id' => Company::firstOrCreate(['num_doc' => '20100000001'], $base + [
                'name' => 'Contratista', 'complete_name' => 'Contratista SAC',
            ])->id,
            'work_type_id'     => WorkType::firstOrCreate(['code' => 'MTTO'], $base)->id,
            'work_location_id' => WorkLocation::firstOrCreate(['name' => 'Planta'], $base)->id,
            'user_id'          => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'description'      => 'Trabajo', 'date_start' => today(),
        ]);

        $plantilla = FormTemplate::create($base + [
            'slug' => Str::random(22), 'code' => $codigo, 'kind' => FormTemplate::UPLOAD_ONLY,
            'status' => 'published', 'version' => 1, 'requires_signature' => false, 'published_at' => now(),
        ]);

        return FormSubmission::create($base + [
            'slug' => Str::random(22), 'work_plan_id' => $plan->id,
            'form_template_id' => $plantilla->id, 'template_version' => 1, 'status' => 'draft',
        ]);
    }
}
