/**
 * Textos de la cabecera de fisicoquímico (3 líneas: nombre · norma · medida).
 *
 * Salen de los ARCHIVOS DE IDIOMA, junto a los nombres — son etiquetas, no
 * datos del motor. La columna del backend (`fiquisColumns`) queda solo como
 * respaldo, por si algún día se agrega un parámetro que no tenga traducción.
 */

/** El plugin i18n devuelve la clave cuando no existe: eso significa "sin valor". */
function trad(t, clave) {
    const v = t(clave);
    return v && v !== clave ? v : null;
}

/**
 * Nombre CON la condición de ensayo pegada ("Factor de Potencia 100 °C",
 * "Rigidez Dieléctrica 2.54 mm").
 *
 * Se usa donde los dos métodos de una misma propiedad conviven en una lista y el
 * nombre pelado se repite: la traza del diagnóstico y el editor de umbrales. En
 * la tabla de ensayos NO — ahí la condición ya tiene su propia línea de cabecera
 * y repetirla sería ruido. Respaldo al nombre corto si la clave no existe.
 */
export function fiquiFullName(t, key) {
    return trad(t, `fiquis.${key}_full`) || t(`fiquis.${key}`);
}

/** Línea 2: norma del método ("ASTM D1816"). */
export function fiquiAstm(t, col) {
    return trad(t, `fiquis.${col.key}_astm`) || (col.astm ? `ASTM ${col.astm}` : null);
}

/**
 * Línea 3: la medida con su condición de ensayo ("25 °C. %", "kV/2.0 mm").
 * Sin clave definida cae a la unidad pelada, que es lo correcto para los
 * parámetros que no necesitan condición (acidez, agua, tensión interfacial).
 */
export function fiquiHead(t, col) {
    return trad(t, `fiquis.${col.key}_head`)
        || col.unit
        || trad(t, `fiquis.${col.key}_unit`);
}
