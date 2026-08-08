<?php

namespace Tests\Feature\FieldWork;

use App\Models\Company;
use App\Models\EvidenceFile;
use App\Models\Person;
use App\Models\SignatureEvent;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
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
 * La bandeja de firmas por revisar no cruza empresas.
 *
 * `signature_events` no lleva scope de workspace propio —solo `Auditable`—, y
 * los tres caminos de la bandeja consultaban la tabla a pelo. Un admin de la
 * empresa A con `signature_events.review` veia la lista de firmas dudosas de la
 * empresa B: nombre, documento y **la foto de la cara** de gente que no es suya.
 * Con el id en la mano ademas podia darlas por buenas.
 *
 * El permiso dice QUE puede revisar firmas, no DE QUIEN. Esto ultimo lo pone el
 * plan del que cuelga la firma, que si esta acotado por workspace.
 */
class SignatureReviewScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        foreach ([1, 2] as $id) {
            DB::table('tenants')->insertOrIgnore([['id' => $id, 'slug' => Str::random(22), 'name' => 'Empresa ' . $id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        }

        Permission::firstOrCreate(['name' => 'signature_events.review', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);
    }

    public function test_la_bandeja_solo_lista_las_firmas_del_propio_workspace(): void
    {
        $mia   = $this->firmaPendiente(1);
        $ajena = $this->firmaPendiente(2);

        $this->actingAs($this->revisor(1));

        $this->get(route('field_work.signatures.review'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('FieldWork/SignatureReview')
                ->where('events.total', 1)
                ->where('events.data.0.id', $mia->id));

        // Y la de la otra empresa no aparece por ningun lado del JSON.
        $this->assertSame(2, SignatureEvent::withoutGlobalScopes()->count(),
            'las dos firmas siguen en la tabla: lo que cambia es quien las ve');
        $this->assertNotNull($ajena->fresh());
    }

    public function test_no_se_resuelve_la_firma_de_otra_empresa(): void
    {
        $ajena = $this->firmaPendiente(2);

        $this->actingAs($this->revisor(1));

        // 404 y no 403: fuera del workspace esa fila no existe, y decir «no
        // puedes» ya confirma que existe. En el navegador el 404 lo convierte
        // `bootstrap/app.php` en una vuelta al panel con su aviso.
        $this->post(route('field_work.signatures.resolve', $ajena->id), ['accepted' => true])
            ->assertRedirect(route('dashboard_management.dashboards.index'));

        $this->assertTrue((bool) $ajena->fresh()->pending_review, 'la firma ajena sigue pendiente');
    }

    public function test_no_se_descarga_la_foto_de_la_firma_de_otra_empresa(): void
    {
        Storage::fake('local');

        $ajena = $this->firmaPendiente(2);
        Storage::disk('local')->put('evidencias/ajena.webp', 'binario');

        $archivo = EvidenceFile::create([
            'signature_event_id' => $ajena->id, 'kind' => EvidenceFile::FACE,
            'file_path' => 'evidencias/ajena.webp', 'sha256' => hash('sha256', 'binario'),
            'byte_size' => 7, 'width' => 320, 'height' => 240,
        ]);

        $this->actingAs($this->revisor(1));

        $this->get(route('field_work.signatures.evidence', $archivo->id))
            ->assertRedirect(route('dashboard_management.dashboards.index'));
    }

    /** El super sí las ve todas: es el caso legítimo de soporte. */
    public function test_el_super_ve_las_de_todas_las_empresas(): void
    {
        $this->firmaPendiente(1);
        $this->firmaPendiente(2);

        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 's']);
        $super = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $super->assignRole('super');

        $this->actingAs($super)
            ->get(route('field_work.signatures.review'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('events.total', 2));
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    protected function revisor(int $tenant): User
    {
        $usuario = User::factory()->create(['tenant_id' => $tenant, 'country_id' => 1, 'locale_id' => 1]);
        $usuario->assignRole('admin');
        $usuario->givePermissionTo('signature_events.review');

        return $usuario;
    }

    /** Una firma dudosa colgando de un trabajador de un plan de ese workspace. */
    protected function firmaPendiente(int $tenant): SignatureEvent
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => $tenant, 'created_by' => 1];

        $empresa = Company::withoutGlobalScopes()->create($base + [
            'num_doc' => (string) random_int(20000000000, 20999999999),
            'name' => 'Contratista ' . $tenant, 'complete_name' => 'Contratista SAC', 'is_active' => true,
        ]);

        $persona = Person::withoutGlobalScopes()->create($base + [
            'doc_type' => 'DNI', 'num_doc' => (string) random_int(40000000, 49999999),
            'name' => 'Ana', 'lastname' => 'Quispe',
        ]);

        $tipo  = WorkType::withoutGlobalScopes()->create($base + ['code' => 'MTTO-' . $tenant]);
        $lugar = WorkLocation::withoutGlobalScopes()->create($base + ['name' => 'Planta ' . $tenant]);
        $user  = User::factory()->create(['tenant_id' => $tenant, 'country_id' => 1, 'locale_id' => 1]);

        $plan = WorkPlan::withoutGlobalScopes()->create($base + [
            'slug' => Str::random(22), 'company_id' => $empresa->id, 'work_type_id' => $tipo->id,
            'work_location_id' => $lugar->id, 'user_id' => $user->id,
            'code' => 'OT-' . random_int(1000, 9999), 'description' => 'Mantenimiento', 'date_start' => today(),
        ]);

        $asignado = WorkPlanPerson::create([
            'slug' => Str::random(22), 'work_plan_id' => $plan->id, 'person_id' => $persona->id,
        ]);

        return SignatureEvent::create([
            'signable_type' => WorkPlanPerson::class, 'signable_id' => $asignado->id,
            'person_id' => $persona->id, 'role_signed' => 'worker', 'signed_at' => now(),
            'method' => SignatureEvent::MANUAL, 'pending_review' => true, 'tenant_id' => $tenant,
        ]);
    }
}
