<?php

namespace Tests\Feature\Ui;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Todos los listados llevan un subtitulo de una linea bajo su titulo.
 *
 * El dueño del producto lo pidio como norma de orden: los formularios y las
 * fichas ya dicen para que sirven, pero los listados no, y al pasar de un
 * modulo a otro unos explicaban y otros no. La regla es una sola: cada
 * `Pages/**\/Index.vue` pasa `:subtitle` a su cabecera de modulo
 * (`XxxPageHeader` o `CatalogPageHeader`), con la clave `index_subtitle` del
 * archivo de idioma del modulo.
 *
 * Se lee el .vue como texto (igual que RiskMatrixAgrupadaTest) porque lo que
 * se protege es la convencion en el codigo fuente, no el render: sin esto, el
 * proximo modulo nuevo naceria sin subtitulo y nadie lo notaria hasta que el
 * dueño volviera a verlo.
 */
class SubtituloDeIndexTest extends TestCase
{
    /**
     * Indices que NO pasan `:subtitle` a un componente de cabecera, con el
     * motivo y un fragmento que debe seguir presente (el sentinela): asi la
     * excepcion no se convierte en un agujero por el que un dia desaparezca
     * tambien el subtitulo inline.
     *
     * Los cinco pintan su cabecera inline (no existe XxxPageHeader del
     * modulo) y su subtitulo va inline en el mismo sitio: un <p> dentro de
     * `.page-header__heading`, estilado por el CSS global de `.mi-title`.
     */
    private const EXCEPCIONES = [
        // El subtitulo es DINAMICO: total de eventos + a quien le son
        // visibles. Una frase fija diria menos que lo que ya se muestra.
        'AuditLogs/Index.vue' => 'audit_logs.visible_for',

        // La cabecera es el saludo personalizado; el subtitulo es el
        // "hola, :name" con el nombre del usuario, no una frase de catalogo.
        'Dashboard/Index.vue' => 'dashboard.hello',

        // Subtitulo dinamico: contador de notificaciones + aviso de que los
        // descargables se borran solos. Cambiarlo por una frase fija
        // perderia el aviso.
        'Notifications/Index.vue' => 'notifications.auto_delete_hint',

        // Bandeja personal: comparte messages.php con Mensajes, asi que
        // lleva su propia clave inline en vez de index_subtitle.
        'Inbox/Index.vue' => 'messages.inbox_subtitle',

        // Cabecera inline sin componente de modulo; el subtitulo va inline
        // con la misma clave index_subtitle que usarian los demas.
        'Messages/Index.vue' => 'messages.index_subtitle',
    ];

    /** @return array<string, string> ruta relativa a Pages => contenido */
    private function indices(): array
    {
        $base = resource_path('js/Pages');

        $indices = [];

        $iterador = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterador as $archivo) {
            if ($archivo->getFilename() !== 'Index.vue') {
                continue;
            }

            $relativa = str_replace('\\', '/', substr($archivo->getPathname(), strlen($base) + 1));

            $indices[$relativa] = file_get_contents($archivo->getPathname());
        }

        ksort($indices);

        return $indices;
    }

    public function test_todos_los_index_pasan_subtitulo_a_su_cabecera(): void
    {
        $indices = $this->indices();

        // Si esto falla, el glob se rompio o alguien movio Pages entero:
        // con cero archivos el resto del test pasaria en vacio.
        $this->assertGreaterThan(20, count($indices), 'no se encontraron los Index.vue bajo resources/js/Pages');

        $sinSubtitulo = [];

        foreach ($indices as $relativa => $contenido) {
            if (array_key_exists($relativa, self::EXCEPCIONES)) {
                continue;
            }

            // La cabecera del modulo recibe el subtitulo traducido como
            // prop; el componente lo pinta bajo el h1.
            if (! str_contains($contenido, ':subtitle="$t(')) {
                $sinSubtitulo[] = $relativa;
            }
        }

        $this->assertSame([], $sinSubtitulo,
            'Estos listados no pasan :subtitle a su cabecera (el dueño pidio subtitulo en TODOS los index): '
            . implode(', ', $sinSubtitulo));
    }

    public function test_las_excepciones_conservan_su_subtitulo_inline(): void
    {
        $indices = $this->indices();

        foreach (self::EXCEPCIONES as $relativa => $sentinela) {
            // Si el archivo ya no existe, la excepcion esta muerta y hay
            // que quitarla de la lista (o el modulo se renombro sin mas).
            $this->assertArrayHasKey($relativa, $indices,
                "la excepcion «{$relativa}» apunta a un Index.vue que ya no existe");

            $this->assertStringContainsString($sentinela, $indices[$relativa],
                "«{$relativa}» esta exceptuado de :subtitle porque su subtitulo va inline, "
                . "pero ya no contiene «{$sentinela}»: o se le quito el subtitulo o hay que actualizar la excepcion");
        }
    }
}
