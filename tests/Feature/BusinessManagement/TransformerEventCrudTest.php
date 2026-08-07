<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Transformer;
use App\Models\TransformerEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TransformerEventCrudTest extends TestCase
{
    use RefreshDatabase;

    protected Transformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('plans')->insertOrIgnore([['id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1, 'export_rate_limit' => 50, 'support_level' => 'priority', 'features' => json_encode([]), 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD', 'is_active' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['transformers.view', 'transformers.edit'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin']);
        $admin->syncPermissions(Permission::all());

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $this->actingAs($u);

        $this->transformer = (new Transformer())->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => 'S1', 'tag' => 'TR',
            'tenant_id' => 1, 'created_by' => $u->id,
        ]);
        $this->transformer->save();
    }

    public function test_create_point_and_range_events(): void
    {
        $this->post(route('business_management.transformers.events.store', $this->transformer->slug), [
            'title' => 'Inspección visual', 'status' => 'pendiente', 'category' => 'observacion',
            'starts_at' => '2024-09-04 10:00',
        ])->assertRedirect(route('business_management.transformers.show', $this->transformer->slug));

        $this->post(route('business_management.transformers.events.store', $this->transformer->slug), [
            'title' => 'Parada programada', 'status' => 'en_seguimiento',
            'starts_at' => '2024-09-01 08:00', 'ends_at' => '2024-09-05 18:00', 'body' => 'Mantenimiento mayor',
        ])->assertRedirect();

        $this->assertSame(2, TransformerEvent::where('transformer_id', $this->transformer->id)->count());
        $this->assertDatabaseHas('transformer_events', ['title' => 'Parada programada', 'status' => 'en_seguimiento']);
    }

    public function test_update_moves_status(): void
    {
        $this->post(route('business_management.transformers.events.store', $this->transformer->slug), [
            'title' => 'X', 'status' => 'pendiente', 'starts_at' => '2024-09-04 10:00',
        ]);
        $e = TransformerEvent::where('transformer_id', $this->transformer->id)->firstOrFail();

        $this->put(route('business_management.transformers.events.update', [$this->transformer->slug, $e->id]), [
            'title' => 'X', 'status' => 'resuelto', 'starts_at' => '2024-09-04 10:00',
        ])->assertRedirect();
        $this->assertSame('resuelto', $e->fresh()->status);
    }

    public function test_delete_soft_deletes(): void
    {
        $this->post(route('business_management.transformers.events.store', $this->transformer->slug), [
            'title' => 'X', 'status' => 'pendiente', 'starts_at' => '2024-09-04 10:00',
        ]);
        $e = TransformerEvent::where('transformer_id', $this->transformer->id)->firstOrFail();

        $this->delete(route('business_management.transformers.events.destroy', [$this->transformer->slug, $e->id]))
            ->assertRedirect();
        $this->assertSoftDeleted('transformer_events', ['id' => $e->id]);
    }

    public function test_validation_title_status_and_range(): void
    {
        // Falta título; status inválido.
        $this->post(route('business_management.transformers.events.store', $this->transformer->slug), [
            'status' => 'inexistente', 'starts_at' => '2024-09-04 10:00',
        ])->assertSessionHasErrors(['title', 'status']);

        // ends_at antes de starts_at.
        $this->post(route('business_management.transformers.events.store', $this->transformer->slug), [
            'title' => 'X', 'status' => 'pendiente', 'starts_at' => '2024-09-04 10:00', 'ends_at' => '2024-09-01 10:00',
        ])->assertSessionHasErrors('ends_at');
    }
}
