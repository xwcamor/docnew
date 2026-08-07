<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CustomerCrudTest extends CustomerTestCase
{
    public function test_can_create_customer_with_logo(): void
    {
        Storage::fake('public');
        $this->actingAsTenantAdmin(1);

        $this->post(route('business_management.customers.store'), [
            'name' => 'Logo Co',
            'cod' => 'RUC-LOGO',
            'country_id' => 1,
            'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
        ])->assertRedirect();

        $customer = Customer::where('name', 'Logo Co')->firstOrFail();
        $this->assertNotNull($customer->logo);
        Storage::disk('public')->assertExists($customer->logo);
    }

    public function test_admin_sees_only_customers_of_his_tenant(): void
    {
        Customer::factory()->create(['tenant_id' => 1, 'name' => 'Cliente A']);
        Customer::factory()->create(['tenant_id' => 2, 'name' => 'Cliente B']);

        $this->actingAsTenantAdmin(1);
        $response = $this->get(route('business_management.customers.index'));
        $response->assertOk();

        // BelongsToTenant trait filtra automatico
        $visible = Customer::query()->pluck('name')->all();
        $this->assertContains('Cliente A', $visible);
        $this->assertNotContains('Cliente B', $visible);
    }

    public function test_admin_can_create_customer(): void
    {
        $this->actingAsTenantAdmin(1);

        $response = $this->post(route('business_management.customers.store'), [
            'name'       => 'Acme Corp',
            'cod'        => '20123456789',
            'country_id' => 1,
            'is_active'  => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('customers', [
            'name'       => 'Acme Corp',
            'cod'        => '20123456789',
            'tenant_id'  => 1,
        ]);
    }

    public function test_quick_store_creates_customer_and_returns_json(): void
    {
        $this->actingAsTenantAdmin(1);

        $response = $this->postJson(route('business_management.customers.quick_store'), [
            'name'       => 'Quick Co',
            'cod'        => 'RUC-QUICK',
            'country_id' => 1,
            'is_active'  => true,
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['id', 'name']);
        $this->assertSame('Quick Co', $response->json('name'));
        $this->assertDatabaseHas('customers', ['name' => 'Quick Co', 'cod' => 'RUC-QUICK', 'tenant_id' => 1]);
    }

    public function test_quick_store_validates_required_fields(): void
    {
        $this->actingAsTenantAdmin(1);

        // Sin cod ni país → 422 con errores (mismas reglas que el alta normal).
        $this->postJson(route('business_management.customers.quick_store'), [
            'name' => 'Solo Nombre',
        ])->assertStatus(422)->assertJsonValidationErrors(['cod', 'country_id']);
    }

    public function test_create_seeds_default_hierarchy_and_redirects_to_show(): void
    {
        $this->actingAsTenantAdmin(1);

        $response = $this->post(route('business_management.customers.store'), [
            'name'       => 'Hierarchy Co',
            'cod'        => 'RUC-HIER',
            'country_id' => 1,
            'address'    => 'Av. Siempre Viva 123',
        ]);

        $customer = \App\Models\Customer::where('name', 'Hierarchy Co')->firstOrFail();
        $response->assertRedirect(route('business_management.customers.show', $customer->slug));

        $location = \App\Models\CustomerLocation::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('Sede Principal', $location->name);
        $area = \App\Models\CustomerArea::where('customer_location_id', $location->id)->firstOrFail();
        $this->assertSame('General', $area->name);
        $sub = \App\Models\CustomerSubstation::where('customer_area_id', $area->id)->firstOrFail();
        $this->assertSame('Principal', $sub->name);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'address' => 'Av. Siempre Viva 123']);
    }

    public function test_can_add_rename_and_delete_hierarchy_nodes(): void
    {
        $this->actingAsTenantAdmin(1);
        $this->post(route('business_management.customers.store'), ['name' => 'Tree Co', 'cod' => 'RUC-TREE', 'country_id' => 1]);
        $customer = \App\Models\Customer::where('name', 'Tree Co')->firstOrFail();
        $location = \App\Models\CustomerLocation::where('customer_id', $customer->id)->firstOrFail();

        // Agregar un área bajo la ubicación.
        $this->post(route('business_management.customers.hierarchy.store', $customer->slug), [
            'level' => 'area', 'parent_id' => $location->id, 'name' => 'Zona Norte',
        ])->assertRedirect(route('business_management.customers.show', $customer->slug));
        $area = \App\Models\CustomerArea::where('name', 'Zona Norte')->firstOrFail();

        // Renombrar.
        $this->put(route('business_management.customers.hierarchy.update', [$customer->slug, 'area', $area->id]), [
            'name' => 'Zona Sur',
        ])->assertRedirect();
        $this->assertSame('Zona Sur', $area->fresh()->name);

        // Borrar (soft-delete) con motivo.
        $this->delete(route('business_management.customers.hierarchy.destroy', [$customer->slug, 'area', $area->id]), [
            'reason' => 'Área duplicada',
        ])->assertRedirect();
        $this->assertSoftDeleted('customer_areas', ['id' => $area->id, 'deleted_description' => 'Área duplicada']);

        // Deshacer (restore).
        $this->post(route('business_management.customers.hierarchy.restore', [$customer->slug, 'area', $area->id]))
            ->assertRedirect();
        $this->assertNotSoftDeleted('customer_areas', ['id' => $area->id]);
    }

    public function test_customer_activity_includes_hierarchy_events(): void
    {
        $this->actingAsTenantAdmin(1);
        $this->post(route('business_management.customers.store'), ['name' => 'Audit Co', 'cod' => 'RUC-AUDIT', 'country_id' => 1]);
        $customer = \App\Models\Customer::where('name', 'Audit Co')->firstOrFail();

        $response = $this->get(route('business_management.customers.show', $customer->slug));
        $response->assertInertia(fn ($page) => $page->has('activity'));

        // El auto-create deja 4 eventos: cliente + ubicación + área + subestación,
        // y los de la jerarquía llevan un `subject` (independiente del idioma).
        $activity = collect($response->viewData('page')['props']['activity']);
        $this->assertGreaterThanOrEqual(4, $activity->count());
        $this->assertTrue(
            $activity->contains(fn ($a) => !empty($a['subject'])),
            'La auditoría del cliente debe incluir eventos de la jerarquía (con subject).'
        );
    }

    public function test_creating_a_location_seeds_area_and_substation(): void
    {
        $this->actingAsTenantAdmin(1);
        $this->post(route('business_management.customers.store'), ['name' => 'Cascade Co', 'cod' => 'RUC-CASC', 'country_id' => 1]);
        $customer = \App\Models\Customer::where('name', 'Cascade Co')->firstOrFail();

        $this->post(route('business_management.customers.hierarchy.store', $customer->slug), [
            'level' => 'location', 'parent_id' => $customer->id, 'name' => 'Planta Sur',
        ])->assertRedirect();

        $loc = \App\Models\CustomerLocation::where('name', 'Planta Sur')->firstOrFail();
        $area = \App\Models\CustomerArea::where('customer_location_id', $loc->id)->firstOrFail();
        $this->assertSame('General', $area->name);
        $this->assertDatabaseHas('customer_substations', ['customer_area_id' => $area->id, 'name' => 'Principal']);
    }

    public function test_deleting_a_node_requires_a_reason(): void
    {
        $this->actingAsTenantAdmin(1);
        $this->post(route('business_management.customers.store'), ['name' => 'Reason Co', 'cod' => 'RUC-REAS', 'country_id' => 1]);
        $customer = \App\Models\Customer::where('name', 'Reason Co')->firstOrFail();
        $loc = \App\Models\CustomerLocation::where('customer_id', $customer->id)->firstOrFail();
        // Segunda área para que el borrado no choque con la invariante.
        $this->post(route('business_management.customers.hierarchy.store', $customer->slug), [
            'level' => 'area', 'parent_id' => $loc->id, 'name' => 'Otra',
        ]);
        $area = \App\Models\CustomerArea::where('name', 'Otra')->firstOrFail();

        // Sin motivo -> error de validación, no se borra.
        $this->delete(route('business_management.customers.hierarchy.destroy', [$customer->slug, 'area', $area->id]))
            ->assertSessionHasErrors('reason');
        $this->assertNotSoftDeleted('customer_areas', ['id' => $area->id]);
    }

    public function test_cannot_delete_the_last_node(): void
    {
        $this->actingAsTenantAdmin(1);
        $this->post(route('business_management.customers.store'), ['name' => 'Solo Co', 'cod' => 'RUC-SOLO', 'country_id' => 1]);
        $customer = \App\Models\Customer::where('name', 'Solo Co')->firstOrFail();
        // Su única ubicación por defecto.
        $loc = \App\Models\CustomerLocation::where('customer_id', $customer->id)->firstOrFail();

        $this->delete(route('business_management.customers.hierarchy.destroy', [$customer->slug, 'location', $loc->id]), [
            'reason' => 'Intento de borrar la única',
        ])->assertRedirect();

        // No se borró: sigue siendo la única ubicación (invariante).
        $this->assertNotSoftDeleted('customer_locations', ['id' => $loc->id]);
    }

    public function test_cannot_attach_node_to_another_customers_parent(): void
    {
        $this->actingAsTenantAdmin(1);
        // Dos clientes, cada uno con su jerarquía por defecto.
        $this->post(route('business_management.customers.store'), ['name' => 'Cliente A', 'cod' => 'RUC-CLIA', 'country_id' => 1]);
        $this->post(route('business_management.customers.store'), ['name' => 'Cliente B', 'cod' => 'RUC-CLIB', 'country_id' => 1]);
        $a = \App\Models\Customer::where('name', 'Cliente A')->firstOrFail();
        $b = \App\Models\Customer::where('name', 'Cliente B')->firstOrFail();
        $locationB = \App\Models\CustomerLocation::where('customer_id', $b->id)->firstOrFail();

        // Intentar colgar un área de A usando la ubicación de B. El controller
        // aborta 403 (el handler de la app lo redirige a dashboard); lo clave es
        // que el nodo intruso NO se crea.
        $this->post(route('business_management.customers.hierarchy.store', $a->slug), [
            'level' => 'area', 'parent_id' => $locationB->id, 'name' => 'Intruso',
        ]);
        $this->assertDatabaseMissing('customer_areas', ['name' => 'Intruso']);
    }

    public function test_index_includes_hierarchy_counts(): void
    {
        $this->actingAsTenantAdmin(1);
        $this->post(route('business_management.customers.store'), ['name' => 'Counts Co', 'cod' => 'RUC-CNT', 'country_id' => 1]);

        $response = $this->get(route('business_management.customers.index'));
        $response->assertOk();

        $row = collect($response->viewData('page')['props']['customers']['data'])
            ->firstWhere('name', 'Counts Co');

        // Jerarquía por defecto: 1 ubicación, 1 área, 1 subestación, 0 trafos.
        $this->assertSame(1, $row['locations_count']);
        $this->assertSame(1, $row['areas_count']);
        $this->assertSame(1, (int) $row['substations_count']);
        $this->assertSame(0, $row['transformers_count']);
    }

    public function test_cod_must_be_unique_within_tenant_and_country(): void
    {
        Customer::factory()->create(['tenant_id' => 1, 'cod' => 'RUC123', 'country_id' => 1, 'name' => 'Existing']);

        $this->actingAsTenantAdmin(1);
        $response = $this->post(route('business_management.customers.store'), [
            'name'       => 'Duplicate',
            'cod'        => 'RUC123',
            'country_id' => 1,
        ]);

        $response->assertSessionHasErrors('cod');
    }

    public function test_same_cod_allowed_in_different_countries(): void
    {
        Customer::factory()->create(['tenant_id' => 1, 'cod' => 'RUC123', 'country_id' => 1, 'name' => 'En Argentina']);

        $this->actingAsTenantAdmin(1);
        // Mismo cod pero otro país (id 2 = Perú) → permitido.
        $this->post(route('business_management.customers.store'), [
            'name'       => 'En Peru',
            'cod'        => 'RUC123',
            'country_id' => 2,
        ])->assertRedirect();

        $this->assertDatabaseHas('customers', ['name' => 'En Peru', 'cod' => 'RUC123', 'country_id' => 2]);
    }

    public function test_same_cod_allowed_in_different_tenants(): void
    {
        Customer::factory()->create(['tenant_id' => 2, 'cod' => 'RUC123', 'name' => 'Tenant 2 Cliente']);

        $this->actingAsTenantAdmin(1);
        $response = $this->post(route('business_management.customers.store'), [
            'name'       => 'Tenant 1 Cliente',
            'cod'        => 'RUC123',
            'country_id' => 1,
        ]);

        $this->assertDatabaseHas('customers', ['name' => 'Tenant 1 Cliente', 'tenant_id' => 1]);
        $this->assertDatabaseHas('customers', ['name' => 'Tenant 2 Cliente', 'tenant_id' => 2]);
    }

    public function test_soft_delete_with_reason(): void
    {
        $this->actingAsTenantAdmin(1);
        $customer = Customer::factory()->create(['tenant_id' => 1, 'name' => 'To Delete']);

        $response = $this->delete(route('business_management.customers.deleteSave', $customer->slug), [
            'deleted_description' => 'Cliente cerrado.',
        ]);

        $response->assertRedirect();
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }
}
