<?php

namespace Tests\Feature\SystemManagement\Tenants;

use App\Models\Tenant;

/**
 * Que las pantallas del workspace abran y que guardar NO borre lo que no se
 * tocó. El caso real: editar el nombre de un workspace le borraba el logo.
 */
class TenantScreensTest extends TenantTestCase
{
    private function crear(array $overrides = []): Tenant
    {
        return Tenant::create(array_merge([
            'name'      => 'Constructora ' . uniqid(),
            'is_active' => true,
        ], $overrides));
    }

    public function test_all_screens_open(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->crear();

        foreach (['index', 'create', 'trash', 'edit_all'] as $pantalla) {
            $this->get(route("system_management.tenants.{$pantalla}"))->assertOk();
        }

        foreach (['show', 'edit', 'delete'] as $pantalla) {
            $this->get(route("system_management.tenants.{$pantalla}", $tenant->slug))->assertOk();
        }
    }

    /**
     * El formulario manda `logo: null` cuando NO se cambió la imagen. Ese null
     * se escribía en la columna: guardar cualquier cosa dejaba el workspace
     * sin logo, y en el membrete de los informes se notaba al día siguiente.
     */
    public function test_saving_without_touching_the_logo_keeps_it(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->crear(['logo' => 'tenants/logo.png']);

        $this->put(route('system_management.tenants.update', $tenant->slug), [
            'name'      => 'Constructora renombrada',
            'is_active' => true,
            'logo'      => null,
        ])->assertRedirect();

        $tenant->refresh();
        $this->assertSame('Constructora renombrada', $tenant->name);
        $this->assertSame('tenants/logo.png', $tenant->logo);
    }

    /**
     * El campo «Aprobador externo» existe en el formulario pero el controlador
     * no lo cargaba al abrir «Editar»: el formulario lo mandaba vacío y el dato
     * se perdía en el primer guardado.
     */
    public function test_edit_screen_loads_the_report_approver(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->crear(['report_approver' => 'Ing. Ramos']);

        $this->get(route('system_management.tenants.edit', $tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('tenant.report_approver', 'Ing. Ramos'));
    }

    /** La ficha tiene que decir dónde está el workspace y cuánta gente cabe. */
    public function test_show_carries_timezone_and_user_headroom(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = $this->crear(['timezone' => 'America/Lima', 'address' => 'Av. Arequipa 1234']);

        $this->get(route('system_management.tenants.show', $tenant->slug))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('tenant.timezone', 'America/Lima')
                ->where('tenant.address', 'Av. Arequipa 1234')
                ->has('tenant.users_count')
                ->has('tenant.max_users')
                ->etc());
    }

    /** Alta real: el workspace y su admin quedan en la base. */
    public function test_create_persists_workspace_and_admin(): void
    {
        $this->actingAsSuperAdmin();

        $datos = $this->validTenantData(['name' => 'Constructora Nueva']);
        $this->post(route('system_management.tenants.store'), $datos)->assertRedirect();

        $tenant = Tenant::where('name', 'Constructora Nueva')->first();
        $this->assertNotNull($tenant);
        $this->assertDatabaseHas('users', [
            'email'     => $datos['admin_email'],
            'tenant_id' => $tenant->id,
        ]);
    }
}
