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
 * Quien puede tocar una firma ajena — y quien no.
 *
 * Aqui vivia la bandeja de firmas por revisar, que ya no existe: la firma sin
 * reconocimiento vale desde que se toma y lo unico que quedo de aquello es
 * `resolve`, que anula una firma y es SOLO SUPER. Lo que estas pruebas
 * vigilan ahora son las dos vallas que siguen en pie:
 *
 *   · el permiso `signature_events.review` ya NO resuelve firmas — solo
 *     autoriza la manual, retira biometria y sirve las evidencias;
 *   · las evidencias no cruzan empresas: un admin de la empresa A con el
 *     permiso no se descarga la foto de la cara de un trabajador de la B.
 *     El permiso dice QUE puede ver evidencias, no DE QUIEN — eso lo pone el
 *     plan del que cuelga la firma, que si esta acotado por workspace.
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

    /**
     * El permiso de revision ya no resuelve firmas, ni siquiera las del propio
     * workspace: anular quedo SOLO SUPER, desde el album de las firmas.
     */
    public function test_el_permiso_de_revision_ya_no_resuelve_firmas(): void
    {
        $mia = $this->firmaPendiente(1);

        $this->actingAs($this->revisor(1));

        $this->post(route('field_work.signatures.resolve', $mia->id), ['accepted' => false])
            ->assertRedirect(route('dashboard_management.dashboards.index'));

        $this->assertTrue((bool) $mia->fresh()->pending_review, 'la firma sigue tal cual: nadie la toco');
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

    /** El super sí anula la de cualquier empresa: es el caso legítimo de soporte. */
    public function test_el_super_anula_la_firma_de_cualquier_empresa(): void
    {
        $ajena = $this->firmaPendiente(2);

        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 's']);
        $super = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $super->assignRole('super');

        $this->actingAs($super)
            ->post(route('field_work.signatures.resolve', $ajena->id), ['accepted' => false])
            ->assertSessionHas('success');

        $fresca = $ajena->fresh();
        $this->assertFalse((bool) $fresca->pending_review, 'la firma quedo resuelta');
        $this->assertNotNull($fresca->reviewed_at);
        $this->assertFalse((bool) $fresca->signable->fresh()->is_approved,
            'anular tumba la aprobacion del firmable');
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    protected function revisor(int $tenant): User
    {
        $usuario = User::factory()->create(['tenant_id' => $tenant, 'country_id' => 1, 'locale_id' => 1]);
        $usuario->assignRole('admin');
        $usuario->givePermissionTo('signature_events.review');

        return $usuario;
    }

    /** Una firma sin reconocimiento colgando de un trabajador de un plan de ese workspace. */
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
            'is_approved' => true,
        ]);

        return SignatureEvent::create([
            'signable_type' => WorkPlanPerson::class, 'signable_id' => $asignado->id,
            'person_id' => $persona->id, 'role_signed' => 'worker', 'signed_at' => now(),
            'method' => SignatureEvent::MANUAL, 'pending_review' => true, 'tenant_id' => $tenant,
        ]);
    }
}
