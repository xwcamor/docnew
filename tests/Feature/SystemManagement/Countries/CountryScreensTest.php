<?php

namespace Tests\Feature\SystemManagement\Countries;

use App\Models\Country;
use App\Models\User;

/**
 * Lo que se rompio de verdad en Paises, con la prueba que lo pilla.
 *
 * Las tres cosas que se comprueban aqui pasaban desapercibidas porque ninguna
 * da error al ocurrir: la pantalla abre, el guardado responde 302 y la fila
 * queda escrita. El fallo aparece despues y en otro sitio.
 */
class CountryScreensTest extends CountryTestCase
{
    // ─── Las pantallas abren ───────────────────────────────────────────────

    /**
     * Las siete pantallas del modulo devuelven 200.
     *
     * Es la prueba barata que encuentra los fallos caros: una ficha que lee una
     * columna inexistente o un formulario al que le falta un catalogo revientan
     * aqui y no en produccion.
     */
    public function test_todas_las_pantallas_abren(): void
    {
        $this->actingAsSuperAdmin();
        $pais = Country::factory()->create();

        foreach (['index', 'create', 'trash', 'edit_all'] as $pantalla) {
            $this->get(route("system_management.countries.{$pantalla}"))
                ->assertOk();
        }

        foreach (['show', 'edit', 'delete'] as $pantalla) {
            $this->get(route("system_management.countries.{$pantalla}", $pais->slug))
                ->assertOk();
        }
    }

    // ─── Zona horaria en «Editar todo» ─────────────────────────────────────

    /**
     * «Editar todo» no traga una zona horaria que no existe.
     *
     * La zona era una caja de texto libre sin validacion: `Nowhere/Nothing` se
     * guardaba sin una queja. El destrozo llega despues — App\Support\Tz resuelve
     * la zona de quien no tiene una propia por la de su pais, y
     * Carbon::setTimezone con una zona inexistente lanza
     * InvalidTimeZoneException: un 500 en cada pantalla que pinte una fecha.
     */
    public function test_editar_todo_rechaza_una_zona_horaria_que_no_existe(): void
    {
        $this->actingAsSuperAdmin();
        $pais = Country::factory()->create(['timezone' => 'America/Lima']);

        $this->post(route('system_management.countries.edit_all.update'), [
            'changes' => [['id' => $pais->id, 'timezone' => 'Nowhere/Nothing']],
        ])->assertSessionHasErrors('changes.0.timezone');

        $this->assertSame('America/Lima', $pais->fresh()->timezone,
            'la zona invalida no debe llegar a la base');
    }

    /** Y una zona IANA de verdad si se guarda. */
    public function test_editar_todo_guarda_una_zona_horaria_valida(): void
    {
        $this->actingAsSuperAdmin();
        $pais = Country::factory()->create(['timezone' => 'UTC']);

        $this->post(route('system_management.countries.edit_all.update'), [
            'changes' => [['id' => $pais->id, 'timezone' => 'America/Lima']],
        ])->assertRedirect();

        $this->assertSame('America/Lima', $pais->fresh()->timezone);
    }

    // ─── Eliminar: dependientes ────────────────────────────────────────────

