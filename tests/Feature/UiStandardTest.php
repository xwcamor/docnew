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
     * La fecha de creacion no vuelve a la pantalla.
     *
     * «No aporta nada ese campo»: cuando se tecleo el registro no contesta
     * ninguna de las preguntas con las que se abre una lista o una ficha, y se
     * llevaba una columna de 180px en la tablet, una fila en el panel de datos
     * y una entrada en el desplegable de filtros y en el de orden.
     *
     * Su sitio es la pestana Historial, que es la traza del registro —creado el
     * X por Y— y ahi si tiene sentido. Se cuela sola: la trae el generador de
     * modulos y nadie la borra, asi que estaba en 24 `filterSchema()` y en 23
     * `columns.js`. Ver docs/UI.md §4-ter.
     *
     * Lo que esta prueba NO mira, a proposito: `RecordHistory`, la papelera,
     * las listas blancas de orden del servidor —hay vistas guardadas ordenando
     * por esa clave— ni los `fields()` del motor de automatizaciones, que es un
     * motor de reglas y no una pantalla.
     */
    public function test_la_fecha_de_creacion_no_esta_en_columnas_ni_en_filtros(): void
    {
        $encontrada = [];

        // 1. El selector de columnas y la tabla.
        foreach (glob(resource_path('js/Pages/*/config/columns.js')) as $archivo) {
            if (str_contains(file_get_contents($archivo), "'created_at'")) {
                $encontrada[] = str_replace(base_path() . '/', '', $archivo);
            }
        }

        // 2. El desplegable de «Filtros avanzados». Solo el cuerpo de
        //    `filterSchema()`: el mismo fichero filtra por `created_at` en el
        //    scope del listado y eso es lo que hace que las vistas guardadas
        //    que ya existen sigan funcionando.
        foreach ($this->archivosPhpDeApp() as $archivo) {
            $fuente = file_get_contents($archivo);

            if (! preg_match('/function filterSchema\([^)]*\): array\s*\{.*?\n    \}/s', $fuente, $m)) {
                continue;
            }

            if (str_contains($m[0], "'key' => 'created_at'")) {
                $encontrada[] = str_replace(base_path() . '/', '', $archivo) . ' → filterSchema()';
            }
        }

        $this->assertSame([], $encontrada,
            "La fecha de creacion volvio a una pantalla (ver docs/UI.md §4-ter).\n"
            . "Su sitio es la pestana Historial, no la columna ni el filtro:\n  "
            . implode("\n  ", $encontrada));
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

    /**
     * Una sola barra de acciones, no cinco.
     *
     * Llego a haber cuatro copias de la misma franja con numeros distintos —20
     * de padding arriba en unas y 10 en otras, botones `middle` en unas y
     * `small` en otras— asi que cambiaba de alto entre el formulario y el
     * indice, que son pantallas que se abren una detras de otra. Medido en el
     * navegador: 57px contra 41px.
     *
     * Se caza aqui porque el sintoma —«la barra se ve distinta»— solo lo ve
     * quien abre las dos pantallas seguidas y se fija.
     */
    public function test_la_barra_de_acciones_es_una_sola(): void
    {
        // Los unicos que tienen permiso: la clase compartida vive en app.css y
        // estos son casos que no son la barra del pie.
        $permitidos = [
            'Components/Common/RotatePortraitOverlay.vue',  // aviso de girar el movil
            'Components/GlobalSearch.vue',                  // paleta de busqueda
            // La navegacion en modo inferior. No es una barra de acciones: es
            // el menu de la aplicacion anclado abajo en vez de arriba, y va
            // `fixed` a la ventana entera, no `sticky` dentro de una pagina.
            'Layouts/AppLayout.vue',
        ];

        $reinventadas = [];

        foreach ($this->archivosVue() as $archivo) {
            $corto = str_replace(resource_path('js') . '/', '', $archivo);

            if (in_array($corto, $permitidos, true)) {
                continue;
            }

            $estilos = $this->bloqueDeEstilos(file_get_contents($archivo));

            if ($estilos === '') {
                continue;
            }

            // `position: sticky` o `fixed` pegado abajo = otra barra de pie.
            if (preg_match('/position:\s*(sticky|fixed)[^}]*bottom:\s*0/s', $estilos)
                || preg_match('/bottom:\s*0[^}]*position:\s*(sticky|fixed)/s', $estilos)) {
                $reinventadas[] = $corto;
            }
        }

        // Y tampoco vale declararla en `app.css`, que es por donde se colo la
        // de la pantalla de llenado: media 69 px donde el resto mide 57, no
        // llegaba a los bordes y esta prueba no la veia porque solo miraba los
        // `.vue`. Una barra que se pega abajo y NO es la compartida es otra
        // barra, este donde este escrita.
        //
        // Los comentarios se quitan antes de trocear: el bloque que explica la
        // barra compartida va justo delante de su regla y, si se deja, el
        // troceo lo lee como parte del selector y la prueba se acusa a si misma.
        $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('css/app.css')));

        foreach (preg_split('/(?<=\})\s*/', $css) as $regla) {
            if (! preg_match('/position:\s*(sticky|fixed)[^}]*bottom:\s*0/s', $regla)) {
                continue;
            }

            preg_match('/^\s*([^{]+)\{/s', $regla, $m);
            $selector = trim($m[1] ?? '');

            // La compartida, y los dos casos que no son el pie de una pagina:
            // la barra de la aplicacion anclada abajo y el aviso de girar el
            // movil, que tapa la pantalla entera.
            if ($selector === '' || str_contains($selector, 'sap-actionbar')
                || str_contains($selector, 'bulk-bar')
                || str_contains($selector, 'app-bottomnav')
                || str_contains($selector, 'rotate-overlay')) {
                continue;
            }

            $reinventadas[] = "resources/css/app.css → {$selector}";
        }

        $this->assertSame([], $reinventadas,
            "Barras de pie escritas a mano en vez de usar `.sap-actionbar` (ver docs/UI.md §8).\n"
            . "Cada una acaba con su propio alto y su propio ancho:\n  "
            . implode("\n  ", $reinventadas));
    }

    /**
     * Ningun color tecleado a mano en una pantalla.
     *
     * Hay tema claro, tema oscuro y cuatro esquemas mas que el usuario elige en
     * su perfil. Un `#0A6ED1` escrito en un `.vue` se queda azul en los seis.
     *
     * El recuento arranco en 753 hexadecimales repartidos por 77 ficheros, y la
     * mayoria eran tokens que YA existian: `#0A6ED1` aparecia 73 veces teniendo
     * `--color-primary` desde el primer dia. Van 566. El tope de abajo es un
     * cepo, no una meta: solo puede bajar, y se baja cuando se limpia un lote. Si tu cambio lo sube, es que has tecleado un color
     * que ya tiene nombre — mira docs/UI.md §5-bis.
     */
    public function test_los_colores_de_las_pantallas_salen_de_un_token(): void
    {
        $tope = 566;
        $cuantos = 0;
        $porFichero = [];

        foreach ($this->archivosVue() as $archivo) {
            if (! str_contains($archivo, '/Pages/')) {
                continue;
            }

            $n = preg_match_all('/#[0-9A-Fa-f]{6}\b/', file_get_contents($archivo));

            if ($n > 0) {
                $cuantos += $n;
                $porFichero[str_replace(resource_path('js/Pages') . '/', '', $archivo)] = $n;
            }
        }

        arsort($porFichero);
        $peores = array_slice($porFichero, 0, 8, true);
        $detalle = implode("\n  ", array_map(fn ($f, $n) => "{$n}\t{$f}", array_keys($peores), $peores));

        $this->assertLessThanOrEqual($tope, $cuantos,
            "Hay {$cuantos} colores escritos a mano en las pantallas y el tope es {$tope}.\n"
            . "Usa los tokens de docs/UI.md §5-bis. Los ficheros con mas:\n  " . $detalle);
    }

    /**
     * Ningun cliente de verdad dentro de un ejemplo.
     *
     * Los campos de la pantalla proponian «HITACHI» y «Hitachi Energy Perú
     * S.A.», y la plantilla de empresas que se descarga traia esa fila y otra de
     * LIMTEK con sus RUC. Son clientes reales del sistema anterior metidos en el
     * producto: salen en la pantalla de cualquier otro cliente, viajan en un
     * .xlsx que acaba en el correo de cualquiera, y encima invitan a subirlos tal
     * cual y crear la empresa equivocada.
     *
     * Los ejemplos usan nombres de mentira —ACME, Globex— y numeros en cuesta.
     * Ver docs/UI.md §2-bis.
     */
    public function test_ningun_ejemplo_nombra_a_un_cliente_de_verdad(): void
    {
        $reales = ['hitachi', 'limtek', 'enel', 'serce', 'ransa', 'adecco', 'sipal', 'cosapi'];

        $donde = array_merge(
            glob(resource_path('lang/*/*.php')),
            glob(base_path('app/Exports/*/*/*ImportTemplate.php')),
            // Los seeders tambien. Los datos de ejemplo que trae el producto se
            // siembran en CADA instalacion: el `DocufizDemoSeeder` traia una
            // contratista de verdad con su RUC, y debajo dos personas con nombre
            // y DNI que tambien lo parecian. Un documento de identidad ajeno
            // viajando en el repositorio no es un dato de ejemplo.
            glob(base_path('database/seeders/*.php')),
        );

        // Salvo el volcado del sistema anterior, que es real por definicion: son
        // los datos de ESE cliente, en SU instalacion, traidos por su migracion.
        $donde = array_values(array_filter(
            $donde,
            fn ($f) => ! str_contains($f, 'DocufizLegacyDataSeeder'),
        ));

        $encontrados = [];

        foreach ($donde as $archivo) {
            $fuente = file_get_contents($archivo);

            // Los comentarios del codigo si pueden nombrarlos: explican el caso
            // real que motivo cada decision, y no los ve ningun usuario.
            $fuente = preg_replace('#(/\*.*?\*/|//[^\n]*)#s', '', $fuente);

            foreach ($reales as $nombre) {
                // Palabra entera, no trozo. Buscando `ransa` a secas saltaba
                // `DB::transaction`, que lleva el nombre dentro sin nombrar a
                // nadie — y una prueba que grita por eso se acaba desactivando.
                if (preg_match('/\b' . preg_quote($nombre, '/') . '\b/i', $fuente) === 1) {
                    $encontrados[] = str_replace(base_path() . '/', '', $archivo) . " → {$nombre}";
                }
            }
        }

        $this->assertSame([], array_unique($encontrados),
            "Clientes de verdad dentro de textos o plantillas que ve el usuario (ver docs/UI.md §2-bis):\n  "
            . implode("\n  ", array_unique($encontrados)));
    }

    /**
     * Ningun texto nombra el documento de un pais.
     *
     * DOCUFIZ se vende en 21 paises y cada uno lleva el suyo: RUC en Peru, RUT
     * en Chile, NIT en Colombia, CNPJ en Brasil, RFC en Mexico. Cual es lo dice
     * el catalogo `document_types`, asi que una traduccion que escribe la sigla
     * a mano acierta en un pais y miente en los otros veinte — «Da de alta una
     * empresa contratista con su RUC y su razon social» salia igual para todos.
     *
     * El texto o va en generico —«el numero de documento»— o interpola la sigla
     * del catalogo donde el tipo ya esta elegido. Ver docs/UI.md §2.
     *
     * Se miran solo los VALORES: los comentarios del archivo de idioma explican
     * por que el texto dejo de nombrarlo, y son justo lo que hay que conservar.
     */
    public function test_ningun_texto_nombra_el_documento_de_un_pais(): void
    {
        $siglas = ['RUC', 'DNI', 'RUT', 'CUIT', 'CNPJ', 'RFC', 'NIT'];

        // Las tres excepciones, y por que cada una:
        //
        //  · `document_types.php` es el catalogo. Explicar que es un tipo de
        //    documento sin nombrar ninguno no se puede hacer, y ahi las siglas
        //    son el contenido y no una suposicion sobre quien mira.
        //
        //  · Las otras dos son integraciones de UN pais de verdad: SUNAT solo
        //    contesta por un RUC y RENIEC solo por un DNI. Nombrarlos ahi es
        //    exacto, y ademas ese texto solo aparece cuando la consulta ya ha
        //    contestado, que solo pasa en Peru.
        $salvo = ['document_types.php'];
        $salvoClaves = ['ruc_no_encontrado', 'dni_no_encontrado'];

        $encontradas = [];

        foreach (glob(lang_path('*/*.php')) as $archivo) {
            if (in_array(basename($archivo), $salvo, true)) {
                continue;
            }

            foreach ($this->valores(require $archivo) as $clave => $texto) {
                if (in_array($clave, $salvoClaves, true)) {
                    continue;
                }

                foreach ($siglas as $sigla) {
                    // Palabra entera, no trozo. Buscando `RUT` a secas saltaba
                    // «ruta» y `NIT` saltaba «infinito»: una prueba que grita
                    // por eso se acaba desactivando (misma leccion que `ransa`
                    // dentro de `DB::transaction`, mas abajo).
                    if (preg_match('/\b' . $sigla . '\b/iu', $texto) === 1) {
                        $encontradas[] = basename(dirname($archivo)) . '/' . basename($archivo)
                            . " → {$clave} ({$sigla})";
                    }
                }
            }
        }

        $this->assertSame([], array_unique($encontradas),
            "Textos que nombran el documento de un pais concreto (ver docs/UI.md §2).\n"
            . "Dilo en generico, o saca la sigla del catalogo con :type:\n  "
            . implode("\n  ", array_unique($encontradas)));
    }

    /**
     * Ningun icono se queda en un hueco.
     *
     * Un `<SearchOutlined />` que nadie importo no revienta: Vue no encuentra el
     * componente, pinta un elemento desconocido —vacio— y sigue. En produccion
     * ni siquiera avisa por consola. Lo que queda es el hueco donde iba la lupa,
     * y solo lo ve quien abre esa pantalla concreta. Paso en dos papeleras a la
     * vez, la de automatizaciones y la de planes, con el mismo copiar y pegar.
     *
     * Y el nombre tiene que existir DE VERDAD: `@ant-design/icons-vue` no
     * publica todos los glifos que uno se imagina —no hay `SunOutlined` ni
     * `MoonOutlined`, por ejemplo—, asi que la lista se saca del paquete
     * instalado y no de la memoria de nadie.
     */
    public function test_todo_icono_que_se_pinta_esta_importado_y_existe(): void
    {
        $delPaquete = $this->iconosDelPaquete();

        if ($delPaquete === []) {
            $this->markTestSkipped('Sin node_modules: no hay contra que comprobar los nombres de icono.');
        }

        $problemas = [];

        foreach ($this->archivosDelFront() as $archivo) {
            $corto = str_replace(resource_path('js') . '/', '', $archivo);
            $fuente = file_get_contents($archivo);
            $esVue = str_ends_with($archivo, '.vue');

            $script = $esVue ? $this->bloqueDeScript($fuente) : $fuente;
            // Los imports se leen con las cadenas intactas: el nombre del
            // paquete ES una cadena, y vaciarlas antes deja `from ''`.
            $codigo = $this->sinComentarios($script);

            // 1. Lo que el fichero importa del paquete de iconos.
            $importados = [];

            preg_match_all("/import\s*\{([^}]*)\}\s*from\s*['\"]@ant-design\/icons-vue['\"]/", $codigo, $bloques);

            foreach ($bloques[1] as $bloque) {
                foreach (explode(',', $bloque) as $trozo) {
                    $trozo = trim($trozo);

                    if ($trozo === '') {
                        continue;
                    }

                    // `X as Y`: el nombre que se pinta es Y, el que tiene que
                    // existir en el paquete es X.
                    $partes = preg_split('/\s+as\s+/', $trozo);
                    $original = trim($partes[0]);
                    $importados[] = trim($partes[1] ?? $partes[0]);

                    if (! in_array($original, $delPaquete, true)) {
                        $problemas[] = "{$corto} → importa {$original}, que no existe en @ant-design/icons-vue";
                    }
                }
            }

            // 2. Lo que el fichero define o trae de otro sitio. Un icono puede
            //    venir envuelto en un componente propio, y eso vale.
            $propios = $this->identificadoresPropios($codigo);

            // 3. Lo que se pinta. En el `.vue` cuentan las etiquetas del
            //    template —`<SearchOutlined />`— y en el codigo los usos como
            //    valor —`{ icon: SearchOutlined }`—, que acaban igual de vacios.
            $usados = [];

            if ($esVue) {
                preg_match_all('/<([A-Z][A-Za-z0-9]*(?:Outlined|Filled|TwoTone))[\s\/>]/',
                    $this->bloqueDePlantilla($fuente), $etiquetas);
                $usados = $etiquetas[1];
            }

            preg_match_all('/\b([A-Z][A-Za-z0-9]*(?:Outlined|Filled|TwoTone))\b/',
                $this->sinCadenas($codigo), $enCodigo);
            $usados = array_merge($usados, $enCodigo[1]);

            foreach (array_unique($usados) as $icono) {
                if (in_array($icono, $importados, true) || in_array($icono, $propios, true)) {
                    continue;
                }

                $problemas[] = "{$corto} → pinta {$icono} y no lo importa";
            }
        }

        $this->assertSame([], array_values(array_unique($problemas)),
            "Iconos que se van a quedar en un hueco (ver docs/UI.md §9):\n  "
            . implode("\n  ", array_unique($problemas)));
    }

    /**
     * Ningun recuadro de icono se pinta sin icono dentro.
     *
     * Las cabeceras compartidas dibujan el hueco del icono ELLAS —un cuadrado de
     * 40x40 con su tinte, un glifo de 56px en el estado vacio— y lo rellenan con
     * el slot `#icon`. La pantalla que se olvida del slot no se queda sin icono:
     * se queda con el cuadrado de color y nada dentro, que el usuario lee como
     * un icono que no cargo. Y no hay error, ni aviso, ni nada que lo delate
     * leyendo el diff: el slot que falta no se menciona en ninguna parte.
     *
     * Se comprueba en el componente y no en las sesenta pantallas que lo usan,
     * porque es ahi donde se arregla de una vez: el recuadro con `v-if` no puede
     * salir vacio lo llame quien lo llame.
     *
     * Paso con las tres pantallas de trabajo en obra a la vez —los formatos del
     * plan, el llenado de un formato y la revision de firmas—, que se
     * escribieron sin el slot y salian con el cuadrado en blanco.
     */
    public function test_ningun_recuadro_de_icono_se_pinta_sin_icono_dentro(): void
    {
        // Los que reservan el hueco en su propio CSS. Si aparece otro componente
        // con un `.algo__icon` y un `<slot name="icon" />` dentro, va aqui.
        $conHueco = [
            'Components/Common/SectionHeader.vue',
            'Components/Catalog/CatalogPageHeader.vue',
            'Components/Catalog/CatalogEmptyState.vue',
        ];

        $sinGuarda = [];

        foreach ($conHueco as $corto) {
            $ruta = resource_path("js/{$corto}");

            $this->assertFileExists($ruta);

            $plantilla = $this->bloqueDePlantilla(file_get_contents($ruta));

            // El elemento que envuelve al `<slot name="icon">`: desde el `<` que
            // lo abre hasta el slot. Si en medio no hay `$slots.icon`, ese
            // envoltorio se pinta tambien cuando nadie pasa icono.
            if (! preg_match('/<[a-zA-Z][^<>]*>\s*<slot\s+name="icon"/s', $plantilla, $m)) {
                $sinGuarda[] = "{$corto}: no se encontro el envoltorio del slot #icon";

                continue;
            }

            if (! str_contains($m[0], '$slots.icon')) {
                $sinGuarda[] = "{$corto}: " . trim(preg_replace('/\s+/', ' ', $m[0]));
            }
        }

        $this->assertSame([], $sinGuarda,
            "Recuadros de icono que se pintan aunque la pantalla no pase el slot.\n"
            . "Ponles `v-if=\"\$slots.icon\"`, o saldran como un cuadrado de color vacio:\n  "
            . implode("\n  ", $sinGuarda));
    }

    /**
     * Toda cabecera pasa su icono.
     *
     * La guarda del recuadro (prueba de arriba) evita el cuadrado en blanco,
     * pero no es la meta: la meta es que la pantalla tenga icono, como lo tienen
     * las otras sesenta. Una cabecera sin el suyo se ve rara en cuanto se abre
     * detras de cualquier otra, y ademas es el sintoma de que ese modulo se
     * escribio a mano en vez de copiar el patron.
     *
     * Esto no se ve buscando imports —el slot que falta no aparece en ninguna
     * busqueda— ni leyendo el diff del modulo: hay que mirar cada llamada.
     */
    public function test_toda_cabecera_pasa_su_icono(): void
    {
        $conHueco = ['SectionHeader', 'CatalogPageHeader', 'CatalogEmptyState'];

        // Deuda, no permiso. Es un cepo como el de los hexadecimales: solo puede
        // menguar. Cuando la pantalla reciba su icono, la linea se borra de aqui
        // y nadie puede anadir otra sin explicar por que.
        //
        // Vacia: la ultima que quedaba era la pantalla de llenado de un formato,
        // y ya pasa su `FileOutlined` —el mismo del menu lateral y el de la lista
        // de formatos del plan, que es de donde se llega—.
        $pendientes = [];

        $sinIcono = [];

        foreach ($this->archivosVue() as $archivo) {
            // Solo el template: el `<script>` de EntityShowActions documenta su
            // uso con un `<SectionHeader>` de ejemplo, y un ejemplo no pinta.
            $plantilla = $this->bloqueDePlantilla(file_get_contents($archivo));

            foreach ($conHueco as $componente) {
                $patron = '/<' . $componente . '\b[^>]*?(?:\/>|>(?:(?!<\/?' . $componente . '\b).)*<\/' . $componente . '>)/s';

                preg_match_all($patron, $plantilla, $usos);

                foreach ($usos[0] as $uso) {
                    if (str_contains($uso, '#icon') || str_contains($uso, 'v-slot:icon')) {
                        continue;
                    }

                    $sinIcono[] = str_replace(resource_path('js') . '/', '', $archivo) . " → <{$componente}>";
                }
            }
        }

        $nuevos = array_values(array_diff(array_unique($sinIcono), $pendientes));

        $this->assertSame([], $nuevos,
            "Cabeceras a las que no se les pasa el slot #icon (ver docs/UI.md §5-quater):\n  "
            . implode("\n  ", $nuevos));

        // Y al reves: una pendiente que ya se arreglo tiene que salir de la
        // lista, o el cepo deja de apretar sin que nadie se entere.
        $this->assertSame([], array_values(array_diff($pendientes, $sinIcono)),
            'Pantallas listadas como pendientes que ya pasan su icono: quitalas de $pendientes.');
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    /** El contenido de los bloques `<style>` de un .vue, o cadena vacia. */
    private function bloqueDeEstilos(string $fuente): string
    {
        preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $fuente, $m);

        return implode("\n", $m[1] ?? []);
    }

    /** El contenido de los bloques `<script>` de un .vue, o cadena vacia. */
    private function bloqueDeScript(string $fuente): string
    {
        preg_match_all('/<script[^>]*>(.*?)<\/script>/s', $fuente, $m);

        return implode("\n", $m[1] ?? []);
    }

    /**
     * Lo que un .vue pinta: todo menos `<script>`, `<style>` y los comentarios.
     *
     * No se recorta por el `<template>` de fuera: dentro hay `<template #icon>`
     * anidados y quedarse con el primer cierre deja media pantalla sin mirar.
     */
    private function bloqueDePlantilla(string $fuente): string
    {
        $plantilla = preg_replace('/<script[^>]*>.*?<\/script>/s', '', $fuente);
        $plantilla = preg_replace('/<style[^>]*>.*?<\/style>/s', '', $plantilla);

        return preg_replace('/<!--.*?-->/s', '', $plantilla);
    }

    /** Codigo JavaScript sin comentarios. */
    private function sinComentarios(string $script): string
    {
        $codigo = preg_replace('#/\*.*?\*/#s', '', $script);

        // La guarda del `:` deja pasar los `https://` sin comerse la linea.
        return preg_replace('#(^|[^:\'"/\\\\])//[^\n]*#m', '$1', $codigo);
    }

    /**
     * Codigo JavaScript con las cadenas vaciadas.
     *
     * `{ value: 'CrownOutlined' }` es el nombre del icono guardado en la base de
     * datos, no un componente: mirarlo como si lo fuera acusa de "no importado"
     * al catalogo que precisamente lo resuelve.
     */
    private function sinCadenas(string $codigo): string
    {
        return preg_replace(
            ['/\'(?:[^\'\\\\\n]|\\\\.)*\'/', '/"(?:[^"\\\\\n]|\\\\.)*"/', '/`(?:[^`\\\\]|\\\\.)*`/s'],
            "''",
            $codigo,
        );
    }

    /** Nombres que el fichero declara o importa de cualquier otro sitio. */
    private function identificadoresPropios(string $codigo): array
    {
        $nombres = [];

        preg_match_all('/\b(?:const|let|var|function|class)\s+([A-Za-z0-9_$]+)/', $codigo, $m);
        $nombres = array_merge($nombres, $m[1]);

        // `import Algo from '...'` y `import Algo, { ... } from '...'`.
        preg_match_all('/import\s+([A-Za-z0-9_$]+)\s*(?:,|from)/', $codigo, $m);
        $nombres = array_merge($nombres, $m[1]);

        // `import { A, B as C } from '...'` de cualquier paquete.
        preg_match_all('/import\s*\{([^}]*)\}\s*from/', $codigo, $m);

        foreach ($m[1] as $bloque) {
            foreach (explode(',', $bloque) as $trozo) {
                $trozo = trim($trozo);

                if ($trozo === '') {
                    continue;
                }

                $partes = preg_split('/\s+as\s+/', $trozo);
                $nombres[] = trim($partes[1] ?? $partes[0]);
            }
        }

        return $nombres;
    }

    /**
     * Los iconos que publica de verdad la version instalada del paquete.
     *
     * Se leen del disco a proposito: la lista de memoria siempre tiene alguno
     * que suena bien y no existe.
     *
     * @return string[]
     */
    private function iconosDelPaquete(): array
    {
        $carpeta = base_path('node_modules/@ant-design/icons-vue/es/icons');

        if (! is_dir($carpeta)) {
            return [];
        }

        return array_map(
            fn ($ruta) => basename($ruta, '.js'),
            glob("{$carpeta}/*.js") ?: [],
        );
    }

    /**
     * Todo lo que se compila al front: los `.vue` y los `.js` que los alimentan.
     *
     * @return string[]
     */
    private function archivosDelFront(): array
    {
        $salida = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));

        foreach ($it as $archivo) {
            if ($archivo->isFile() && in_array($archivo->getExtension(), ['vue', 'js'], true)) {
                $salida[] = $archivo->getPathname();
            }
        }

        return $salida;
    }

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
    private function archivosPhpDeApp(): array
    {
        $salida = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($it as $archivo) {
            if ($archivo->isFile() && $archivo->getExtension() === 'php') {
                $salida[] = $archivo->getPathname();
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
