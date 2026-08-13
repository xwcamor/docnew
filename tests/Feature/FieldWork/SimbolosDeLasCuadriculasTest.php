<?php

namespace Tests\Feature\FieldWork;

use App\Services\FieldWork\Pdf\Simbolos;
use Tests\TestCase;

/**
 * Las marcas de las cuadriculas del PDF, y la leyenda que las explica.
 *
 * El EPP y la inspeccion de herramientas son rejillas de veinte y veinticinco
 * columnas. Escribir «Conforme» entero en cada celda gasta en cada columna el
 * ancho de la palabra mas larga, asi que o caben tres columnas por hoja o se lee
 * a lupa; en el papel de la v1 tampoco esta escrito, hay una marca y una leyenda
 * (`show_pdf_page1.erb` pinta `check.png`, `equis.png` y `minus.png`).
 *
 * Lo que se vigila aqui son las dos cosas que hacen que eso no sea peor que la
 * palabra:
 *
 *   - que «no aplica» y «sin responder» NO se pinten igual. Uno es una decision
 *     de quien inspecciono —ese trabajador no necesitaba careta de soldar— y el
 *     otro es un item que nadie miro. Igualarlos convierte un olvido en una
 *     decision, que en un formato de seguridad es justo lo que no puede pasar;
 *   - y que la leyenda hable con las palabras DEL CLIENTE. Las respuestas salen
 *     de `config.answers` de su plantilla, no de una lista nuestra: un formato
 *     que alguien defina mañana con otras palabras sale explicado sin tocar
 *     nada, y uno migrado sigue diciendo lo que se firmo.
 *
 * No hace falta base de datos: esto es una decision pura sobre textos.
 */
class SimbolosDeLasCuadriculasTest extends TestCase
{
    /** Cada tono tiene su marca, y ninguna se repite. */
    public function test_las_cuatro_marcas_son_distintas(): void
    {
        $marcas = [
            Simbolos::de('ok'),
            Simbolos::de('bad'),
            Simbolos::de('na'),
            Simbolos::de('vacio'),
        ];

        $this->assertSame($marcas, array_values(array_unique($marcas)),
            'dos estados con la misma marca es una cuadricula que miente');
    }

    /**
     * Un tono que no conocemos cae al interrogante, nunca al check.
     *
     * Es la caida segura: lo que no se sabe se enseña como que no se sabe. Si
     * cayera a «conforme», un estado nuevo —o un dato raro de la migracion—
     * imprimiria un visto bueno que nadie dio.
     */
    public function test_lo_desconocido_no_se_da_por_bueno(): void
    {
        $this->assertSame(Simbolos::SIN_RESPONDER, Simbolos::de('vacio'));
        $this->assertSame(Simbolos::SIN_RESPONDER, Simbolos::de(null));
        $this->assertSame(Simbolos::SIN_RESPONDER, Simbolos::de('lo_que_sea'));

        $this->assertNotSame(Simbolos::OK, Simbolos::de('vacio'));
    }

    /** «No aplica» y el hueco son cosas distintas y se ven distintas. */
    public function test_no_aplica_no_se_confunde_con_el_hueco(): void
    {
        $this->assertNotSame(Simbolos::de('na'), Simbolos::de('vacio'));
    }

    /** La leyenda habla con las palabras de la plantilla del cliente. */
    public function test_la_leyenda_usa_las_respuestas_del_formato(): void
    {
        app()->setLocale('es');

        $leyenda = Simbolos::leyenda(['Cumple', 'No aplica', 'No cumple']);

        $textos = array_column($leyenda, 'texto');

        $this->assertContains('Cumple', $textos);
        $this->assertContains('No cumple', $textos);
        $this->assertContains('No aplica', $textos);

        // Y el hueco al final, que no es una respuesta de nadie.
        $this->assertSame('Sin responder', end($textos));
        $this->assertSame(Simbolos::SIN_RESPONDER, $leyenda[array_key_last($leyenda)]['simbolo']);
    }

    /**
     * Cada palabra con la marca que le toca.
     *
     * La clasificacion la hace `FormFindingsService::tono()`, la misma que
     * cuenta las no conformidades del plan. Si la leyenda usara otra regla, el
     * papel diria «conforme» donde el contador del plan cuenta una.
     */
    public function test_cada_respuesta_lleva_su_marca(): void
    {
        $leyenda = collect(Simbolos::leyenda(['Cumple', 'No aplica', 'No cumple']))
            ->mapWithKeys(fn (array $e) => [$e['texto'] => $e['simbolo']]);

        $this->assertSame(Simbolos::OK, $leyenda['Cumple']);
        $this->assertSame(Simbolos::MALA, $leyenda['No cumple']);
        $this->assertSame(Simbolos::NO_APLICA, $leyenda['No aplica']);
    }

    /**
     * Una plantilla sin respuestas declaradas no se queda sin leyenda.
     *
     * Pasa en lo migrado. Salen las tres genericas, que es peor que las suyas
     * pero muchisimo mejor que una rejilla de marcas sin explicar.
     */
    public function test_sin_respuestas_declaradas_hay_leyenda_igual(): void
    {
        app()->setLocale('es');

        $leyenda = Simbolos::leyenda([]);

        $this->assertCount(4, $leyenda);

        foreach ($leyenda as $entrada) {
            $this->assertNotSame('', trim($entrada['texto']));
            // Y nunca la ruta de una clave de idioma en mitad del documento.
            $this->assertStringNotContainsString('form_submissions.', $entrada['texto']);
        }
    }

    /** Dos respuestas del mismo tono no repiten marca en la leyenda. */
    public function test_no_se_repite_una_marca_en_la_leyenda(): void
    {
        $leyenda = Simbolos::leyenda(['No aplica', 'N/A', 'Conforme', 'No conforme']);

        $simbolos = array_column($leyenda, 'simbolo');

        $this->assertSame($simbolos, array_values(array_unique($simbolos)));
    }

    /** Las dos leyendas de idioma tienen las mismas claves. */
    public function test_la_leyenda_esta_en_los_dos_idiomas(): void
    {
        $es = require resource_path('lang/es/form_submissions.php');
        $en = require resource_path('lang/en/form_submissions.php');

        $this->assertSame(
            array_keys($es['pdf']['legend']),
            array_keys($en['pdf']['legend']),
        );
    }
}
