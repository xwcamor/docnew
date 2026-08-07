<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use Database\Seeders\DiagnosticCatalogSeeder;
use Database\Seeders\LegacyPhysicalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica el importador de muestras de fisicoquímico (physicals→fiquis): parseo
 * y, sobre todo, que el mapeo de parámetros NO esté cruzado (confundir rig con
 * rig877 o pot con pot100 arruinaría el diagnóstico). Cubre también soft-delete
 * y omisión de trafos inexistentes.
 */
class LegacyPhysicalsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_params_without_crossing(): void
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

        // Columnas viejas: id, transformer_id, date_rehearsal, num_acid, num_pot,
        // num_pot2, num_rig, num_rig2, num_ten, num_wat, diag_status, deleted,
        // created_at, updated_at.
        $header = "INSERT INTO `physicals` (`id`, `transformer_id`, `date_rehearsal`, `num_acid`, `num_pot`, `num_pot2`, `num_rig`, `num_rig2`, `num_ten`, `num_wat`, `diag_status`, `deleted`, `created_at`, `updated_at`) VALUES\n";
        // acid=0.011 pot=0.004 pot2=0.099 rig=51 rig2=33 ten=40.1 wat=12 — valores
        // distintos a propósito para detectar cruces rig↔rig877 / pot↔pot100.
        $row1 = "(10, 1, '2021-05-08 00:00:00', 0.01100, 0.00400, 0.09900, 51.00000, 33.00000, 40.10000, 12.00000, 5, 0, '2022-04-26 16:03:09', '2022-04-26 16:03:09'),\n";
        // borrada (deleted=1) + trafo inexistente (id 999) que debe omitirse
        $row2 = "(11, 1, '2021-10-05 00:00:00', 0.01000, 0.00200, NULL, 51.00000, NULL, 40.20000, 8.00000, 5, 1, '2022-04-26 16:35:16', '2022-04-26 16:35:16'),\n";
        $row3 = "(12, 999, '2019-08-20 00:00:00', 0.01000, 0.00400, NULL, 65.00000, NULL, 39.00000, 6.00000, 5, 0, '2022-04-26 16:42:44', '2022-04-26 16:42:44');\n";

        $f = tempnam(sys_get_temp_dir(), 'fiquis') . '.sql';
        file_put_contents($f, $header . $row1 . $row2 . $row3);

        $seeder = new LegacyPhysicalsSeeder();
        $seeder->file = $f;
        $seeder->run();
        unlink($f);

        // Mapeo de parámetros SIN cruzar: rig=51 ≠ rig877=33, pot=0.004 ≠ pot100=0.099.
        $this->assertDatabaseHas('fiquis', [
            'id' => 10, 'transformer_id' => 1,
            'acid' => 0.011, 'pot' => 0.004, 'pot100' => 0.099,
            'rig' => 51, 'rig877' => 33, 'ten' => 40.1, 'wat' => 12,
        ]);

        // deleted=1 → soft-delete.
        $this->assertSoftDeleted('fiquis', ['id' => 11]);

        // trafo inexistente (999) → omitido.
        $this->assertDatabaseMissing('fiquis', ['id' => 12]);
    }
}
