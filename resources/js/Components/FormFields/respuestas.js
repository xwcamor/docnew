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

/**
 * Estado de una fila de checklist, para el indice y para la cabecera plegada.
 *
 * El orden de las preguntas no es caprichoso: primero **cuanto falta**, que es
 * el trabajo que queda, y solo cuando la fila esta entera se anuncia si salio
 * no conforme. Al reves, un trabajador con una casilla en rojo y otras doce sin
 * tocar saldria como «No conforme» y quien rellena leeria que ya termino.
 *
 * Devuelve la clave del tono —los `--state-*` de app.css— y cuantos faltan,
 * para que el texto se escriba en el idioma de quien mira.
 */
export function estadoChecklist(items = []) {
    const total = items.length;
    const hechos = respondidos(items);

    if (hechos === 0) return { clave: 'off', faltan: total, hechos, total };
    if (hechos < total) return { clave: 'warn', faltan: total - hechos, hechos, total };
    if (! filaConforme(items)) return { clave: 'bad', faltan: 0, hechos, total };

    return { clave: 'ok', faltan: 0, hechos, total };
}

/**
 * El COLOR de una fila cuando el servidor dijo que el campo entero sigue
 * faltando (la prop `faltante` que baja de FormFill tras un guardado a medias).
 *
 * Una fila a medias pasa de aviso (naranja) a bloqueo (rojo): es exactamente
 * lo que impide cerrar el formato, y el usuario lo descubria recien en el
 * aviso del guardado, lejos de la fila.
 *
 * Devuelve SOLO la clave del tono, nunca el estado entero, y no es un
 * capricho: la primera version reescribia `clave` dentro del estado, el texto
 * se derivaba despues con `textoEstado()` y un trabajador sin empezar salia
 * como «No conforme» — una mentira en un documento de seguridad. La palabra
 * sale siempre del estado real («Sin empezar», «Faltan 3», que ya dicen lo
 * concreto); esto solo decide el color que la acompaña (docs/UI.md §5). Las
 * filas completas no se tocan: un rojo generico sobre todo no señala nada.
 */
export function claveExigida(estado, faltante) {
    return faltante && estado.faltan > 0 ? 'bad' : estado.clave;
}

/** El estado en palabra, que es lo que acompaña al color (docs/UI.md §5). */
export function textoEstado(t, estado) {
    if (estado.clave === 'off')  return t('field_work.progress.not_started');
    if (estado.clave === 'warn') return t('field_work.progress.missing', { n: estado.faltan });
    if (estado.clave === 'bad')  return t('field_work.progress.nonconforming');

    return t('field_work.progress.complete');
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
