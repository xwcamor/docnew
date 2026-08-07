<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Chromatographical;
use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Models\User;
use Database\Seeders\CromasRulesSeeder;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * verify:legacy — compara el HI cacheado del sistema viejo (dump) contra el
 * motor nuevo. Aquí con un dump sintético de 2 trafos: uno que coincide y uno
 * con HI viejo absurdo (debe salir como discrepancia).
 */
class VerifyLegacyHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_compares_legacy_snapshot_against_new_engine(): void
    {
        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        $this->seed(DiagnosticCatalogSeeder::class);
        $this->seed(CromasRulesSeeder::class);

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);

        // Trafo con gases normales → motor nuevo da HI alto (Muy Bueno = 100).
        $t = (new Transformer())->forceFill([
            'id' => 501, 'slug' => 'tr-501', 'serial' => 'S-501', 'tag' => 'T501',
            'oil_type_id' => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id' => TransformerType::where('code', 'potencia')->value('id'),
            'tenant_id' => 1, 'created_by' => $u->id,
        ]);
        $t->save();
        (new Chromatographical())->forceFill([
            'transformer_id' => 501, 'tenant_id' => 1, 'created_by' => $u->id,
            'sample_date' => '2024-01-01',
            'h2' => 10, 'ch4' => 10, 'c2h4' => 10, 'c2h6' => 10, 'co' => 100, 'co2' => 1000, 'c2h2' => 0,
        ])->save();
        // Fiquis + furanos sanos: con las TRES pruebas el trafo entra a la
        // paridad (sin ellas sería "parcial" y queda fuera por diseño).
        (new \App\Models\Fiqui())->forceFill([
            'transformer_id' => 501, 'tenant_id' => 1, 'created_by' => $u->id,
            'sample_date' => '2024-01-01', 'rig' => 55, 'ten' => 40, 'acid' => 0.01, 'wat' => 5, 'pot' => 0.05,
        ])->save();
        (new \App\Models\Furano())->forceFill([
            'transformer_id' => 501, 'tenant_id' => 1, 'created_by' => $u->id,
            'sample_date' => '2024-01-01', 'fal' => 10,
        ])->save();
        // fpot ahora entra al HI por default → sin muestra el trafo sería "parcial"
        // y quedaría fuera de la comparación. Con fpot sano (0.3 → rating 4) el HI
        // sigue en 100 y el trafo entra a la paridad.
        (new \App\Models\Fpot())->forceFill([
            'transformer_id' => 501, 'tenant_id' => 1, 'created_by' => $u->id,
            'sample_date' => '2024-01-01', 'value' => 0.3,
        ])->save();

        // Dump sintético (30 columnas como el real): 501 coincide (HI viejo 100,
        // Muy Bueno) · 502 no existe en BD (se ignora) · 503 borrado (se ignora).
        $row = fn ($id, $hi, $st, $del) => "({$id},1,1,1,1,1,1,NULL,'S-{$id}','T{$id}',66,6,18,'Trifásico',NULL,{$hi},'{$st}','green','x','x','x','x',NULL,NULL,0,0,0,{$del},'2019-01-01','2019-01-01')";
        $dump = 'INSERT INTO `transformers` VALUES ' . implode(',', [
            $row(501, 100, 'Muy Bueno', 0),
            $row(502, 50, 'Medio', 0),
            $row(503, 10, 'Muy Malo', 1),
        ]) . ';';
        $file = sys_get_temp_dir() . '/tr_legacy_test.sql';
        file_put_contents($file, $dump);

        $this->artisan('verify:legacy', ['--file' => $file, '--tolerance' => 5])
            ->expectsOutputToContain('Comparados:            1')
            ->expectsOutputToContain('Discrepancias:         0')
            ->assertExitCode(0);

        // Con HI viejo absurdo, el mismo trafo debe salir como discrepancia.
        file_put_contents($file, 'INSERT INTO `transformers` VALUES ' . $row(501, 10, 'Muy Malo', 0) . ';');
        $this->artisan('verify:legacy', ['--file' => $file, '--tolerance' => 5])
            ->expectsOutputToContain('Discrepancias:         1')
            ->assertExitCode(0);

        @unlink($file);
    }
}
