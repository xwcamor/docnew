<?php

namespace Database\Seeders;

use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Services\Diagnostics\HealthIndexService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DemoTransformersSeeder — 5 transformadores de demostración en Empresa 1 (tenant 1),
 * uno por cada estado del semáforo (Muy Bueno → Muy Malo), cada uno con 10 muestras
 * en cada prueba (cromas, fisicoquímico, furanos, factor de potencia).
 *
 * Los valores se eligen para que la ÚLTIMA muestra de cada prueba caiga en la banda
 * objetivo del trafo; las 10 muestras forman una serie temporal que va degradándose
 * (trends muestran movimiento). Todos son Mineral + Potencia para que los umbrales
 * sean consistentes con el cuadro de reglas y los 5 estados se vean limpios.
 *
 * Pensado para verse en setup:project. Si no hay clientes en tenant 1, se omite.
 */
class DemoTransformersSeeder extends Seeder
{
    private const SAMPLES = 10;       // muestras por prueba
    private const TENANT  = 1;

    /** Valores objetivo (última muestra) por severidad 0..4. */
    private const CROMAS = [
        // h2, o2, n2, ch4, co, co2, c2h4, c2h6, c2h2
        0 => ['h2' => 50,  'o2' => 8000, 'n2' => 40000, 'ch4' => 40,  'co' => 200, 'co2' => 5000,  'c2h4' => 50,  'c2h6' => 20,  'c2h2' => 2],
        1 => ['h2' => 50,  'o2' => 8000, 'n2' => 40000, 'ch4' => 40,  'co' => 200, 'co2' => 5000,  'c2h4' => 50,  'c2h6' => 20,  'c2h2' => 25],
        2 => ['h2' => 50,  'o2' => 8000, 'n2' => 40000, 'ch4' => 140, 'co' => 200, 'co2' => 5000,  'c2h4' => 320, 'c2h6' => 20,  'c2h2' => 25],
        3 => ['h2' => 180, 'o2' => 7000, 'n2' => 42000, 'ch4' => 140, 'co' => 650, 'co2' => 14500, 'c2h4' => 320, 'c2h6' => 100, 'c2h2' => 25],
        4 => ['h2' => 260, 'o2' => 6000, 'n2' => 44000, 'ch4' => 180, 'co' => 800, 'co2' => 15500, 'c2h4' => 380, 'c2h6' => 130, 'c2h2' => 45],
    ];

