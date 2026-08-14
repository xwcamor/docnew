import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import {
    entradasDeCatalogo, etiquetaDeCatalogo, textoTraducible, valoresDeCatalogo,
} from '@/Support/catalogo';

/**
 * Los catalogos de un campo, leidos en el idioma de quien mira.
 *
 * POR QUE UN COMPUESTO Y NO LLAMAR A LAS FUNCIONES SUELTAS
 * --------------------------------------------------------
 * Las de `@/Support/catalogo` son puras a proposito —el servidor tiene un gemelo
 * exacto y las pruebas comparan los dos— asi que reciben el idioma por
 * parametro. Pero el idioma es el mismo para toda la pantalla, y pasarlo a mano
 * en cada llamada acaba en la llamada que se olvida: un rotulo que sale en
 * castellano en medio de un formato en ingles, y nadie lo ve hasta que lo ve un
 * cliente.
 *
 * Aqui se lee una sola vez de las props de Inertia y se cierra encima. Los
 * campos compuestos no vuelven a saber que existe un idioma.
 *
 * `valores` NO lleva idioma, y no es un olvido: el valor es la clave que se
 * guarda en la respuesta y la que casa con las 14 000 entregas migradas.
 * Traducirlo convertiria el mismo documento en dos documentos distintos segun el
 * idioma de la tablet en que se llenara.
 */
export function useCatalogos() {
    const pagina = usePage();

    const locale = computed(() => pagina.props?.locale ?? null);

    return {
        locale,
        /** [{value, label, tone}] de una lista de config. */
        entradas: (crudo) => entradasDeCatalogo(crudo, locale.value),
        /** El rotulo de un valor guardado. */
        etiqueta: (crudo, valor) => etiquetaDeCatalogo(crudo, valor, locale.value),
        /** Un texto suelto del cliente: el nombre de un grupo, el de una banda. */
        texto: (valor) => textoTraducible(valor, locale.value),
        /** Solo los valores. Sin idioma: es la clave, no el rotulo. */
        valores: valoresDeCatalogo,
    };
}
