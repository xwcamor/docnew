<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\Workstation;
use App\Models\WorkType;
use Illuminate\Support\Str;

/**
 * Los puestos de trabajo: cada uno cuelga de una sede.
 *
 * Las dos cosas que fija esta prueba son las dos que se rompen solas:
 *
 *   1. el puesto pertenece a una sede y el nombre solo tiene que ser unico
 *      DENTRO de ella — «Celda 1» existe en Lurín y en Talara y son dos puestos
 *      distintos;
 *   2. un puesto donde ya se hizo un plan no se borra. El plan firmado es la
 *      prueba de que ese trabajo se hizo ahi (docs/UI.md §6).
 */
class WorkstationCrudTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'workstations';
    }

    private function sede(string $nombre = 'Lurín'): WorkLocation
    {
        return WorkLocation::firstOrCreate(['name' => $nombre], $this->base());
    }

    private function puesto(WorkLocation $sede, string $nombre = 'Celda 1'): Workstation
    {
        return Workstation::create([
            'slug' => Str::random(22), 'work_location_id' => $sede->id,
            'name' => $nombre, 'tenant_id' => 1, 'created_by' => 1,
        ]);
    }

    /** Un plan hecho en ese puesto: es lo que impide borrarlo. */
    private function planEn(Workstation $puesto): WorkPlan
    {
        $empresa = Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
        $tipo = WorkType::firstOrCreate(['code' => 'MTTO'], $this->base());

        return WorkPlan::create($this->base() + [
            'company_id'       => $empresa->id,
            'work_type_id'     => $tipo->id,
            'work_location_id' => $puesto->work_location_id,
            'workstation_id'   => $puesto->id,
            'user_id'          => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'code'             => 'PE26-0808-0001',
            'description'      => 'Mantenimiento',
            'date_start'       => '2026-08-08',
        ]);
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->puesto($this->sede());
    }

    // ── Alta ─────────────────────────────────────────────────────────────────

    public function test_un_puesto_nuevo_cuelga_de_la_sede_que_se_elige(): void
    {
        $this->actingAs($this->admin());
        $sede = $this->sede();

        $this->post(route('business_management.workstations.store'), [
            'work_location_id' => $sede->id, 'name' => 'Tablero principal',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('workstations', [
            'name' => 'Tablero principal', 'work_location_id' => $sede->id,
        ]);
    }

    /** Sin sede no hay puesto: la tabla lo exige y el formulario tambien. */
    public function test_un_puesto_sin_sede_no_se_guarda(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.workstations.store'), [
            'name' => 'Huérfano',
        ])->assertSessionHasErrors('work_location_id');

        $this->assertDatabaseMissing('workstations', ['name' => 'Huérfano']);
    }

    /**
     * El nombre es unico DENTRO de la sede, no en toda la tabla. «Celda 1» hay
     * en casi todas las sedes: exigir que fuera unico global obligaria a
     * inventarse nombres que en obra nadie usa.
     */
    public function test_el_mismo_nombre_vale_en_dos_sedes_distintas_pero_no_dos_veces_en_la_misma(): void
    {
        $this->actingAs($this->admin());
        $lurin  = $this->sede('Lurín');
        $talara = $this->sede('Talara');
        $this->puesto($lurin, 'Celda 1');

        $this->post(route('business_management.workstations.store'), [
            'work_location_id' => $talara->id, 'name' => 'Celda 1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post(route('business_management.workstations.store'), [
            'work_location_id' => $lurin->id, 'name' => 'Celda 1',
        ])->assertSessionHasErrors('name');

        $this->assertSame(2, Workstation::where('name', 'Celda 1')->count());
    }

    // ── Edicion ──────────────────────────────────────────────────────────────

    public function test_se_le_cambia_el_nombre_desde_la_pantalla(): void
    {
        $this->actingAs($this->admin());
        $puesto = $this->puesto($this->sede());

        $this->put(route('business_management.workstations.update', $puesto->slug), [
            'work_location_id' => $puesto->work_location_id,
            'name' => 'Celda 1 (norte)', 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('workstations', ['id' => $puesto->id, 'name' => 'Celda 1 (norte)']);
    }

    // ── Borrado ──────────────────────────────────────────────────────────────

    public function test_un_puesto_sin_planes_se_borra_y_queda_en_papelera(): void
    {
        $this->actingAs($this->admin());
        $puesto = $this->puesto($this->sede());

        $this->delete(route('business_management.workstations.deleteSave', $puesto->slug), [
            'deleted_description' => 'se desmontó',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSoftDeleted('workstations', ['id' => $puesto->id]);
    }

    /** El plan firmado es la prueba de que ese trabajo se hizo ahi. */
    public function test_un_puesto_donde_ya_se_hizo_un_plan_no_se_puede_borrar(): void
    {
        $this->actingAs($this->admin());
        $puesto = $this->puesto($this->sede());
        $this->planEn($puesto);

        $this->delete(route('business_management.workstations.deleteSave', $puesto->slug), [
            'deleted_description' => 'ya no se usa',
        ])->assertSessionHas('error');

        $this->assertNotSoftDeleted('workstations', ['id' => $puesto->id]);
    }

    public function test_a_cambio_se_puede_desactivar_y_el_plan_sigue_en_pie(): void
    {
        $this->actingAs($this->admin());
        $puesto = $this->puesto($this->sede());
        $plan   = $this->planEn($puesto);

        $this->post(route('business_management.workstations.deactivate', $puesto->slug))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('workstations', ['id' => $puesto->id, 'is_active' => false]);
        $this->assertDatabaseHas('work_plans', ['id' => $plan->id, 'workstation_id' => $puesto->id]);
    }

    // ── Listado ──────────────────────────────────────────────────────────────

    /**
     * El listado enseña la sede de cada puesto. Sin ella, 16 filas llamadas
     * «Celda 1» y «Tablero» son indistinguibles.
     */
    public function test_el_listado_enseña_la_sede_de_cada_puesto(): void
    {
        $this->actingAs($this->admin());
        $this->puesto($this->sede('Lurín'), 'Celda 1');

        $this->get(route('business_management.workstations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Workstations/Index')
                ->where('workstations.data.0.name', 'Celda 1')
                ->where('workstations.data.0.work_location.name', 'Lurín'));
    }

    /**
     * El listado manda `locked_at`, que es lo que la pantalla mira para dejar
     * la casilla de seleccion en gris. Sin esa columna en el payload, los
     * dieciseis puestos que trajo la migracion se dejan marcar y el borrado
     * masivo devuelve «N saltados (bloqueados)» despues de pedir el motivo.
     */
    public function test_el_listado_dice_cuales_estan_bloqueados(): void
    {
        $puesto = $this->puesto($this->sede());
        $puesto->lock($this->makeSuper());

        $this->actingAs($this->admin())
            ->get(route('business_management.workstations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workstations.data.0.locked_at', fn ($v) => $v !== null));
    }

    // ── Ficha ────────────────────────────────────────────────────────────────

    /**
     * Un plan a medias esta «en curso» y uno cerrado «terminado». La ficha
     * mandaba un booleano `is_active` que salia al reves de docs/UI.md §5: el
     * plan TERMINADO se pintaba gris «Inactivo» y el que seguia abierto, verde
     * «Activo».
     */
    public function test_la_ficha_dice_si_el_plan_esta_en_curso_o_terminado(): void
    {
        $this->actingAs($this->admin());
        $puesto = $this->puesto($this->sede());
        $plan   = $this->planEn($puesto);

        $this->get(route('business_management.workstations.show', $puesto->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('usages.0.state', 'in_progress'));

        $plan->update(['is_done' => true]);

        $this->get(route('business_management.workstations.show', $puesto->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('usages.0.state', 'done'));
    }

    /**
     * Si la sede se desactiva —que es justo lo que este modulo ofrece como
     * alternativa al borrado— el formulario de edicion del puesto tiene que
     * seguir ofreciendola. Si no, el selector no encuentra su valor y pinta el
     * numero del id donde deberia decir «Lurín».
     */
    public function test_el_formulario_sigue_ofreciendo_la_sede_aunque_se_desactive(): void
    {
        $this->actingAs($this->admin());
        $sede   = $this->sede();
        $puesto = $this->puesto($sede);
        $sede->update(['is_active' => false]);

        $this->get(route('business_management.workstations.edit', $puesto->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workLocationOptions', fn ($o) => collect($o)->contains('value', $sede->id)));

        // Y guardar sin tocar la sede sigue funcionando: la validacion no puede
        // exigir que este activa o el puesto quedaria inmodificable.
        $this->put(route('business_management.workstations.update', $puesto->slug), [
            'work_location_id' => $sede->id,
            'name' => 'Celda 1 (norte)', 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_sin_permiso_de_crear_no_se_crea(): void
    {
        $sede = $this->sede();
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->get(route('business_management.workstations.create')));
        $this->assertProhibido($this->post(route('business_management.workstations.store'), [
            'work_location_id' => $sede->id, 'name' => 'Colado',
        ]));

        $this->assertDatabaseMissing('workstations', ['name' => 'Colado']);
    }

    public function test_sin_permiso_de_borrar_no_se_borra(): void
    {
        $puesto = $this->puesto($this->sede());
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->delete(route('business_management.workstations.deleteSave', $puesto->slug), [
            'deleted_description' => 'porque sí',
        ]));

        $this->assertNotSoftDeleted('workstations', ['id' => $puesto->id]);
    }

    public function test_sin_permiso_de_ver_no_se_entra_al_listado(): void
    {
        $this->assertProhibido(
            $this->actingAs($this->sinPermisos())->get(route('business_management.workstations.index'))
        );
    }

    public function test_la_papelera_es_solo_del_super(): void
    {
        $this->assertProhibido(
            $this->actingAs($this->admin())->get(route('business_management.workstations.trash'))
        );
    }
}
