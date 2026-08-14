/**
 * Las listas configurables de un campo, leidas igual que en el servidor.
 *
 * ESTE ARCHIVO ES EL GEMELO DE `app/Support/TextoTraducible.php`,
 * `app/Support/Catalogo.php` y `app/Support/BandasDeRiesgo.php`.
 *
 * No es duplicacion por descuido: la pantalla tiene que pintar la casilla en el
 * mismo momento en que se toca, sin ir al servidor, y el servidor tiene que
 * contar las no conformidades y dibujar el PDF sin preguntarle a la pantalla. Lo
 * que NO puede pasar es que las dos digan cosas distintas —la casilla en rojo y
 * el contador en cero—, y eso lo vigila `EscalabilidadDelMotorTest`, que compara
 * las dos implementaciones caso por caso.
 *
 * LAS DOS FORMAS DE TODO LO QUE CONFIGURA EL CLIENTE
 * --------------------------------------------------
 *     "Conforme"                                       ← forma corta
 *     { value: "No conforme", tone: "bad",             ← forma larga
 *       label: { en: "Non-compliant" } }
 *
 * `value` es lo que se GUARDA y no se traduce jamas: es la clave de la respuesta
 * en la base y la que casa el PDF con su columna. `label` es lo unico que se
 * lee. `tone` es lo que decide el color, el simbolo y si cuenta como
 * observacion — y se declara en vez de adivinarse del castellano.
 */

/** Los tonos que puede declarar una respuesta. Los mismos que cuenta el servidor. */
export const TONOS = ['ok', 'bad', 'na'];

/** Los tonos que puede declarar una banda de riesgo: los `--state-*` de la hoja. */
export const TONOS_BANDA = ['bad', 'warn', 'ok', 'info', 'off'];

/**
 * Un texto del cliente en el idioma de quien mira.
 *
 * Nunca devuelve un hueco pudiendo devolver algo: el idioma pedido, y si no,
 * el primero que alguien haya escrito. Un rotulo en otro idioma dice
 * infinitamente mas que un blanco donde va el nombre de un equipo.
 */
export function textoTraducible(valor, locale = null) {
    if (typeof valor === 'string') return valor;
    if (typeof valor === 'number') return String(valor);

    // Una lista no es un mapa por idioma: devolver su primer elemento seria
    // inventarse un rotulo.
    if (! valor || typeof valor !== 'object' || Array.isArray(valor)) return '';

    const pedido = locale ? valor[locale] : null;

    if (typeof pedido === 'string' && pedido.trim() !== '') return pedido;

    for (const texto of Object.values(valor)) {
        if (typeof texto === 'string' && texto.trim() !== '') return texto;
    }

    return '';
}

/**
 * La lista en bruto, tolerando el atajo numerico.
 *
 * El seeder de demostracion guarda `severidades: 5` en vez de la lista de cinco.
 * La misma tolerancia existe en `Catalogo::crudas()`, y tienen que decir lo
 * mismo o el numero de columnas del PDF no cuadraria con el de la tablet.
 */
function crudas(crudo) {
    if (Array.isArray(crudo)) return crudo;

    const n = Number(crudo);

    return Number.isInteger(n) && n > 0 ? Array.from({ length: n }, (_, i) => String(i + 1)) : [];
}

/**
 * La lista normalizada: cada entrada con su valor, su rotulo y su tono.
 *
 * @returns {Array<{value: string, label: string, tone: string|null}>}
 */
