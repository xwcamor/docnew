<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\User;
use App\Models\WorkPlan;
use App\Models\WorkType;
use App\Services\FieldWork\FormSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Un plan espera los documentos que existian cuando se creo — y uno solo por
 * documento, aunque el catalogo lleve dos versiones.
 *
 * DE DONDE VIENE
 * --------------
 * El dueño del producto lo describio con su caso: «imagina que creo 5 formatos,
 * esto provoca que en todos los planes aparezca [...] incluso hara que se
 * confundan los planes que siguen en pendiente o reabiertos». Publicar un
 * documento nuevo y colgarlo del tipo de trabajo lo metia como pendiente en
 * todos los planes abiertos de ese tipo — planes que iban a cerrarse ese dia
 * amanecian con cinco documentos «sin llenar» que nadie pidio para esa jornada.
 *
 * Y editar un formato con planes a medias hacia dos daños mas: el plan
 * enseñaba el MISMO documento dos veces (el llenado, version vieja, y la
 * version nueva como pendiente), y abrir el llenado reventaba con 500 porque
 * su plantilla habia pasado a «archivada» al publicarse la nueva.
 */
class PlanConoceSusDocumentosTest extends TestCase
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

    /**
     * Un documento publicado DESPUES de crear el plan no se cuela solo.
     *
     * Sale en el catalogo de la ficha, apagado, y quien arma el plan lo
     * enciende si esa obra lo necesita — para eso existe
     * `WorkPlanSetupService::addFormTemplate()`.
     */
    public function test_un_documento_nuevo_no_aparece_en_los_planes_de_antes(): void
    {
        [$plan, $tipo] = $this->planConTipo();

        $conocido = $this->plantilla('AST', creada: now()->subYear());
        $tipo->formTemplates()->attach($conocido->id, ['is_required' => true]);

        // Fuera del margen de «la misma sentada» (10 min): es el formato
        // que alguien publica al dia siguiente sobre planes que ya andaban.
        $nuevo = $this->plantilla('NUEVO', creada: now()->addDay());
        $tipo->formTemplates()->attach($nuevo->id, ['is_required' => true]);

        $esperados = $plan->fresh()->expectedFormTemplates();

        $this->assertTrue($esperados->has($conocido->id));
        $this->assertFalse($esperados->has($nuevo->id),
            'un documento publicado despues de crear el plan no se mete solo como pendiente');
    }

    /** Añadido a mano, si entra: esa es la puerta. */
    public function test_el_documento_nuevo_entra_si_alguien_lo_añade_al_plan(): void
    {
        [$plan, $tipo] = $this->planConTipo();

        // Fuera del margen de «la misma sentada» (10 min): es el formato
        // que alguien publica al dia siguiente sobre planes que ya andaban.
        $nuevo = $this->plantilla('NUEVO', creada: now()->addDay());
        $tipo->formTemplates()->attach($nuevo->id, ['is_required' => false]);

        app(\App\Services\BusinessManagement\WorkPlanSetupService::class)
            ->addFormTemplate($plan, $nuevo);

        $this->assertTrue($plan->fresh()->expectedFormTemplates()->has($nuevo->id));
    }

    /**
     * Una VERSION nueva de un documento conocido no es un documento nuevo.
     *
     * La identidad es el codigo: si el plan aun no lleno el AST y hoy el AST va
     * por la v2, se llena con la v2. Filtrar por id habria dejado los planes
     * sin sus documentos cada vez que alguien editara un formato.
     */
    public function test_la_version_nueva_de_un_documento_conocido_si_llega(): void
    {
        [$plan, $tipo] = $this->planConTipo();

        $v2 = $this->plantilla('AST', creada: now()->addDay(), version: 2);

        // La primera version nacio antes que el plan: el documento es conocido.
        $this->plantilla('AST-v1', creada: now()->subYear())->update(['code' => 'AST', 'status' => 'archived']);

        $tipo->formTemplates()->attach($v2->id, ['is_required' => true]);

        $this->assertTrue($plan->fresh()->expectedFormTemplates()->has($v2->id),
            'la version nueva de un documento que el plan conocia tiene que llegarle');
    }

    /**
     * Editar un formato con el plan a medias NO duplica el documento.
     *
     * La entrega apunta a la version vieja (archivada al publicar la nueva) y
     * el tipo de trabajo ya apunta a la nueva: sin el colapso por codigo, el
     * plan enseñaba el mismo documento dos veces y uno de los dos «sin llenar».
     */
    public function test_dos_versiones_del_mismo_documento_son_una_sola_fila(): void
    {
        [$plan, $tipo] = $this->planConTipo();

        $v1 = $this->plantilla('EPP', creada: now()->subYear());
        $entrega = $this->entrega($plan, $v1);

        // Se publica la v2: la v1 se archiva y el pivote pasa a la v2.
        $v1->update(['status' => 'archived']);
        $v2 = $this->plantilla('EPP-v2', creada: now()->subYear(), version: 2);
        $v2->update(['code' => 'EPP']);
        $tipo->formTemplates()->attach($v2->id, ['is_required' => true]);

        $esperados = $plan->fresh()->expectedFormTemplates();

        $delCodigo = $esperados->filter(fn ($item) => $item['template']->code === 'EPP');

        $this->assertCount(1, $delCodigo, 'el mismo documento no puede salir dos veces en el plan');
        $this->assertSame($v1->id, $delCodigo->first()['template']->id,
            'gana la version CON entrega: es la que este plan conocio');
        $this->assertTrue($delCodigo->first()['is_required'],
            'la exigencia de la version nueva no se pierde al colapsar');
    }

    /**
     * Y la entrega de la version archivada SE ABRE, no revienta.
     *
     * Era un 500 de verdad: «La plantilla no esta publicada» sobre un documento
     * que se lleno cuando SI lo estaba. Lo que exige plantilla publicada es
     * CREAR una entrega nueva, nunca volver a abrir la que existe.
     */
    public function test_la_entrega_de_una_version_archivada_se_vuelve_a_abrir(): void
    {
        [$plan] = $this->planConTipo();

        $v1 = $this->plantilla('EPP', creada: now()->subYear());
        $entrega = $this->entrega($plan, $v1);

        $v1->update(['status' => 'archived']);

        $abierta = app(FormSubmissionService::class)->abrir($plan, $v1->fresh());

        $this->assertTrue($abierta->is($entrega), 'lo ya abierto se vuelve a abrir siempre');

        // Y crear una nueva contra una plantilla no publicada sigue vetado.
        $otra = $this->plantilla('OTRA', creada: now()->subYear());
        $otra->update(['status' => 'draft']);

        $this->expectException(\InvalidArgumentException::class);
        app(FormSubmissionService::class)->abrir($plan, $otra->fresh());
    }

    /**
     * Un plan migrado no pierde sus documentos por la fecha.
     *
     * Los planes migrados guardan el `created_at` de la v1 — anterior a que
     * estos formatos se sembraran aqui. La entrega es lo que prueba que el plan
     * conocia el documento, tenga la fecha que tenga.
     */
    public function test_un_plan_migrado_conserva_la_exigencia_de_lo_que_ya_llenaba(): void
    {
        [$plan, $tipo] = $this->planConTipo();
        $plan->forceFill(['created_at' => now()->subYears(3)])->saveQuietly();

        $ast = $this->plantilla('AST', creada: now());
        $tipo->formTemplates()->attach($ast->id, ['is_required' => true]);
        $this->entrega($plan, $ast);

        $esperados = $plan->fresh()->expectedFormTemplates();

        $this->assertTrue($esperados->has($ast->id));
        $this->assertTrue($esperados[$ast->id]['is_required'],
            'el AST que este plan lleva llenando desde la v1 sigue siendo exigido');
    }

    // ── Decorado ────────────────────────────────────────────────────────────

    /** @return array{0: WorkPlan, 1: WorkType} */
    private function planConTipo(): array
    {
        $tipo = WorkType::create($this->base() + ['name' => 'Mantenimiento', 'code' => 'MANT']);

        $sede = \App\Models\WorkLocation::create($this->base() + ['name' => 'Planta']);

        $plan = WorkPlan::create($this->base() + [
            'company_id'       => \App\Models\Company::create($this->base() + [
                'name' => 'Contratista', 'complete_name' => 'Contratista SAC', 'num_doc' => '20100000001',
            ])->id,
            'work_type_id'     => $tipo->id,
            'work_location_id' => $sede->id,
            'user_id'          => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'code'             => 'PE26-0814-0001', 'description' => 'Trabajo', 'date_start' => '2026-08-14',
        ]);

        return [$plan, $tipo];
    }

    private function plantilla(string $codigo, \DateTimeInterface $creada, int $version = 1): FormTemplate
    {
        $plantilla = FormTemplate::create($this->base() + [
            'code' => $codigo, 'kind' => FormTemplate::STRUCTURED, 'status' => 'published',
            'version' => $version, 'requires_signature' => false, 'published_at' => now(),
        ]);

        $plantilla->forceFill(['created_at' => $creada])->saveQuietly();

        return $plantilla->fresh();
    }

    private function entrega(WorkPlan $plan, FormTemplate $plantilla): FormSubmission
    {
        return FormSubmission::create($this->base() + [
            'work_plan_id' => $plan->id, 'form_template_id' => $plantilla->id,
            'template_version' => $plantilla->version, 'status' => 'draft',
        ]);
    }

    /** @return array<string, mixed> */
    private function base(): array
    {
        return ['slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 1, 'is_active' => true];
    }
}
