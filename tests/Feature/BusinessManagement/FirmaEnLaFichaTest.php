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

        $this->actingAs($this->admin())
            ->get(route('business_management.work_plans.signer_face', [$plan->slug, $persona->slug]))
            ->assertOk();
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

        $this->actingAs($this->admin())
            ->get(route('business_management.work_plans.signer_face', [$plan->slug, $ajena->slug]))
            ->assertRedirect(route('dashboard_management.dashboards.index'))
            ->assertSessionHas('error');
    }

    /** La ficha manda la URL de la cara del aprobador, que antes ni existia. */
    public function test_la_ficha_manda_la_cara_del_aprobador(): void
    {
        $plan = $this->plan();
        $this->aprobacionFirmada($plan, $this->persona('44445555'), SignatureEvent::FACE_RECOGNITION);

        $this->actingAs($this->admin())
            ->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page->where('approvals.0.face_url', fn ($u) => filled($u)));
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
