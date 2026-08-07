<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Customer;
use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Models\User;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Chips rápidos: condición de trafos (health_band) y país de clientes (country_group).
 */
class PresetFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([
            ['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Chile', 'iso_code' => 'CL', 'currency' => 'CLP', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        $this->seed(DiagnosticCatalogSeeder::class);

        $this->admin = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->actingAs($this->admin);
    }

    private function tx(?int $rating): Transformer
    {
        $t = (new Transformer())->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => Str::random(6), 'tag' => 'T',
            'oil_type_id' => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id' => TransformerType::where('code', 'potencia')->value('id'),
            'health_rating' => $rating, 'tenant_id' => 1, 'created_by' => $this->admin->id,
        ]);
        $t->save();
        return $t;
    }

    public function test_health_band_filters_transformers(): void
    {
        $this->tx(4); $this->tx(3);   // buenos
        $this->tx(2);                 // regular
        $this->tx(1); $this->tx(0);   // malos
        $this->tx(null);              // sin pruebas

        $count = fn (string $band) => Transformer::filter(new Request(['health_band' => $band]))->count();

        $this->assertSame(2, $count('good'));
        $this->assertSame(1, $count('regular'));
        $this->assertSame(2, $count('bad'));
        $this->assertSame(1, $count('none'));
        $this->assertSame(6, Transformer::count()); // sin filtro
    }

    private function customer(bool $active, bool $withTx = false): Customer
    {
        $c = (new Customer())->forceFill([
            'slug' => 'c-' . uniqid(), 'name' => 'C' . uniqid(), 'cod' => Str::random(8),
            'country_id' => 1, 'is_active' => $active, 'tenant_id' => 1, 'created_by' => $this->admin->id,
        ]);
        $c->save();
        if ($withTx) {
            $t = $this->tx(4);
            DB::table('transformers')->where('id', $t->id)->update(['customer_id' => $c->id]);
        }
        return $c;
    }

    public function test_customer_group_filters(): void
    {
        $this->customer(true, withTx: true);   // activo + con trafo
        $this->customer(true);                 // activo, sin trafo
        $this->customer(false);                // inactivo, sin trafo

        $g = fn (string $group) => Customer::filter(new Request(['customer_group' => $group]))->count();

        $this->assertSame(2, $g('active'));
        $this->assertSame(1, $g('inactive'));
        $this->assertSame(1, $g('with_tx'));
        $this->assertSame(2, $g('without_tx'));
        $this->assertSame(3, Customer::count());
    }
}
