<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Mi workspace": el admin configura el membrete de informes de SU tenant
 * (dirección, disclaimer, aprobador) sin depender del super.
 */
class WorkspaceBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'admin']);
        Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web'], ['description' => 'user']);

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
    }

    private function makeUser(string $role): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($role);
        return $u;
    }

    public function test_admin_can_update_own_workspace_branding(): void
    {
        $this->actingAs($this->makeUser('admin'));

        $this->get(route('workspace.edit'))->assertOk();

        $this->put(route('workspace.update'), [
            'address'           => 'Av. Siempre Viva 742, Lima',
            'report_disclaimer' => 'Los resultados aplican solo a la muestra ensayada.',
            'signers'           => [['user_id' => null, 'name' => 'Ing. María García', 'title' => 'Jefa de Laboratorio']],
        ])->assertRedirect();

        $t = Tenant::find(1);
        $this->assertSame('Av. Siempre Viva 742, Lima', $t->address);
        $this->assertNotNull($t->report_disclaimer);
        $this->assertSame('Ing. María García', $t->reportSigners()->first()->name);
    }

    public function test_signers_flow_is_saved_in_order_and_rejects_cross_tenant(): void
    {
        DB::table('tenants')->insertOrIgnore([['id' => 2, 'slug' => Str::random(22), 'name' => 'Otra Empresa', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        $outsider = User::factory()->create(['tenant_id' => 2, 'country_id' => 1, 'locale_id' => 1]);
        $insider  = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);

        $this->actingAs($this->makeUser('admin'));

        // Usuario de OTRO tenant como firmante → rechazado, no se guarda nada.
        $this->put(route('workspace.update'), [
            'signers' => [['user_id' => $outsider->id, 'title' => 'Auditor']],
        ])->assertSessionHasErrors('signers.0.user_id');
        $this->assertSame(0, Tenant::find(1)->reportSigners()->count());

        // Flujo válido: usuario propio + externo, con cargos, en orden.
        $this->put(route('workspace.update'), [
            'signers' => [
                ['user_id' => $insider->id, 'title' => 'Supervisor'],
                ['user_id' => null, 'name' => 'C.P. Pedro Auditor', 'title' => 'Auditor'],
            ],
        ])->assertRedirect();

        $signers = Tenant::find(1)->reportSigners;
        $this->assertCount(2, $signers);
        $this->assertSame(['Supervisor', 'Auditor'], $signers->pluck('title')->all());
        $this->assertSame($insider->id, $signers[0]->user_id);
        $this->assertSame('C.P. Pedro Auditor', $signers[1]->name);

        // Slot sin usuario NI nombre → rechazado. Slot sin cargo → rechazado.
        $this->put(route('workspace.update'), [
            'signers' => [['user_id' => null, 'name' => '', 'title' => 'Supervisor']],
        ])->assertSessionHasErrors('signers.0.name');
        $this->put(route('workspace.update'), [
            'signers' => [['user_id' => $insider->id, 'title' => '']],
        ])->assertSessionHasErrors('signers.0.title');
    }

    public function test_admin_updates_workspace_logo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAs($this->makeUser('admin'));

        $this->post(route('workspace.logo.update'), [
            'logo' => \Illuminate\Http\UploadedFile::fake()->image('logo.png', 300, 100),
        ])->assertRedirect();

        $t = Tenant::find(1);
        $this->assertNotNull($t->logo);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($t->logo);

        // No-imagen → rechazada. Usuario común → bloqueado.
        $this->post(route('workspace.logo.update'), [
            'logo' => \Illuminate\Http\UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
        ])->assertSessionHasErrors('logo');

        $this->actingAs($this->makeUser('user'));
        $this->post(route('workspace.logo.update'), [
            'logo' => \Illuminate\Http\UploadedFile::fake()->image('x.png'),
        ])->assertRedirect(); // 403 → redirect al dashboard (handler global)
        $this->assertSame($t->logo, Tenant::find(1)->logo); // sin cambios
    }

    public function test_regular_user_cannot_access_workspace_branding(): void
    {
        $this->actingAs($this->makeUser('user'));

        // El handler global convierte los 403 de usuarios autenticados en
        // redirect al dashboard — lo que importa es que NO toca el tenant.
        $this->get(route('workspace.edit'))->assertRedirect();
        $this->put(route('workspace.update'), ['address' => 'hackeado'])->assertRedirect();
        $this->assertNotSame('hackeado', Tenant::find(1)->address);
    }
}
