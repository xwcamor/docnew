<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\Person;
use App\Models\PersonCompanyLink;
use App\Models\Position;

/**
 * Los cargos: que hace cada persona en obra.
 *
 * Este catalogo tuvo una marca de «puede firmar aprobaciones» y aqui se
 * comprobaba con detalle: que naciera marcada, que se pudiera quitar desde la
 * edicion, que el listado ordenara por ella. Nada de eso decidia nada —ninguna
 * parte del sistema leia la columna— y encima estaba en el sitio equivocado:
 * quien aprueba lo dicen los roles de la persona, no su cargo. La columna se
 * borro y con ella esos tests, porque ya no habia nada que comprobar.
 *
 * Lo que queda es lo que un catalogo si tiene que garantizar: que el nombre no
 * se repita dentro de lo que quien lo escribe puede ver, que un cargo que
 * alguien tiene no se pueda borrar, y que el candado de la v1 llegue al
 * listado.
 */
class PositionCrudTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'positions';
    }

    private function cargo(string $nombre = 'Técnico'): Position
    {
        return Position::create($this->base() + ['code' => $nombre]);
    }

    /** Una persona vinculada a una empresa con ese cargo: impide borrarlo. */
    private function personaCon(Position $cargo): PersonCompanyLink
    {
        $empresa = Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
        $persona = Person::create($this->base() + [
            'doc_type' => 'DNI', 'num_doc' => '44556677',
            'name' => 'Juan', 'lastname' => 'Pérez', 'is_active' => true,
        ]);

        return PersonCompanyLink::create([
            'person_id' => $persona->id, 'company_id' => $empresa->id,
            'position_id' => $cargo->id, 'is_active' => true,
        ]);
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->cargo();
    }

    // ── Alta ─────────────────────────────────────────────────────────────────

    public function test_un_cargo_nuevo_se_da_de_alta_desde_la_pantalla(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.positions.store'), [
            'country_id' => 1, 'code' => 'Mecánico',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('positions', ['code' => 'Mecánico']);
    }

    /**
     * Un interruptor apagado no viaja como `false`, viaja ausente, y con
     * `sometimes` eso conserva el valor anterior: lo que se acaba de desmarcar
     * sigue encendido despues de guardar. Aqui solo queda el de estado, asi que
     * es el que lo comprueba: un cargo que se desactiva desde el formulario
     * tiene que quedar desactivado.
     */
    public function test_un_cargo_se_desactiva_desde_la_edicion(): void
    {
        $this->actingAs($this->admin());
        $cargo = $this->cargo('Ayudante');

        $this->put(route('business_management.positions.update', $cargo->slug), [
            'country_id' => 1, 'code' => 'Ayudante',
            // Sin `is_active`: apagado viaja ausente.
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('positions', ['id' => $cargo->id, 'is_active' => false]);
    }

    public function test_no_puede_haber_dos_cargos_con_el_mismo_nombre_en_un_pais(): void
    {
        $this->actingAs($this->admin());
        $this->cargo('Técnico');

        $this->post(route('business_management.positions.store'), [
            'country_id' => 1, 'code' => 'Técnico',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, Position::where('code', 'Técnico')->count());
    }

    /**
     * Ni escrito de otra manera: en el desplegable del plan «TECNICO» y
     * «Tecnico» se leen igual, y quien elige no sabe cual de los dos tocar.
     *
     * Aqui se comprueba solo la caja porque la prueba corre en SQLite y su
     * `LOWER()` no toca las tildes. En Postgres, que es donde vive esto, la
     * comparacion va con `unaccent()` y «Técnico» tambien choca.
     */
    public function test_tampoco_cambiando_mayusculas(): void
    {
        $this->actingAs($this->admin());
        $this->cargo('Tecnico');

        $this->post(route('business_management.positions.store'), [
            'country_id' => 1, 'code' => 'TECNICO',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, Position::withoutGlobalScopes()->where('country_id', 1)->count());
    }

    /**
     * Pero el cargo de OTRA empresa no estorba.
     *
     * `Rule::unique` consulta la tabla cruda, sin el scope del catalogo, asi que
     * un cargo privado de otro workspace bloqueaba el alta con un «ya existe»
     * que señalaba una fila invisible: no salia en el listado, no se podia
     * abrir y no se podia renombrar. Sin salida.
     */
    public function test_el_cargo_de_otra_empresa_no_impide_crear_el_mismo_nombre(): void
    {
        \Illuminate\Support\Facades\DB::table('tenants')->insertOrIgnore([[
            'id' => 2, 'slug' => \Illuminate\Support\Str::random(22), 'name' => 'Empresa 2',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        \Illuminate\Support\Facades\DB::table('positions')->insert([
            'slug' => \Illuminate\Support\Str::random(22), 'country_id' => 1, 'code' => 'Soldador',
            'is_active' => true, 'tenant_id' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin());

        $this->post(route('business_management.positions.store'), [
            'country_id' => 1, 'code' => 'Soldador',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('positions', ['code' => 'Soldador', 'tenant_id' => 1]);
    }

    /** El global de la plataforma si: ese se ve en el mismo selector. */
    public function test_el_cargo_global_de_la_plataforma_si_impide_repetirlo(): void
    {
        \Illuminate\Support\Facades\DB::table('positions')->insert([
            'slug' => \Illuminate\Support\Str::random(22), 'country_id' => 1, 'code' => 'Supervisor',
            'is_active' => true, 'tenant_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin());

        $this->post(route('business_management.positions.store'), [
            'country_id' => 1, 'code' => 'Supervisor',
        ])->assertSessionHasErrors('code');
    }

    /** Y renombrarse a si mismo no es repetirse. */
    public function test_guardar_un_cargo_sin_cambiarle_el_nombre_no_da_error(): void
    {
        $this->actingAs($this->admin());
        $cargo = $this->cargo('Técnico');

        $this->put(route('business_management.positions.update', $cargo->slug), [
            'country_id' => 1, 'code' => 'Técnico', 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    // ── Borrado ──────────────────────────────────────────────────────────────

    public function test_un_cargo_que_no_tiene_nadie_se_borra_y_queda_en_papelera(): void
    {
        $this->actingAs($this->admin());
        $cargo = $this->cargo('Ayudante');

        $this->delete(route('business_management.positions.deleteSave', $cargo->slug), [
            'deleted_description' => 'ya no existe ese puesto',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSoftDeleted('positions', ['id' => $cargo->id]);
    }

    public function test_un_cargo_que_alguien_tiene_no_se_puede_borrar(): void
    {
        $this->actingAs($this->admin());
        $cargo = $this->cargo();
        $this->personaCon($cargo);

        $this->delete(route('business_management.positions.deleteSave', $cargo->slug), [
            'deleted_description' => 'limpieza',
        ])->assertSessionHas('error');

        $this->assertNotSoftDeleted('positions', ['id' => $cargo->id]);
    }

    public function test_a_cambio_se_puede_desactivar_y_quien_lo_tiene_lo_conserva(): void
    {
        $this->actingAs($this->admin());
        $cargo   = $this->cargo();
        $vinculo = $this->personaCon($cargo);

        $this->post(route('business_management.positions.deactivate', $cargo->slug))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('positions', ['id' => $cargo->id, 'is_active' => false]);
        $this->assertDatabaseHas('person_company_links', ['id' => $vinculo->id, 'position_id' => $cargo->id]);
    }

    // ── Listado ──────────────────────────────────────────────────────────────

    public function test_el_listado_dice_cuanta_gente_tiene_cada_cargo(): void
    {
        $this->actingAs($this->admin());
        $cargo = $this->cargo('Supervisor');
        $this->personaCon($cargo);

        $this->get(route('business_management.positions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Positions/Index')
                ->where('positions.data.0.code', 'Supervisor')
                ->where('positions.data.0.usage_count', 1));
    }

    /**
     * Un orden que ya no existe no puede tumbar el listado.
     *
     * Aqui se comprobaba que se pudiera ordenar por «quien firma». Esa columna
     * se borro, pero las vistas guardadas de quien ya usaba el catalogo siguen
     * pidiendola en la URL: si el orden llegara crudo a la consulta, el listado
     * reventaria con un error de columna inexistente. La lista blanca de
     * `PositionService` lo descarta y la tabla sale en su orden de siempre, que
     * es alfabetico.
     */
    public function test_pedir_un_orden_por_una_columna_que_ya_no_existe_no_rompe_el_listado(): void
    {
        $this->actingAs($this->admin());
        $this->cargo('Ayudante');
        $this->cargo('Supervisor');

        $this->get(route('business_management.positions.index', [
            'sort' => 'is_signature_approver', 'direction' => 'desc',
        ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('positions.data.0.code', 'Ayudante')
                ->where('positions.data.1.code', 'Supervisor'));
    }

    /**
     * El listado tiene que traer el candado.
     *
     * `is_locked` es un accesor y no viaja en el JSON; lo que viaja es
     * `locked_at`, y es de lo que depende que la casilla de la fila salga
     * deshabilitada. Casi todos los cargos vienen de la v1 y nacen bloqueados:
     * sin este dato la lista entera se deja marcar y la masiva contesta «N
     * saltados (bloqueados)».
     */
    public function test_el_listado_trae_el_candado_de_cada_fila(): void
    {
        $cargo = $this->cargo('Supervisor');
        $cargo->lock($this->makeSuper());

        $this->actingAs($this->admin());

        $this->get(route('business_management.positions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->whereNot('positions.data.0.locked_at', null));
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_sin_permiso_de_crear_no_se_crea(): void
    {
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->get(route('business_management.positions.create')));
        $this->assertProhibido($this->post(route('business_management.positions.store'), [
            'country_id' => 1, 'code' => 'Colado',
        ]));

        $this->assertDatabaseMissing('positions', ['code' => 'Colado']);
    }

    public function test_sin_permiso_de_borrar_no_se_borra(): void
    {
        $cargo = $this->cargo();
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->delete(route('business_management.positions.deleteSave', $cargo->slug), [
            'deleted_description' => 'porque sí',
        ]));

        $this->assertNotSoftDeleted('positions', ['id' => $cargo->id]);
    }

    public function test_sin_permiso_de_ver_no_se_entra_al_listado(): void
    {
        $this->assertProhibido(
            $this->actingAs($this->sinPermisos())->get(route('business_management.positions.index'))
        );
    }

    public function test_la_papelera_es_solo_del_super(): void
    {
        $this->assertProhibido(
            $this->actingAs($this->admin())->get(route('business_management.positions.trash'))
        );
    }
}
