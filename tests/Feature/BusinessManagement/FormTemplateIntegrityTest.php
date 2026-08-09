<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\FormTemplate;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;
use App\Services\BusinessManagement\FormTemplateService;
use App\Services\FieldWork\FormTemplateBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tres agujeros de Plantillas de formato que dejaban el modulo inservible por
 * caminos distintos.
 *
 * Un formato es el papel que se llena en obra (AST, EPP, IHM, PTF). Que se
 * duplique vacio, que nazca sin nombre o que se pueda borrar con entregas
 * detras no son detalles: rompen el documento que un inspector pide.
 */
class FormTemplateIntegrityTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'form_templates';
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->plantilla();
    }

    private function plantilla(string $nombre = 'AUD Formato', string $code = 'aud_formato'): FormTemplate
    {
        return FormTemplate::create($this->base() + [
            'name' => $nombre, 'code' => $code,
            'kind' => FormTemplate::STRUCTURED, 'status' => 'draft', 'version' => 1,
        ]);
    }

    /** Le mete una seccion con dos campos, que es lo que hace util a un formato. */
    private function conCampos(FormTemplate $p): FormTemplate
    {
        $seccion = $p->sections()->create(['position' => 1]);
        $seccion->fields()->create(['code' => 'campo_uno', 'field_type' => 'text', 'is_required' => true, 'position' => 1]);
        $seccion->fields()->create(['code' => 'campo_dos', 'field_type' => 'text', 'is_required' => false, 'position' => 2]);

        return $p->fresh();
    }

    /**
     * El constructor recibia el nombre y no lo escribia, asi que los cuatro
     * formatos que trae `docufiz:migrate-formats` nacian con `name` NULL en una
     * instalacion limpia.
     */
    public function test_el_constructor_guarda_el_nombre_que_recibe(): void
    {
        $this->actingAs($this->admin());

        $creada = app(FormTemplateBuilder::class)->crear([
            'country_id' => 1,
            'code'       => 'aud_ast',
            'name'       => 'AST (Análisis de Seguridad en el Trabajo)',
            'kind'       => FormTemplate::STRUCTURED,
            'tenant_id'  => 1,
        ]);

        $this->assertSame('AST (Análisis de Seguridad en el Trabajo)', $creada->fresh()->name);
    }

    /**
     * Duplicar copiaba la cabecera y ni una seccion: la copia de un AST nacia
     * vacia, y un formato vacio no se puede publicar — o sea que no servia.
     */
    public function test_al_duplicar_se_llevan_las_secciones_y_los_campos(): void
    {
        $this->actingAs($this->admin());
        $original = $this->conCampos($this->plantilla());

        $copia = app(FormTemplateService::class)->duplicate($original);

        $this->assertNotNull($copia);
        $this->assertSame(1, $copia->sections()->count(), 'La copia nacio sin secciones.');
        $this->assertSame(2, $copia->fields()->count(), 'La copia nacio sin campos.');
        $this->assertSame(
            ['campo_uno', 'campo_dos'],
            $copia->sections()->first()->fields()->pluck('code')->all(),
        );
    }

    /** Un plan al que colgar la entrega. */
    private function unPlan(): WorkPlan
    {
        $empresa = Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
        $tipo = WorkType::firstOrCreate(['code' => 'MTTO'], $this->base());
        $sede = WorkLocation::firstOrCreate(['name' => 'Lurín'], $this->base());

        return WorkPlan::create($this->base() + [
            'company_id'       => $empresa->id,
            'work_type_id'     => $tipo->id,
            'work_location_id' => $sede->id,
            'user_id'          => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'code'             => 'PE26-0808-0001',
            'description'      => 'Mantenimiento',
            'date_start'       => '2026-08-08',
        ]);
    }

    /** Con entregas detras no se da de baja: el papel quedaria sin su plantilla. */
    public function test_no_se_borra_un_formato_que_ya_tiene_entregas(): void
    {
        $plantilla = $this->plantilla();

        DB::table('form_submissions')->insert([
            'slug' => Str::random(22), 'form_template_id' => $plantilla->id,
            'work_plan_id' => $this->unPlan()->id,
            'template_version' => 1, 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->delete(route('business_management.form_templates.deleteSave', $plantilla->slug), [
                'deleted_description' => 'Ya no se usa',
            ])
            ->assertSessionHas('error');

        $this->assertFalse($plantilla->fresh()->trashed(), 'Se borro un formato con entregas.');
    }

    /** Y sin entregas si se puede: el guard no puede bloquearlo todo. */
    public function test_un_formato_sin_entregas_si_se_borra(): void
    {
        $plantilla = $this->plantilla('AUD Suelto', 'aud_suelto');

        $this->actingAs($this->admin())
            ->delete(route('business_management.form_templates.deleteSave', $plantilla->slug), [
                'deleted_description' => 'Ya no se usa',
            ]);

        $this->assertTrue($plantilla->fresh()->trashed());
    }
}
