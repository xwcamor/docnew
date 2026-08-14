<?php

namespace Tests\Feature\FieldWork;

use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Services\FieldWork\FormTemplateBuilder;
use Database\Seeders\FormTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los cuatro formatos de la v1 entran al sembrar.
 *
 * Habia un camino para crearlos —`docufiz:migrate-formats`— pero solo funciona
 * con la MySQL vieja delante: en una instalacion limpia se cae, y el motor se
 * quedaba con las seis tablas a cero. Estas pruebas fijan lo contrario: que
 * `php artisan setup:project --datos`, que por dentro es este seeder, deja los
 * cuatro montados y usables sin ninguna base anterior.
 *
 * Lo que se comprueba:
 *   1. estan los cuatro, publicados y con nombre en los dos idiomas;
 *   2. ninguno se queda sin secciones ni sin campos;
 *   3. sembrar dos veces no duplica nada;
 *   4. cada campo trae la configuracion que su tipo exige, que es la regla que
 *      `FormTemplateBuilder` aplica cuando el formato se crea desde la pantalla
 *      y que el seeder se salta al escribir filas directamente;
 *   5. los codigos de campo son los que la migracion de datos busca por nombre;
 *   6. ningun catalogo nombra a un cliente de verdad.
 */
class FormatosSembradosTest extends TestCase
{
    use RefreshDatabase;

