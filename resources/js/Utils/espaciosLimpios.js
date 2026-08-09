/**
 * Los espacios sobrantes se quitan solos, en todos los campos de la aplicación.
 *
 * «Carlos  Gamarra», « Carlos Gamarra» y «carlos gamarra » son la misma persona
 * escrita de tres formas, y el sistema las trata como tres. No es teoría: al
 * repasar la base maestra del sistema anterior salieron nombres con dos y con
 * seis espacios seguidos, y la regla que elegía «el nombre más largo» se quedaba
 * justo con el que llevaba los espacios de más. Un espacio al final además es
 * invisible en pantalla: nadie lo ve, nadie lo borra, y el índice único da por
 * distintas a dos filas que se leen igual.
 *
 * Se engancha una sola vez en `app.js` y cubre los 129 archivos con campos de
 * texto sin tocar ninguno. Va en fase de captura, así que limpia el valor ANTES
 * de que Vue lo lea: al `v-model` le llega ya limpio y al servidor nunca sale
 * lo sucio.
 *
 * Mientras se teclea se permite UN espacio final —si no, no se podría separar
 * dos palabras— y ese se va al salir del campo.
 *
 * En un área de texto, además, no se admite el doble Enter: un párrafo vacío no
 * aporta nada a una descripción de obra y descuadra el PDF.
 */

/** Campos donde el espacio se limpia. El resto se deja en paz. */
const CAMPOS = [
    'input[type="text"]',
    'input[type="search"]',
    'input[type="email"]',
    'input[type="tel"]',
    'input[type="url"]',
    'input:not([type])',
    'textarea',
].join(', ');

/**
 * Donde NO se toca nada.
 *
 * - La contraseña, porque un espacio suyo es un carácter como cualquier otro.
 * - El buscador de un desplegable, el selector de fecha y el campo numérico de
 *   Ant Design: son cajas de texto por dentro, pero su valor lo gobierna el
 *   componente y escribir encima lo descuadra.
 * - `data-espacios="libre"` es la salida para un campo que sí necesite escribir
 *   espacios tal cual.
 */
const INTOCABLES = '.ant-select, .ant-picker, .ant-input-number, .ant-mentions, [data-espacios="libre"]';

/** Un espacio horizontal: el normal, el tabulador, el duro. El salto de línea NO. */
const HORIZONTAL = '[^\\S\\n]';

/**
 * Limpia una línea suelta.
 *
 * @param {string} texto
 * @param {boolean} alSalir  al salir del campo se quita también el espacio
 *                           final; mientras se teclea se deja, o no se podrían
 *                           separar dos palabras.
 */
const limpiarLinea = (texto, alSalir) => {
    const sinDobles = texto
        .replace(new RegExp(`^${HORIZONTAL}+`), '')
        .replace(new RegExp(`${HORIZONTAL}{2,}`, 'g'), ' ');

    return alSalir ? sinDobles.replace(new RegExp(`${HORIZONTAL}+$`), '') : sinDobles;
};

/** Lo mismo, más el doble Enter, para un área de texto. */
const limpiarParrafos = (texto, alSalir) => {
    const lineas = texto
        // Un espacio antes del salto de línea no se ve y viaja igual.
        .replace(new RegExp(`${HORIZONTAL}+\\n`, 'g'), '\n')
        // Nada de párrafos vacíos.
        .replace(/\n{2,}/g, '\n')
        .replace(new RegExp(`${HORIZONTAL}{2,}`, 'g'), ' ')
        .replace(/^\s+/, '');

    return alSalir ? lineas.replace(/\s+$/, '') : lineas;
};

const limpiar = (elemento, alSalir) => (elemento.tagName === 'TEXTAREA'
    ? limpiarParrafos(elemento.value, alSalir)
    : limpiarLinea(elemento.value, alSalir));

/** ¿Este elemento es un campo de texto de los que se limpian? */
const esLimpiable = (elemento) => elemento instanceof HTMLElement
    && elemento.matches(CAMPOS)
    && ! elemento.closest(INTOCABLES);

/**
 * Aplica la limpieza conservando dónde estaba el cursor.
 *
 * Escribir `value` a pelo manda el cursor al final, y con eso no se puede
 * corregir una palabra en medio de una frase. Como la limpieza solo BORRA, el
 * cursor nuevo es el largo de lo que había antes de él ya limpio.
 */
const aplicar = (elemento, alSalir) => {
    const nuevo = limpiar(elemento, alSalir);

    if (nuevo === elemento.value) {
        return;
    }

    const cursor = elemento.selectionStart;
    const hastaElCursor = cursor === null
        ? nuevo.length
        : limpiar({ tagName: elemento.tagName, value: elemento.value.slice(0, cursor) }, false).length;

    elemento.value = nuevo;

    // `setSelectionRange` revienta en los tipos que no lo admiten (email, url).
    try {
        elemento.setSelectionRange(hastaElCursor, hastaElCursor);
    } catch {
        /* el cursor se queda donde el navegador quiera; el valor ya está limpio */
    }
};

export const activarEspaciosLimpios = () => {
    // Captura: esto corre ANTES del manejador de Vue, así que al `v-model` le
    // llega el valor ya limpio y no hace falta reenviar ningún evento.
    document.addEventListener('input', (e) => {
        if (esLimpiable(e.target)) aplicar(e.target, false);
    }, true);

    // Al salir del campo se va el espacio final, el único que se dejaba pasar.
    // Se dispara `input` porque a estas alturas Vue ya no está escuchando el
    // tecleo: sin esto la pantalla se vería limpia y el modelo seguiría sucio.
    document.addEventListener('blur', (e) => {
        if (! esLimpiable(e.target)) return;

        const antes = e.target.value;
        aplicar(e.target, true);

        if (e.target.value !== antes) {
            e.target.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }, true);
};
