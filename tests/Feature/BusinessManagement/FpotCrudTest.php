<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Fpot;
use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Models\User;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FpotCrudTest extends TestCase
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
        DB::table('plans')->insertOrIgnore([['id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1, 'export_rate_limit' => 50, 'support_level' => 'priority', 'features' => json_encode(['team_management' => true]), 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD', 'is_active' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        $this->seed(DiagnosticCatalogSeeder::class);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['transformers.view', 'transformers.edit', 'transformers.create', 'transformers.delete'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin']);
        $admin->syncPermissions(Permission::all());

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $this->actingAs($u);

        $this->transformer = (new Transformer())->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => 'S1', 'tag' => 'TR',
            'oil_type_id' => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id' => TransformerType::where('code', 'potencia')->value('id'),
            'tenant_id' => 1, 'created_by' => $u->id,
        ]);
        $this->transformer->save();
    }

    public function test_create_diagnoses_condition_and_rating(): void
    {
        $this->post(route('business_management.transformers.fpot.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'value' => 0.3, 'temperature' => 25,
        ])->assertRedirect(route('business_management.transformers.show', $this->transformer->slug));

        $sample = Fpot::where('transformer_id', $this->transformer->id)->firstOrFail();
        $this->assertSame('Muy Bueno', $sample->condition);
        $this->assertEqualsWithDelta(4.0, (float) $sample->rating, 0.001);
        $this->assertEqualsWithDelta(0.3, (float) $sample->value, 0.001);
        $this->assertEqualsWithDelta(25.0, (float) $sample->temperature, 0.001);

        // fpot viene hi_enabled=true por default: entra al Índice de Salud.
        // Única prueba presente, rating 4 (value 0.3 < 0.5) → HI = 100.
        $this->assertEqualsWithDelta(100.0, (float) $this->transformer->fresh()->health_index, 0.1);
    }

    public function test_update_re_diagnoses(): void
    {
        $this->post(route('business_management.transformers.fpot.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'value' => 0.3, 'temperature' => 25,
        ]);
        $sample = Fpot::where('transformer_id', $this->transformer->id)->firstOrFail();
        $this->assertSame('Muy Bueno', $sample->condition);

        $this->put(route('business_management.transformers.fpot.update', [$this->transformer->slug, $sample->id]), [
            'sample_date' => '2024-09-04', 'value' => 3.0, 'temperature' => 25,
        ])->assertRedirect();

        $fresh = $sample->fresh();
        $this->assertSame('Muy Malo', $fresh->condition);
        $this->assertEqualsWithDelta(0.0, (float) $fresh->rating, 0.001);
    }

    public function test_duplicate_date_same_temperature_rejected_but_other_temp_ok(): void
    {
        $this->post(route('business_management.transformers.fpot.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'value' => 0.3, 'temperature' => 25,
        ])->assertSessionHasNoErrors();

        // Misma fecha + misma temperatura -> rechazado.
        $this->post(route('business_management.transformers.fpot.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'value' => 0.4, 'temperature' => 25,
        ])->assertSessionHasErrors('sample_date');

        // Misma fecha pero OTRA temperatura -> permitido.
        $this->post(route('business_management.transformers.fpot.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'value' => 0.5, 'temperature' => 100,
        ])->assertSessionHasNoErrors();
    }

    public function test_delete_removes_sample(): void
    {
        $this->post(route('business_management.transformers.fpot.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'value' => 0.3, 'temperature' => 25,
        ]);
        $sample = Fpot::where('transformer_id', $this->transformer->id)->firstOrFail();

        $this->delete(route('business_management.transformers.fpot.destroy', [$this->transformer->slug, $sample->id]))
            ->assertRedirect();
        $this->assertSoftDeleted('fpots', ['id' => $sample->id]);
    }

    public function test_date_required(): void
    {
        $this->post(route('business_management.transformers.fpot.store', $this->transformer->slug), [
            'value' => 0.3,
        ])->assertSessionHasErrors('sample_date');
    }

    public function test_value_and_temperature_required(): void
    {
        // Solo fecha: faltan value y temperature (ahora obligatorios).
        $this->post(route('business_management.transformers.fpot.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04',
        ])->assertSessionHasErrors(['value', 'temperature']);
    }

    public function test_batch_creates_updates_and_deletes(): void
    {
        $this->post(route('business_management.transformers.fpot.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'value' => 0.3, 'temperature' => 25,
        ]);
        $this->post(route('business_management.transformers.fpot.store', $this->transformer->slug), [
            'sample_date' => '2024-01-01', 'value' => 0.4, 'temperature' => 25,
        ]);
        $samples = Fpot::where('transformer_id', $this->transformer->id)->orderBy('id')->get();
        [$toEdit, $toDelete] = [$samples[0], $samples[1]];

        $this->post(route('business_management.transformers.fpot.batch', $this->transformer->slug), [
            'upserts' => [
                ['id' => $toEdit->id, 'sample_date' => '2024-09-04', 'value' => 3.0, 'temperature' => 25],
                ['id' => null, 'sample_date' => '2024-12-01', 'value' => 0.2, 'temperature' => 25],
            ],
            'deletes' => [$toDelete->id],
        ])->assertRedirect(route('business_management.transformers.show', $this->transformer->slug));

        $this->assertSame('Muy Malo', $toEdit->fresh()->condition);
        $this->assertSoftDeleted('fpots', ['id' => $toDelete->id]);
        $this->assertSame(2, Fpot::where('transformer_id', $this->transformer->id)->count());
    }

    public function test_explain_returns_trace_without_persisting(): void
    {
        $res = $this->postJson(route('business_management.transformers.fpot.explain', $this->transformer->slug), [
            'value' => 0.3,
        ]);

        $res->assertOk();
        $res->assertJsonPath('fpot.has_value', true);
        $res->assertJsonPath('fpot.condition', 'Muy Bueno');
        $this->assertCount(1, collect($res->json('fpot.scale'))->where('matched', true));
        $this->assertSame(0, Fpot::where('transformer_id', $this->transformer->id)->count());
    }
}
