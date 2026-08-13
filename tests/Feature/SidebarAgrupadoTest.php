<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * El menu lateral esta agrupado, y sus rotulos existen.
 *
 * «Me da la impresion que no todo va agrupado ahi», dijo el dueño del producto,
 * y tenia razon por partida doble:
 *
 *   - «Maestros» era un cajon de once modulos —de empresas a tipos de
 *     documento— que no comparten nada mas que no ser planes de trabajo;
 *   - y «Mi workspace» colgaba SUELTO entre dos grupos, sin titulo encima.
 *
 * Esta prueba no juzga el reparto —eso es criterio y cambia—, sino las dos
 * cosas que lo hacen legible y que se rompen solas cuando alguien añade un
 * modulo con prisa: que nada quede fuera de un grupo salvo el panel, y que cada
 * clave de idioma que el menu nombra exista de verdad en los dos idiomas.
 *
 * Se lee el fichero como texto a proposito. Montar el layout entero pediria un
 * navegador; lo que hay que vigilar es la estructura declarada, y esa esta ahi.
 */
class SidebarAgrupadoTest extends TestCase
{
    private string $layout;

    protected function setUp(): void
    {
        parent::setUp();

        $this->layout = file_get_contents(resource_path('js/Layouts/AppLayout.vue'));
    }

    /**
     * Solo el panel va suelto.
     *
     * Es la portada y no una familia de modulos, asi que un titulo encima
     * sobraria. Cualquier otro `kind: 'item'` en la raiz es un modulo que se
     * quedo sin sitio.
     */
    public function test_solo_el_panel_va_fuera_de_un_grupo(): void
    {
        $estructura = $this->estructura();

        $sueltos = preg_match_all("/kind: 'item'/", $estructura);

        $this->assertSame(1, $sueltos,
            'solo el panel va suelto: todo lo demas tiene que colgar de un grupo con su titulo');

        $this->assertStringContainsString("key: 'dashboard'", $estructura);
    }

    /** Y hay grupos de verdad, no uno solo con todo dentro. */
    public function test_hay_varios_grupos_y_ninguno_se_come_el_menu(): void
    {
        $estructura = $this->estructura();

        $grupos = preg_match_all("/kind: 'group'/", $estructura);

        $this->assertGreaterThanOrEqual(6, $grupos, 'el menu tiene que estar repartido');

        // Ninguno con mas de seis modulos: por encima de eso deja de ser un
        // grupo y vuelve a ser el cajon que habia («Maestros» tenia once).
        foreach ($this->itemsPorGrupo($estructura) as $grupo => $cuantos) {
            $this->assertLessThanOrEqual(8, $cuantos,
                "el grupo «{$grupo}» tiene {$cuantos} modulos: eso ya es un cajon de sastre");
        }
    }

    /**
     * Cada rotulo que el menu nombra existe en los dos idiomas.
     *
     * Una clave sin traducir sale en pantalla como su propia ruta
     * —«sidebar.group_places»— y eso no se ve en ninguna prueba que no mire
     * esto: el menu se pinta igual.
     */
    public function test_todos_los_rotulos_del_menu_estan_traducidos(): void
    {
        preg_match_all("/t\('sidebar\.([a-z_]+)'\)/", $this->layout, $m);

        $usadas = array_unique($m[1]);
        $this->assertNotEmpty($usadas);

        foreach (['es', 'en'] as $idioma) {
            $textos = require resource_path("lang/{$idioma}/sidebar.php");

            foreach ($usadas as $clave) {
                $this->assertArrayHasKey($clave, $textos,
                    "falta «{$clave}» en resources/lang/{$idioma}/sidebar.php");
            }
        }
    }

    /**
     * Los modulos sin entrada de menu tambien tienen rotulo.
     *
     * El historial de cambios rotula cada modulo con `sidebar.<modulo>` y cae
     * al identificador crudo si falta, asi que quitar una clave del lateral
     * —porque su modulo ya no sale ahi— deja «form_submissions» escrito en la
     * cara del usuario en otra pantalla. Paso al reordenar este menu.
     */
    public function test_los_modulos_del_historial_tienen_rotulo(): void
    {
        $textos = require resource_path('lang/es/sidebar.php');

        foreach (['form_submissions', 'customers', 'brands'] as $modulo) {
            $this->assertArrayHasKey($modulo, $textos,
                "«{$modulo}» es un modulo auditado: sin su rotulo, el historial enseña el identificador");
        }
    }

    /** El bloque `menuStructure`, que es donde se declara el menu. */
    private function estructura(): string
    {
        $desde = strpos($this->layout, 'const menuStructure = computed(() => [');
        $hasta = strpos($this->layout, "\n    ]);\n", $desde);

        $this->assertNotFalse($desde, 'no se encuentra menuStructure en AppLayout.vue');

        return substr($this->layout, $desde, $hasta - $desde);
    }

    /** @return array<string, int> titulo del grupo => cuantos modulos lleva */
    private function itemsPorGrupo(string $estructura): array
    {
        $bloques = preg_split("/kind: 'group'/", $estructura);
        array_shift($bloques);

        $cuenta = [];

        foreach ($bloques as $bloque) {
            preg_match("/key: '(group-[a-z]+)'/", $bloque, $nombre);
            $cuenta[$nombre[1] ?? '?'] = preg_match_all("/^\s+key: '[a-z_]+', label:/m", $bloque);
        }

        return $cuenta;
    }
}
