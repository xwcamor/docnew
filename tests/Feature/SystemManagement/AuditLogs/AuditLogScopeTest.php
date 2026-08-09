<?php

namespace Tests\Feature\SystemManagement\AuditLogs;

use App\Models\AuditLog;
use App\Models\SystemModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Qué ve el admin de un workspace en «Logs del sistema».
 *
 * La lista de módulos auditables del admin era la del dominio de
 * transformadores (`transformers`, `laboratories`, `tap_changer_*`), purgado
 * del producto: el admin abría la pantalla y NO veía nada de planes de trabajo,
 * personas, formatos ni firmas — justo el rastro que este producto existe para
 * conservar. Ahora sale de `system_modules`, que es el registro de lo que el
 * admin puede delegar en sus perfiles, así que no puede volver a quedarse atrás.
 */
class AuditLogScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            LocaleSessionRedirect::class,
        ]);

        DB::table('languages')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('locales')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español (PE)',
            'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('regions')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'name' => 'América del Sur',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('countries')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'region_id' => 1, 'default_locale_id' => 1,
            'name' => 'Perú', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('tenants')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'name' => 'Constructora',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['super', 'admin', 'user'] as $rol) {
            Role::firstOrCreate(['name' => $rol, 'guard_name' => 'web'], ['description' => "Test {$rol}"]);
        }

        // Los módulos que el admin puede delegar. El observer les crea sus
        // siete permisos al insertarlos.
        foreach (['WorkPlan', 'Person', 'FormTemplate'] as $nombre) {
            SystemModule::create(['name' => $nombre]);
        }
    }

    private function actingAsAdmin(): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $this->actingAs($u);
        return $u;
    }

    private function log(int $userId, string $module): AuditLog
    {
        return AuditLog::create([
            'user_id' => $userId, 'event' => 'updated', 'module' => $module,
            'auditable_type' => 'App\\Models\\Dummy', 'auditable_id' => 1,
            'created_at' => now(),
        ]);
    }

    public function test_admin_sees_the_field_work_trail_of_his_workspace(): void
    {
        $admin = $this->actingAsAdmin();

        $this->log($admin->id, 'work_plans');
        $this->log($admin->id, 'people');
        $this->log($admin->id, 'signature_events');

        $this->get(route('system_management.audit_logs.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('logs.total', 3)->etc());
    }

    /** El núcleo de plataforma sigue siendo exclusivo del super. */
    public function test_admin_does_not_see_platform_core_modules(): void
    {
        $admin = $this->actingAsAdmin();

        $this->log($admin->id, 'settings');
        $this->log($admin->id, 'tenants');
        $this->log($admin->id, 'system_modules');
        $this->log($admin->id, 'work_plans');

        $this->get(route('system_management.audit_logs.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('logs.total', 1)
                ->where('logs.data.0.module', 'work_plans')
                ->etc());
    }

    /**
     * Registrar un módulo nuevo en `system_modules` basta para que el admin
     * pueda auditarlo: sin esto había que acordarse de tocar una constante.
     */
    public function test_registering_a_module_makes_it_auditable_for_admin(): void
    {
        $admin = $this->actingAsAdmin();
        $this->log($admin->id, 'document_types');

        $this->get(route('system_management.audit_logs.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('logs.total', 0)->etc());

        SystemModule::create(['name' => 'DocumentType']);

        $this->get(route('system_management.audit_logs.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('logs.total', 1)->etc());
    }
}
