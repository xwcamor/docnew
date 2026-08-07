/**
 * Utilidades compartidas por los campos compuestos (EPP, IHM, PTF).
 *
 * Cada formato trae sus propias respuestas en `config.answers` — EPP usa
 * Conforme / No conforme / No aplica, IHM No cumple / Cumple / No aplica y PTF
 * Si / No / No aplica —, asi que el color no se puede sacar de la posicion en
 * la lista: se deduce del texto.
 */

/** Minusculas y sin tildes, para que 'Sí' y 'Si' se comparen igual. */
export function normalizar(texto) {
    return String(texto ?? '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim();
}

/**
 * Tono de una respuesta: 'ok' (verde), 'bad' (rojo) o 'na' (gris).
 *
 * 'No aplica' se comprueba ANTES que el negativo generico, porque si no la
 * regla de "empieza por no" se lo come y lo pinta de rojo.
 */
export function tono(respuesta) {
    const texto = normalizar(respuesta);

    if (texto === '') return 'na';
    if (texto.startsWith('no aplica') || texto === 'n/a' || texto === 'na') return 'na';
    if (texto.startsWith('no')) return 'bad';

    return 'ok';
}

/** La primera respuesta positiva del catalogo: la que usa "marcar todo". */
export function respuestaPositiva(respuestas = []) {
    return respuestas.find((r) => tono(r) === 'ok') ?? respuestas[0] ?? null;
}

/**
 * Una fila esta conforme mientras ninguna de sus respuestas sea negativa. Es lo
 * que en el formato de papel era la columna "apto / no apto", y lo que decide
 * si hay que pedir medida de correccion.
 */
export function filaConforme(items = []) {
    return !items.some((i) => tono(i?.answer) === 'bad');
}

/** Cuantos items de la fila estan respondidos (para el contador de avance). */
export function respondidos(items = []) {
    return items.filter((i) => i?.answer !== null && i?.answer !== undefined && i?.answer !== '').length;
}

/** 'matriz_de_riesgo' → 'Matriz de riesgo'. El code ES la etiqueta del campo. */
export function humanizar(code) {
    const texto = String(code ?? '').replace(/_/g, ' ').trim();

    return texto.charAt(0).toUpperCase() + texto.slice(1);
}

/**
 * Catalogo de la config como lista de textos.
 *
 * Tolera que venga un numero en vez de la lista: el seeder de demostracion
 * guarda `['severidades' => 5]` y sin esto el campo no se podria pintar.
 */
export function catalogo(config, ...claves) {
    for (const clave of claves) {
        const valor = config?.[clave];

        if (Array.isArray(valor)) return valor.filter((v) => v !== null && v !== undefined).map(String);

        const n = Number(valor);

        if (Number.isInteger(n) && n > 0) {
            return Array.from({ length: n }, (_, i) => String(i + 1));
        }
    }

    return [];
}
