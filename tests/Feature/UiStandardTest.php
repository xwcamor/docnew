<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * El estandar de interfaz, comprobado.
 *
 * `docs/UI.md` dice como se construye una pantalla aqui. Un documento que nadie
 * verifica se incumple en tres semanas, asi que lo que se puede comprobar sin
 * abrir un navegador se comprueba aqui.
 *
 * Cada prueba viene de un fallo que ya paso una vez. Si una falla, no es que la
 * prueba sea quisquillosa: es que el fallo volvio.
 */
class UiStandardTest extends TestCase
{
    /**
     * Los dos idiomas tienen las mismas claves.
     *
     * Una clave que existe en `es` y no en `en` sale en pantalla como
     * `MODULO.CLAVE` en mayusculas, que es lo que ve el usuario cuando alguien
     * anade un texto y se olvida de la otra mitad.
     */
    public function test_los_dos_idiomas_tienen_las_mismas_claves(): void
    {
        $problemas = [];

        foreach (glob(lang_path('es/*.php')) as $rutaEs) {
            $archivo = basename($rutaEs);
            $rutaEn = lang_path("en/{$archivo}");

            if (! file_exists($rutaEn)) {
                $problemas[] = "{$archivo}: existe en es y no en en";

                continue;
            }

            $es = $this->claves(require $rutaEs);
            $en = $this->claves(require $rutaEn);

            foreach (['es' => array_diff($es, $en), 'en' => array_diff($en, $es)] as $donde => $sobran) {
                if ($sobran !== []) {
                    $otro = $donde === 'es' ? 'en' : 'es';
                    $problemas[] = sprintf('%s: %d clave(s) en %s que faltan en %s (%s)',
                        $archivo, count($sobran), $donde, $otro,
                        implode(', ', array_slice($sobran, 0, 5)));
                }
            }
        }

        $this->assertSame([], $problemas, "Traducciones descuadradas:\n" . implode("\n", $problemas));
    }

    /**
     * Cada namespace que usa el front esta en `loadTranslations()`.
     *
     * Se pueden tener las traducciones perfectas en los dos idiomas y que el
     * navegador no las reciba nunca: si el namespace no esta en esa lista, no
     * viaja. Paso con work_plans, people y companies a la vez.
     */
    public function test_los_namespaces_que_usa_el_front_se_le_envian(): void
    {
        $middleware = file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php'));

        preg_match('/\$namespaces = \[(.*?)\];/s', $middleware, $m);
        $this->assertNotEmpty($m, 'no se encontro la lista $namespaces en HandleInertiaRequests');

        preg_match_all("/'([a-z_]+)'/", $m[1], $enviados);
        $enviados = $enviados[1];

        // Los que el front pide de verdad, leidos de las paginas y componentes.
        $usados = [];

        foreach ($this->archivosVue() as $archivo) {
            preg_match_all("/\\\$t\('([a-z_]+)\./", file_get_contents($archivo), $encontrados);
            $usados = array_merge($usados, $encontrados[1]);
        }

        $faltan = array_values(array_unique(array_filter(
            array_diff($usados, $enviados),
            // Solo cuentan los que existen como archivo de idioma: `$t('algo.x')`
            // sobre un namespace inexistente es otro problema distinto.
            fn ($ns) => file_exists(lang_path("es/{$ns}.php")),
        )));

        $this->assertSame([], $faltan,
            'Namespaces que el front usa y no recibe (anadelos a loadTranslations): ' . implode(', ', $faltan));
    }

    /**
     * Ningun alto de pagina con un numero a ojo.
     *
     * Se descontaban 110px del alto de la ventana, «un numero que no
     * correspondia a nada», y las paginas terminaban 66px antes del borde: en
     * una lista corta la barra de acciones quedaba flotando a media pantalla.
     * Lo que hay que descontar es la barra superior, y tiene su variable.
     */
    public function test_el_alto_de_pagina_sale_de_la_variable_y_no_de_un_numero(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        preg_match_all('/calc\(100vh\s*-\s*([^)]+)\)/', $css, $m);

        $aOjo = array_values(array_filter(
            array_map('trim', $m[1]),
            fn ($resta) => ! str_contains($resta, 'var('),
        ));

        $this->assertSame([], $aOjo,
            'Altos de pagina con un numero fijo en vez de var(--shell-bar-h): ' . implode(' · ', $aOjo));
    }

    /**
     * La palabra «cuadrilla» no vuelve a la pantalla.
     *
     * La invento quien programaba; el sistema anterior decia `plan_workers` y el
     * dueno del producto pregunto que era. Llego a la ficha, a la pantalla de
     * firma y a tres archivos de traduccion antes de que nadie la cuestionara.
     * Sirve de centinela de la regla: las cosas se llaman como se llaman en obra.
     *
     * Se mira **solo lo que ve el usuario** —el valor de las traducciones y el
     * `<template>` de los componentes—, no los comentarios: un comentario que
     * explica por que se quito la palabra es justo lo que se quiere conservar.
     */
    public function test_no_se_usa_jerga_que_el_usuario_no_reconoce(): void
    {
        $prohibidas = ['cuadrilla'];
        $encontradas = [];

        // Valores de las traducciones, que es literalmente lo que se pinta.
        foreach (glob(lang_path('*/*.php')) as $archivo) {
            foreach ($this->valores(require $archivo) as $clave => $texto) {
                foreach ($prohibidas as $palabra) {
                    if (mb_stripos($texto, $palabra) !== false) {
                        $encontradas[] = basename(dirname($archivo)) . '/' . basename($archivo) . " → {$clave}";
                    }
                }
            }
        }

        // Y el texto suelto del template, por si alguien escribio a mano.
        foreach ($this->archivosVue() as $archivo) {
            if (! preg_match('/<template>(.*)<\/template>/s', file_get_contents($archivo), $m)) {
                continue;
            }

            // Los comentarios `<!-- ... -->` no los ve nadie. Y un comentario
            // que explica por que se dejo de usar la palabra es justo lo que se
            // quiere conservar: sin esto, documentar la regla la incumple.
            $visible = preg_replace('/<!--.*?-->/s', '', $m[1]);

            foreach ($prohibidas as $palabra) {
                if (mb_stripos($visible, $palabra) !== false) {
                    $encontradas[] = str_replace(base_path() . '/', '', $archivo);
                }
            }
        }

        $this->assertSame([], array_unique($encontradas),
            "Jerga que el usuario no reconoce (ver docs/UI.md §2):\n" . implode("\n", array_unique($encontradas)));
    }