export function entradasDeCatalogo(crudo, locale = null) {
    const salida = [];

    for (const entrada of crudas(crudo)) {
        if (entrada === null || entrada === undefined || typeof entrada === 'boolean') continue;

        if (typeof entrada !== 'object' || Array.isArray(entrada)) {
            const valor = String(entrada);

            salida.push({ value: valor, label: valor, tone: null });

            continue;
        }

        // Sin `value` no hay entrada: no se puede guardar una respuesta que no
        // tiene con que identificarse, y sacarla del rotulo la ataria al idioma
        // en que se configuro.
        const valor = entrada.value;

        if (valor === null || valor === undefined || valor === '' || typeof valor === 'object') continue;

        const clave  = String(valor);
        const rotulo = textoTraducible(entrada.label, locale);

        salida.push({
            value: clave,
            label: rotulo !== '' ? rotulo : clave,
            tone:  TONOS.includes(entrada.tone) ? entrada.tone : null,
        });
    }

    return salida;
}

/** Solo los valores: lo que se guarda y lo que casa con lo guardado. */
export function valoresDeCatalogo(crudo) {
    return entradasDeCatalogo(crudo).map((e) => e.value);
}

/**
 * El rotulo de un valor guardado.
 *
 * Una respuesta que ya no esta en el catalogo se sigue leyendo tal cual: pasa
 * cuando el formato cambia de version y hay entregas viejas, y borrar el texto
 * seria reescribir un documento firmado.
 */
export function etiquetaDeCatalogo(crudo, valor, locale = null) {
    const clave = valor === null || valor === undefined ? '' : String(valor);

    return entradasDeCatalogo(crudo, locale).find((e) => e.value === clave)?.label ?? clave;
}

/**
 * El tono que el catalogo DECLARA para un valor, o null si no dice nada.
 *
 * Null no es «neutro»: es «no lo dijo», y entonces decide la heuristica. Los dos
 * casos tienen que poder distinguirse — «no aplica» es un tono declarado y es
 * distinto de no haber declarado ninguno.
 */
export function tonoDeclarado(crudo, valor) {
    const clave = valor === null || valor === undefined ? '' : String(valor);

    return entradasDeCatalogo(crudo).find((e) => e.value === clave)?.tone ?? null;
}

// ── Bandas de la matriz de riesgo ───────────────────────────────────────────

/**
 * Las bandas de un `config`, normalizadas. Gemela de `BandasDeRiesgo::de()`.
 *
 * DOS COSAS QUE ESTABAN MAL Y AQUI YA NO:
 *
 *  1. La CLAVE de la banda se usaba de clave de traduccion y de clase CSS, asi
 *     que las bandas eran configurables siempre que se llamaran alto, medio y
 *     bajo. Ahora el rotulo y el tono son datos de la banda.
 *  2. El reparto era un `hasta` acumulado, que da por supuesto que el numero
 *     pequeño es el peor. Es verdad en la matriz de la v1 y es falso en la
 *     clasica de severidad × probabilidad, donde 25 es lo peor: ahi las bandas
 *     salian del reves y lo critico se pintaba de verde. Con `min`/`max` la
 *     direccion de la escala deja de importar.
 *
 * @param {Function} t  el traductor, para el respaldo `risk_matrix.level_*` de
 *                      las plantillas migradas, que no traen rotulo.
 */
export function bandasDeRiesgo(config, locale = null, t = null) {
    const crudo = config?.levels ?? config?.niveles ?? null;

    if (! Array.isArray(crudo) || ! crudo.length) return [];

    const bandas = [];
    // Forma vieja: una banda con solo `hasta` empieza donde acabo la anterior.
    let desde = 1;

    for (const cruda of crudo) {
        if (! cruda || typeof cruda !== 'object') continue;

        const clave = cruda.clave ?? cruda.key ?? null;
        const hasta = cruda.max ?? cruda.hasta ?? null;

        if (clave === null || clave === '' || ! Number.isFinite(Number(hasta))) continue;

        let max = Number(hasta);
        let min = Number.isFinite(Number(cruda.min)) ? Number(cruda.min) : desde;

        // Un rango escrito al reves se endereza: quien lo escribio queria ese
        // tramo, no un agujero.
        if (min > max) [min, max] = [max, min];

        bandas.push({
            clave:     String(clave),
            min,
            max,
            label:     textoTraducible(cruda.label, locale),
            tone:      TONOS_BANDA.includes(cruda.tone) ? cruda.tone : null,
            tolerable: cruda.tolerable === true,
        });

        desde = max + 1;
    }

    return conLosHuecosResueltos(bandas, t);
}

