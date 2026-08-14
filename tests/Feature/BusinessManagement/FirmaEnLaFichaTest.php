<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\ApprovalRule;
use App\Models\Company;
use App\Models\EvidenceFile;
use App\Models\Person;
use App\Models\SignatureEvent;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanApproval;
use App\Models\WorkPlanPerson;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lo que la ficha del plan cuenta de cada firma.
 *
 * Dos huecos que se veian a simple vista y uno que no:
 *
 *   - La cara solo salia en la cuadrilla. Un aprobador que no fuera ademas
 *     trabajador —el supervisor autorizante, casi siempre— se quedaba sin ella,
 *     y es justo de quien mas interesa saber que estuvo.
 *   - Y nada decia CON QUE se comprobo la firma. Una verificada por la cara y
 *     una que se capturo porque el servidor NO reconocio salian identicas: la
 *     misma hora y la misma marca verde. La segunda es la que hay que ir a
 *     revisar.
 */
class FirmaEnLaFichaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sin esto la peticion se va en un 302 al prefijo de idioma y la
        // prueba no llega ni a tocar el controlador.
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        Storage::fake('local');
    }

    private function base(): array
    {
        return ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];
    }

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Admin de prueba']);

        foreach (['work_plans.view', 'work_plans.edit', 'people.view_private_info'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo(['work_plans.view', 'work_plans.edit', 'people.view_private_info']);

        return $u;
    }

    /**
     * Quien SI ve caras y rastro: el super, y solo el.
     *
     * Antes bastaba `people.view_private_info` —que el admin tambien tiene— y
     * el dueño del producto lo endurecio: «solo el super ve fotos de los
     * trabajadores en Ver Plan».
     */
    private function super(): User
    {
        $u = $this->admin();
        $u->assignRole(Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Super de prueba']));

        return $u;
    }

    private function plan(): WorkPlan
    {
        return WorkPlan::create($this->base() + [
            'company_id' => Company::firstOrCreate(['num_doc' => '20100000001'],
                $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'])->id,
            'work_type_id'     => WorkType::firstOrCreate(['code' => 'MTTO'], $this->base())->id,
            'work_location_id' => WorkLocation::firstOrCreate(['name' => 'Planta'], $this->base())->id,
            'user_id'          => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'code' => 'PE26-0808-0001', 'description' => 'Trabajo', 'date_start' => '2026-08-08',
        ]);
    }

    private function persona(string $doc): Person
    {
        return Person::create($this->base() + [
            'doc_type' => 'DNI', 'num_doc' => $doc,
            'name' => 'Ana', 'lastname' => 'Quispe', 'is_active' => true,
        ]);
    }

    /** Una aprobacion firmada por alguien que NO esta en la cuadrilla. */
    private function aprobacionFirmada(WorkPlan $plan, Person $persona, string $metodo): WorkPlanApproval
    {
        $regla = ApprovalRule::create($this->base() + [
            'name' => 'Autorizante', 'approver_role' => 'supervisor',
            'priority_level' => 1, 'is_required' => true, 'is_active' => true,
        ]);

        $aprobacion = $plan->approvals()->create([
            'slug' => Str::random(22), 'approval_rule_id' => $regla->id,
            'person_id' => $persona->id, 'is_required' => true, 'is_approved' => true,
        ]);

        $evento = SignatureEvent::create([
            'signable_type' => (new WorkPlanApproval)->getMorphClass(),
            'signable_id' => $aprobacion->id, 'person_id' => $persona->id,
            'role_signed' => 'supervisor', 'signed_at' => now(), 'method' => $metodo,
            'used_ai' => $metodo === SignatureEvent::FACE_RECOGNITION,
            'pending_review' => $metodo !== SignatureEvent::FACE_RECOGNITION,
            'tenant_id' => 1,
        ]);

        Storage::disk('local')->put('evidencias/cara.webp', 'bytes');

        EvidenceFile::create([
            'signature_event_id' => $evento->id, 'kind' => EvidenceFile::FACE,
            'file_path' => 'evidencias/cara.webp', 'sha256' => str_repeat('a', 64), 'byte_size' => 5,
        ]);

        return $aprobacion;
    }

    /**
     * La cara del aprobador se sirve aunque no este en la cuadrilla.
     *
     * Antes la ruta solo miraba `work_plan_people`, asi que devolvia 404 y la
     * fila del flujo se quedaba sin el icono.
     */
    public function test_la_cara_del_aprobador_se_sirve(): void
    {
        $plan = $this->plan();
        $persona = $this->persona('44445555');
        $this->aprobacionFirmada($plan, $persona, SignatureEvent::TIMEOUT_CAPTURE);

        $this->assertSame(0, $plan->people()->count(), 'El aprobador no esta en la cuadrilla: ese es el caso.');

        $this->actingAs($this->super())
            ->get(route('business_management.work_plans.signer_face', [$plan->slug, $persona->slug]))
            ->assertOk();
    }

    /**
     * Con la evidencia sin archivo detras se cae a la foto de la ficha.
     *
     * Las filas de evidencia que apuntan a `legacy/…` son un MARCADOR y no un
     * fichero: la importacion escribe ahi el nombre que la v1 tenia en su
     * columna, y el paso `archivos` lo cambia por la ruta real cuando la foto
     * aparece en la carpeta. Si nunca aparecio —y en la v1 el 60 % de esas
     * referencias eran «detected_by_IA», que jamas fue un archivo— la fila se
     * queda apuntando a la nada.
     *
     * Esa fila ganaba sobre la foto de la ficha, la ruta no resolvia y la
     * ventana de la firma se abria con la imagen rota. Teniendo la foto buena
     * de la persona ahi al lado.
     */
    public function test_si_la_evidencia_es_un_marcador_se_enseña_la_foto_de_la_ficha(): void
    {
        $plan = $this->plan();
        $persona = $this->persona('44445555');
        $aprobacion = $this->aprobacionFirmada($plan, $persona, SignatureEvent::FACE_RECOGNITION);

        // La evidencia queda como la deja la importacion cuando el fichero no
        // llego nunca, y la persona tiene su foto de referencia.
        EvidenceFile::whereIn('signature_event_id', SignatureEvent::where('signable_id', $aprobacion->id)->select('id'))
            ->update(['file_path' => 'legacy/images_uploads/detected_by_IA']);

        Storage::disk('local')->put('fotos/ana.webp', 'bytes-de-la-ficha');

        \App\Models\PersonPhoto::create([
            'person_id' => $persona->id, 'file_path' => 'fotos/ana.webp',
            'sha256' => str_repeat('b', 64),
            'source' => \App\Models\PersonPhoto::MIGRADA, 'valid_from' => now(),
        ]);

        $respuesta = $this->actingAs($this->super())
            ->get(route('business_management.work_plans.signer_face', [$plan->slug, $persona->slug]));

        $respuesta->assertOk();
        $this->assertSame('bytes-de-la-ficha', $respuesta->streamedContent());
    }

    /**
     * Y a alguien que no pinta nada en el plan se le sigue negando.
     *
     * El controlador aborta con 404, y para un usuario con sesion la
     * aplicacion convierte eso en una vuelta al panel con un aviso en vez de
     * una pagina de error (ver `bootstrap/app.php`). Se comprueba lo que el
     * usuario ve, no lo que se lanza por dentro.
     */
    public function test_a_un_ajeno_al_plan_no_se_le_saca_la_cara(): void
    {
        $plan = $this->plan();
        $ajena = $this->persona('99998888');

        $this->actingAs($this->super())
            ->get(route('business_management.work_plans.signer_face', [$plan->slug, $ajena->slug]))
            ->assertRedirect(route('dashboard_management.dashboards.index'))
            ->assertSessionHas('error');
    }

    /** La ficha manda la URL de la cara del aprobador, que antes ni existia. */
    public function test_la_ficha_manda_la_cara_del_aprobador(): void
    {
        $plan = $this->plan();
        $this->aprobacionFirmada($plan, $this->persona('44445555'), SignatureEvent::FACE_RECOGNITION);

        $this->actingAs($this->super())
            ->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page->where('approvals.0.face_url', fn ($u) => filled($u)));
    }

    /**
     * Al admin la ficha NO le manda ni la cara ni el rastro.
     *
     * Cambio de reglas del dueño del producto: «solo el super ve fotos de los
     * trabajadores en Ver Plan». El admin conserva `people.view_private_info`
     * —el DNI sigue siendo suyo— pero `face_url` y `audit` le llegan en nulo,
     * y con eso la tarjeta ni pinta el icono de la camara.
     */
    public function test_al_admin_no_le_llega_ni_la_cara_ni_el_rastro(): void
    {
        $plan = $this->plan();
        $this->aprobacionFirmada($plan, $this->persona('44445555'), SignatureEvent::FACE_RECOGNITION);

        $this->actingAs($this->admin())
            ->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page
                ->where('approvals.0.face_url', null)
                ->where('approvals.0.signature.audit', null));
    }

    /**
     * Y la ruta de la imagen tampoco se le abre: un payload en nulo no
     * protege una URL adivinable. El 403 del middleware se convierte en la
     * vuelta al panel con aviso (ver `bootstrap/app.php`).
     */
    public function test_la_ruta_de_la_cara_no_se_le_abre_al_admin(): void
    {
        $plan = $this->plan();
        $persona = $this->persona('44445555');
        $this->aprobacionFirmada($plan, $persona, SignatureEvent::FACE_RECOGNITION);

        $this->actingAs($this->admin())
            ->get(route('business_management.work_plans.signer_face', [$plan->slug, $persona->slug]))
            ->assertRedirect(route('dashboard_management.dashboards.index'))
            ->assertSessionHas('error');
    }

    /**
     * Y dice con QUE se comprobo cada firma.
     *
     * Es la diferencia entre «el servidor reconocio la cara» y «no reconocio,
     * se guardo la foto y esto queda por revisar», que en la ficha se contaban
     * exactamente igual.
     */
    public function test_la_ficha_dice_como_se_firmo(): void
    {
        $plan = $this->plan();
        $this->aprobacionFirmada($plan, $this->persona('44445555'), SignatureEvent::TIMEOUT_CAPTURE);

        $this->actingAs($this->admin())
            ->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page
                ->where('approvals.0.signature.method', SignatureEvent::TIMEOUT_CAPTURE)
                ->where('approvals.0.signature.verified', false)
                ->where('approvals.0.signature.pending_review', true));
    }

    /** Una reconocida por la cara sale verificada y sin nada que revisar. */
    public function test_una_firma_reconocida_sale_verificada(): void
    {
        $plan = $this->plan();
        $this->aprobacionFirmada($plan, $this->persona('44445555'), SignatureEvent::FACE_RECOGNITION);

        $this->actingAs($this->admin())
            ->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page
                ->where('approvals.0.signature.verified', true)
                ->where('approvals.0.signature.pending_review', false));
    }

    /**
     * Una firma migrada que SI se reconocio lo dice.
     *
     * Su metodo es `migrated` —el reconocimiento de la v1 lo decidia el
     * navegador y no dejo prueba— pero se conservo lo que aquel sistema creia.
     * Decir «del sistema anterior» de una firma que se reconocio no cuenta
     * nada: lo que se pregunta mirando la fila es como se comprobo a esa
     * persona. La salvedad va en el tooltip, que la pantalla arma con
     * `used_ai`.
     */
    public function test_una_firma_migrada_reconocida_lo_dice(): void
    {
        $plan = $this->plan();
        $persona = $this->persona('44445555');
        $aprobacion = $this->aprobacionFirmada($plan, $persona, SignatureEvent::MIGRATED);

        SignatureEvent::where('signable_id', $aprobacion->id)->update(['used_ai' => true]);

        $this->actingAs($this->admin())
            ->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page
                ->where('approvals.0.signature.method', SignatureEvent::MIGRATED)
                ->where('approvals.0.signature.used_ai', true));
    }

    /** Y una migrada que no reconocio se queda como lo que es. */
    public function test_una_firma_migrada_sin_reconocer_no_presume(): void
    {
        $plan = $this->plan();
        $persona = $this->persona('44445555');
        $aprobacion = $this->aprobacionFirmada($plan, $persona, SignatureEvent::MIGRATED);

        SignatureEvent::where('signable_id', $aprobacion->id)->update(['used_ai' => false]);

        $this->actingAs($this->admin())
            ->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page->where('approvals.0.signature.used_ai', false));
    }

    /**
     * Las coordenadas viajan en numero, no solo en texto.
     *
     * La ficha las pinta en un mapa, y un mapa no se arma con la cadena
     * «-12.046374, -77.042793». El texto se sigue componiendo en la pantalla
     * —es lo que se copia a un informe— pero el dato que se manda es el numero.
     */
    public function test_la_ficha_manda_las_coordenadas_para_el_mapa(): void
    {
        $plan = $this->plan();
        $aprobacion = $this->aprobacionFirmada($plan, $this->persona('44445555'), SignatureEvent::FACE_RECOGNITION);

        SignatureEvent::where('signable_id', $aprobacion->id)->update([
            'latitude' => -12.046374, 'longitude' => -77.042793,
            'device_id' => '6f1c9a2e-4b77-4c31-9a0d-77f2b1c0e5aa',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 13) Chrome/126.0.0.0 Mobile Safari/537.36',
        ]);

        $this->actingAs($this->super())
            ->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page
                ->where('approvals.0.signature.audit.latitude', -12.046374)
                ->where('approvals.0.signature.audit.longitude', -77.042793)
                // El aparato y el navegador van ENTEROS. La pantalla los
                // resume —«Chrome 126 · Android 13»— y deja el crudo
                // desplegable detras; recortarlos aqui seria tirar el unico
                // dato que vale si un dia hay que discutir la firma.
                ->where('approvals.0.signature.audit.device_id', '6f1c9a2e-4b77-4c31-9a0d-77f2b1c0e5aa')
                ->where(
                    'approvals.0.signature.audit.user_agent',
                    'Mozilla/5.0 (Linux; Android 13) Chrome/126.0.0.0 Mobile Safari/537.36',
                ));
    }

    /** Sin ubicacion no hay mapa que pintar, y el resto del rastro sigue igual. */
    public function test_una_firma_sin_ubicacion_manda_las_coordenadas_en_nulo(): void
    {
        $plan = $this->plan();
        $this->aprobacionFirmada($plan, $this->persona('44445555'), SignatureEvent::FACE_RECOGNITION);

        $this->actingAs($this->super())
            ->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page
                ->where('approvals.0.signature.audit.latitude', null)
                ->where('approvals.0.signature.audit.longitude', null)
                ->where('approvals.0.signature.verified', true));
    }

    /** La cuadrilla tambien lo lleva: es la misma pregunta en la otra columna. */
    public function test_la_cuadrilla_tambien_dice_como_se_firmo(): void
    {
        $plan = $this->plan();
        $persona = $this->persona('11112222');

        $asignado = $plan->people()->create(['slug' => Str::random(22), 'person_id' => $persona->id, 'is_approved' => true]);

        SignatureEvent::create([
            'signable_type' => (new WorkPlanPerson)->getMorphClass(),
            'signable_id' => $asignado->id, 'person_id' => $persona->id,
            'role_signed' => 'worker', 'signed_at' => now(),
            'method' => SignatureEvent::FACE_RECOGNITION, 'used_ai' => true,
            'pending_review' => false, 'tenant_id' => 1,
        ]);

        $this->actingAs($this->admin())
            ->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page
                ->where('crew.0.signature.method', SignatureEvent::FACE_RECOGNITION));
    }
}
