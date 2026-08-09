<?php

namespace Tests\Feature\SystemManagement\Locales;

use App\Models\Locale;

/** Las pantallas de Locales abren. Ver RegionScreensTest para el por que. */
class LocaleScreensTest extends LocaleTestCase
{
    public function test_todas_las_pantallas_abren(): void
    {
        $this->actingAsSuperAdmin();
        $locale = Locale::factory()->create(['language_id' => 1]);

        foreach (['index', 'create', 'trash', 'edit_all'] as $pantalla) {
            $this->get(route("system_management.locales.{$pantalla}"))->assertOk();
        }

        foreach (['show', 'edit', 'delete'] as $pantalla) {
            $this->get(route("system_management.locales.{$pantalla}", $locale->slug))->assertOk();
        }
    }

    /**
     * Un locale que ya usa un pais no se puede eliminar: la pantalla lo dice y
     * el servidor lo impide. Si se pudiera, el pais se quedaria sin idioma y el
     * PDF del formato firmado saldria en el idioma de quien lo descargue.
     */
    public function test_no_se_elimina_un_locale_que_usa_un_pais(): void
    {
        $this->actingAsSuperAdmin();
        $locale = Locale::factory()->create(['language_id' => 1]);

        \Illuminate\Support\Facades\DB::table('countries')->insert([
            'slug' => \Illuminate\Support\Str::random(22),
            'region_id' => 999, 'default_locale_id' => $locale->id,
            'name' => 'Pais de prueba', 'iso_code' => 'QQ',
            'currency' => 'XXX', 'timezone' => 'UTC', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get(route('system_management.locales.delete', $locale->slug))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Locales/Delete')
                ->where('dependents.countries.count', 1)
                ->where('dependents.countries.block', true)
                ->where('dependents.countries.label', __('countries.records'))
            );

        $this->delete(route('system_management.locales.deleteSave', $locale->slug), [
            'deleted_description' => 'Prueba de borrado bloqueado',
        ]);

        $this->assertNull($locale->fresh()->deleted_at);
    }
}