/**
 * Rellena rotulo y tono de las bandas que no los declaran.
 *
 * EL TONO, por posicion: la primera en rojo, la ultima en verde y las de en
 * medio en ambar. Sale de la convencion «se declaran de peor a mejor», que es
 * como vienen las cuatro plantillas migradas, y da exactamente alto/medio/bajo
 * para ellas. En cuanto una banda declara su `tone`, manda el suyo.
 *
 * EL ROTULO cae a la traduccion del producto para que las migradas se sigan
 * leyendo igual sin reescribirles la config, y a la clave pelada si tampoco
 * existe —que dice menos, pero dice.
 */
function conLosHuecosResueltos(bandas, t) {
    const ultima = bandas.length - 1;

    return bandas.map((banda, i) => {
        const tone = banda.tone ?? (
            ultima === 0 ? 'info' : i === 0 ? 'bad' : i === ultima ? 'ok' : 'warn'
        );

        let label = banda.label;

        if (label === '' && typeof t === 'function') {
            const clave = `field_work.risk_matrix.level_${banda.clave}`;
            const texto = t(clave);

            // `$t` devuelve la clave cuando no existe la traduccion.
            label = texto === clave ? banda.clave : texto;
        }

        return { ...banda, tone, label: label || banda.clave };
    });
}

/**
 * En que banda cae un valor. Gana la PRIMERA que lo contenga.
 *
 * Con rangos escritos a mano puede haber solapes, y en un documento de seguridad
 * es mejor una regla que siempre da el mismo resultado que una que intenta ser
 * lista. Un valor fuera de todas las bandas NO cae en la ultima: sale sin
 * evaluar, que es lo que es — meterlo en la ultima seria decir que un peligro es
 * tolerable sin que nadie lo haya dicho.
 */
export function bandaDeValor(valor, bandas = []) {
    const n = Number(valor);

    // El 0 y los negativos no son puntuaciones: son el hueco de una fila sin
    // evaluar, y de eso avisa el marcado de lo que falta.
    if (! Number.isFinite(n) || n <= 0) return null;

    return bandas.find((b) => n >= b.min && n <= b.max) ?? null;
}

/**
 * La banda que NO cuenta como observacion: la marcada con `tolerable`, y si
 * nadie la marca, la ultima —convencion de las cuatro plantillas migradas, que
 * van de peor a mejor.
 */
export function bandaTolerable(bandas = []) {
    return bandas.find((b) => b.tolerable)?.clave ?? bandas.at(-1)?.clave ?? null;
}

/**
 * El valor de la matriz para una severidad y una probabilidad.
 *
 * SALE DE LA TABLA, NO DE UNA MULTIPLICACION. La matriz de la v1 es un ranking
 * del 1 al 25 donde el 1 es lo peor, y doce de las veinticinco celdas caen en
 * otra banda si se multiplica: c2×p4 vale 12 en la tabla y 8 multiplicando, que
 * es la diferencia entre «medio» y «alto» en un documento de seguridad.
 *
 * El producto queda de red para un formato nuevo definido sin tabla, y por eso
 * el editor la pide: una matriz sin tabla es una matriz de otra empresa.
 */
export function valorDeLaMatriz(config, indiceSeveridad, indiceProbabilidad) {
    if (indiceSeveridad < 0 || indiceProbabilidad < 0) return null;

    const tabla = config?.matrix;

    if (Array.isArray(tabla) && Array.isArray(tabla[indiceSeveridad])) {
        return tabla[indiceSeveridad][indiceProbabilidad] ?? null;
    }

    return (indiceSeveridad + 1) * (indiceProbabilidad + 1);
}
