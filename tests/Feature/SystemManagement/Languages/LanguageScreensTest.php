<?php

namespace Tests\Feature\SystemManagement\Languages;

use App\Models\Language;

/** Las pantallas de Idiomas abren. Ver RegionScreensTest para el por que. */
class LanguageScreensTest extends LanguageTestCase
{
    public function test_todas_las_pantallas_abren(): void
    {
        $this->actingAsSuperAdmin();
        $idioma = Language::factory()->create();

        foreach (['index', 'create', 'trash', 'edit_all'] as $pantalla) {
            $this->get(route("system_management.languages.{$pantalla}"))->assertOk();
        }

        foreach (['show', 'edit', 'delete'] as $pantalla) {
            $this->get(route("system_management.languages.{$pantalla}", $idioma->slug))->assertOk();
        }
    }
}
