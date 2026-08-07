<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Locks down the v1 Customers API (template para futuras APIs B2B):
 *   - Auth via Sanctum bearer token
 *   - Abilities (customers:read / write / delete) enforced
 *   - Plan gating (plan_feature:api_access — solo enterprise)
 *   - CRUD happy paths
 *   - Validación (unique name, required fields)
 *   - Filtering + pagination + cursor
 *
 * Para clones futuros (cuando se exponga un modulo nuevo via API): copiar
 * este file, renombrar Customer→X y ajustar el payload de store.
 */
class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedParentRows();
    }

    /** Parent rows minimas que User FKs requieren + tenant con plan enterprise. */
    protected function seedParentRows(): void
    {
        DB::table('languages')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('locales')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'code' => 'es_AR', 'name' => 'Español (AR)', 'language_id' => 1,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('regions')->insertOrIgnore([[
            'id' => 999, 'slug' => Str::random(22),
            'name' => '__bootstrap__', 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => now(),
            'deleted_description' => 'Bootstrap fixture for tests.',
        ]]);
        DB::table('countries')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'region_id' => 999, 'name' => 'Argentina',
            'iso_code' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Argentina/Buenos_Aires',
            'default_locale_id' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        // Tenant + suscripcion enterprise (API requiere plan_feature:api_access).
        DB::table('tenants')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'name' => 'Test Tenant',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('subscriptions')->insertOrIgnore([[
            'id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
            'currency' => 'USD', 'payment_method' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    /** Crea un usuario del tenant 1 y autentica via Sanctum con las abilities pasadas. */
    protected function actingAsApiUser(array $abilities = ['*']): User
    {
        $user = User::factory()->create([
            'tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1,
        ]);
        Sanctum::actingAs($user, $abilities);
        return $user;
    }

    /** Factory helper: crea un customer del tenant 1 con FKs minimas. */
    protected function makeCustomer(array $attrs = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'tenant_id'  => 1,
            'country_id' => 1,
        ], $attrs));
    }

    // ─── AUTH / ABILITIES ──────────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
    }

    public function test_index_requires_customers_read_ability(): void
    {
        $this->actingAsApiUser(['customers:write']);
        $this->getJson('/api/v1/customers')->assertForbidden();
    }

    public function test_store_requires_customers_write_ability(): void
    {
        $this->actingAsApiUser(['customers:read']);
        $this->postJson('/api/v1/customers', ['name' => 'X'])->assertForbidden();
    }

    public function test_destroy_requires_customers_delete_ability(): void
    {
        $this->actingAsApiUser(['customers:read', 'customers:write']);
        $customer = $this->makeCustomer();
        $this->deleteJson("/api/v1/customers/{$customer->slug}")->assertForbidden();
    }

    // ─── PLAN GATING (api_access) ──────────────────────────────────────────

    public function test_api_blocks_when_plan_loses_api_access(): void
    {
        $this->actingAsApiUser(['customers:read']);
        DB::table('subscriptions')->where('tenant_id', 1)->update(['plan' => 'pro']);
        $this->getJson('/api/v1/customers')->assertStatus(402);
    }

    public function test_api_blocks_when_plan_is_basic(): void
    {
        $this->actingAsApiUser(['customers:read']);
        DB::table('subscriptions')->where('tenant_id', 1)->update(['plan' => 'basic']);
        $this->getJson('/api/v1/customers')->assertStatus(402);
    }

    public function test_api_blocks_when_plan_is_free(): void
    {
        $this->actingAsApiUser(['customers:read']);
        // Sin suscripcion vigente → currentPlan() deriva a 'free'.
        DB::table('subscriptions')->where('tenant_id', 1)->delete();
        $this->getJson('/api/v1/customers')->assertStatus(402);
    }

    // ─── INDEX ──────────────────────────────────────────────────────────────

    public function test_index_returns_paginated_list(): void
    {
        $this->actingAsApiUser(['customers:read']);
        Customer::factory()->count(15)->create(['tenant_id' => 1, 'country_id' => 1]);

        $response = $this->getJson('/api/v1/customers');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [['id', 'name', 'cod', 'is_active', 'created_at']],
            'meta' => ['current_page', 'per_page', 'total'],
        ]);
    }

    public function test_index_supports_pagination_per_page(): void
    {
        $this->actingAsApiUser(['customers:read']);
        Customer::factory()->count(8)->create(['tenant_id' => 1, 'country_id' => 1]);

        $response = $this->getJson('/api/v1/customers?per_page=3');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
        $this->assertEquals(3, $response->json('meta.per_page'));
    }

    public function test_index_filters_by_is_active(): void
    {
        $this->actingAsApiUser(['customers:read']);
        $this->makeCustomer(['name' => 'Activa']);
        $this->makeCustomer(['name' => 'Inactiva', 'is_active' => false]);

        $response = $this->getJson('/api/v1/customers?is_active=1');

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Activa', $names);
        $this->assertNotContains('Inactiva', $names);
    }

    // ─── SHOW ──────────────────────────────────────────────────────────────

    public function test_show_returns_customer_by_slug(): void
    {
        $this->actingAsApiUser(['customers:read']);
        $customer = $this->makeCustomer(['name' => 'Acme']);

        $response = $this->getJson("/api/v1/customers/{$customer->slug}");

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Acme');
        $response->assertJsonPath('data.id', $customer->id);
    }

    public function test_show_returns_404_for_missing_slug(): void
    {
        $this->actingAsApiUser(['customers:read']);
        $this->getJson('/api/v1/customers/nonexistent-slug-xxx')->assertNotFound();
    }

    // ─── STORE ─────────────────────────────────────────────────────────────

    public function test_store_creates_a_customer(): void
    {
        $user = $this->actingAsApiUser(['customers:write']);

        $response = $this->postJson('/api/v1/customers', [
            'name'       => 'Acme S.A.',
            'cod'        => 'CLI-001',
            'country_id' => 1,
            'is_active'  => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Acme S.A.');
        $this->assertDatabaseHas('customers', [
            'name'       => 'Acme S.A.',
            'created_by' => $user->id,
            'tenant_id'  => 1,
        ]);
    }

    public function test_store_validates_required_name(): void
    {
        $this->actingAsApiUser(['customers:write']);
        $response = $this->postJson('/api/v1/customers', []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_store_blocks_duplicate_name(): void
    {
        $this->actingAsApiUser(['customers:write']);
        $this->makeCustomer(['name' => 'Existing Corp']);

        $response = $this->postJson('/api/v1/customers', [
            'name'       => 'Existing Corp',
            'country_id' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    // ─── UPDATE ────────────────────────────────────────────────────────────

    public function test_update_modifies_the_customer(): void
    {
        $this->actingAsApiUser(['customers:write']);
        $customer = $this->makeCustomer(['name' => 'Old Corp']);

        $response = $this->putJson("/api/v1/customers/{$customer->slug}", [
            'name'       => 'New Corp',
            'country_id' => 1,
            'is_active'  => false,
        ]);

        $response->assertOk();
        $customer->refresh();
        $this->assertSame('New Corp', $customer->name);
        $this->assertFalse($customer->is_active);
    }

    public function test_update_allows_keeping_same_name(): void
    {
        $this->actingAsApiUser(['customers:write']);
        $customer = $this->makeCustomer(['name' => 'Stable Corp']);

        $response = $this->putJson("/api/v1/customers/{$customer->slug}", [
            'name'       => 'Stable Corp',
            'country_id' => 1,
            'is_active'  => true,
        ]);

        $response->assertOk();
    }

    public function test_update_blocks_duplicate_name_against_other_record(): void
    {
        $this->actingAsApiUser(['customers:write']);
        $this->makeCustomer(['name' => 'Existing Corp']);
        $other = $this->makeCustomer(['name' => 'Other Corp']);

        $response = $this->putJson("/api/v1/customers/{$other->slug}", [
            'name' => 'Existing Corp',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    // ─── DESTROY ───────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_with_default_reason(): void
    {
        $this->actingAsApiUser(['customers:read', 'customers:write', 'customers:delete']);
        $customer = $this->makeCustomer();

        $response = $this->deleteJson("/api/v1/customers/{$customer->slug}");

        $response->assertNoContent();
        $customer->refresh();
        $this->assertNotNull($customer->deleted_at);
        $this->assertNotEmpty($customer->deleted_description);
    }

    public function test_destroy_uses_provided_reason(): void
    {
        $this->actingAsApiUser(['customers:delete']);
        $customer = $this->makeCustomer();

        $this->deleteJson("/api/v1/customers/{$customer->slug}", [
            'deleted_description' => 'Razón custom desde integración X',
        ])->assertNoContent();

        $this->assertSame('Razón custom desde integración X', $customer->fresh()->deleted_description);
    }
}