    /** rig, ten (más alto = mejor) · acid, wat, pot (más bajo = mejor). */
    private const FIQUIS = [
        0 => ['rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 10, 'pot' => 0.05],
        1 => ['rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 25, 'pot' => 0.05],
        2 => ['rig' => 37, 'ten' => 30, 'acid' => 0.03, 'wat' => 25, 'pot' => 0.30],
        3 => ['rig' => 37, 'ten' => 22, 'acid' => 0.08, 'wat' => 25, 'pot' => 0.30],
        4 => ['rig' => 32, 'ten' => 18, 'acid' => 0.15, 'wat' => 45, 'pot' => 0.80],
    ];

    /** 2-FAL (ppb): <100 MuyBueno · 100-500 Bueno · 500-2000 Medio · 2000-6000 Malo · >6000 MuyMalo. */
    private const FURANOS = [0 => 50, 1 => 250, 2 => 1000, 3 => 3500, 4 => 9000];

    /** Factor de potencia (%): <0.5 · 0.5-0.7 · 0.7-1.0 · 1.0-2.0 · >2.0. */
    private const FPOT = [0 => 0.2, 1 => 0.6, 2 => 0.85, 3 => 1.5, 4 => 3.0];

    private const SPECS = [
        ['serial' => 'DEMO-TR-1', 'tag' => 'TR-DEMO-1', 'kv' => 34.5, 'mva' => 10.0, 'phases' => 'three',  'year' => 2020],
        ['serial' => 'DEMO-TR-2', 'tag' => 'TR-DEMO-2', 'kv' => 23.0, 'mva' => 5.0,  'phases' => 'three',  'year' => 2016],
        ['serial' => 'DEMO-TR-3', 'tag' => 'TR-DEMO-3', 'kv' => 69.0, 'mva' => 20.0, 'phases' => 'three',  'year' => 2012],
        ['serial' => 'DEMO-TR-4', 'tag' => 'TR-DEMO-4', 'kv' => 13.8, 'mva' => 2.5,  'phases' => 'single', 'year' => 2008],
        ['serial' => 'DEMO-TR-5', 'tag' => 'TR-DEMO-5', 'kv' => 138.0,'mva' => 50.0, 'phases' => 'three',  'year' => 2000],
    ];

    public function run(): void
    {
        $userId    = DB::table('users')->where('tenant_id', self::TENANT)->value('id');
        $oilId     = OilType::where('name', 'Mineral')->value('id');
        $trafoId   = TransformerType::where('name', 'Potencia')->value('id');
        $customers = DB::table('customers')->where('tenant_id', self::TENANT)
            ->whereNull('deleted_at')->orderBy('id')->limit(5)->pluck('id')->all();

        if (!$userId || !$oilId || !$trafoId || count($customers) < 5) {
            $this->command->warn('DemoTransformersSeeder: faltan tenant 1 / aceite / trafo / 5 clientes. Se omite.');
            return;
        }

        $hi = app(HealthIndexService::class);
        $now = Carbon::now();
        mt_srand(20260606); // estados pseudo-aleatorios reproducibles entre setups

        foreach (self::SPECS as $i => $spec) {
            $id = DB::table('transformers')->insertGetId([
                'slug'                => Str::random(22),
                'serial'              => $spec['serial'],
                'tag'                 => $spec['tag'],
                'customer_id'         => $customers[$i],
                'oil_type_id'         => $oilId,
                'transformer_type_id' => $trafoId,
                'voltage_kv'          => $spec['kv'],
                'power_mva'           => $spec['mva'],
                'manufacture_year'    => $spec['year'],
                'phases'              => $spec['phases'],
                'paper_type'          => 'standard',
                'tenant_id'           => self::TENANT,
                'created_by'          => $userId,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            $this->seedSamples($id, $now);

            $t = Transformer::withoutGlobalScopes()->find($id);
            $hi->evaluate($t, persist: true, notify: false);   // cachea el HI; sin alertas (seed)
        }

        $this->command->info('DemoTransformersSeeder: 5 trafos · ' . (5 * 4 * self::SAMPLES) . ' muestras con estados aleatorios.');
    }

    /**
     * 10 muestras por prueba, cada una con un estado ALEATORIO (severidad 0..4)
     * para ver distintos casos en un mismo trafo (semáforo, tendencias, drawers).
     */
    private function seedSamples(int $transformerId, Carbon $now): void
    {
        $cromas = $fiquis = $furanos = $fpot = [];

        for ($i = 0; $i < self::SAMPLES; $i++) {
            $date = $now->copy()->subMonths((self::SAMPLES - 1 - $i) * 6);
            // tenant_id es CLAVE: las tablas de muestras usan BelongsToTenant; sin
            // él, el scope por tenant las esconde al abrir el trafo (quedan invisibles).
            $ts   = ['tenant_id' => self::TENANT, 'created_at' => $now, 'updated_at' => $now];

            // Jitter ±8% por muestra: mismos estados, pero valores únicos, para que
            // los puntos no se apilen en Duval y las tendencias se vean naturales.
            $cromas[] = ['transformer_id' => $transformerId, 'sample_date' => $date] + $this->jitter(self::CROMAS[mt_rand(0, 4)], 0) + $ts;
            $fiquis[] = ['transformer_id' => $transformerId, 'sample_date' => $date] + $this->jitter(self::FIQUIS[mt_rand(0, 4)], 3) + $ts;

            $fal = self::FURANOS[mt_rand(0, 4)];
            $furanos[] = [
                'transformer_id' => $transformerId, 'sample_date' => $date,
                'fal' => $fal, 'hme' => round($fal * 0.25, 1), 'ace' => round($fal * 0.1, 1),
            ] + $ts;

            $fpot[] = [
                'transformer_id' => $transformerId, 'sample_date' => $date,
                'value' => self::FPOT[mt_rand(0, 4)], 'temperature' => 28,
            ] + $ts;
        }

        DB::table('chromatographicals')->insert($cromas);
        DB::table('fiquis')->insert($fiquis);
        DB::table('furanos')->insert($furanos);
        DB::table('fpots')->insert($fpot);
    }

    /** Perturba cada valor numérico ±8% (redondeo `$prec`) para variar las muestras. */
    private function jitter(array $row, int $prec): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $factor = 0.92 + (mt_rand(0, 160) / 1000); // 0.92 .. 1.08
            $out[$k] = round($v * $factor, $prec);
        }
        return $out;
    }
}
