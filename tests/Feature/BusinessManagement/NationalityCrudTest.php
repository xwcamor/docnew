<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Nationality;
use App\Models\Person;

/**
 * Las nacionalidades: lo que se anota en la ficha de cada persona.
 *
 * La tabla arranca vacia, asi que la primera prueba de verdad es que se pueda
 * llenar. Y la primera que falla es la del texto: `code` no estaba en el
 * `$fillable` del modelo, con lo que Eloquent lo tiraba en silencio y la
 * nacionalidad se guardaba sin nombre — en el selector de la persona salia una
 * opcion en blanco.
 */
class NationalityCrudTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'nationalities';
    }

    private function nacionalidad(string $nombre = 'Peruana'): Nationality
    {
        return Nationality::create($this->base() + ['code' => $nombre]);
    }

    /** Una persona con esa nacionalidad: es lo que impide borrarla. */
    private function personaCon(Nationality $nacionalidad): Person
    {
        return Person::create($this->base() + [
            'nationality_id' => $nacionalidad->id,
            'doc_type' => 'DNI', 'num_doc' => '44556677',
            'name' => 'Juan', 'lastname' => 'Pérez', 'is_active' => true,
        ]);
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->nacionalidad();
    }

    // ── Alta ─────────────────────────────────────────────────────────────────

    public function test_una_nacionalidad_nueva_se_da_de_alta_desde_la_pantalla(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.nationalities.store'), [
            'country_id' => 1, 'code' => 'Peruana',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('nationalities', ['code' => 'Peruana', 'tenant_id' => 1]);
    }

    /**
     * El texto se guarda de verdad y no queda en blanco. Es la prueba del
     * `$fillable`: sin `code` en la lista, la fila se creaba vacia y el error
     * solo se veia al abrir el selector de la ficha de una persona.
     */
    public function test_el_nombre_de_la_nacionalidad_no_se_pierde_al_guardar(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.nationalities.store'), [
            'country_id' => 1, 'code' => 'Venezolana',
        ])->assertRedirect();

        $this->assertSame('Venezolana', Nationality::latest('id')->first()->code);
    }

    public function test_no_puede_haber_dos_nacionalidades_iguales_en_un_pais(): void
    {
        $this->actingAs($this->admin());
        $this->nacionalidad('Peruana');

        $this->post(route('business_management.nationalities.store'), [
            'country_id' => 1, 'code' => 'Peruana',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, Nationality::where('code', 'Peruana')->count());
    }

    // ── Edicion ──────────────────────────────────────────────────────────────

    public function test_se_le_cambia_el_nombre_desde_la_pantalla(): void
    {
        $this->actingAs($this->admin());
        $nacionalidad = $this->nacionalidad();

        $this->put(route('business_management.nationalities.update', $nacionalidad->slug), [
            'country_id' => 1, 'code' => 'Peruano/a', 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('nationalities', ['id' => $nacionalidad->id, 'code' => 'Peruano/a']);
    }

    // ── Borrado ──────────────────────────────────────────────────────────────

    public function test_una_nacionalidad_que_no_tiene_nadie_se_borra_y_queda_en_papelera(): void
    {
        $this->actingAs($this->admin());
        $nacionalidad = $this->nacionalidad('Boliviana');

        $this->delete(route('business_management.nationalities.deleteSave', $nacionalidad->slug), [
            'deleted_description' => 'se creó por error',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSoftDeleted('nationalities', ['id' => $nacionalidad->id]);
    }

    public function test_una_nacionalidad_que_alguien_tiene_no_se_puede_borrar(): void
    {
        $this->actingAs($this->admin());
        $nacionalidad = $this->nacionalidad();
        $this->personaCon($nacionalidad);

        $this->delete(route('business_management.nationalities.deleteSave', $nacionalidad->slug), [
            'deleted_description' => 'limpieza',
        ])->assertSessionHas('error');

        $this->assertNotSoftDeleted('nationalities', ['id' => $nacionalidad->id]);
    }

    public function test_a_cambio_se_puede_desactivar_y_la_ficha_de_la_persona_la_conserva(): void
    {
        $this->actingAs($this->admin());
        $nacionalidad = $this->nacionalidad();
        $persona      = $this->personaCon($nacionalidad);

        $this->post(route('business_management.nationalities.deactivate', $nacionalidad->slug))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('nationalities', ['id' => $nacionalidad->id, 'is_active' => false]);
        $this->assertDatabaseHas('people', ['id' => $persona->id, 'nationality_id' => $nacionalidad->id]);
    }

    // ── Listado ──────────────────────────────────────────────────────────────

    public function test_el_listado_dice_cuanta_gente_tiene_cada_nacionalidad(): void
    {
        $this->actingAs($this->admin());
        $nacionalidad = $this->nacionalidad();
        $this->personaCon($nacionalidad);

        $this->get(route('business_management.nationalities.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Nationalities/Index')
                ->where('nationalities.data.0.code', 'Peruana')
                ->where('nationalities.data.0.usage_count', 1));
    }

    /** La tabla arranca vacía: el listado tiene que abrir igual. */
    public function test_el_listado_abre_con_la_tabla_vacia(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('business_management.nationalities.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Nationalities/Index')
                ->where('nationalities.total', 0));
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_sin_permiso_de_crear_no_se_crea(): void
    {
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->get(route('business_management.nationalities.create')));
        $this->assertProhibido($this->post(route('business_management.nationalities.store'), [
            'country_id' => 1, 'code' => 'Colada',
        ]));

        $this->assertDatabaseMissing('nationalities', ['code' => 'Colada']);
    }

    public function test_sin_permiso_de_borrar_no_se_borra(): void
    {
        $nacionalidad = $this->nacionalidad();
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->delete(route('business_management.nationalities.deleteSave', $nacionalidad->slug), [
            'deleted_description' => 'porque sí',
        ]));

        $this->assertNotSoftDeleted('nationalities', ['id' => $nacionalidad->id]);
    }

    public function test_sin_permiso_de_ver_no_se_entra_al_listado(): void
    {
        $this->assertProhibido(
            $this->actingAs($this->sinPermisos())->get(route('business_management.nationalities.index'))
        );
    }

    public function test_la_papelera_es_solo_del_super(): void
    {
        $this->assertProhibido(
            $this->actingAs($this->admin())->get(route('business_management.nationalities.trash'))
        );
    }
}
