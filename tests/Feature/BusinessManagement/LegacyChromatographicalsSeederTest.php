<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use Database\Seeders\DiagnosticCatalogSeeder;
use Database\Seeders\LegacyChromatographicalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica el importador de muestras de cromatografía: parseo y, sobre todo, que
 * el mapeo de gases viejo→nuevo NO esté cruzado (confundir CO/CO2 arruinaría el
 * diagnóstico). Cubre también soft-delete y omisión de trafos inexistentes.
 */
class LegacyChromatographicalsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_gases_without_crossing(): void
    {
        DB::table('tenants')->insert(['id' => 1, 'slug' => Str::random(22), 'name' => 'E1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->seed(DiagnosticCatalogSeeder::class);

        $tr = (new Transformer())->forceFill([
            'slug' => 'tr-1', 'serial' => 'S1', 'tag' => 'TR',
            'oil_type_id' => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id' => TransformerType::where('code', 'potencia')->value('id'),
            'tenant_id' => 1, 'created_by' => 1,
        ]);
        $tr->id = 1;
        $tr->save();

        // Columnas viejas: id, transformer_id, date_rehearsal, num_hid, num_oxi,
        // num_nit, num_met, num_mon, num_dio, num_eti, num_eta, num_ace, diag_status,
        // deleted, created_at, updated_at.
        $header = "INSERT INTO `chromatographicals` (`id`, `transformer_id`, `date_rehearsal`, `num_hid`, `num_oxi`, `num_nit`, `num_met`, `num_mon`, `num_dio`, `num_eti`, `num_eta`, `num_ace`, `diag_status`, `deleted`, `created_at`, `updated_at`) VALUES\n";
        // num_hid=60 num_oxi=9170 num_nit=43943 num_met=82 num_mon=30 num_dio=492 num_eti=12 num_eta=100 num_ace=8
        $row1 = "(29, 1, '2022-03-05 00:00:00', 60.00, 9170.00, 43943.00, 82.00, 30.00, 492.00, 12.00, 100.00, 8.00, 2, 0, '2022-04-06 20:56:12', '2022-04-25 17:27:53'),\n";
        // borrada (deleted=1) + trafo inexistente (id 999) que debe omitirse
        $row2 = "(30, 1, '2022-03-27 00:00:00', 40.00, 9077.00, 15687.00, 78.00, 36.00, 427.00, 10.00, 90.00, 5.00, 2, 1, '2022-04-06 20:56:12', '2022-04-25 17:27:38'),\n";
        $row3 = "(31, 999, '2021-07-13 00:00:00', 15.00, 9278.00, 41826.00, 73.00, 23.00, 413.00, 5.00, 92.00, 5.00, 2, 0, '2022-04-06 21:22:42', '2022-04-25 18:23:28');\n";

        $f = tempnam(sys_get_temp_dir(), 'cromas') . '.sql';
        file_put_contents($f, $header . $row1 . $row2 . $row3);

        $seeder = new LegacyChromatographicalsSeeder();
        $seeder->file = $f;
        $seeder->run();
        unlink($f);

        // Mapeo de gases SIN cruzar (CO=30 ≠ CO2=492 es la prueba clave).
        $this->assertDatabaseHas('chromatographicals', [
            'id' => 29, 'transformer_id' => 1,
            'h2' => 60, 'o2' => 9170, 'n2' => 43943, 'ch4' => 82,
            'co' => 30, 'co2' => 492, 'c2h4' => 12, 'c2h6' => 100, 'c2h2' => 8,
        ]);

        // deleted=1 → soft-delete.
        $this->assertSoftDeleted('chromatographicals', ['id' => 30]);

        // trafo inexistente (999) → omitido.
        $this->assertDatabaseMissing('chromatographicals', ['id' => 31]);
    }
}
