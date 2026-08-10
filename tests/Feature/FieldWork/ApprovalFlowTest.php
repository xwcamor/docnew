<?php

namespace Tests\Feature\FieldWork;

use App\Models\ApprovalRule;
use App\Models\ApproverRole;
use App\Models\Company;
use App\Models\Person;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanApproval;
use App\Models\WorkType;
use App\Services\BusinessManagement\WorkPlanService;
use App\Services\BusinessManagement\WorkPlanSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El flujo de aprobacion es configuracion, no codigo.
 *
 * Arrancamos con dos firmas —supervisor y supervisor HSE— pero eso es una
 * decision de la empresa, no del programa. Antes eran tres: la primera la daba
 * el rol «trabajador», que era el ejecutante. Dejo de ser una aprobacion —no
 * recogia firma propia y desde el flujo se podia borrar o volver opcional— asi
 * que quien responde por la gente de la obra es ahora una columna del plan.
 *
 * Lo que estas pruebas fijan es que el flujo crezca sin tocar codigo:
 *
 *   - cuantas firmas y en que orden: filas de `approval_rules`;
 *   - quien puede firmar: filas de `approver_roles`, no constantes;
 *   - que un tipo de trabajo de mas riesgo exija mas firmas que otro;
 *   - y que, si el procedimiento lo pide, el orden se respete de verdad.
 */
