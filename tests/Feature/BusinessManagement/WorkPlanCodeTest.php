<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;
use App\Services\BusinessManagement\WorkPlanCodeGenerator;
use App\Services\BusinessManagement\WorkPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El codigo del plan lo pone el sistema, y no se repite.
 *
 * En el sistema anterior el ultimo bloque era la hora de creacion, asi que dos
 * planes registrados en el mismo minuto salian con el mismo codigo: 3 722
 * planes tenian solo 3 526 codigos distintos. Buscar uno devolvia varios
 * trabajos y no habia forma de saber cual se firmo.
 *
 * Aqui ese bloque es el correlativo del dia del trabajo, y estas pruebas son
 * las que impiden que vuelva a pasar.
 */
class WorkPlanCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }

    public function test_el_codigo_lleva_el_pais_el_ano_el_dia_del_trabajo_y_su_numero(): void
    {
        $plan = $this->crear('2026-08-06');

        $this->assertSame('PE26-0608-0001', $plan->code);
    }

    public function test_el_segundo_plan_del_dia_es_el_dos(): void
    {
        $this->assertSame('PE26-0608-0001', $this->crear('2026-08-06')->code);
        $this->assertSame('PE26-0608-0002', $this->crear('2026-08-06')->code);
        $this->assertSame('PE26-0608-0003', $this->crear('2026-08-06')->code);
    }

    public function test_cada_dia_arranca_de_uno(): void
    {
        $this->crear('2026-08-06');
        $this->crear('2026-08-06');

        $this->assertSame('PE26-0708-0001', $this->crear('2026-08-07')->code);
    }

    /**
     * El caso que rompia el sistema anterior: dos supervisores dando de alta un
     * plan a la vez. Con la hora en el codigo salian identicos.
     */
    public function test_diez_planes_del_mismo_dia_no_repiten_codigo(): void
    {
        $codigos = collect(range(1, 10))->map(fn () => $this->crear('2026-08-06')->code);

        $this->assertCount(10, $codigos->unique(), 'hay codigos repetidos: ' . $codigos->implode(', '));
        $this->assertSame('PE26-0608-0010', $codigos->last());
    }

    /**
     * Se sigue del mayor, no se rellenan huecos.
     *
     * Si el 0002 ya existe, el siguiente es el 0003 aunque el 0001 este libre.
     * Rellenar seria peor: haria pensar que hubo un plan 0001 que nunca
     * existio, y un numero reutilizado significa dos documentos distintos con
     * el mismo codigo en el archivo, que es justo lo que veniamos a arreglar.
     */
    public function test_sigue_del_mayor_y_no_rellena_huecos(): void
    {
        $this->crear('2026-08-06', 'PE26-0608-0002');

        $this->assertSame('PE26-0608-0003', $this->crear('2026-08-06')->code);
        $this->assertSame('PE26-0608-0004', $this->crear('2026-08-06')->code);
    }

    /** Los planes borrados siguen ocupando su numero: no se reutiliza. */
    public function test_un_plan_borrado_no_libera_su_numero(): void
    {
        $primero = $this->crear('2026-08-06');
        $primero->delete();

        $this->assertSame('PE26-0608-0002', $this->crear('2026-08-06')->code);
    }

    /** Renumerar es estable: dos pasadas dan el mismo resultado. */
    public function test_renumerar_da_siempre_lo_mismo(): void
    {
        foreach (['PE26-0608-9999', 'X', 'X-2'] as $i => $codigo) {
            $this->crear('2026-08-06', $codigo);
        }

        $generador = app(WorkPlanCodeGenerator::class);
        $generador->renumerar(1);
        $primera = WorkPlan::orderBy('id')->pluck('code')->all();

        $generador->renumerar(1);
        $segunda = WorkPlan::orderBy('id')->pluck('code')->all();

        $this->assertSame(['PE26-0608-0001', 'PE26-0608-0002', 'PE26-0608-0003'], $primera);
        $this->assertSame($primera, $segunda, 'renumerar dos veces cambio los codigos');
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    private function crear(string $fecha, ?string $codigo = null): WorkPlan
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        $empresa = Company::create($base + [
            'num_doc' => (string) random_int(20000000000, 20999999999),
            'name' => 'Contratista', 'complete_name' => 'Contratista SAC',
        ]);

        $usuario = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->actingAs($usuario);

        return app(WorkPlanService::class)->create([
            'slug'             => Str::random(22),
            'country_id'       => 1,
            'tenant_id'        => 1,
            'company_id'       => $empresa->id,
            'work_type_id'     => WorkType::create($base + ['slug' => Str::random(22), 'code' => 'MTTO'])->id,
            'work_location_id' => WorkLocation::create($base + ['slug' => Str::random(22), 'name' => 'Planta'])->id,
            'user_id'          => $usuario->id,
            'code'             => $codigo,
            'description'      => 'Trabajo programado',
            'date_start'       => $fecha,
        ]);
    }
}
