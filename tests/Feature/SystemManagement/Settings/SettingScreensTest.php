<?php

namespace Tests\Feature\SystemManagement\Settings;

use App\Models\Setting;

/**
 * Que las pantallas abran, y que el editor de ajustes no deje guardar un valor
 * que rompa lo que el ajuste controla.
 *
 * Los ajustes de este módulo NO son decorativos: `docufiz.num_doc_minimum` en 0
 * convierte el buscador de personas en un volcado del padrón, y un `int` con
 * texto dentro se lee como 0 en todo el sistema. Antes la pantalla aceptaba las
 * dos cosas sin rechistar.
 */
class SettingScreensTest extends SettingTestCase
{
    private function crear(array $overrides = []): Setting
    {
        return Setting::create($this->validSettingData($overrides));
    }

    public function test_all_screens_open(): void
    {
        $this->actingAsSuperAdmin();
        $setting = $this->crear();

        foreach (['index', 'create', 'trash', 'edit_all'] as $pantalla) {
            $this->get(route("system_management.settings.{$pantalla}"))
                ->assertOk();
        }

        foreach (['show', 'edit', 'delete'] as $pantalla) {
            $this->get(route("system_management.settings.{$pantalla}", $setting->slug))
                ->assertOk();
        }
    }

    public function test_create_and_edit_persist_the_row(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('system_management.settings.store'), $this->validSettingData([
            'key'   => 'test.alta_real',
            'name'  => 'Alta real',
            'type'  => 'int',
            'value' => '12',
        ]))->assertRedirect();

        $fila = Setting::where('key', 'test.alta_real')->first();
        $this->assertNotNull($fila);
        $this->assertSame('12', $fila->value);
        $this->assertSame(12, Setting::getInt('test.alta_real'));

        $this->put(route('system_management.settings.update', $fila->slug), [
            'key' => 'test.alta_real', 'name' => 'Alta real', 'type' => 'int', 'value' => '30',
        ])->assertRedirect();

        $this->assertSame('30', $fila->fresh()->value);
    }

    /** Un ajuste declarado `int` no puede guardar texto: `getInt()` lo leería como 0. */
    public function test_int_setting_rejects_text(): void
    {
        $this->actingAsSuperAdmin();
        $fila = $this->crear(['key' => 'test.entero', 'type' => 'int', 'value' => '5']);

        $this->from(route('system_management.settings.edit', $fila->slug))
            ->put(route('system_management.settings.update', $fila->slug), [
                'key' => 'test.entero', 'name' => $fila->name, 'type' => 'int', 'value' => 'no sé',
            ])
            ->assertSessionHasErrors('value');

        $this->assertSame('5', $fila->fresh()->value);
    }

    public function test_json_setting_rejects_broken_json(): void
    {
        $this->actingAsSuperAdmin();
        $fila = $this->crear(['key' => 'test.estructura', 'type' => 'json', 'value' => '{"a":1}']);

        $this->put(route('system_management.settings.update', $fila->slug), [
            'key' => 'test.estructura', 'name' => $fila->name, 'type' => 'json', 'value' => '{a:1',
        ])->assertSessionHasErrors('value');
    }

    /**
     * El caso que abre la puerta: el mínimo de dígitos para buscar a una
     * persona. En 0 la búsqueda contesta con la caja vacía.
     */
    public function test_document_search_minimum_cannot_be_zero(): void
    {
        $this->actingAsSuperAdmin();
        $fila = $this->crear([
            'key' => 'docufiz.num_doc_minimum', 'name' => 'Mínimo de dígitos',
            'type' => 'int', 'value' => '7',
        ]);

        $this->put(route('system_management.settings.update', $fila->slug), [
            'key' => 'docufiz.num_doc_minimum', 'name' => $fila->name, 'type' => 'int', 'value' => '0',
        ])->assertSessionHasErrors('value');

        $this->assertSame('7', $fila->fresh()->value);

        // Dentro del margen sí entra.
        $this->put(route('system_management.settings.update', $fila->slug), [
            'key' => 'docufiz.num_doc_minimum', 'name' => $fila->name, 'type' => 'int', 'value' => '8',
        ])->assertSessionHasNoErrors();

        $this->assertSame('8', $fila->fresh()->value);
    }

    /** El umbral facial se guarda como texto pero es un número acotado. */
    public function test_face_threshold_stays_inside_its_range(): void
    {
        $this->actingAsSuperAdmin();
        $fila = $this->crear([
            'key' => 'docufiz.face_threshold', 'name' => 'Umbral facial',
            'type' => 'string', 'value' => '0.50',
        ]);

        $this->put(route('system_management.settings.update', $fila->slug), [
            'key' => 'docufiz.face_threshold', 'name' => $fila->name, 'type' => 'string', 'value' => '0.95',
        ])->assertSessionHasErrors('value');

        $this->assertSame('0.50', $fila->fresh()->value);
    }

    /**
     * Duplicar copiaba SOLO el nombre: la copia salía sin clave, sin valor y
     * con el tipo en `string`. Una fila que no configura nada.
     */
    public function test_duplicate_copies_the_whole_setting(): void
    {
        $this->actingAsSuperAdmin();
        $fila = $this->crear([
            'key' => 'test.duplicable', 'type' => 'int', 'value' => '42', 'group' => 'pruebas',
        ]);

        $this->post(route('system_management.settings.duplicate', $fila->slug))->assertRedirect();

        $copia = Setting::orderByDesc('id')->first();
        $this->assertNotSame($fila->id, $copia->id);
        $this->assertSame('int', $copia->type);
        $this->assertSame('42', $copia->value);
        $this->assertSame('pruebas', $copia->group);
        $this->assertNotEmpty($copia->key);
        $this->assertNotSame($fila->key, $copia->key);
        // Nace inactiva: dos ajustes activos diciendo lo mismo es justo lo que
        // no se quiere.
        $this->assertFalse((bool) $copia->is_active);
    }
}
