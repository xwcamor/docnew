<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\OilType;
use Database\Seeders\DiagnosticCatalogSeeder;
use Database\Seeders\LegacyTransformersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica el importador del dump real de transformadores: parseo (comillas
 * escapadas), mapeo de IDs viejo→nuevo (aceite por código, connection/preservation
 * directos), derivación de customer_id desde la subestación, y campos clave.
 */
class LegacyTransformersSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedChain(): void
    {
        DB::table('tenants')->insert(['id' => 1, 'slug' => Str::random(22), 'name' => 'E1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->seed(DiagnosticCatalogSeeder::class); // oil_types + transformer_types
        $this->seed(\Database\Seeders\ConnectionTypesSeeder::class);
        $this->seed(\Database\Seeders\TransformerPreservationsSeeder::class);
        $this->seed(\Database\Seeders\TapChangerTypesSeeder::class);
        DB::table('brands')->insert(['id' => 37, 'slug' => Str::random(22), 'name' => 'ABB', 'code' => 'abb37', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customers')->insert(['id' => 1, 'slug' => Str::random(22), 'name' => 'C1', 'is_active' => true, 'tenant_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_locations')->insert(['id' => 1, 'slug' => Str::random(22), 'customer_id' => 1, 'name' => 'L1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_areas')->insert(['id' => 1, 'slug' => Str::random(22), 'customer_location_id' => 1, 'name' => 'A1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_substations')->insert(['id' => 519, 'slug' => Str::random(22), 'customer_area_id' => 1, 'name' => 'S519', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_imports_real_dump_with_mapping(): void
    {
        $this->seedChain();

        $header = "INSERT INTO `transformers` (`id`, `customer_substation_id`, `transformer_type_id`, `connection_type_id`, `conmutation_type_id`, `transformer_preservation_id`, `oil_type_id`, `mark_id`, `num_serie`, `num_tag`, `num_vol`, `num_pot`, `age`, `num_fas`, `num_tap`, `num_health`, `state_health`, `color_health`, `con_cro`, `rec_cro`, `con_fiq`, `rec_fiq`, `duval_triangle_first_image`, `duval_pentagon_first_image`, `has_special_testing_cro`, `has_special_testing_fiq`, `has_special_testing_fur`, `deleted`, `created_at`, `updated_at`) VALUES\n";
        $row1  = "(1019, 519, 1, 16, 1, 4, 4, 37, 'ET09618/1', 'AT74-523(FASE \\\"T\\\")', 500.00000, 360.00000, 2010, 3, NULL, '52', 'Medio', 'yellow', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, '2023-10-22 17:05:28', '2023-10-22 17:05:28'),\n";
        $row2  = "(646, 519, 2, 1, 1, 3, 1, 1, '145879-T1', '-', 22.90000, 2.50000, 2012, 3, 1, '0', 'Muy Malo', 'red', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 1, '2023-02-06 21:08:17', '2023-02-10 15:24:11'),\n";
        // oil viejo 8 ("-") → ninguno (equipo sin aceite).
        $row3  = "(1015, 519, 1, 16, 3, 4, 8, 1, 'ET09618/9', '-', 500.00000, 200.00000, 2010, NULL, NULL, '36', 'Malo', 'red', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, '2023-10-22 17:04:47', '2023-10-22 17:04:47'),\n";
        // oil viejo 5 (Éster Vegetal) → vegetal_soya.
        $row4  = "(143, 519, 1, 1, 2, 3, 5, 37, '1933431', 'SIL05', 110.00000, 70.00000, 1972, 3, 5, '43', 'Malo', 'red', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, '2022-08-01 21:22:17', '2023-02-20 21:26:30');\n";

        $f = tempnam(sys_get_temp_dir(), 'trafo') . '.sql';
        file_put_contents($f, $header . $row1 . $row2 . $row3 . $row4);

        $seeder = new LegacyTransformersSeeder();
        $seeder->file = $f;
        $seeder->run();
        unlink($f);

        // Mapeo directo + comillas escapadas + derivación de cliente.
        $this->assertDatabaseHas('transformers', [
            'id' => 1019, 'serial' => 'ET09618/1', 'tag' => 'AT74-523(FASE "T")',
            'customer_id' => 1, 'customer_substation_id' => 519,
            'connection_type_id' => 16, 'brand_id' => 37, 'health_index' => 52,
            // health_rating ya no lo trae el legacy seeder; lo recalcula el motor
            // (RecalculateTransformerHealthSeeder) desde las muestras.
            'phases' => 'three', 'tenant_id' => 1,
            // conmutador viejo 1 (OLTC) → tap_changer_type_id 1 (OLTC).
            'tap_changer_type_id' => 1,
        ]);

        // oil_type viejo 4 → código silicona → id nuevo de silicona.
        $silicona = OilType::where('code', 'silicona')->value('id');
        $this->assertSame($silicona, (int) DB::table('transformers')->where('id', 1019)->value('oil_type_id'));

        // deleted=1 → soft-delete; transformer_type 2 (distribución) preservado.
        $this->assertSoftDeleted('transformers', ['id' => 646]);
        $this->assertSame(2, (int) DB::table('transformers')->where('id', 646)->value('transformer_type_id'));

        // Reforma de aceites: viejo 8 "-" → ninguno (equipo sin aceite).
        $ninguno = OilType::where('code', 'ninguno')->value('id');
        $this->assertSame($ninguno, (int) DB::table('transformers')->where('id', 1015)->value('oil_type_id'));
        // viejo 5 "Éster Vegetal" → vegetal_soya (éster natural).
        $soya = OilType::where('code', 'vegetal_soya')->value('id');
        $this->assertSame($soya, (int) DB::table('transformers')->where('id', 143)->value('oil_type_id'));
    }
}
