<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\User;
use App\Models\WorkArea;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;

/**
 * Las areas: la parte de la sede donde se trabaja.
 *
 * Es el mas pequeño de los cinco catalogos y el que mas facil se rompe por lo
 * mismo: un area que ya salio en un plan no puede desaparecer, porque el plan
 * es el documento que un inspector puede pedir (docs/UI.md §6).
 */
class WorkAreaCrudTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'work_areas';
    }

    private function area(string $nombre = 'Bobinado'): WorkArea
    {
        return WorkArea::create($this->base() + ['name' => $nombre]);
    }

    /** Un plan hecho en esa area: es lo que impide borrarla. */
    private function planEn(WorkArea $area): WorkPlan
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
            'work_area_id'     => $area->id,
            'user_id'          => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'code'             => 'PE26-0808-0001',
            'description'      => 'Mantenimiento',
            'date_start'       => '2026-08-08',
        ]);
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->area();
    }

    // ── Alta ─────────────────────────────────────────────────────────────────

    public function test_un_area_nueva_se_da_de_alta_desde_la_pantalla(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.work_areas.store'), [
            'country_id' => 1, 'name' => 'Patio de maniobras',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_areas', ['name' => 'Patio de maniobras', 'tenant_id' => 1]);
    }

    public function test_no_puede_haber_dos_areas_con_el_mismo_nombre_en_un_pais(): void
    {
        $this->actingAs($this->admin());
        $this->area('Bobinado');

        $this->post(route('business_management.work_areas.store'), [
            'country_id' => 1, 'name' => 'Bobinado',
        ])->assertSessionHasErrors('name');

        $this->assertSame(1, WorkArea::where('name', 'Bobinado')->count());
    }

    // ── Edicion ──────────────────────────────────────────────────────────────

    public function test_se_le_cambia_el_nombre_desde_la_pantalla(): void
    {
        $this->actingAs($this->admin());
        $area = $this->area();

        $this->put(route('business_management.work_areas.update', $area->slug), [
            'country_id' => 1, 'name' => 'Bobinado y armado', 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_areas', ['id' => $area->id, 'name' => 'Bobinado y armado']);
    }

    // ── Borrado ──────────────────────────────────────────────────────────────

    public function test_un_area_sin_planes_se_borra_y_queda_en_papelera(): void
    {
        $this->actingAs($this->admin());
        $area = $this->area();

        $this->delete(route('business_management.work_areas.deleteSave', $area->slug), [
            'deleted_description' => 'se fusionó con otra',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSoftDeleted('work_areas', ['id' => $area->id]);
    }

    public function test_un_area_que_ya_salio_en_un_plan_no_se_puede_borrar(): void
    {
        $this->actingAs($this->admin());
        $area = $this->area();
        $this->planEn($area);

        $this->delete(route('business_management.work_areas.deleteSave', $area->slug), [
            'deleted_description' => 'ya no se usa',
        ])->assertSessionHas('error');

        $this->assertNotSoftDeleted('work_areas', ['id' => $area->id]);
    }

    public function test_a_cambio_se_puede_desactivar_y_el_plan_sigue_en_pie(): void
    {
        $this->actingAs($this->admin());
        $area = $this->area();
        $plan = $this->planEn($area);

        $this->post(route('business_management.work_areas.deactivate', $area->slug))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('work_areas', ['id' => $area->id, 'is_active' => false]);
        $this->assertDatabaseHas('work_plans', ['id' => $plan->id, 'work_area_id' => $area->id]);
    }

    // ── Listado ──────────────────────────────────────────────────────────────

    public function test_el_listado_dice_cuantos_planes_usan_cada_area(): void
    {
        $this->actingAs($this->admin());
        $area = $this->area();
        $this->planEn($area);

        $this->get(route('business_management.work_areas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WorkAreas/Index')
                ->where('work_areas.data.0.name', 'Bobinado')
                ->where('work_areas.data.0.usage_count', 1));
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_sin_permiso_de_crear_no_se_crea(): void
    {
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->get(route('business_management.work_areas.create')));
        $this->assertProhibido($this->post(route('business_management.work_areas.store'), [
            'country_id' => 1, 'name' => 'Colada',
        ]));

        $this->assertDatabaseMissing('work_areas', ['name' => 'Colada']);
    }

    public function test_sin_permiso_de_borrar_no_se_borra(): void
    {
        $area = $this->area();
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->delete(route('business_management.work_areas.deleteSave', $area->slug), [
            'deleted_description' => 'porque sí',
        ]));

        $this->assertNotSoftDeleted('work_areas', ['id' => $area->id]);
    }

    public function test_sin_permiso_de_ver_no_se_entra_al_listado(): void
    {
        $this->assertProhibido(
            $this->actingAs($this->sinPermisos())->get(route('business_management.work_areas.index'))
        );
    }

    public function test_la_papelera_es_solo_del_super(): void
    {
        $this->assertProhibido(
            $this->actingAs($this->admin())->get(route('business_management.work_areas.trash'))
        );
    }
}
