<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use Database\Seeders\DiagnosticCatalogSeeder;
use Database\Seeders\LegacyFuranosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifica el importador de muestras de furanos (furanos→furanos): parseo y que
 * el mapeo de compuestos NO esté cruzado (fal es el que diagnostica DP; cruzarlo
 * con otro compuesto arruinaría el cálculo). Cubre soft-delete y omisión de
 * trafos inexistentes.
 */
class LegacyFuranosSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_compounds_without_crossing(): void
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

        // Columnas viejas: id, transformer_id, date_rehearsal, num_fal, num_hme,
        // num_ace, num_mfu, num_fua, deleted, created_at, updated_at.
        $header = "INSERT INTO `furanos` (`id`, `transformer_id`, `date_rehearsal`, `num_fal`, `num_hme`, `num_ace`, `num_mfu`, `num_fua`, `deleted`, `created_at`, `updated_at`) VALUES\n";
        // fal=120 hme=5 ace=3 mfu=8 fua=15 — valores distintos a propósito para
        // detectar cruces (fal es el que diagnostica DP).
        $row1 = "(10, 1, '2021-05-08 00:00:00', 120.00000, 5.00000, 3.00000, 8.00000, 15.00000, 0, '2022-04-26 16:03:09', '2022-04-26 16:03:09'),\n";
        // borrada (deleted=1) + trafo inexistente (id 999) que debe omitirse
        $row2 = "(11, 1, '2021-10-05 00:00:00', 80.00000, NULL, NULL, NULL, NULL, 1, '2022-04-26 16:35:16', '2022-04-26 16:35:16'),\n";
        $row3 = "(12, 999, '2019-08-20 00:00:00', 65.00000, NULL, NULL, NULL, NULL, 0, '2022-04-26 16:42:44', '2022-04-26 16:42:44');\n";

        $f = tempnam(sys_get_temp_dir(), 'furanos') . '.sql';
        file_put_contents($f, $header . $row1 . $row2 . $row3);

        $seeder = new LegacyFuranosSeeder();
        $seeder->file = $f;
        $seeder->run();
        unlink($f);

        // Mapeo de compuestos SIN cruzar: fal=120 ≠ hme=5 ≠ ace=3 ≠ mfu=8 ≠ fua=15.
        $this->assertDatabaseHas('furanos', [
            'id' => 10, 'transformer_id' => 1,
            'fal' => 120, 'hme' => 5, 'ace' => 3, 'mfu' => 8, 'fua' => 15,
        ]);

        // deleted=1 → soft-delete.
        $this->assertSoftDeleted('furanos', ['id' => 11]);

        // trafo inexistente (999) → omitido.
        $this->assertDatabaseMissing('furanos', ['id' => 12]);
    }
}
