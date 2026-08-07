<?php

namespace Tests\Feature\Diagnostics;

use App\Models\DiagnosticDataset;
use App\Models\User;
use App\Support\Diagnostics\RuleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RuleData híbrido: se prefiere el dataset del tenant; si no tiene, cae al global.
 */
class RuleDataTenantTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(int $id, string $name): void
    {
        DB::table('tenants')->insertOrIgnore([['id' => $id, 'slug' => Str::random(22), 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }

    public function test_prefiere_el_dataset_del_tenant_y_cae_al_global(): void
    {
        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        $this->tenant(1, 'Empresa 1');
        $this->tenant(2, 'Empresa 2');

        // Global (fábrica) + override del tenant 1, misma key.
        DiagnosticDataset::create(['key' => 'demo_rules', 'tenant_id' => null, 'data' => ['v' => 1]]);
        DiagnosticDataset::create(['key' => 'demo_rules', 'tenant_id' => 1, 'data' => ['v' => 99]]);

        // Ambos users ANTES de autenticarse (evita que el trait fuerce el tenant).
        $u1 = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u2 = User::factory()->create(['tenant_id' => 2, 'country_id' => 1, 'locale_id' => 1]);

        // Tenant 1 → su override.
        $this->actingAs($u1);
        RuleData::flush();
        $this->assertSame(99, RuleData::get('demo_rules')['v']);

        // Tenant 2 (sin override) → global.
        $this->actingAs($u2);
        RuleData::flush();
        $this->assertSame(1, RuleData::get('demo_rules')['v']);
    }
}
