<?php

namespace Tests\Feature\UserManagement;

use App\Models\Role;
use App\Models\User;

class UserCrudTest extends UserTestCase
{
    public function test_admin_sees_only_users_of_his_tenant(): void
    {
        $this->actingAsTenantAdmin(1);
        User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1, 'name' => 'Bob 1']);
        User::factory()->create(['tenant_id' => 2, 'country_id' => 1, 'locale_id' => 1, 'name' => 'Bob 2']);

        $response = $this->get(route('user_management.users.index'));
        $response->assertOk();
    }

    public function test_belongs_to_tenant_trait_auto_fills_tenant_id_on_create(): void
    {
        // El trait BelongsToTenant auto-llena tenant_id cuando un user
        // autenticado crea otro user. Test enfocado solo en eso.
        $admin = $this->actingAsTenantAdmin(1);

        $created = User::factory()->create([
            'country_id' => 1,
            'locale_id'  => 1,
            'name'       => 'Nuevo User',
            'email'      => 'nuevo@example.com',
        ]);

        $this->assertSame(1, $created->tenant_id, 'tenant_id debe auto-asignarse del admin creador');
    }

    public function test_role_options_for_admin_includes_global_admin_role(): void
    {
        // Crear un rol custom del tenant
        $custom = Role::create(['name' => 'soporte', 'guard_name' => 'web', 'tenant_id' => 1, 'description' => 'Soporte custom']);

        $this->actingAsTenantAdmin(1);
        $response = $this->get(route('user_management.users.create'));

        $response->assertOk();
        // El dropdown debe incluir 'admin' (global) + 'soporte' (tenant 1)
        // No debe incluir super ni api.
        $props = $response->viewData('page')['props'];
        $roleNames = collect($props['roleOptions'])->pluck('label')->map(fn ($l) => explode(' ', $l)[0])->all();
        $this->assertContains('admin', $roleNames);
        $this->assertContains('soporte', $roleNames);
        $this->assertNotContains('super', $roleNames);
        $this->assertNotContains('api', $roleNames);
    }

    public function test_admin_can_assign_a_global_custom_role(): void
    {
        // Rol GLOBAL custom (tenant_id null) creado por el super como plantilla.
        Role::create(['name' => 'auditor_global', 'guard_name' => 'web', 'tenant_id' => null, 'description' => 'Plantilla global']);

        $this->actingAsTenantAdmin(2);
        $response = $this->get(route('user_management.users.create'));

        $response->assertOk();
        $roleNames = collect($response->viewData('page')['props']['roleOptions'])
            ->pluck('label')->map(fn ($l) => explode(' ', $l)[0])->all();

        // El rol global custom debe ofrecerse a un admin de OTRO tenant.
        $this->assertContains('auditor_global', $roleNames);
    }

    public function test_admin_update_assigns_a_global_custom_role(): void
    {
        // Rol GLOBAL custom (lo publica el super); el admin debe poder asignarlo
        // a un usuario de su tenant al EDITARLO (no solo al crearlo).
        $global = Role::create(['name' => 'soporte_global', 'guard_name' => 'web', 'tenant_id' => null, 'description' => 'Plantilla global']);

        $admin = $this->actingAsTenantAdmin(1);
        $target = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1, 'email' => 'edit@example.com']);

        $this->put(route('user_management.users.update', $target->slug), [
            'name' => 'Editado', 'email' => 'edit@example.com',
            'country_id' => 1, 'locale_id' => 1, 'role_id' => $global->id,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($target->fresh()->hasRole('soporte_global'), 'el rol global debe quedar sincronizado tras el update');
    }

    public function test_system_user_with_api_role_is_hidden_from_listing(): void
    {
        // Crear un system_user con rol api en tenant 1
        $systemUser = User::factory()->create([
            'tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1,
            'email' => 'api+test@system.local',
        ]);
        $systemUser->assignRole('api');

        $admin = $this->actingAsTenantAdmin(1);

        // La query del controller usa BelongsToTenant + HideSuperScope.
        // El system_user NO debe aparecer en el listado.
        $visibleUsers = User::query()->get(['id', 'email']);
        $emails = $visibleUsers->pluck('email')->all();
        $this->assertNotContains('api+test@system.local', $emails);
        $this->assertContains($admin->email, $emails);
    }

    public function test_crear_usuario_con_clientes_asignados_crea_pivote(): void
    {
        // Restricción por cliente (enterprise): el form manda assigned_customer_ids
        // y el controller sincroniza la pivote customer_user.
        $this->actingAsTenantAdmin(1);
        \DB::table('customers')->insert([
            ['id' => 10, 'slug' => \Str::random(22), 'name' => 'Cliente A', 'is_active' => true, 'tenant_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 20, 'slug' => \Str::random(22), 'name' => 'Cliente B', 'is_active' => true, 'tenant_id' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $roleId = Role::withoutGlobalScopes()->where('name', 'admin')->value('id');

        $response = $this->post(route('user_management.users.store'), [
            'name' => 'Cliente User', 'email' => 'cliente@example.com',
            'password' => 'Password123!', 'country_id' => 1, 'locale_id' => 1,
            'role_id' => $roleId,
            // El 20 es de OTRO tenant: debe filtrarse (anti-IDOR).
            'assigned_customer_ids' => [10, 20],
        ]);

        $response->assertSessionHasNoErrors();
        $created = User::where('email', 'cliente@example.com')->firstOrFail();
        $this->assertSame([10], \DB::table('customer_user')->where('user_id', $created->id)->pluck('customer_id')->map(fn ($i) => (int) $i)->all());

        // Update con lista vacía → quita la restricción.
        $this->put(route('user_management.users.update', $created->slug), [
            'name' => 'Cliente User', 'email' => 'cliente@example.com',
            'country_id' => 1, 'locale_id' => 1,
            'role_id' => $roleId,
            'assigned_customer_ids' => [],
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, \DB::table('customer_user')->where('user_id', $created->id)->count());
    }

    public function test_vaciar_clientes_asignados_via_flag_los_quita(): void
    {
        // Reproduce el form real: con FormData el array vacío se PIERDE, pero el
        // form manda el flag escalar `assigned_customers_touched`. Sin el flag, el
        // backend no sincronizaba → no se podían quitar todos los clientes.
        $this->actingAsTenantAdmin(1);
        \DB::table('customers')->insert([
            ['id' => 10, 'slug' => \Str::random(22), 'name' => 'Cliente A', 'is_active' => true, 'tenant_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $roleId = Role::withoutGlobalScopes()->where('name', 'admin')->value('id');

        $this->post(route('user_management.users.store'), [
            'name' => 'U', 'email' => 'u@example.com', 'password' => 'Password123!',
            'country_id' => 1, 'locale_id' => 1, 'role_id' => $roleId,
            'assigned_customer_ids' => [10],
        ])->assertSessionHasNoErrors();
        $user = User::where('email', 'u@example.com')->firstOrFail();
        $this->assertSame(1, \DB::table('customer_user')->where('user_id', $user->id)->count());

        // Update SIN el array (como lo descarta FormData) pero CON el flag → limpia.
        $this->put(route('user_management.users.update', $user->slug), [
            'name' => 'U', 'email' => 'u@example.com',
            'country_id' => 1, 'locale_id' => 1, 'role_id' => $roleId,
            'assigned_customers_touched' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, \DB::table('customer_user')->where('user_id', $user->id)->count());
    }

    public function test_creating_a_user_sends_a_welcome_notification(): void
    {
        $this->actingAsTenantAdmin(1);

        $roleId = Role::withoutGlobalScopes()->where('name', 'admin')->value('id');

        $this->post(route('user_management.users.store'), [
            'name' => 'Nuevo', 'email' => 'nuevo@example.com',
            'password' => 'Password123!', 'country_id' => 1, 'locale_id' => 1,
            'role_id' => $roleId,
        ])->assertSessionHasNoErrors();

        $created = User::where('email', 'nuevo@example.com')->firstOrFail();

        // Bienvenida en la campana (notifications table), para todos, sin email.
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id'   => $created->id,
            'type'            => \App\Notifications\WelcomeUser::class,
        ]);
    }

    public function test_soft_delete_user_with_reason(): void
    {
        $admin = $this->actingAsTenantAdmin(1);
        $target = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);

        $response = $this->delete(route('user_management.users.deleteSave', $target->slug), [
            'deleted_description' => 'Dejó la empresa.',
        ]);

        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'deleted_description' => 'Dejó la empresa.',
        ]);
    }
}
