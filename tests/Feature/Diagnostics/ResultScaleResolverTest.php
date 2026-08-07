<?php

namespace Tests\Feature\Diagnostics;

use App\Models\ResultScale;
use App\Models\Test;
use App\Models\User;
use App\Support\Diagnostics\ResultScaleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ResultScaleResolver (semáforos híbridos): el tenant ve SU override; si no
 * tiene, cae al global. El override NO pisa las bandas globales (coexisten).
 */
class ResultScaleResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_ve_su_override_y_no_pisa_el_global(): void
    {
        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([
            ['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'slug' => Str::random(22), 'name' => 'Empresa 2', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $cromas = Test::create(['code' => 'cromas', 'name' => 'Cromas', 'sort_order' => 1]);

        // Global: 2 bandas. Override del tenant 1: 1 banda distinta.
        ResultScale::create(['test_id' => $cromas->id, 'tenant_id' => null, 'score_from' => null, 'score_to' => 1.2, 'condition_label' => 'Muy Bueno', 'color' => 'green', 'hi_rating' => 4, 'sort_order' => 1]);
        ResultScale::create(['test_id' => $cromas->id, 'tenant_id' => null, 'score_from' => 1.2, 'score_to' => null, 'condition_label' => 'Malo', 'color' => 'red', 'hi_rating' => 0, 'sort_order' => 2]);
        ResultScale::create(['test_id' => $cromas->id, 'tenant_id' => 1, 'score_from' => null, 'score_to' => null, 'condition_label' => 'Custom', 'color' => 'lime', 'hi_rating' => 3, 'sort_order' => 1]);

        $u1 = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u2 = User::factory()->create(['tenant_id' => 2, 'country_id' => 1, 'locale_id' => 1]);

        // Tenant 1 → su override (1 banda 'Custom').
        $this->actingAs($u1);
        $b1 = ResultScaleResolver::bands($cromas->id);
        $this->assertCount(1, $b1);
        $this->assertSame('Custom', $b1->first()->condition_label);

        // Tenant 2 (sin override) → global (2 bandas).
        $this->actingAs($u2);
        $b2 = ResultScaleResolver::bands($cromas->id);
        $this->assertCount(2, $b2);

        // El global SIGUE intacto (el override del tenant 1 no lo pisó).
        $this->assertSame(2, ResultScale::whereNull('tenant_id')->where('test_id', $cromas->id)->count());
    }
}
