<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\ApprovalRule;
use App\Models\ApproverRole;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;
use App\Services\BusinessManagement\WorkPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Duplicar un plan es abrir el trabajo de HOY.
 *
 * No es archivar una copia del de la semana pasada: quien lo pulsa esta
 * levantando la misma maniobra otra vez, hoy, con la misma cuadrilla y los
 * mismos papeles. De ahi salen las tres reglas — codigo nuevo, empieza ahora,
 * dura lo mismo — y la cuarta, que no se veia: sus aprobaciones.
 */
class DuplicarPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        // `Tz::for()` cachea el huso en una estatica por id de usuario, y entre
        // clases de prueba los ids se reciclan: sin esto, el huso que dejo otra
        // prueba se aplica al usuario de esta y las fechas salen corridas. En
        // produccion no pasa —cada peticion es un proceso— pero aqui el orden
        // de las clases decidiria si esta prueba pasa.
        \App\Support\Tz::forget();

        $this->actingAs(User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        \App\Support\Tz::forget();

        parent::tearDown();
    }

    private function base(): array
    {
        return ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];
    }

    private function plan(?string $inicio = '2026-08-20 08:00', ?string $fin = '2026-08-23 17:00'): WorkPlan
    {
        $empresa = Company::firstOrCreate(['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC']);

        return app(WorkPlanService::class)->create([
            'country_id'       => 1,
            'company_id'       => $empresa->id,
            'work_type_id'     => WorkType::firstOrCreate(['code' => 'MTTO'], $this->base())->id,
            'work_location_id' => WorkLocation::firstOrCreate(['name' => 'Planta'], $this->base())->id,
            'description'      => 'Mantenimiento de celda',
            'date_start'       => $inicio,
            'date_end'         => $fin,
        ]);
    }

    /**
     * El caso que puso el dueño: del 20 al 23, duplicado el 25 → del 25 al 28.
     *
     * Y con un codigo de plan de verdad, no «…-COPIA»: ese no dice de que dia
     * es, se alarga con cada copia de la copia, y en obra nadie lo dicta por
     * radio.
     */
    public function test_el_clon_empieza_hoy_y_dura_lo_mismo(): void
    {
        Carbon::setTestNow('2026-08-25 09:30');

        $clon = app(WorkPlanService::class)->duplicate($this->plan());

        $this->assertSame('2026-08-25 09:30', $clon->date_start->format('Y-m-d H:i'));
        $this->assertSame('2026-08-28 18:30', $clon->date_end->format('Y-m-d H:i'), 'Tres dias y nueve horas, igual que el original.');
        $this->assertSame('PE26-2508-0001', $clon->code);
        $this->assertStringNotContainsStringIgnoringCase('copia', $clon->code);
    }

    /** Una jornada de nueve horas duplicada a media tarde sigue durando nueve. */
    public function test_se_conserva_el_intervalo_exacto_no_los_dias_redondos(): void
    {
        Carbon::setTestNow('2026-08-25 14:30');

        $clon = app(WorkPlanService::class)->duplicate($this->plan('2026-08-20 08:00', '2026-08-20 17:00'));

        $this->assertSame('2026-08-25 23:30', $clon->date_end->format('Y-m-d H:i'));
    }

    /** Sin fin en el original no hay duracion que copiar, y el clon nace abierto. */
    public function test_sin_fecha_de_fin_el_clon_tampoco_la_tiene(): void
    {
        Carbon::setTestNow('2026-08-25 09:00');

        $clon = app(WorkPlanService::class)->duplicate($this->plan('2026-08-20 08:00', null));

        $this->assertSame('2026-08-25 09:00', $clon->date_start->format('Y-m-d H:i'));
        $this->assertNull($clon->date_end);
    }

    /** Fechas al reves en el original: dato malo, no duracion negativa. */
    public function test_unas_fechas_al_reves_no_dan_un_clon_imposible(): void
    {
        Carbon::setTestNow('2026-08-25 09:00');

        $plan = $this->plan('2026-08-20 08:00', '2026-08-20 08:00');
        $plan->forceFill(['date_end' => '2026-08-18 08:00'])->saveQuietly();

        $this->assertNull(app(WorkPlanService::class)->duplicate($plan->fresh())->date_end);
    }

    /**
     * Y el clon nace con las firmas que su flujo exige.
     *
     * Faltaba, y el hueco no se veia: «cero aprobaciones pendientes» y «ninguna
     * aprobacion» se cuentan igual, asi que un plan duplicado se daba por
     * terminado sin que nadie autorizara nada.
     */
    public function test_el_clon_nace_con_sus_aprobaciones(): void
    {
        ApproverRole::firstOrCreate(['code' => 'supervisor'],
            ['slug' => Str::random(22), 'name_es' => 'Supervisor', 'name_en' => '', 'sort_order' => 1, 'is_active' => true]);

        ApprovalRule::create($this->base() + [
            'name' => 'Autorizante', 'approver_role' => 'supervisor',
            'priority_level' => 1, 'is_required' => true, 'is_active' => true,
        ]);

        Carbon::setTestNow('2026-08-25 09:00');

        $clon = app(WorkPlanService::class)->duplicate($this->plan());

        $this->assertSame(1, $clon->approvals()->count(), 'Sin aprobaciones el clon cierra sin que nadie autorice.');
        $this->assertSame(1, $clon->approvals()->where('is_required', true)->count());
    }

    /** Lo del original que NO se hereda: nace pendiente, abierto y sin firmar. */
    public function test_el_clon_nace_pendiente_y_sin_candado(): void
    {
        $plan = $this->plan();
        $plan->forceFill(['is_done' => true, 'is_closed' => true])->saveQuietly();

        Carbon::setTestNow('2026-08-25 09:00');

        $clon = app(WorkPlanService::class)->duplicate($plan->fresh());

        $this->assertFalse($clon->is_done);
        $this->assertFalse($clon->is_closed);
        $this->assertSame('Mantenimiento de celda', $clon->description);
    }
}