    /** Los cuatro, tal y como los llamaba el sistema anterior. */
    private const CODIGOS = ['AST', 'PTF', 'EPP', 'IHM'];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }

    private function sembrar(): void
    {
        $this->seed(FormTemplatesSeeder::class);
    }

    public function test_siembra_los_cuatro_formatos_publicados_y_con_nombre(): void
    {
        $this->sembrar();

        $this->assertSame(
            self::CODIGOS,
            FormTemplate::orderBy('id')->pluck('code')->all(),
            'Faltan formatos, o llegaron en otro orden del esperado.',
        );

        foreach (FormTemplate::all() as $plantilla) {
            $this->assertSame('published', $plantilla->status, "{$plantilla->code} no quedo publicado.");
            $this->assertNotNull($plantilla->published_at);
            $this->assertNotEmpty($plantilla->name_es, "{$plantilla->code} sin nombre en castellano.");
            $this->assertNotEmpty($plantilla->name_en, "{$plantilla->code} sin nombre en ingles.");

            // `name` es el que consultan `scopeFilter()` y las pantallas ya
            // escritas: si se queda nulo, la lista de documentos enseña un
            // hueco donde deberia ir el nombre.
            $this->assertSame($plantilla->name_es, $plantilla->name);
        }
    }

    /** El nombre sale en el idioma en que se esta mirando la aplicacion. */
    public function test_el_nombre_del_formato_cambia_con_el_idioma(): void
    {
        $this->sembrar();

        $ast = FormTemplate::where('code', 'AST')->first();

        app()->setLocale('es');
        $this->assertStringContainsString('Análisis de Seguridad', $ast->label);

        app()->setLocale('en');
        $this->assertStringContainsString('Job Safety Analysis', $ast->label);
    }

    /**
     * Ninguno vacio.
     *
     * Un formato sin campos se publica igual y no se ve raro en la lista: el
     * fallo aparece cuando la obra lo abre para llenarlo y no hay nada.
     */
    public function test_ningun_formato_se_queda_sin_secciones_ni_sin_campos(): void
    {
        $this->sembrar();

        foreach (FormTemplate::with('sections.fields')->get() as $plantilla) {
            $this->assertNotEmpty($plantilla->sections, "{$plantilla->code} no tiene secciones.");

            foreach ($plantilla->sections as $seccion) {
                $this->assertNotEmpty($seccion->name_es, "{$plantilla->code}: una seccion sin titulo.");
                $this->assertNotEmpty($seccion->name_en, "{$plantilla->code}: una seccion sin titulo en ingles.");
                $this->assertNotEmpty($seccion->fields, "{$plantilla->code}: seccion «{$seccion->name_es}» sin campos.");
            }

            $this->assertGreaterThan(0, $plantilla->fields()->count(), "{$plantilla->code} no tiene ni un campo.");
        }
    }

    /** Todo campo se lee con una etiqueta escrita, no con el codigo humanizado. */
    public function test_todos_los_campos_tienen_etiqueta_en_los_dos_idiomas(): void
    {
        $this->sembrar();

        foreach (FormField::all() as $campo) {
            $this->assertNotEmpty($campo->label_es, "Campo «{$campo->code}» sin etiqueta en castellano.");
            $this->assertNotEmpty($campo->label_en, "Campo «{$campo->code}» sin etiqueta en ingles.");
        }
    }

    /**
     * Sembrar dos veces no duplica nada.
     *
     * `db:seed` se corre suelto mas a menudo de lo que parece, y el motor no
     * tiene indice unico por codigo en SQLite: la copia no reventaria, se
     * quedaria ahi y la obra veria el AST dos veces en la lista.
     */
    public function test_sembrar_dos_veces_no_duplica_nada(): void
    {
        $this->sembrar();

        $antes = [
            'plantillas' => FormTemplate::count(),
            'secciones'  => FormSection::count(),
            'campos'     => FormField::count(),
        ];

        $this->sembrar();

        $this->assertSame($antes, [
            'plantillas' => FormTemplate::count(),
            'secciones'  => FormSection::count(),
            'campos'     => FormField::count(),
        ]);
    }

    /**
     * Una plantilla ya editada no se pisa al volver a sembrar.
     *
     * Es lo que separa «reparar lo que falta» de «devolver todo a la version de
     * fabrica»: un formato con entregas firmadas detras no se puede rehacer.
     */
    public function test_no_pisa_una_plantilla_que_ya_tiene_campos(): void
    {
        $this->sembrar();

        $campo = FormField::whereHas('section.formTemplate', fn ($q) => $q->where('code', 'EPP'))
            ->where('code', 'observaciones')->firstOrFail();
        $campo->update(['label_es' => 'Lo que quiera el cliente']);

        $this->sembrar();

        $this->assertSame('Lo que quiera el cliente', $campo->fresh()->label_es);
    }

    /**
     * Cada campo trae lo que su tipo necesita para poder pintarse.
     *
     * Es la regla de `FormTemplateBuilder::agregarCampo()`, que el seeder no
     * atraviesa porque escribe filas. Sin esto, un `risk_matrix` sembrado sin
     * `severities` se guarda tan tranquilo y el campo sale en blanco en la
     * tablet, que es donde se descubre.
     */
    public function test_cada_campo_trae_la_configuracion_que_su_tipo_exige(): void
    {
        $this->sembrar();

        foreach (FormField::all() as $campo) {
            $this->assertContains($campo->field_type, FormField::TIPOS,
                "Campo «{$campo->code}» de un tipo que el motor no conoce.");

            if (! in_array($campo->field_type, FormTemplateBuilder::CONFIG_OBLIGATORIA, true)) {
                continue;
            }

            $faltan = array_diff(
                FormTemplateBuilder::TIPOS[$campo->field_type],
                array_keys($campo->config ?? []),
            );

            $this->assertSame([], $faltan,
                "Campo «{$campo->code}» ({$campo->field_type}) sin configurar: " . implode(', ', $faltan));
        }
    }

    /**
     * Los codigos que la migracion de datos busca por nombre.
     *
     * `MigrateLegacyDataCommand` engancha cada tabla vieja a su campo mirando
     * `$campos['matriz_de_riesgo']`, `$campos['preguntas']`… y si no lo
     * encuentra se salta la respuesta **en silencio**. Renombrar un codigo aqui
     * dejaria las entregas migradas medio vacias sin que fallara nada.
     */
    public function test_los_codigos_de_campo_son_los_que_espera_la_migracion(): void
    {
        $this->sembrar();

        $esperados = [
            'AST' => ['permisos', 'herramientas_adicionales', 'objetivos', 'equipos', 'matriz_de_riesgo', 'observaciones'],
            'PTF' => ['preguntas', 'matriz_de_riesgo', 'observaciones'],
            'EPP' => ['epp_por_trabajador', 'observaciones'],
            'IHM' => ['inspeccion_de_herramientas', 'observaciones'],
        ];

        foreach ($esperados as $codigo => $campos) {
            $sembrados = FormField::whereHas('section.formTemplate', fn ($q) => $q->where('code', $codigo))
                ->pluck('code')->sort()->values()->all();

            sort($campos);

            $this->assertSame($campos, $sembrados, "Los campos del {$codigo} no son los esperados.");
        }
    }

    /**
     * La matriz de riesgo es la tabla de la v1, no severidad × probabilidad.
     *
     * Doce de las veinticinco celdas caen en otra banda si se multiplica.
     * `c2 × p4` es la que mas duele: vale 12 en la tabla —«medio»— y 8
     * multiplicando, que es «alto».
     */
    public function test_la_matriz_de_riesgo_es_la_tabla_real_y_no_el_producto(): void
    {
        $this->sembrar();

        $config = FormField::whereHas('section.formTemplate', fn ($q) => $q->where('code', 'AST'))
            ->where('code', 'matriz_de_riesgo')->firstOrFail()->config;

        $this->assertCount(5, $config['matrix']);
        $this->assertSame([1, 2, 4, 7, 11], $config['matrix'][0]);
        $this->assertSame(12, $config['matrix'][1][3], 'c2 × p4 vale 12 en la v1, no 8.');
        $this->assertSame(
            [['hasta' => 8, 'clave' => 'alto'], ['hasta' => 15, 'clave' => 'medio'], ['hasta' => 25, 'clave' => 'bajo']],
            $config['levels'],
        );

        // Las cuatro columnas encadenadas del papel: sin `risks` la
        // consecuencia no tiene donde escribirse.
        foreach (['activities', 'dangers', 'risks', 'controls'] as $catalogo) {
            $this->assertNotEmpty($config[$catalogo], "El catalogo «{$catalogo}» llego vacio.");
        }
    }

    /**
     * Las respuestas son las etiquetas que escribe la migracion, no su posicion.
     *
     * `LegacyFormMapper` guarda «Conforme» y no un 1. Si el catalogo de la
     * plantilla no contiene exactamente esas cadenas, las entregas migradas se
     * abren sin ninguna casilla marcada.
     */
    public function test_las_respuestas_coinciden_con_las_que_guarda_la_migracion(): void
    {
        $this->sembrar();

        $config = fn (string $plantilla, string $campo) => FormField::whereHas(
            'section.formTemplate', fn ($q) => $q->where('code', $plantilla)
        )->where('code', $campo)->firstOrFail()->config;

        // Y en este orden: la buena, «no aplica», la mala. Es como se lee, y
        // evita el toque equivocado por prisa — que en un EPP significa dar por
        // bueno un arnes roto.
        //
        // Se comparan los VALORES, no la forma cruda: desde que las respuestas
        // declaran su tono, cada entrada es `{value, tone}` y lo que tiene que
        // casar con la migracion es el valor — que es lo que se guarda.
        $valores = fn (string $plantilla, string $campo) => \App\Support\Catalogo::valores(
            $config($plantilla, $campo)['answers'],
        );

        $this->assertSame(['Conforme', 'No aplica', 'No conforme'], $valores('EPP', 'epp_por_trabajador'));
        $this->assertSame(['Cumple', 'No aplica', 'No cumple'], $valores('IHM', 'inspeccion_de_herramientas'));

        // El PTF tiene dos, no tres: el formulario de la v1 pinta dos radios.
        $this->assertSame(['Si', 'No'], $valores('PTF', 'preguntas'));

        // Y los tonos van DECLARADOS: los formatos sembrados no dependen de la
        // heuristica del castellano, que existe solo por compatibilidad.
        $this->assertSame(
            ['ok', 'na', 'bad'],
            array_column(\App\Support\Catalogo::entradas($config('EPP', 'epp_por_trabajador')['answers']), 'tone'),
        );
    }

    /**
     * Los campos de correccion del IHM, que no se estaban trayendo.
     *
     * `f4_document_tools` guarda medida de correccion y responsable, y las
     * plantillas creadas por `docufiz:migrate-formats` no declaraban ningun
     * `extra`: las dos columnas no tenian donde escribirse.
     */
    public function test_el_ihm_conserva_sus_campos_de_correccion(): void
    {
        $this->sembrar();

        $config = FormField::whereHas('section.formTemplate', fn ($q) => $q->where('code', 'IHM'))
            ->where('code', 'inspeccion_de_herramientas')->firstOrFail()->config;

        // `correction_verification` es del #57: el PDF imprime ese hueco
        // siempre que algo sale no conforme, y sin la clave en la config el
        // formulario no ofrecia donde llenarlo — el plan quedaba atrapado.
        $this->assertSame(['correction_measure', 'responsible', 'correction_verification'], $config['extra']);
    }

    /**
     * Ningun catalogo nombra a un cliente de verdad.
     *
     * La base viva traia once controles del AST que son procedimientos escritos
     * por un cliente, con su nombre dentro. Salen en el desplegable de
     * cualquier otro cliente y acaban impresos en su PDF. Ver docs/UI.md §2-bis.
     */
    public function test_ningun_catalogo_nombra_a_un_cliente_de_verdad(): void
    {
        $this->sembrar();

        $reales = ['hitachi', 'limtek', 'enel', 'serce', 'ransa', 'adecco', 'sipal', 'cosapi'];
        $encontrados = [];

        foreach (FormField::all() as $campo) {
            $texto = json_encode($campo->config ?? [], JSON_UNESCAPED_UNICODE);

            foreach ($reales as $nombre) {
                if (stripos($texto, $nombre) !== false) {
                    $encontrados[] = "{$campo->code} → {$nombre}";
                }
            }
        }

        $this->assertSame([], array_unique($encontrados),
            "Clientes de verdad dentro de un catalogo:\n  " . implode("\n  ", array_unique($encontrados)));
    }
}
