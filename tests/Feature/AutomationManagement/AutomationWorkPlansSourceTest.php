<?php

namespace Tests\Feature\AutomationManagement;

use App\Models\Automation;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;
use App\Services\Automations\Actions\EmailAction;
use App\Services\Automations\DataSourceRegistry;
use App\Services\Automations\DataSources\WorkPlansDataSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La fuente de datos «Planes de trabajo».
 *
 * Hasta que existio, Automatizaciones no podia automatizar NADA de DOCUFIZ: las
 * dos fuentes registradas eran `customers` —un modulo que ya no sale en el menu
 * lateral— y `subscriptions` —facturacion, y solo para el super—. El caso que
 * pide un supervisor de HSE, «avisame de los planes que quedaron sin
 * terminar», no se podia armar.
 *
 * Lo que estas pruebas fijan:
 *   1. La fuente aparece en el catalogo que recibe el formulario.
 *   2. Trae solo los planes del workspace de la automatizacion — el job corre
 *      en la cola, sin sesion, asi que el scope global no lo protege.
 *   3. El correo lista los planes por su CODIGO. Un plan no tiene nombre, y sin
 *      esto la lista salian 22 caracteres al azar (el slug).
 */
class AutomationWorkPlansSourceTest extends AutomationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([[
            'id' => 2, 'slug' => Str::random(22), 'name' => 'Otro workspace',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    public function test_la_fuente_de_planes_esta_en_el_catalogo_del_admin(): void
    {
        $this->actingAsTenantAdmin();

        $keys = collect(app(DataSourceRegistry::class)->catalog())->pluck('key');

        $this->assertTrue($keys->contains('work_plans'),
            'el formulario no ofrece los planes de trabajo como fuente');
    }

    public function test_la_fuente_solo_trae_los_planes_de_su_workspace(): void
    {
        $this->plan('PE26-MIO-0001', tenantId: 1, terminado: false);
        $this->plan('PE26-MIO-0002', tenantId: 1, terminado: true);
        $this->plan('PE26-OTRO-001', tenantId: 2, terminado: false);

        $automatizacion = $this->automatizacion(tenantId: 1, filtro: [
            'where' => [['field' => 'is_done', 'op' => '=', 'value' => false]],
            'limit' => 100,
        ]);

        $encontrados = (new WorkPlansDataSource())->fetch($automatizacion)->pluck('code');

        $this->assertSame(['PE26-MIO-0001'], $encontrados->all());
    }

    /** Un campo que no esta en `fields()` se ignora: no se filtra por columnas sueltas. */
    public function test_un_campo_fuera_del_catalogo_no_filtra(): void
    {
        $this->plan('PE26-MIO-0003', tenantId: 1, terminado: false);

        $automatizacion = $this->automatizacion(tenantId: 1, filtro: [
            'where' => [['field' => 'tenant_id', 'op' => '=', 'value' => 999]],
            'limit' => 100,
        ]);

        $this->assertCount(1, (new WorkPlansDataSource())->fetch($automatizacion));
    }

    /** El correo tiene que decir PE26-…, no el slug de 22 caracteres. */
    public function test_el_correo_lista_los_planes_por_su_codigo(): void
    {
        $plan = $this->plan('PE26-MIO-0004', tenantId: 1, terminado: false);

        $automatizacion = $this->automatizacion(tenantId: 1, filtro: ['limit' => 100]);
        $datos = (new WorkPlansDataSource())->fetch($automatizacion);

        $cuerpo = $this->interpolarCuerpo($automatizacion, $datos);

        $this->assertStringContainsString('- PE26-MIO-0004', $cuerpo);
        $this->assertStringNotContainsString($plan->slug, $cuerpo);
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    /** Renderiza {list} con el mismo trait que usan las acciones reales. */
    private function interpolarCuerpo(Automation $automatizacion, $datos): string
    {
        $accion = new class extends EmailAction {
            public function render(Automation $a, $datos): string
            {
                return $this->interpolate('{count}:\n{list}', $this->templateVars($a, $datos));
            }
        };

        return $accion->render($automatizacion, $datos);
    }

    private function automatizacion(int $tenantId, array $filtro): Automation
    {
        $automatizacion = new Automation([
            'name'           => 'Planes sin terminar',
            'is_active'      => true,
            'trigger_type'   => 'schedule',
            'trigger_config' => ['kind' => 'daily', 'time' => '07:00'],
            'data_source'    => 'work_plans',
            'data_filter'    => $filtro,
            'action_type'    => 'email',
            'action_config'  => ['to' => ['hse@empresa.com'], 'subject' => 'x', 'body' => '{list}'],
        ]);
        $automatizacion->tenant_id = $tenantId;
        $automatizacion->saveQuietly();

        return $automatizacion;
    }

    private function plan(string $codigo, int $tenantId, bool $terminado): WorkPlan
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => $tenantId];

        $empresa = Company::firstOrCreate(
            ['num_doc' => '2010000000' . $tenantId],
            $base + ['name' => "Contratista {$tenantId}", 'complete_name' => "Contratista {$tenantId} SAC"],
        );
        $tipo = WorkType::firstOrCreate(['code' => 'MTTO'], ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1]);
        $sede = WorkLocation::firstOrCreate(['name' => 'Planta'], ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1]);

        $plan = new WorkPlan($base + [
            'company_id'       => $empresa->id,
            'work_type_id'     => $tipo->id,
            'work_location_id' => $sede->id,
            'user_id'          => User::factory()->create(['tenant_id' => $tenantId, 'country_id' => 1, 'locale_id' => 1])->id,
            'code'             => $codigo,
            'description'      => 'Trabajo',
            'date_start'       => now()->format('Y-m-d H:i'),
            'is_done'          => $terminado,
        ]);
        $plan->tenant_id = $tenantId;
        $plan->saveQuietly();

        return $plan;
    }
}
