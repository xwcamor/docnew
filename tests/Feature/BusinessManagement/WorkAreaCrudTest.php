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

    /**
     * Ni escrita de otra manera: en el desplegable del plan «Almacen» y
     * «ALMACEN» se leen igual, y quien elige no sabe cual de las dos tocar.
     *
     * Aqui se comprueba solo la caja porque la prueba corre en SQLite y su
     * `LOWER()` no toca las tildes. En Postgres, que es donde vive esto, la
     * comparacion va con `unaccent()` y «Almacén» tambien choca.
     */
    public function test_tampoco_cambiando_mayusculas(): void
    {
        $this->actingAs($this->admin());
        $this->area('Almacen');

        $this->post(route('business_management.work_areas.store'), [
            'country_id' => 1, 'name' => 'ALMACEN',
        ])->assertSessionHasErrors('name');

        $this->assertSame(1, WorkArea::withoutGlobalScopes()->where('country_id', 1)->count());
    }

    /**
     * Pero el area de OTRA empresa no estorba.
     *
     * `Rule::unique` consulta la tabla cruda, sin el scope del catalogo, asi que
     * un area privada de otro workspace bloqueaba el alta con un «ya existe»
     * que señalaba una fila invisible: no salia en el listado, no se podia
     * abrir y no se podia renombrar. Sin salida.
     */
    public function test_el_area_de_otra_empresa_no_impide_crear_el_mismo_nombre(): void
    {
        \Illuminate\Support\Facades\DB::table('tenants')->insertOrIgnore([[
            'id' => 2, 'slug' => \Illuminate\Support\Str::random(22), 'name' => 'Empresa 2',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        \Illuminate\Support\Facades\DB::table('work_areas')->insert([
            'slug' => \Illuminate\Support\Str::random(22), 'country_id' => 1, 'name' => 'Patio',
            'is_active' => true, 'tenant_id' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin());

        $this->post(route('business_management.work_areas.store'), [
            'country_id' => 1, 'name' => 'Patio',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_areas', ['name' => 'Patio', 'tenant_id' => 1]);
    }

    /** La global de la plataforma si: esa se ve en el mismo selector. */
    public function test_el_area_global_de_la_plataforma_si_impide_repetirla(): void
    {
        \Illuminate\Support\Facades\DB::table('work_areas')->insert([
            'slug' => \Illuminate\Support\Str::random(22), 'country_id' => 1, 'name' => 'Planta',
            'is_active' => true, 'tenant_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin());

        $this->post(route('business_management.work_areas.store'), [
            'country_id' => 1, 'name' => 'Planta',
        ])->assertSessionHasErrors('name');
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

    /** Y renombrarse a si misma no es repetirse. */
    public function test_guardar_un_area_sin_cambiarle_el_nombre_no_da_error(): void
    {
        $this->actingAs($this->admin());
        $area = $this->area();

        $this->put(route('business_management.work_areas.update', $area->slug), [
            'country_id' => 1, 'name' => 'Bobinado', 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    /**
     * El interruptor apagado viaja AUSENTE, no como `false`. Con
     * `sometimes|boolean` a secas se conservaba el valor anterior: el area que
     * alguien acababa de desactivar seguia ofreciendose al registrar planes.
     * Es el mismo fallo que se vio en «puede firmar aprobaciones» de los cargos.
     */
    public function test_un_area_se_desactiva_desde_la_edicion(): void
    {
        $this->actingAs($this->admin());
        $area = $this->area();

        $this->put(route('business_management.work_areas.update', $area->slug), [
            'country_id' => 1, 'name' => 'Bobinado',
            // Sin `is_active`.
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_areas', ['id' => $area->id, 'is_active' => false]);
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

    /**
     * El listado tiene que traer el candado.
     *
     * `is_locked` es un accesor y no viaja en el JSON; lo que viaja es
     * `locked_at`, y es de lo que depende que la casilla de la fila salga
     * deshabilitada. El área que trajo la migración nace bloqueada: sin este
     * dato la casilla se deja marcar y la masiva contesta «N saltados
     * (bloqueados)».
     */
    public function test_el_listado_trae_el_candado_de_cada_fila(): void
    {
        $area = $this->area();
        $area->lock($this->makeSuper());

        $this->actingAs($this->admin());

        $this->get(route('business_management.work_areas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->whereNot('work_areas.data.0.locked_at', null));
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