    /**
     * La pantalla de eliminar dice cuantos registros cuelgan del pais, y con
     * que etiqueta.
     *
     * La etiqueta se pintaba tal cual venia del modelo, en ingles: «3 users
     * estan vinculados a este registro». Ahora sale traducida.
     */
    public function test_la_pantalla_de_eliminar_cuenta_los_dependientes_con_etiqueta_traducida(): void
    {
        $this->actingAsSuperAdmin();
        $pais = Country::factory()->create();

        User::factory()->count(2)->create([
            'tenant_id'  => 1,
            'country_id' => $pais->id,
            'locale_id'  => 1,
        ]);

        $this->get(route('system_management.countries.delete', $pais->slug))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Countries/Delete')
                ->where('dependents.users.count', 2)
                ->where('dependents.users.block', true)
                // Traducida: la clave cruda no se pinta nunca.
                ->where('dependents.users.label', __('users.records'))
            );
    }

    /**
     * Un pais del que cuelgan usuarios no se borra, ni por la puerta de atras.
     *
     * La pantalla ya deshabilita el boton; esto comprueba la red del servidor.
     */
    public function test_no_se_elimina_un_pais_con_usuarios_detras(): void
    {
        $this->actingAsSuperAdmin();
        $pais = Country::factory()->create();

        User::factory()->create([
            'tenant_id'  => 1,
            'country_id' => $pais->id,
            'locale_id'  => 1,
        ]);

        $this->delete(route('system_management.countries.deleteSave', $pais->slug), [
            'deleted_description' => 'Prueba de borrado bloqueado',
        ]);

        $this->assertNull($pais->fresh()->deleted_at, 'el pais no debe quedar eliminado');
    }

    /**
     * Los planes, los formatos, las personas y las empresas tambien cuelgan del
     * pais y tambien bloquean.
     *
     * Solo figuraba `users`, y la pantalla decia «sin datos relacionados» para un
     * pais del que cuelga toda la operacion. Todas esas tablas llevan
     * `country_id ... restrictOnDelete`: el borrado definitivo reventaba con un
     * 23503, y el soft-delete dejaba los planes sin pais — con lo que el PDF del
     * formato firmado pierde su idioma (FormSubmissionPdfService).
     */
    public function test_el_pais_declara_todo_lo_que_cuelga_de_el(): void
    {
        $dependientes = (new Country)->dependents();

        foreach (['users', 'work_plans', 'form_templates', 'people', 'companies'] as $clave) {
            $this->assertArrayHasKey($clave, $dependientes);
            $this->assertTrue($dependientes[$clave]['block'], "{$clave} deberia bloquear el borrado");
            // La etiqueta pasa por el traductor: en ingles coincide con la clave,
            // en castellano no («usuarios», «planes de trabajo»).
            $this->assertSame(__("{$clave}.records"), $dependientes[$clave]['label'],
                "la etiqueta de {$clave} esta sin traducir");
        }
    }

    // ─── Alta y edicion, contra la base ────────────────────────────────────

    /** El alta escribe TODAS las columnas obligatorias de la tabla. */
    public function test_el_alta_guarda_todas_las_columnas_obligatorias(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(
            route('system_management.countries.store'),
            $this->validCountryData([
                'name'     => 'Ecuador',
                'currency' => 'USD',
                'timezone' => 'America/Guayaquil',
            ]),
        )->assertRedirect();

        $this->assertDatabaseHas('countries', [
            'name'              => 'Ecuador',
            'currency'          => 'USD',
            'timezone'          => 'America/Guayaquil',
            'region_id'         => 1,
            'default_locale_id' => 1,
            'is_active'         => true,
        ]);
    }

    /**
     * El idioma por defecto se puede cambiar y queda guardado.
     *
     * No es un adorno: de esta columna sale el idioma del PDF de un formato
     * firmado en este pais.
     */
    public function test_se_puede_cambiar_el_idioma_por_defecto(): void
    {
        $this->actingAsSuperAdmin();
        $pais = Country::factory()->create(['default_locale_id' => 1]);

        $otro = \Illuminate\Support\Facades\DB::table('locales')->insertGetId([
            'slug'        => \Illuminate\Support\Str::random(22),
            'code'        => 'en_US',
            'name'        => 'English (United States)',
            'language_id' => 1,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->put(
            route('system_management.countries.update', $pais->slug),
            $this->validCountryData([
                'name'              => $pais->name,
                'iso_code'          => $pais->iso_code,
                'default_locale_id' => $otro,
            ]),
        )->assertRedirect();

        $this->assertSame($otro, $pais->fresh()->default_locale_id);
    }
}