    /** El estandar existe y se puede leer. */
    public function test_el_estandar_esta_escrito(): void
    {
        $this->assertFileExists(base_path('docs/UI.md'));
    }

    /**
     * Ninguna fila de formulario sin `form-grid`.
     *
     * `app.css` aplana con `!important` toda `.ant-row` dentro de `.sap-form`
     * que no lleve la clase. Es una regla deliberada —la mayoria de los
     * formularios heredados escribian columnas que no querian— pero tiene un
     * efecto feo: una fila nueva bien escrita, con sus `:lg="12"`, se pinta
     * apilada y **no hay ningun error en ninguna parte**. Se ve, y sólo si
     * alguien abre esa pantalla en un monitor ancho.
     *
     * Llego a once formularios a la vez. Se comprueba aqui porque es lo unico
     * que lo caza sin abrir un navegador.
     */
    public function test_toda_fila_de_formulario_pide_su_rejilla(): void
    {
        $sinRejilla = [];

        foreach ($this->archivosVue() as $archivo) {
            $fuente = file_get_contents($archivo);

            // Solo los formularios: el aplanado esta acotado a `.sap-form`.
            if (! str_contains($fuente, 'sap-form') || ! preg_match('/<(Col|a-col)\b/', $fuente)) {
                continue;
            }

            preg_match_all('/<(?:Row|a-row)\b[^>]*>/', $fuente, $filas);

            foreach ($filas[0] as $fila) {
                if (! str_contains($fila, 'form-grid')) {
                    $sinRejilla[] = str_replace(base_path() . '/', '', $archivo);
                }
            }
        }

        $this->assertSame([], array_values(array_unique($sinRejilla)),
            "Filas de formulario que se van a pintar apiladas (les falta class=\"form-grid\"):\n"
            . implode("\n", array_unique($sinRejilla)));
    }

    /**
     * Ningún índice reserva sitio abajo para la barra fija de móvil.
     *
     * Esa barra ya no existe: hoy la de acciones masivas es la misma franja
     * blanca pegajosa en todas las pantallas. Pero 19 índices seguían con un
     * `<style>` sin scope que empujaba el contenedor de la página 150px hacia
     * arriba en móvil, y como la barra vive DENTRO de ese contenedor, se
     * quedaba flotando a 134px del borde con un pastizal gris debajo.
     *
     * Es la clase de resto que nadie va a ver leyendo el diff de su módulo:
     * está al final del archivo, fuera del `scoped`, y toca el layout de
     * todos. Si vuelve a hacer falta reservar sitio abajo, que se ponga en el
     * layout una vez, no copiado en cada índice.
     */
    public function test_ningun_indice_reserva_sitio_para_una_barra_que_ya_no_existe(): void
    {
        $conReserva = [];

        foreach ($this->archivosVue() as $archivo) {
            if (! str_ends_with($archivo, '/Index.vue')) {
                continue;
            }

            if (preg_match('/\.below-shell\s+\.content\s*\{[^}]*padding-bottom/', file_get_contents($archivo))) {
                $conReserva[] = str_replace(base_path() . '/', '', $archivo);
            }
        }

        $this->assertSame([], $conReserva,
            "Índices que empujan el layout hacia arriba para una barra fija que ya no existe.\n"
            . "La barra de acciones masivas se queda flotando lejos del borde en móvil:\n  "
            . implode("\n  ", $conReserva));
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    /** Claves de un archivo de idioma, aplanadas: 'a.b.c'. */
    private function claves(array $arr, string $prefijo = ''): array
    {
        $salida = [];

        foreach ($arr as $clave => $valor) {
            $entera = $prefijo === '' ? (string) $clave : "{$prefijo}.{$clave}";
            $salida = array_merge($salida, is_array($valor) ? $this->claves($valor, $entera) : [$entera]);
        }

        return $salida;
    }

    /** Valores de un archivo de idioma, aplanados: 'a.b' => 'texto'. */
    private function valores(array $arr, string $prefijo = ''): array
    {
        $salida = [];

        foreach ($arr as $clave => $valor) {
            $entera = $prefijo === '' ? (string) $clave : "{$prefijo}.{$clave}";

            if (is_array($valor)) {
                $salida += $this->valores($valor, $entera);
            } elseif (is_string($valor)) {
                $salida[$entera] = $valor;
            }
        }

        return $salida;
    }

    /** @return string[] */
    private function archivosVue(): array
    {
        $salida = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));

        foreach ($it as $archivo) {
            if ($archivo->isFile() && $archivo->getExtension() === 'vue') {
                $salida[] = $archivo->getPathname();
            }
        }

        return $salida;
    }
}
