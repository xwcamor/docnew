/**
 * Utilidades del eje Y de las tendencias (cromas y fiquis).
 *
 * Regla del proyecto: el eje se acota a los LÍMITES NORMATIVOS, no al dato
 * suelto. Sin esto el cuadro se estira y las franjas de límite quedan como una
 * astilla ilegible al pie (pedido del jefe, 2026-07-26).
 *
 * INVIOLABLE: el eje NUNCA recorta el dato. Si un valor supera todos los
 * límites, el tope sube hasta él — esconder una medición fuera de norma en una
 * app de diagnóstico sería un bug de seguridad, no un detalle estético.
 */

/**
 * Redondeo "bonito" del tope, conservando la magnitud del valor.
 *
 * OJO con los sub-unitarios: la acidez vive en 0.01–0.2 mg KOH/g. Un
 * `Math.ceil()` pelado los llevaba a 1 → el eje quedaba 10-20× por encima del
 * dato y la curva se veía plana contra el piso. Por eso el redondeo se hace
 * sobre la magnitud del número (≈2 cifras significativas), no sobre el entero.
 */
export function niceMax(v) {
    if (!Number.isFinite(v) || v <= 0) return 1;
    if (v >= 10) return Math.ceil(v);
    if (v >= 1) return clean(Math.ceil(v * 10) / 10);

    const mag = Math.pow(10, Math.floor(Math.log10(v)));
    return clean((Math.ceil((v / mag) * 10) / 10) * mag);
}

/**
 * Limpia el ruido de coma flotante del redondeo: 0.15 sale como
 * 0.15000000000000002 y echarts lo pintaría tal cual en la etiqueta del eje.
 */
function clean(v) {
    return parseFloat(v.toPrecision(12));
}

/**
 * LÍMITE DE NORMA de una serie: la frontera entre lo aceptable y lo que no.
 *
 * Se deduce de la banda "buena" (sev 0), sin asumir dirección:
 *  - si es la PRIMERA banda (menor = mejor: gases, acidez, agua) el límite es
 *    su `to` — el valor típico que no hay que pasar;
 *  - si es la ÚLTIMA (mayor = mejor: rigidez, tensión interfacial) el límite es
 *    su `from` — el piso por debajo del cual el aceite deja de cumplir.
 *
 * Coincide con el número que ya muestran la cabecera del PDF ("Máx 150") y la
 * tarjeta de resumen ("Límite: 47"), así que el gráfico no inventa un límite
 * propio: dice el mismo que el resto del sistema.
 */
export function normLimit(bands = []) {
    const list = (bands ?? []).filter((b) => b && b.sev !== undefined)
        .sort((a, b) => (a.from ?? 0) - (b.from ?? 0));
    if (!list.length) return null;

    const primera = list[0];
    const ultima = list[list.length - 1];

    // Parámetros de modo "limit" (rigidez D877, factor de potencia a 100 °C):
    // 3 bandas verde/ámbar/rojo donde el ÁMBAR es la tolerancia ±10% heredada
    // del sistema viejo — colorea, pero NO es norma. Ahí el límite normativo es
    // la frontera del ROJO, no el borde del verde: decir 27.5 kV en vez de 25
    // haría parecer fuera de norma muestras que IEEE C57.106 acepta.
    // Espejo de TransformerReportService::pdf() — si una cambia, cambiar la otra.
    const conTolerancia = list.length === 3 && Number(list[1].sev) === 0.5;

    let v = null;
    if (Number(primera.sev) === 0 && primera.to !== null && primera.to !== undefined) {
        v = conTolerancia ? ultima.from : primera.to;   // menor = mejor (Máx)
    } else if (Number(ultima.sev) === 0) {
        v = conTolerancia ? primera.to : ultima.from;   // mayor = mejor (Mín)
    }

    return v === null || v === undefined || !Number.isFinite(Number(v)) ? null : clean(Number(v));
}

/**
 * Tope del eje Y para una serie, dados sus valores y sus bandas de límite.
 *
 * Bandas: [{ from, to, sev }] — la última siempre trae `to: null` (abierta al
 * infinito), así que los cortes útiles son los `to` finitos. Sirve igual para
 * parámetros donde MENOR es mejor (gases, acidez) y donde MAYOR es mejor
 * (rigidez dieléctrica): se elige el primer corte POR ENCIMA del dato, sin
 * asumir dirección.
 *
 * @returns {{ max: number, edges: number[], crossed: number[] }}
 *   max     — tope del eje
 *   edges   — cortes finitos ordenados
 *   crossed — cortes que el dato YA superó (van como línea dentro del cuadro)
 */
export function axisFromLimits(values, bands = []) {
    const nums = (values ?? []).filter((v) => v !== null && v !== undefined && Number.isFinite(Number(v))).map(Number);
    const edges = (bands ?? [])
        .map((b) => b.to)
        .filter((v) => v !== null && v !== undefined && Number.isFinite(Number(v)))
        .map(Number)
        .sort((a, b) => a - b);

    if (!nums.length) {
        return { max: edges.length ? niceMax(edges[edges.length - 1]) : 1, edges, crossed: [] };
    }

    const dataMax = Math.max(...nums);
    // Primer límite por encima del dato: ése es el tope natural del cuadro.
    const above = edges.find((e) => e > dataMax);
    // Si el dato ya pasó TODOS los límites, el eje llega al dato (+5% de aire).
    // Antes era +20%: aire muerto que alargaba el cuadro sin aportar nada.
    let max = above !== undefined ? Math.max(above, dataMax) : dataMax * 1.05;

    // El LÍMITE DE NORMA siempre tiene que entrar en el cuadro. Importa en los
    // parámetros donde MAYOR es mejor (rigidez, tensión interfacial): ahí el
    // límite está POR ENCIMA del dato, y sin esto un aceite muy degradado
    // recortaba el eje antes de llegar al piso que debe superar.
    const norma = normLimit(bands);
    if (norma !== null) {
        max = Math.max(max, norma);
    }

    return { max: niceMax(max), edges, crossed: edges.filter((e) => e <= dataMax) };
}