class ApprovalFlowTest extends TestCase
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

    public function test_los_roles_de_siempre_son_filas_y_se_pueden_ampliar(): void
    {
        // Sin «trabajador»: la migracion lo saco del catalogo el dia que el
        // representante dejo de ser una aprobacion.
        $this->assertEqualsCanonicalizing(
            ['supervisor', 'hse_supervisor'],
            ApproverRole::pluck('code')->all(),
        );

        // Un aprobador mas es una fila, no un despliegue.
        ApproverRole::create([
            'slug' => Str::random(22), 'code' => 'client',
            'name_es' => 'Cliente', 'name_en' => 'Client', 'sort_order' => 4,
        ]);

        $this->assertArrayHasKey('client', ApproverRole::opciones());

        // Y sale en el idioma en que se este mirando la aplicacion.
        app()->setLocale('es');
        $this->assertSame('Cliente', ApproverRole::opciones()['client']);

        app()->setLocale('en');
        $this->assertSame('Client', ApproverRole::opciones()['client']);
    }

    public function test_una_cuarta_aprobacion_es_una_fila_mas(): void
    {
        $this->reglasBase();
        $plan = $this->plan($this->tipo('MTTO'));

        $this->assertSame(3, $plan->approvals()->count());

        // Se anade el cliente al flujo y el siguiente plan ya lo exige.
        ApproverRole::create(['slug' => Str::random(22), 'code' => 'client', 'name_es' => 'Cliente', 'name_en' => 'Client', 'sort_order' => 4]);
        $this->regla('client', 4, true);

        $this->assertSame(4, $this->plan($this->tipo('MTTO2'))->approvals()->count());
    }

    /**
     * El caso que motivó esto: a más riesgo, más firmas. Antes las reglas eran
     * por país, así que un izaje de carga exigía lo mismo que un mantenimiento.
     */
    public function test_un_tipo_de_trabajo_puede_exigir_mas_firmas_que_otro(): void
    {
        $this->reglasBase();

        $izaje = $this->tipo('IZAJE');
        ApproverRole::create(['slug' => Str::random(22), 'code' => 'rigging_chief', 'name_es' => 'Jefe de izaje', 'name_en' => 'Rigging chief', 'sort_order' => 5]);

        // Cuatro firmas, y solo para el izaje.
        foreach ([['site_chief', 1], ['supervisor', 2], ['hse_supervisor', 3], ['rigging_chief', 4]] as [$rol, $nivel]) {
            $this->regla($rol, $nivel, true, $izaje->id);
        }

        $this->assertSame(4, $this->plan($izaje)->approvals()->count());
        $this->assertSame(3, $this->plan($this->tipo('MTTO'))->approvals()->count());
    }

    /** Acotar un tipo no obliga a repetir el flujo general en los demas. */
    public function test_sin_reglas_propias_el_tipo_hereda_las_del_pais(): void
    {
        $this->reglasBase();
        $this->regla('site_chief', 1, true, $this->tipo('IZAJE')->id);

        $this->assertSame(3, $this->plan($this->tipo('OTRO'))->approvals()->count());
    }

    public function test_el_orden_solo_se_exige_si_el_workspace_lo_pide(): void
    {
        $this->reglasBase();
        $plan = $this->plan($this->tipo('MTTO'));

        $hse = $plan->approvals()->whereHas('approvalRule', fn ($q) => $q->where('priority_level', 3))->sole();

        // Por defecto no se exige: el HSE puede pasar antes que el supervisor.
        $this->assertFalse((bool) (Setting::get('docufiz.sequential_approvals') ?? false));

        // Y cuando se exige, el HSE tiene dos firmas obligatorias por delante.
        $pendientes = $hse->aprobacionesPendientesAntes();

        $this->assertCount(2, $pendientes);
        $this->assertEqualsCanonicalizing(
            ['site_chief', 'supervisor'],
            $pendientes->map(fn ($a) => $a->approvalRule->approver_role)->all(),
        );
    }

    /** Una aprobacion opcional no frena a las de arriba: para eso es opcional. */
    public function test_una_aprobacion_opcional_no_bloquea_el_flujo(): void
    {
        $this->regla('site_chief', 1, false);
        $this->regla('supervisor', 2, true);
        $plan = $this->plan($this->tipo('MTTO'));

        $supervisor = $plan->approvals()->whereHas('approvalRule', fn ($q) => $q->where('priority_level', 2))->sole();

        $this->assertCount(0, $supervisor->aprobacionesPendientesAntes());
    }

    /** Firmada la anterior, la siguiente se desbloquea. */
    public function test_al_firmar_la_anterior_se_libera_la_siguiente(): void
    {
        $this->reglasBase();
        $plan = $this->plan($this->tipo('MTTO'));

        $nivel = fn (int $n) => $plan->approvals()
            ->whereHas('approvalRule', fn ($q) => $q->where('priority_level', $n))->sole();

        $nivel(1)->update(['is_approved' => true]);

        $this->assertCount(1, $nivel(3)->aprobacionesPendientesAntes());

        $nivel(2)->update(['is_approved' => true]);

        $this->assertCount(0, $nivel(3)->fresh()->aprobacionesPendientesAntes());
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    /**
     * El flujo de tres firmas de siempre, con un jefe de obra donde antes iba
     * el ejecutante: ese rol ya no aprueba nada, y lo que estas pruebas miran
     * es el orden, no quien firma.
     */
    private function reglasBase(): void
    {
        $this->jefeDeObra();
        $this->regla('site_chief', 1, true);
        $this->regla('supervisor', 2, true);
        $this->regla('hse_supervisor', 3, false);
    }

    /** Un aprobador que no viene con el sistema: se da de alta y ya firma. */
    private function jefeDeObra(): ApproverRole
    {
        return ApproverRole::firstOrCreate(['code' => 'site_chief'], [
            'slug' => Str::random(22), 'name_es' => 'Jefe de obra',
            'name_en' => 'Site chief', 'sort_order' => 4,
        ]);
    }

    private function regla(string $rol, int $nivel, bool $obligatoria, ?int $tipoId = null): ApprovalRule
    {
        return ApprovalRule::create([
            'slug' => Str::random(22), 'country_id' => 1, 'work_type_id' => $tipoId,
            'approver_role' => $rol, 'priority_level' => $nivel,
            'is_required' => $obligatoria, 'is_active' => true,
            'tenant_id' => 1, 'created_by' => 1,
        ]);
    }

    private function tipo(string $codigo): WorkType
    {
        return WorkType::create(['slug' => Str::random(22), 'country_id' => 1,
            'tenant_id' => 1, 'created_by' => 1, 'code' => $codigo]);
    }

    private function plan(WorkType $tipo): WorkPlan
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];
        $usuario = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->actingAs($usuario);

        return app(WorkPlanService::class)->create($base + [
            'company_id' => Company::create($base + ['slug' => Str::random(22),
                'num_doc' => (string) random_int(20000000000, 20999999999),
                'name' => 'Contratista', 'complete_name' => 'Contratista SAC'])->id,
            'work_type_id'     => $tipo->id,
            'work_location_id' => WorkLocation::create($base + ['slug' => Str::random(22), 'name' => 'Planta'])->id,
            'user_id'          => $usuario->id,
            'description'      => 'Trabajo', 'date_start' => today(),
        ]);
    }
}
