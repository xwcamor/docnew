<?php

namespace Tests\Feature\FieldWork;

use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EvidenceFile;
use App\Models\Person;
use App\Models\SignatureEvent;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanApproval;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El album de las firmas: plan, trabajador y la foto de ese momento.
 *
 * Lo pidio el dueño del producto con tres reglas, y las tres se comprueban:
 *
 *   · «esto es codigo solo para SUPER» — la pantalla y la entrada del menu no
 *     existen para nadie mas, ni para el admin del workspace;
 *   · «no quiero que audites eso» — mirar el album no escribe en ningun
 *     historial;
 *   · «no deben exhibirse esos parametros ni en logs» — los parametros de la
 *     captura (coincidencia, umbral, coordenadas, aparato, IP, navegador) no
 *     van en el payload del album NI en `audit_logs`, y la fila de la foto no
 *     escribe apunte ninguno.
 */
class FotoDelMomentoTest extends TestCase
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
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        Storage::fake('local');
    }

    // ── El album ────────────────────────────────────────────────────────────

    /** El super ve las tres cosas: el plan, el trabajador y la foto. Y NADA mas. */
    public function test_el_super_ve_plan_trabajador_y_foto(): void
    {
        [$plan] = $this->firmaConFoto();

        $this->actingAs($this->super())
            ->get(route('field_work.signatures.photos'))
            ->assertInertia(fn ($page) => $page
                ->component('FieldWork/SignaturePhotos')
                ->where('events.data.0.person', 'Quispe Ana')
                ->where('events.data.0.plan.code', $plan->code)
                ->where('events.data.0.photo_url', fn ($u) => filled($u))
                ->where('events.data.0.taken_at', fn ($t) => filled($t))
                // Los parametros de la captura no viajan: ni coincidencia, ni
                // coordenadas, ni aparato. Es la regla de la pantalla.
                ->missing('events.data.0.match_percent')
                ->missing('events.data.0.latitude')
                ->missing('events.data.0.ip_address')
                ->missing('events.data.0.device_id'));
    }

    /** Al admin del workspace ni se le abre: es solo del super. */
    public function test_al_admin_no_se_le_abre(): void
    {
        $this->firmaConFoto();

        $this->actingAs($this->admin())
            ->get(route('field_work.signatures.photos'))
            ->assertRedirect(route('dashboard_management.dashboards.index'))
            ->assertSessionHas('error');
    }

    /**
     * Una firma cuya unica «foto» es un marcador de la importacion no sale.
     *
     * Las filas `legacy/…` apuntan al nombre que la v1 tenia en su columna, no
     * a un archivo. Un album lleno de recuadros rotos no enseña nada.
     */
    public function test_los_marcadores_de_la_importacion_no_salen(): void
    {
        [, , $evento] = $this->firmaConFoto();

        EvidenceFile::where('signature_event_id', $evento->id)
            ->update(['file_path' => 'legacy/images_uploads/detected_by_IA']);

        $this->actingAs($this->super())
            ->get(route('field_work.signatures.photos'))
            ->assertInertia(fn ($page) => $page->where('events.data', []));
    }

    /** Mirar el album no deja rastro: es lectura pura, por regla del dueño. */
    public function test_mirar_el_album_no_deja_rastro(): void
    {
        $this->firmaConFoto();

        // El usuario se crea ANTES de contar: su alta si se audita, y no es
        // lo que esta prueba vigila.
        $super = $this->super();

        $antes = AuditLog::count();

        $this->actingAs($super)
            ->get(route('field_work.signatures.photos'))
            ->assertOk();

        $this->assertSame($antes, AuditLog::count(),
            'abrir el album no puede escribir en el historial');
    }

    // ── Lo que NO va a los logs ─────────────────────────────────────────────

    /**
     * Firmar deja en el historial el hecho, no los parametros de la captura.
     *
     * `Auditable` escribia la fila entera de `signature_events` en
     * `audit_logs.new_values`: coordenadas, IP, aparato y navegador de una
     * persona quedaban en una tabla que ve todo super/admin y que nadie purga.
     */
    public function test_firmar_no_deja_los_parametros_en_el_historial(): void
    {
        [, , $evento] = $this->firmaConFoto();

        $apunte = AuditLog::where('auditable_type', SignatureEvent::class)
            ->where('auditable_id', $evento->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($apunte, 'la firma en si tiene que dejar rastro: quien, cuando y como');
        $this->assertArrayHasKey('method', $apunte->new_values);
        $this->assertArrayHasKey('person_id', $apunte->new_values);

        foreach (['match_distance', 'threshold_used', 'latitude', 'longitude', 'device_id', 'ip_address', 'user_agent'] as $parametro) {
            $this->assertArrayNotHasKey($parametro, $apunte->new_values,
                "«{$parametro}» es un parametro de la captura y no se exhibe en los logs");
        }
    }

    /** La fila de la foto no escribe apunte ninguno: es la evidencia, no un hecho. */
    public function test_la_foto_no_deja_rastro_en_el_historial(): void
    {
        [, , $evento] = $this->firmaConFoto();

        $this->assertSame(0, AuditLog::where('auditable_type', EvidenceFile::class)->count(),
            'guardar la foto de una firma no puede dejar su ruta y su hash en el historial');

        // Y el hecho de la firma si esta: el rastro que importa no se perdio.
        $this->assertSame(1, AuditLog::where('auditable_type', SignatureEvent::class)
            ->where('auditable_id', $evento->id)->count());
    }

    // ── Decorado ────────────────────────────────────────────────────────────

    private function base(): array
    {
        return ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];
    }

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Admin de prueba']);
        Permission::firstOrCreate(['name' => 'people.view_private_info', 'guard_name' => 'web']);

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo('people.view_private_info');

        return $u;
    }

    private function super(): User
    {
        $u = $this->admin();
        $u->assignRole(Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Super de prueba']));

        return $u;
    }

    /**
     * Una firma de aprobacion con su foto y TODOS los parametros de captura.
     *
     * @return array{0: WorkPlan, 1: Person, 2: SignatureEvent}
     */
    private function firmaConFoto(): array
    {
        $plan = WorkPlan::create($this->base() + [
            'company_id' => Company::firstOrCreate(['num_doc' => '20100000001'],
                $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'])->id,
            'work_type_id'     => WorkType::firstOrCreate(['code' => 'MTTO'], $this->base())->id,
            'work_location_id' => WorkLocation::firstOrCreate(['name' => 'Planta'], $this->base())->id,
            'user_id'          => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'code' => 'PE26-0814-0001', 'description' => 'Trabajo', 'date_start' => '2026-08-14',
        ]);

        $persona = Person::create($this->base() + [
            'doc_type' => 'DNI', 'num_doc' => '44445555',
            'name' => 'Ana', 'lastname' => 'Quispe', 'is_active' => true,
        ]);

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
            'role_signed' => 'supervisor', 'signed_at' => now(),
            'method' => SignatureEvent::FACE_RECOGNITION, 'used_ai' => true,
            'pending_review' => false, 'tenant_id' => 1,
            // Los parametros que NO deben exhibirse ni en el album ni en logs.
            'match_distance' => 0.32, 'threshold_used' => 0.5,
            'latitude' => -12.046374, 'longitude' => -77.042793,
            'device_id' => '6f1c9a2e-4b77-4c31-9a0d-77f2b1c0e5aa',
            'ip_address' => '10.0.0.7',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 13) Chrome/126.0.0.0',
        ]);

        Storage::disk('local')->put('evidencias/cara.webp', 'bytes');

        EvidenceFile::create([
            'signature_event_id' => $evento->id, 'kind' => EvidenceFile::FACE,
            'file_path' => 'evidencias/cara.webp', 'sha256' => str_repeat('a', 64), 'byte_size' => 5,
        ]);

        return [$plan, $persona, $evento];
    }
}
