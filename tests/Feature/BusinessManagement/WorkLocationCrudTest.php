<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\WorkLocation;
use App\Models\Workstation;
use Illuminate\Support\Str;

/**
 * Las sedes: donde se trabaja.
 *
 * De una sede cuelgan sus puestos y todos los planes que salieron de ella, asi
 * que lo que se fija aqui no es el camino feliz —eso lo arregla cualquiera— sino
 * que la pantalla no pueda dejar puestos y planes apuntando a una sede que ya no
 * existe.
 */
class WorkLocationCrudTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'work_locations';
    }

    private function sede(string $nombre = 'Lurín'): WorkLocation
    {
        return WorkLocation::create($this->base() + ['name' => $nombre]);
    }

    /** Un puesto colgado de la sede: es lo que impide borrarla. */
    private function puestoEn(WorkLocation $sede): Workstation
    {
        return Workstation::create([
            'slug' => Str::random(22), 'work_location_id' => $sede->id,
            'name' => 'Celda 1', 'tenant_id' => 1, 'created_by' => 1,
        ]);
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->sede();
    }

    // ── Alta ─────────────────────────────────────────────────────────────────

    public function test_una_sede_nueva_se_da_de_alta_desde_la_pantalla(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.work_locations.store'), [
            'country_id' => 1, 'name' => 'Talara',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_locations', ['name' => 'Talara', 'tenant_id' => 1]);
    }

    /**
     * Dos sedes con el mismo nombre en el mismo pais se leen igual en el
     * selector del plan, y no hay forma de saber cual se esta eligiendo.
     */
    public function test_no_puede_haber_dos_sedes_con_el_mismo_nombre_en_un_pais(): void
    {
        $this->actingAs($this->admin());
        $this->sede('Lurín');

        $this->post(route('business_management.work_locations.store'), [
            'country_id' => 1, 'name' => 'Lurín',
        ])->assertSessionHasErrors('name');

        $this->assertSame(1, WorkLocation::where('name', 'Lurín')->count());
    }

    /** Los espacios de los extremos son un error de tecleo, no parte del nombre. */
    public function test_el_nombre_se_guarda_sin_espacios_de_sobra(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.work_locations.store'), [
            'country_id' => 1, 'name' => '  Talara  ',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_locations', ['name' => 'Talara']);
    }

    // ── Edicion ──────────────────────────────────────────────────────────────

    public function test_se_le_cambia_el_nombre_desde_la_pantalla(): void
    {
        $this->actingAs($this->admin());
        $sede = $this->sede();

        $this->put(route('business_management.work_locations.update', $sede->slug), [
            'country_id' => 1, 'name' => 'Lurín Planta 2', 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_locations', ['id' => $sede->id, 'name' => 'Lurín Planta 2']);
    }

    // ── Borrado ──────────────────────────────────────────────────────────────

    public function test_una_sede_sin_nada_colgando_se_borra_y_queda_en_papelera(): void
    {
        $this->actingAs($this->admin());
        $sede = $this->sede();

        $this->delete(route('business_management.work_locations.deleteSave', $sede->slug), [
            'deleted_description' => 'se cerró la planta',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSoftDeleted('work_locations', ['id' => $sede->id]);
    }

    public function test_una_sede_con_puestos_no_se_puede_borrar(): void
    {
        $this->actingAs($this->admin());
        $sede = $this->sede();
        $this->puestoEn($sede);

        $this->delete(route('business_management.work_locations.deleteSave', $sede->slug), [
            'deleted_description' => 'ya no se usa',
        ])->assertSessionHas('error');

        $this->assertNotSoftDeleted('work_locations', ['id' => $sede->id]);
    }

    /** Y a cambio se le ofrece desactivarla, que es lo que casi siempre queria. */
    public function test_a_cambio_se_puede_desactivar_y_sus_puestos_siguen_en_pie(): void
    {
        $this->actingAs($this->admin());
        $sede   = $this->sede();
        $puesto = $this->puestoEn($sede);

        $this->post(route('business_management.work_locations.deactivate', $sede->slug))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('work_locations', ['id' => $sede->id, 'is_active' => false]);
        $this->assertDatabaseHas('workstations',   ['id' => $puesto->id, 'is_active' => true]);
    }

    /** En masa, las protegidas se saltan y se dice cuantas — no se aborta el lote. */
    public function test_el_borrado_masivo_salta_las_que_estan_en_uso_y_borra_el_resto(): void
    {
        $this->actingAs($this->admin());
        $libre  = $this->sede('Talara');
        $en_uso = $this->sede('Lurín');
        $this->puestoEn($en_uso);

        $this->post(route('business_management.work_locations.bulk_delete'), [
            'ids' => [$libre->id, $en_uso->id],
            'deleted_description' => 'limpieza del catálogo',
        ])->assertSessionHas('success');

        $this->assertSoftDeleted('work_locations', ['id' => $libre->id]);
        $this->assertNotSoftDeleted('work_locations', ['id' => $en_uso->id]);
    }

    // ── Listado ──────────────────────────────────────────────────────────────

    /**
     * El listado dice de cuantos registros depende cada sede. Sin ese numero, la
     * unica forma de saber si una sede se puede borrar es intentar borrarla.
     */
    public function test_el_listado_dice_cuantos_puestos_y_planes_cuelgan_de_cada_sede(): void
    {
        $this->actingAs($this->admin());
        $sede = $this->sede();
        $this->puestoEn($sede);

        $this->get(route('business_management.work_locations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WorkLocations/Index')
                ->where('work_locations.data.0.name', 'Lurín')
                ->where('work_locations.data.0.usage_count', 1));
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_sin_permiso_de_crear_no_se_crea(): void
    {
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->get(route('business_management.work_locations.create')));
        $this->assertProhibido($this->post(route('business_management.work_locations.store'), [
            'country_id' => 1, 'name' => 'Colada',
        ]));

        $this->assertDatabaseMissing('work_locations', ['name' => 'Colada']);
    }

    public function test_sin_permiso_de_borrar_no_se_borra(): void
    {
        $sede = $this->sede();
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->delete(route('business_management.work_locations.deleteSave', $sede->slug), [
            'deleted_description' => 'porque sí',
        ]));

        $this->assertNotSoftDeleted('work_locations', ['id' => $sede->id]);
    }

    public function test_sin_permiso_de_ver_no_se_entra_al_listado(): void
    {
        $this->assertProhibido(
            $this->actingAs($this->sinPermisos())->get(route('business_management.work_locations.index'))
        );
    }

    /** La papelera es del super, no del admin del workspace. */
    public function test_la_papelera_es_solo_del_super(): void
    {
        $this->assertProhibido(
            $this->actingAs($this->admin())->get(route('business_management.work_locations.trash'))
        );

        $this->actingAs($this->makeSuper())
            ->get(route('business_management.work_locations.trash'))
            ->assertOk();
    }
}
