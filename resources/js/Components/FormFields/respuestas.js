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

/** La primera respuesta positiva del catalogo. */
export function respuestaPositiva(respuestas = []) {
    return respuestas.find((r) => tono(r) === 'ok') ?? respuestas[0] ?? null;
}

/**
 * La respuesta del catalogo que significa «no aplica», si la hay.
 *
 * Es la que el servidor escribe al confirmar en todo lo que se dejo sin marcar
 * (`FormSubmissionService::cerrarLoSinMarcarComoNoAplica`). Aqui se usa solo
 * para NOMBRARLA en el aviso de la pantalla: el aviso tiene que decir la
 * palabra que va a quedar escrita —«No aplica», «N/A», la que tenga el formato
 * del cliente— y no una nuestra.
 *
 * Devuelve null si el catalogo no tiene ninguna, que es cuando el servidor
 * tampoco rellena nada y por tanto no hay nada que avisar.
 */
export function respuestaNoAplica(respuestas = []) {
    return respuestas.find((r) => tono(r) === 'na') ?? null;
}

/** La primera respuesta negativa del catalogo: «No conforme», «No cumple», «No». */
export function respuestaNegativa(respuestas = []) {
    return respuestas.find((r) => tono(r) === 'bad') ?? null;
}

/**
 * El ciclo de toques de una casilla de checklist: [sin marcar, conforme, no
 * conforme].
 *
 * POR QUE SOLO DOS RESPUESTAS Y NO LAS TRES DEL CATALOGO
 * -----------------------------------------------------
 * «No aplica» NO entra en el ciclo porque ya no hace falta tocarla: al
 * confirmar, el servidor escribe esa misma palabra en todo lo que quedo en
 * blanco (`FormSubmissionService::cerrarLoSinMarcarComoNoAplica`). Dejar la
 * casilla en gris ES decir «no aplica», asi que meterla en el ciclo pondria un
 * tercer toque para llegar a un sitio en el que ya se estaba —y un toque de
 * mas en una lista de veinticinco casillas por trabajador se paga caro.
 *
 * Se ordena a proposito: primero lo que se marca casi siempre (conforme) y
 * despues la excepcion (no conforme). El caso comun cuesta un toque.
 *
 * Es estricto con el tono: `respuestaPositiva()` se inventa una positiva
 * (`?? respuestas[0]`) cuando el catalogo no la tiene, y aqui eso convertiria
 * un «No aplica» suelto en el estado verde del ciclo. Un catalogo sin positiva
 * o sin negativa devuelve un ciclo mas corto y la casilla sigue funcionando.
 *
 * @param {string[]} respuestas  `config.answers` del campo
 * @returns {Array<string|null>} el ciclo, empezando siempre por `null`
 */
export function cicloRespuestas(respuestas = []) {
    const ok = respuestas.find((r) => tono(r) === 'ok') ?? null;
    const bad = respuestaNegativa(respuestas);

    return [null, ok, bad].filter((r, i) => i === 0 || r !== null);
}

/**
 * A donde lleva el siguiente toque.
 *
 * Una respuesta que NO esta en el ciclo —«No aplica» escrita por el servidor en
 * una entrega que se volvio a abrir— cuenta como sin marcar, que es lo que
 * significa: el toque la lleva a conforme, igual que si estuviera en blanco. Si
 * la mandara a `null` el usuario veria gris antes y gris despues, y pensaria
 * que la pantalla no le hizo caso.
 */
export function siguienteEnCiclo(ciclo, valor) {
    const actual = ciclo.indexOf(valor ?? null);

    return ciclo[((actual < 0 ? 0 : actual) + 1) % ciclo.length];
}

/**
 * Las palabras que nombra la leyenda del ciclo, o null si este formato no tiene
 * ciclo que explicar (le falta la respuesta positiva o la negativa).
 *
 * Salen del catalogo del propio formato —el EPP dice «Conforme/No conforme» y
 * el IHM «Cumple/No cumple»— y nunca escritas a pelo: la leyenda tiene que
 * decir la palabra que se va a ver en la casilla y a quedar en el documento.
 *
 * `na` puede venir a null: es el formato cuyo catalogo no tiene «no aplica», y
 * entonces el servidor tampoco rellena los huecos al cerrar. La leyenda que se
 * elige con eso es otra, porque prometer lo que no va a pasar es peor que
 * callarse.
 *
 * @returns {{ok: string, bad: string, na: string|null}|null}
 */
export function palabrasDelCiclo(respuestas = []) {
    const [, ok, bad] = cicloRespuestas(respuestas);

    if (! ok || ! bad) return null;

    return { ok, bad, na: respuestaNoAplica(respuestas) };
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

/**
 * Los items de un checklist repartidos en sus grupos.
 *
 * El EPP del sistema anterior agrupaba los equipos por parte del cuerpo —cabeza,
 * cara, manos, oidos, vias respiratorias, pies— con una fila de cabecera por
 * grupo (`epp_categories`). Es la unica de las cuatro listas que se agrupa, y
 * no es decoracion: veinticinco items seguidos se recorren leyendo uno a uno,
 * y agrupados se recorren mirando la parte del cuerpo que interesa.
 *
 * LA AGRUPACION ES UNA VISTA SOBRE `items`, NO OTRA LISTA.
 *
 * `config.items` sigue siendo EL catalogo: es lo que se guarda en cada
 * respuesta, lo que alinea las columnas del PDF y contra lo que casa la
 * migracion. `config.groups` solo dice bajo que rotulo va cada uno. Asi las dos
 * cosas no pueden contradecirse: un item que alguien añada al catalogo y olvide
 * meter en un grupo aparece igual —al final, sin rotulo— en vez de desaparecer
 * de la pantalla, que es lo que pasaria si el grupo mandara.
 *
 * Sin `groups` devuelve un unico grupo sin nombre con todo dentro, que es
 * exactamente como se pintaba antes de que esto existiera.
 *
 * @param {string[]} items   catalogo del campo, en su orden
 * @param {Array}    groups  [{ name, items: [...] }]
 * @returns {Array<{name: string|null, items: string[]}>}
 */
export function agrupar(items, groups) {
    const todos = Array.isArray(items) ? items.map(String) : [];
    const declarados = Array.isArray(groups) ? groups : [];

    if (! declarados.length) return todos.length ? [{ name: null, items: todos }] : [];

    const disponibles = new Set(todos);
    const grupos = [];

    for (const grupo of declarados) {
        const suyos = (Array.isArray(grupo?.items) ? grupo.items : [])
            .map(String)
            .filter((x) => disponibles.has(x));

        // Un item declarado en dos grupos se queda en el primero: repetirlo
        // pediria dos respuestas para la misma casilla.
        suyos.forEach((x) => disponibles.delete(x));

        if (suyos.length) grupos.push({ name: String(grupo?.name ?? '') || null, items: suyos });
    }

    // Lo que no reclamo ningun grupo, al final y sin rotulo.
    const sueltos = todos.filter((x) => disponibles.has(x));

    if (sueltos.length) grupos.push({ name: null, items: sueltos });

    return grupos;
}
