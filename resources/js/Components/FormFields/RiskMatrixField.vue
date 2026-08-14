<script setup>
/**
 * Matriz de riesgo (AST y PTF).
 *
 * Un AST no es una lista de peligros: es una lista de ACTIVIDADES y, dentro de
 * cada una, sus peligros. Asi esta el papel, asi esta la v1 —`f1_document_activities`
 * tiene muchos `f1_document_dangers`, y `_f1_document_activity_fields.html.erb`
 * pinta un bloque por actividad con su tabla de peligros dentro— y asi sale el
 * PDF (`show_pdf_page1.erb`: una fila por actividad con la lista de sus peligros).
 *
 * QUE ESTABA MAL: aqui se pintaba una lista PLANA de filas, cada una titulada
 * «Actividad → Peligro». La actividad se repetia en cada tarjeta —seis tarjetas
 * diciendo «Bobinado de baja tension fase V» seis veces— y la agrupacion, que es
 * la estructura del documento, no existia. Se colo porque el dato SI es plano:
 * cada peligro es una respuesta con su `row_index`, y se pinto la forma de
 * guardado en vez de la forma del documento.
 *
 * La agrupacion es SOLO DE PRESENTACION. El valor guardado no cambia: se agrupa
 * al pintar por el campo `actividad`, y renombrar una actividad reescribe ese
 * campo en las filas de su grupo. Hay 3 657 AST migrados con esta forma.
 *
 * Se agrupa por TRAMOS SEGUIDOS de la misma actividad, no por el texto suelto:
 * el orden de las filas es el orden del documento y dos actividades distintas
 * pueden llamarse igual. Reordenar al pintar cambiaria el AST sin que nadie lo
 * pidiera.
 *
 * TABLA EN ANCHO, TARJETAS EN ESTRECHO. Aqui decia «NO se vuelve a la tabla
 * ancha de la v1», y los peligros iban en tarjetas plegables con rejilla
 * dentro. El dueño del producto mando la captura de su v1 y pidio esa forma:
 * una tabla por actividad —Peligro, Riesgo, Control, Probabilidad, Severidad,
 * Nivel, papelera—, todas las filas a la vista, el «+» junto a la cabecera de
 * Peligro y «Eliminar actividad» arriba a la derecha. Es como su papel, y con
 * quince peligros la tabla larga es lo que el espera leer.
 *
 * El veto de entonces no era contra la tabla: era contra el scroll horizontal
 * en una tablet de 10" (docs/UI.md §3), que sigue vetado. Por eso el veto se
 * queda pero con su condicion escrita: la tabla manda cuando el CONTENEDOR da
 * el ancho (`UMBRAL_TABLA`, ver abajo), y por debajo el campo vuelve a las
 * tarjetas plegables de la iteracion anterior — mismo indice (`RowNavigator`)
 * y mismo plegado (`usePlegado`) que el EPP y el IHM, que SOLO viven en el
 * modo tarjetas: en la tabla todas las filas estan a la vista, como en la
 * captura. Se conmuta por ancho del contenedor y no de la ventana porque el
 * campo vive dentro de tarjetas y consolas con rellenos propios, y lo que
 * decide si la tabla cabe es el hueco real, no la pantalla.
 *
 * ORDEN DE LAS COLUMNAS, el de la v1: peligro, riesgo, control, probabilidad,
 * severidad, nivel. Aqui la probabilidad iba DESPUES de la severidad; en la
 * tabla vieja va antes, y el AST se rellena leyendo de izquierda a derecha.
 *
 * FORMA DEL VALOR que emite (una respuesta por peligro, con su `row`):
 *
 *   { actividad, peligro, riesgo, control, severidad, probabilidad,
 *     valor_riesgo, nivel }
 *
 * `riesgo` es TEXTO: la consecuencia («Agotamiento de recurso natural»), que en
 * la v1 es la columna `name_risk` y va entre el peligro y el control. El numero
 * de la matriz es `valor_riesgo`.
 *
 * Aqui se llamaba `riesgo` al numero, y como la migracion —que sigue el nombre
 * del dominio— escribia el texto en `riesgo` y el numero en `valor_riesgo`, los
 * 3 657 AST migrados salian con la consecuencia donde va el numero y sin banda
 * de color. Manda el nombre del dominio, que ademas es el de la v1.
 *
 * `severidad` y `probabilidad` son obligatorias porque es lo que exige
 * FormSubmissionService::validarValor() para este tipo. El resto acompaña.
 *
 * LO QUE SE GUARDA ES LA CLAVE, LO QUE SE ENSEÑA ES SU NOMBRE. Las severidades
 * y probabilidades del catalogo son claves internas (c1..c5, p1..p5): es lo que
 * guardan las 3 657 respuestas migradas y lo que indexa `config.matrix` por
 * posicion, y NO cambia jamas. Pero la v1 nunca enseño eso: en pantalla ponia
 * la traduccion del administrador («Catastrofico», «Podria suceder»), y este
 * campo enseñaba la clave pelada. La config puede traer los mapas
 * `severity_labels` / `probability_labels` (y `_en` para el ingles): aqui se
 * pinta `labels[valor] ?? valor` —en selects, chips y solo lectura— y el valor
 * emitido sigue siendo la clave. Si el mapa no llega, se cae al nombre interno,
 * que es lo que habia.
 *
 * LA REGLA DEL PELIGRO ENTERO SE DICE AQUI, ANTES DE GUARDAR. El servidor
 * rechaza una fila empezada a puntuar y sin terminar (`exigirPeligroEntero`:
 * con severidad o probabilidad puestas, las cinco casillas van o no va
 * ninguna), pero el usuario lo descubria recien en el aviso del guardado,
 * lejos de la fila del problema. Ahora la misma regla —calcada, no otra— se
 * evalua fila a fila al pintar: en la tabla, cada celda con hueco sale teñida
 * de rojo con la palabra «Falta» y la columna Nivel dice «Incompleto»; en las
 * tarjetas, la fila a medias sale en rojo en su cabecera y en el indice, y
 * dentro se nombran las columnas que faltan. El servidor sigue siendo el juez;
 * esto es el espejo que avisa antes.
 *
 * Y cuando un guardado dejo el campo entero pendiente, FormFill lo dice con la
 * prop `faltante` (el contrato de esa pantalla con los cuatro compuestos): el
 * campo repite el aviso en rojo y con la cuenta de lo que falta, en su sitio.
 */
import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Button, Popconfirm, Select } from 'ant-design-vue';
import { DeleteOutlined, DownOutlined, PlusOutlined, RightOutlined } from '@ant-design/icons-vue';
import CatalogSelect from './CatalogSelect.vue';
import RowNavigator from './RowNavigator.vue';
import { usePlegado } from './plegado';
import { catalogo } from './respuestas';
import { bandaDeValor, bandasDeRiesgo, bandaTolerable, valorDeLaMatriz } from '@/Support/catalogo';
import { useI18n } from '@/Plugins/i18n';

const props = defineProps({
    field:    { type: Object, required: true },
    value:    { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
    /** El servidor dijo que este campo sigue faltando tras intentar guardar. */
    faltante: { type: Boolean, default: false },
});

const emit = defineEmits(['update:value']);

const { t } = useI18n();
const pagina = usePage();

const config = computed(() => props.field?.config ?? {});
const actividades = computed(() => catalogo(config.value, 'activities'));
const peligros = computed(() => catalogo(config.value, 'dangers'));
const riesgos = computed(() => catalogo(config.value, 'risks'));
const controles = computed(() => catalogo(config.value, 'controls'));
const severidades = computed(() => catalogo(config.value, 'severities', 'severidades'));
const probabilidades = computed(() => catalogo(config.value, 'probabilities', 'probabilidades'));

/**
 * Los nombres con los que se ENSEÑAN las claves internas (c1..c5, p1..p5).
 *
 * `docufiz:migrate-formats` trae de la base vieja los mapas `severity_labels`
 * y `probability_labels` (es) y sus `_en`: son las traducciones que el
 * administrador escribio y que la v1 pintaba con `I18n.t`. El es va de base y
 * el en encima cuando ese es el idioma de quien mira: una clave que el
 * administrador no tradujo al ingles cae al texto en es, que sigue diciendo
 * mas que un «c3» pelado. Y si el mapa no vino —formato nuevo, volcado sin
 * `translations`— `labels[valor] ?? valor` se cae a la clave, como hasta ahora.
 *
 * SOLO presentacion: lo que se emite y se guarda es siempre la clave interna.
 */
function rotulos(base) {
    const es = config.value[base] ?? {};
    const en = pagina.props.locale === 'en' ? (config.value[`${base}_en`] ?? {}) : {};

    return { ...es, ...en };
}

const rotulosSeveridad = computed(() => rotulos('severity_labels'));
const rotulosProbabilidad = computed(() => rotulos('probability_labels'));

const rotuloSeveridad = (v) => rotulosSeveridad.value[v] ?? v;
const rotuloProbabilidad = (v) => rotulosProbabilidad.value[v] ?? v;

/** Para los selects de la tabla: se elige por nombre, se emite la clave. */
const opcionesSeveridad = computed(
    () => severidades.value.map((s) => ({ value: s, label: rotuloSeveridad(s) })),
);
const opcionesProbabilidad = computed(
    () => probabilidades.value.map((p) => ({ value: p, label: rotuloProbabilidad(p) })),
);

const filas = computed(() => (Array.isArray(props.value) ? props.value : []));

const prefijo = `riesgo-${props.field?.id ?? 'x'}`;

/**
 * El plegado y el indice van por ACTIVIDAD, no por peligro.
 *
 * Antes la unidad era el peligro: una pastilla por peligro en el indice y una
 * tarjeta plegable por peligro dentro. Eso no es lo que es un AST. En el EPP la
 * unidad es el trabajador y en el IHM la herramienta —el bloque gordo que se
 * abre y se cierra— y aqui el equivalente es la actividad, con sus peligros
 * dentro. Con la unidad en el peligro, el indice de un AST de tres actividades
 * y quince peligros eran quince pastillas sin decir a que actividad iba cada
 * una, que es justo lo que se pregunta al abrir el documento.
 *
 * Y ahora vive en LOS DOS modos. El indice solo salia en tarjetas «porque la
 * tabla ya enseña todas las filas»: cierto de las filas de UNA actividad, pero
 * en ver plan, en un monitor, el AST salia con sus tres tablas enteras
 * desplegadas y sin un indice donde saltar — precisamente el caso donde mas
 * larga es la pagina.
 *
 * `abierta` guarda el indice del grupo. Se cierra al reordenar? No hace falta:
 * los grupos son tramos contiguos y su posicion solo cambia al añadir o quitar
 * una actividad, que ya llaman a `cerrar()`.
 */
const { todas, idFila, estaAbierta, abierta, abrir, alternar, alternarTodo, cerrar } =
    usePlegado(prefijo);

const idActividad = (g) => idFila(g);

/**
 * Con una sola actividad no se pliega nada.
 *
 * Plegar existe para no tener seis bloques iguales apilados; con uno solo, lo
 * unico que consigue es esconder el formulario entero detras de un clic que
 * nadie entiende por que hay que dar.
 */
const actividadAbierta = (g) => grupos.value.length < 2 || estaAbierta(g);

/**
 * TABLA O TARJETAS: lo decide el ancho del CONTENEDOR, no el de la ventana.
 *
 * El campo vive dentro de una tarjeta, dentro de una consola, al lado de un
 * menu lateral que se colapsa: a la misma ventana le quedan huecos distintos.
 * Se mide el propio campo con un ResizeObserver y por encima del umbral se
 * pinta la tabla de la captura del dueño; por debajo, las tarjetas plegables
 * (docs/UI.md §3: nada de scroll horizontal — la tabla que no cabe no se
 * encoge hasta lo ilegible, se convierte).
 *
 * El umbral es lo que la tabla necesita para ser usable: siete columnas con
 * desplegables de catalogo dentro. Medido en el navegador: con 6 columnas de
 * contenido + papelera, por debajo de ~700px los desplegables quedan en menos
 * de 100px y no se lee ni la primera palabra del peligro.
 */
const UMBRAL_TABLA = 700;

const raiz = ref(null);
const anchoCampo = ref(0);
const modoTabla = computed(() => anchoCampo.value >= UMBRAL_TABLA);

let observador = null;

/**
 * El menu lateral se colapsa al entrar, como invita el propio AppLayout
 * («paginas con mucho contenido pueden colapsar el sidebar al entrar y
 * restaurarlo al salir», via `provide('sidebarCollapsed')`).
 *
 * No es capricho: en una tablet en horizontal (1024px) el menu abierto se come
 * 240px y al campo le quedan ~680 — EXACTAMENTE lo mismo que le queda en la
 * tablet en vertical (768px) sin menu. Con el menu abierto, la tabla que el
 * dueño pidio para su tablet en horizontal no cabria nunca; colapsado quedan
 * ~856px y cabe con holgura. Quien lo reabra a mano, manda: solo se colapsa
 * una vez al montar, y al salir se restaura como estaba.
 */
const barraLateral = inject('sidebarCollapsed', null);
let barraEstaba = null;

onMounted(() => {
    if (barraLateral && typeof barraLateral.value === 'boolean' && ! barraLateral.value) {
        barraEstaba = barraLateral.value;
        barraLateral.value = true;
    }

    anchoCampo.value = raiz.value?.getBoundingClientRect().width ?? 0;

    if (typeof ResizeObserver !== 'undefined' && raiz.value) {
        observador = new ResizeObserver((entradas) => {
            anchoCampo.value = entradas[0]?.contentRect?.width ?? anchoCampo.value;
        });
        observador.observe(raiz.value);
    }
});

onBeforeUnmount(() => {
    observador?.disconnect();

    if (barraLateral && barraEstaba === false) {
        barraLateral.value = false;
    }
});

/**
 * El riesgo sale de la tabla, no de una multiplicacion.
 *
 * La matriz del sistema anterior es un ranking del 1 al 25 donde el 1 es lo
 * peor (c1 = mas grave, p1 = mas probable). No es severidad × probabilidad:
 * doce de las veinticinco celdas caen en otra banda si se multiplica. c2×p4
 * vale 12 en la tabla y 8 multiplicando, que es la diferencia entre «medio» y
 * «alto» en un documento de seguridad. `docufiz:migrate-formats` copia la tabla
 * real a `config.matrix`.
 *
 * El producto queda solo de red, para un formato nuevo que se defina sin matriz.
 */
function valorRiesgo(fila) {
    return valorDeLaMatriz(
        config.value,
        severidades.value.indexOf(fila?.severidad),
        probabilidades.value.indexOf(fila?.probabilidad),
    );
}

/**
 * Las bandas de la plantilla, con su rango, su rótulo y su color.
 *
 * DOS COSAS QUE ESTABAN MAL Y AQUÍ YA NO. La clave de la banda se usaba de clave
 * de traducción (`risk_matrix.level_alto`) y de clase CSS (`.is-alto`), así que
 * las bandas eran configurables **siempre que se llamaran alto, medio y bajo**;
 * y el reparto era un `hasta` acumulado, que da por supuesto que el número
 * pequeño es el peor —cierto en la matriz de la v1, falso en la clásica de
 * severidad × probabilidad, donde 25 es lo peor y las bandas salían del revés.
 * Ver `Support/catalogo.js`, que es la gemela de `App\Support\BandasDeRiesgo`.
 */
const bandas = computed(() => bandasDeRiesgo(config.value, pagina.props.locale, t));

/**
 * Sin bandas declaradas se reparte en tercios, como se hacía antes: es un
 * formato nuevo al que nadie le configuró la matriz todavía.
 */
const bandasEfectivas = computed(() => {
    if (bandas.value.length) return bandas.value;

    const maximo = severidades.value.length * probabilidades.value.length;

    if (! maximo) return [];

    const tercio = Math.ceil(maximo / 3);

    return bandasDeRiesgo({ levels: [
        { clave: 'alto',  hasta: tercio },
        { clave: 'medio', hasta: Math.ceil((maximo * 2) / 3) },
        { clave: 'bajo',  hasta: maximo },
    ] }, pagina.props.locale, t);
});

/** En qué banda cae un valor. Devuelve la CLAVE, que es lo que se guarda. */
function nivelRiesgo(valor) {
    return bandaDeValor(valor, bandasEfectivas.value)?.clave ?? null;
}

/** El rótulo con el que se lee una banda: de la plantilla, no de una clave nuestra. */
function rotuloNivel(clave) {
    return bandasEfectivas.value.find((b) => b.clave === clave)?.label ?? clave;
}

/** Y su color, de la lista corta de tonos del sistema. */
function tonoNivel(clave) {
    return bandasEfectivas.value.find((b) => b.clave === clave)?.tone ?? 'off';
}

/** El riesgo se recalcula en la fila cada vez que cambia, no se pide aparte. */
function conRiesgo(fila) {
    const riesgo = valorRiesgo(fila);

    return { ...fila, valor_riesgo: riesgo, nivel: nivelRiesgo(riesgo) };
}

function filaVacia(actividad = null) {
    return {
        actividad, peligro: null, riesgo: null, control: null,
        severidad: null, probabilidad: null, valor_riesgo: null, nivel: null,
    };
}

function cambiar(indice, clave, valor) {
    emit('update:value', filas.value.map(
        (fila, i) => (i === indice ? conRiesgo({ ...fila, [clave]: valor }) : fila),
    ));
}

/**
 * Las actividades del documento, cada una con las posiciones de sus peligros.
 *
 * Un tramo se corta cuando cambia el texto de `actividad`, asi que el grupo es
 * un trozo contiguo del array y las posiciones que guarda son las de verdad:
 * las que viajan como `row` a `form_answers`. Nada de esto se guarda.
 */
const grupos = computed(() => {
    const lista = [];

    filas.value.forEach((fila, i) => {
        const nombre = fila?.actividad ?? null;
        const ultimo = lista.at(-1);

        if (ultimo && ultimo.actividad === nombre) {
            ultimo.indices.push(i);

            return;
        }

        lista.push({ actividad: nombre, desde: i, indices: [i] });
    });

    return lista;
});

/** Renombrar la actividad la renombra en TODAS sus filas: el dato sigue plano. */
function renombrarActividad(grupo, valor) {
    const dentro = new Set(grupo.indices);

    emit('update:value', filas.value.map(
        (fila, i) => (dentro.has(i) ? { ...fila, actividad: valor } : fila),
    ));
}

/**
 * El peligro nuevo entra DETRAS del ultimo de su actividad, no al final de la
 * lista: si entrara al final rompería el tramo y la actividad saldria partida
 * en dos bloques con el mismo nombre.
 *
 * En la tabla el «+» de la cabecera de Peligro llama aqui con SU grupo, como
 * el `link_to_add_association` de la v1, que insertaba en el tbody de su
 * actividad.
 *
 * No se toca el plegado: la actividad ya esta abierta —se pulso su «+»— y lo
 * unico que hace falta es llevar la vista a la fila nueva, que con quince
 * peligros puede estar fuera de pantalla. Antes esto llamaba a `abrir()` con la
 * posicion del peligro, que desde que la unidad es la actividad abriria la
 * actividad numero 15.
 */
function agregarPeligro(grupo) {
    const posicion = grupo.indices.at(-1) + 1;
    const nuevas = [...filas.value];

    nuevas.splice(posicion, 0, filaVacia(grupo.actividad));

    emit('update:value', nuevas);

    nextTick().then(() => {
        document.getElementById(idFila(`p${posicion}`))
            ?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    });
}

/**
 * Una actividad nueva es una fila con la actividad sin poner y sin peligro.
 *
 * Es exactamente lo que guarda la v1 —`F1DocumentActivity` sin `f1_document_dangers`—
 * y lo que trae la migracion para las actividades sin peligros, asi que no hay
 * una forma nueva de dato: es la fila de siempre a medio llenar.
 */
function agregarActividad() {
    emit('update:value', [...filas.value, filaVacia()]);

    // Abre la actividad nueva y lleva la vista a su cabecera, que es donde esta
    // lo primero que hay que poner: su nombre. Va en `nextTick` porque el grupo
    // todavia no existe —`grupos` se recalcula del valor que acaba de emitirse—
    // y `abrir()` con el indice equivocado abriria otra.
    nextTick().then(() => abrir(grupos.value.length - 1));
}

/** Se pliega todo al borrar: la fila abierta se guarda por posicion y al
 *  quitar la 2 de cuatro la que era 3 pasa a ser 2. Ver el IHM. */
function quitar(indice) {
    emit('update:value', filas.value.filter((_, i) => i !== indice));
    cerrar();
}

/**
 * Quitar la actividad se lleva sus peligros por delante, como el
 * `link_to_remove_association` de la v1 sobre `f1_document_activities`. Por eso
 * pregunta y por eso dice cuantos peligros se va a llevar: es lo unico de esta
 * pantalla que borra mas de lo que se ve en el boton.
 */
function quitarActividad(grupo) {
    const dentro = new Set(grupo.indices);

    emit('update:value', filas.value.filter((_, i) => ! dentro.has(i)));
    cerrar();
}

/**
 * La banda de una fila, tambien cuando la fila no la trae escrita.
 *
 * Es el caso de TODO lo migrado. La v1 guardaba `risk_value` y calculaba la
 * banda al pintar (`Risk#level_name`), asi que las filas que trajo la migracion
 * tienen el numero y no la palabra: salian «Sin evaluar» las ocho de ocho, con
 * el resumen en «0 de 8 peligros evaluados». En un documento de seguridad eso
 * no es un detalle de pantalla — dice que nadie evaluo los peligros de esa
 * jornada, y era mentira.
 *
 * Se deduce al leer y no se guarda: el nivel es un derivado del valor y de las
 * bandas de la plantilla, y guardarlo aparte es tener dos verdades.
 */
const nivelDe = (fila) => fila?.nivel || nivelRiesgo(fila?.valor_riesgo);

/**
 * El espejo de `FormSubmissionService::exigirPeligroEntero()`, columna a
 * columna: en cuanto la fila se empieza a puntuar (severidad o probabilidad),
 * las cinco casillas van juntas. Devuelve las claves que faltan, en el orden
 * de la tabla de la v1, para poder nombrarlas junto a la fila.
 *
 * Una fila sin tocar devuelve vacio a proposito: la actividad sin peligros es
 * una fila legitima (la v1 las guardaba asi) y pintarla de rojo seria acusar
 * a quien no ha hecho nada mal.
 */
const CLAVES_PELIGRO = ['peligro', 'riesgo', 'control', 'probabilidad', 'severidad'];

/** El nombre de cada columna en `resources/lang`, como en el servidor. */
const ROTULO_COLUMNA = {
    peligro: 'danger', riesgo: 'risk', control: 'control',
    probabilidad: 'probability', severidad: 'severity',
};

const lleno = (v) => v !== null && v !== undefined && String(v).trim() !== '';

function faltasDe(fila) {
    if (! lleno(fila?.severidad) && ! lleno(fila?.probabilidad)) return [];

    return CLAVES_PELIGRO.filter((clave) => ! lleno(fila?.[clave]));
}

const faltaCelda = (fila, clave) => faltasDe(fila).includes(clave);

const nombresFaltas = (fila) => faltasDe(fila)
    .map((clave) => t(`field_work.risk_matrix.${ROTULO_COLUMNA[clave]}`))
    .join(', ');

/**
 * Cuantos peligros le impiden al campo darse por respondido: los sin evaluar
 * y los evaluados a medias. Es la cuenta del aviso de `faltante`, y se apaga
 * sola —el aviso con ella— cuando el usuario los termina, aunque el servidor
 * todavia no se haya enterado.
 */
const pendientes = computed(() => filas.value.filter(
    (fila) => faltasDe(fila).length || ! nivelDe(fila),
).length);

/**
 * La banda PEOR de una actividad, que es lo que se lee de un vistazo: un AST se
 * abre para saber que actividad trae el peligro alto, no para contar peligros.
 *
 * El orden lo dan las bandas de la plantilla, que vienen de peor a mejor
 * (`config.levels`); no se escribe aqui una segunda lista de niveles.
 */
const ordenNiveles = computed(() => bandasEfectivas.value.map((b) => b.clave));

function nivelPeor(grupo) {
    let peor = null;
    let posicion = Infinity;

    for (const i of grupo.indices) {
        const nivel = nivelDe(filas.value[i]);

        if (! nivel) continue;

        const suya = ordenNiveles.value.indexOf(nivel);
        const orden = suya === -1 ? ordenNiveles.value.length : suya;

        if (orden < posicion) {
            posicion = orden;
            peor = nivel;
        }
    }

    return peor;
}

/** Cuantos peligros de la actividad estan evaluados, para su cabecera. */
const evaluadasDe = (grupo) => grupo.indices.filter((i) => nivelDe(filas.value[i])).length;

/**
 * Dentro de la tarjeta manda el peligro a secas: la actividad ya esta escrita
 * una vez en la cabecera del grupo, y repetirla en cada tarjeta era justamente
 * lo que se venia a arreglar.
 */
const tituloPeligro = (fila, i) => fila?.peligro || t('field_work.progress.hazard', { n: i + 1 });

/**
 * El estado de una actividad entera, que es lo que dice su pastilla.
 *
 * Manda lo peor, en este orden: una fila a medias bloquea el guardado, asi que
 * gana sobre cualquier otra cosa. Despues, lo mismo que en el EPP y el IHM:
 * completa, a medias con la cuenta, o sin empezar.
 */
function estadoActividad(grupo) {
    const suyas = grupo.indices.map((i) => filas.value[i]);

    if (suyas.some((fila) => faltasDe(fila).length)) {
        return { clave: 'bad', texto: t('field_work.risk_matrix.incomplete') };
    }

    const evaluados = suyas.filter((fila) => nivelDe(fila)).length;

    if (evaluados === suyas.length && suyas.length > 0) {
        return { clave: 'ok', texto: t('field_work.progress.complete') };
    }

    return evaluados === 0
        ? { clave: 'off', texto: t('field_work.progress.not_started') }
        : { clave: 'warn', texto: t('field_work.progress.missing', { n: suyas.length - evaluados }) };
}

/**
 * El titulo de la pastilla es el nombre de la actividad.
 *
 * Una recien creada todavia no tiene ninguno, y ahi se numera: «Actividad 2»
 * es peor que un hueco, porque un hueco no se puede pulsar con confianza.
 */
const tituloActividad = (grupo, g) => grupo.actividad
    || t('field_work.risk_matrix.activity_n', { n: g + 1 });

const resumenActividades = computed(() => grupos.value.map((grupo, g) => {
    const estado = estadoActividad(grupo);

    return { key: g, label: tituloActividad(grupo, g), state: estado.clave, stateText: estado.texto };
}));

/** Cuantas actividades estan enteras, para el titular del indice. */
const actividadesCompletas = computed(
    () => grupos.value.filter((grupo) => estadoActividad(grupo).clave === 'ok').length,
);

const evaluadas = computed(() => filas.value.filter((f) => nivelDe(f)).length);
const porNivel = (nivel) => filas.value.filter((f) => nivelDe(f) === nivel).length;
</script>

<template>
    <div ref="raiz" class="ff-field">
        <!-- El guardado dejo el campo pendiente: se dice AQUI, en rojo y con
             la cuenta de lo concreto que falta, no solo en el aviso de arriba
             que se pierde al scrollear. Se apaga solo cuando ya no queda nada
             que señalar: un «obligatorio» sobre un campo ya completo mentiria. -->
        <p v-if="faltante && !readonly && (pendientes || !filas.length)" class="ff-missing" role="alert">
            {{ $tc('field_work.progress.required_hazards', filas.length ? pendientes : 0) }}
        </p>

        <p v-if="!filas.length" class="ff-empty" :class="{ 'is-missing': faltante && !readonly }">
            {{ $t('field_work.risk_matrix.empty') }}
        </p>

        <!-- El indice, una pastilla por ACTIVIDAD y en los dos modos.
             Decia «es SOLO del modo tarjetas: la tabla ya enseña todas las
             filas a la vez». Eso vale dentro de UNA actividad; entre
             actividades no: en ver plan, en un monitor, un AST de tres
             actividades salia con sus tres tablas enteras desplegadas, que es
             la pagina mas larga de todas, y sin un indice donde saltar. -->
        <RowNavigator
            v-if="grupos.length > 1"
            :rows="resumenActividades"
            :active="todas ? null : abierta"
            :summary="$t('field_work.progress.activities_done', {
                done: actividadesCompletas, total: grupos.length,
            })"
            :detail="$t('field_work.progress.hazards_rated', { done: evaluadas, total: filas.length })"
            :ratio="grupos.length ? actividadesCompletas / grupos.length : 0"
            :all-open="todas"
            :label="$t('field_work.progress.index_activities')"
            @select="abrir"
            @toggle-all="alternarTodo"
        />

        <!-- Un bloque por actividad, y dentro sus peligros: en tabla si el
             contenedor da el ancho, en tarjetas plegables si no. La cabecera
             del bloque es la de la captura del dueño: «Actividad N» a la
             izquierda, «Quitar esta actividad» en rojo a la derecha, y el
             campo Actividad a lo ancho. No es un boton de plegado: lleva
             dentro el selector, y la actividad es el titulo de lo que hay
             debajo, no una fila mas. -->
        <section
            v-for="(grupo, g) in grupos"
            :id="idActividad(g)"
            :key="grupo.desde"
            class="ff-group"
        >
            <!-- La franja de color es UNA fila, como la pidio el dueño:
                 «Actividad N», el avance y el nivel a la izquierda, «Quitar
                 esta actividad» a la derecha. -->
            <header class="ff-group__head">
                <!-- La franja abre y cierra la actividad, como la cabecera de
                     un trabajador en el EPP. Todo lo que se lee sin abrir va
                     DENTRO del boton: el numero, el nombre, el avance y la
                     banda peor. Con una sola actividad no se pliega y el boton
                     no existe: esconder el formulario entero detras de un clic
                     que no evita ningun scroll seria solo un estorbo. -->
                <component
                    :is="grupos.length > 1 ? 'button' : 'div'"
                    :type="grupos.length > 1 ? 'button' : null"
                    class="ff-group__toggle"
                    :aria-expanded="grupos.length > 1 ? actividadAbierta(g) : null"
                    :aria-controls="grupos.length > 1 ? `${idActividad(g)}-cuerpo` : null"
                    @click="grupos.length > 1 && alternar(g)"
                >
                    <component
                        v-if="grupos.length > 1"
                        :is="actividadAbierta(g) ? DownOutlined : RightOutlined"
                        class="ff-row__chev"
                    />

                    <span class="ff-group__tag">
                        {{ $t('field_work.risk_matrix.activity_n', { n: g + 1 }) }}
                    </span>

                    <!-- El nombre, que es lo que distingue una actividad de
                         otra cuando estan todas plegadas. Dentro se vuelve a
                         escribir, pero ahi es el campo que se edita. -->
                    <span v-if="grupo.actividad" class="ff-group__nombre">{{ grupo.actividad }}</span>

                    <span class="ff-count">
                        {{ $t('field_work.progress.hazards_rated', {
                            done: evaluadasDe(grupo), total: grupo.indices.length,
                        }) }}
                    </span>

                    <!-- Color y PALABRA, nunca solo color (docs/UI.md §5). -->
                    <span v-if="nivelPeor(grupo)" class="ff-risk" :class="`is-${tonoNivel(nivelPeor(grupo))}`">
                        {{ rotuloNivel(nivelPeor(grupo)) }}
                    </span>
                    <span v-else class="ff-risk is-none">
                        {{ $t('field_work.risk_matrix.no_risk') }}
                    </span>
                </component>

                <!-- En la captura de la v1 el boton rojo va aqui, arriba a
                     la derecha del bloque — no en el pie, donde estaba. Es
                     lo unico que borra mas de lo que se ve, asi que
                     pregunta con la cuenta. -->
                <Popconfirm
                    v-if="!readonly"
                    :title="$tc('field_work.risk_matrix.remove_activity_confirm', grupo.indices.length)"
                    :ok-text="$t('field_work.risk_matrix.remove_activity')"
                    :cancel-text="$t('global.cancel')"
                    @confirm="quitarActividad(grupo)"
                >
                    <Button class="ff-group__del" danger>
                        <template #icon><DeleteOutlined /></template>
                        {{ $t('field_work.risk_matrix.remove_activity') }}
                    </Button>
                </Popconfirm>
            </header>

            <!-- El cuerpo de la actividad: su nombre y sus peligros. Se
                 pliega entero, que es la unidad que el EPP y el IHM ya
                 usaban con el trabajador y la herramienta. Es `v-if` y no
                 `v-show` a proposito: la tabla de una actividad son siete
                 columnas de desplegables de catalogo por fila, y dejar
                 tres actividades montadas en el DOM para no verlas es lo
                 que hacia pesada la pantalla. El valor no vive aqui —esta
                 en FormFill—, asi que desmontar no pierde nada. -->
            <div v-if="actividadAbierta(g)" :id="`${idActividad(g)}-cuerpo`">
            <!-- El nombre de la actividad, DEBAJO de la franja y con la
                 etiqueta EN LA MISMA FILA que el texto (tambien peticion del
                 dueño: etiqueta arriba y textarea abajo gastaba una linea).
                 En angosto la fila parte sola y vuelven a quedar una encima
                 de la otra. -->
            <div class="ff-group__name" :class="{ 'is-readonly': readonly }">
                <label class="ff-label">
                    {{ $t('field_work.risk_matrix.activity') }}<span class="ff-block__req"> *</span>
                </label>
                <div class="ff-group__texto">
                    <CatalogSelect
                        :value="grupo.actividad" :options="actividades" :readonly="readonly"
                        :placeholder="$t('field_work.risk_matrix.search')"
                        @update:value="renombrarActividad(grupo, $event)" />

                    <p v-if="!readonly && !grupo.actividad" class="ff-cardhint">
                        {{ $t('field_work.risk_matrix.activity_hint') }}
                    </p>
                </div>
            </div>

            <!-- MODO TABLA: la de la captura, una fila por peligro y TODAS a
                 la vista. El «+» azul de la cabecera de Peligro añade fila a
                 ESTA actividad (como el link_to_add_association de la v1) y la
                 papelera de cada fila pregunta antes. Sin plegado: un AST de
                 quince peligros da una tabla larga, y esta bien — es como su
                 papel. -->
            <div v-if="modoTabla" class="ff-tabla-caja">
                <table class="ff-tabla">
                    <thead>
                        <tr>
                            <th scope="col" class="ff-tabla__th ff-tabla__th--texto">
                                <span class="ff-tabla__cab">
                                    <span>{{ $t('field_work.risk_matrix.danger') }}<span class="ff-block__req"> *</span></span>
                                    <Button
                                        v-if="!readonly"
                                        type="primary"
                                        class="ff-tabla__add"
                                        :title="$t('field_work.risk_matrix.add_row')"
                                        :aria-label="$t('field_work.risk_matrix.add_row')"
                                        @click="agregarPeligro(grupo)"
                                    >
                                        <template #icon><PlusOutlined /></template>
                                    </Button>
                                </span>
                            </th>
                            <th scope="col" class="ff-tabla__th ff-tabla__th--texto">
                                {{ $t('field_work.risk_matrix.risk') }}<span class="ff-block__req"> *</span>
                            </th>
                            <th scope="col" class="ff-tabla__th ff-tabla__th--texto">
                                {{ $t('field_work.risk_matrix.control') }}<span class="ff-block__req"> *</span>
                            </th>
                            <th scope="col" class="ff-tabla__th ff-tabla__th--corta">
                                {{ $t('field_work.risk_matrix.probability') }}<span class="ff-block__req"> *</span>
                            </th>
                            <th scope="col" class="ff-tabla__th ff-tabla__th--corta">
                                {{ $t('field_work.risk_matrix.severity') }}<span class="ff-block__req"> *</span>
                            </th>
                            <th scope="col" class="ff-tabla__th ff-tabla__th--nivel">
                                {{ $t('field_work.risk_matrix.level') }}<span class="ff-block__req"> *</span>
                            </th>
                            <!-- Sin titulo, como en la v1: la papelera se explica sola.
                                 El nombre queda para el lector de pantalla. -->
                            <th
                                v-if="!readonly" scope="col"
                                class="ff-tabla__th ff-tabla__th--acciones"
                                :aria-label="$t('global.actions')"
                            ></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- `p${i}` y no `i` a secas: desde que el plegado va
                             por actividad, `idFila(0)` es el ancla de la
                             primera actividad. Sin el prefijo, la fila y su
                             actividad compartian id y el salto del indice
                             aterrizaba en la fila equivocada. -->
                        <tr
                            v-for="i in grupo.indices"
                            :id="idFila(`p${i}`)"
                            :key="i"
                        >
                            <td class="ff-tabla__td" :class="{ 'is-missing': faltaCelda(filas[i], 'peligro') }">
                                <CatalogSelect
                                    :value="filas[i].peligro" :options="peligros" :readonly="readonly"
                                    :placeholder="$t('field_work.risk_matrix.search')"
                                    @update:value="cambiar(i, 'peligro', $event)" />
                                <!-- La celda con hueco lleva su palabra: el rojo
                                     nunca va solo (docs/UI.md §5). -->
                                <span v-if="faltaCelda(filas[i], 'peligro')" class="ff-tabla__falta">
                                    {{ $t('field_work.risk_matrix.cell_missing') }}
                                </span>
                            </td>

                            <td class="ff-tabla__td" :class="{ 'is-missing': faltaCelda(filas[i], 'riesgo') }">
                                <CatalogSelect
                                    :value="filas[i].riesgo" :options="riesgos" :readonly="readonly"
                                    :placeholder="$t('field_work.risk_matrix.search')"
                                    @update:value="cambiar(i, 'riesgo', $event)" />
                                <span v-if="faltaCelda(filas[i], 'riesgo')" class="ff-tabla__falta">
                                    {{ $t('field_work.risk_matrix.cell_missing') }}
                                </span>
                            </td>

                            <td class="ff-tabla__td" :class="{ 'is-missing': faltaCelda(filas[i], 'control') }">
                                <CatalogSelect
                                    :value="filas[i].control" :options="controles" :readonly="readonly"
                                    :placeholder="$t('field_work.risk_matrix.search')"
                                    @update:value="cambiar(i, 'control', $event)" />
                                <span v-if="faltaCelda(filas[i], 'control')" class="ff-tabla__falta">
                                    {{ $t('field_work.risk_matrix.cell_missing') }}
                                </span>
                            </td>

                            <td class="ff-tabla__td" :class="{ 'is-missing': faltaCelda(filas[i], 'probabilidad') }">
                                <span v-if="readonly" class="ff-readonly">
                                    {{ filas[i].probabilidad ? rotuloProbabilidad(filas[i].probabilidad) : '—' }}
                                </span>
                                <Select
                                    v-else
                                    class="ff-select"
                                    :value="filas[i].probabilidad"
                                    :options="opcionesProbabilidad"
                                    :placeholder="$t('field_work.risk_matrix.probability')"
                                    size="large"
                                    allow-clear
                                    @update:value="cambiar(i, 'probabilidad', $event ?? null)"
                                />
                                <span v-if="faltaCelda(filas[i], 'probabilidad')" class="ff-tabla__falta">
                                    {{ $t('field_work.risk_matrix.cell_missing') }}
                                </span>
                            </td>

                            <td class="ff-tabla__td" :class="{ 'is-missing': faltaCelda(filas[i], 'severidad') }">
                                <span v-if="readonly" class="ff-readonly">
                                    {{ filas[i].severidad ? rotuloSeveridad(filas[i].severidad) : '—' }}
                                </span>
                                <Select
                                    v-else
                                    class="ff-select"
                                    :value="filas[i].severidad"
                                    :options="opcionesSeveridad"
                                    :placeholder="$t('field_work.risk_matrix.severity')"
                                    size="large"
                                    allow-clear
                                    @update:value="cambiar(i, 'severidad', $event ?? null)"
                                />
                                <span v-if="faltaCelda(filas[i], 'severidad')" class="ff-tabla__falta">
                                    {{ $t('field_work.risk_matrix.cell_missing') }}
                                </span>
                            </td>

                            <!-- La fila a medias manda sobre el nivel: aunque
                                 tenga banda, el guardado la va a rechazar, y en
                                 la tabla eso se lee AQUI, en su columna. -->
                            <td class="ff-tabla__td ff-tabla__td--nivel">
                                <span v-if="faltasDe(filas[i]).length" class="ff-incomplete">
                                    {{ $t('field_work.risk_matrix.incomplete') }}
                                </span>
                                <span v-else-if="nivelDe(filas[i])" class="ff-risk" :class="`is-${tonoNivel(nivelDe(filas[i]))}`">
                                    {{ rotuloNivel(nivelDe(filas[i])) }} · {{ filas[i].valor_riesgo }}
                                </span>
                                <span v-else class="ff-risk is-none">{{ $t('field_work.risk_matrix.no_risk') }}</span>
                            </td>

                            <td v-if="!readonly" class="ff-tabla__td ff-tabla__td--acciones">
                                <Popconfirm
                                    :title="$t('field_work.risk_matrix.remove_row_confirm')"
                                    :ok-text="$t('global.delete')"
                                    :cancel-text="$t('global.cancel')"
                                    @confirm="quitar(i)"
                                >
                                    <Button
                                        danger
                                        class="ff-tabla__del"
                                        :title="$t('field_work.risk_matrix.remove_row')"
                                        :aria-label="$t('field_work.risk_matrix.remove_row')"
                                    >
                                        <template #icon><DeleteOutlined /></template>
                                    </Button>
                                </Popconfirm>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- MODO TARJETAS: el contenedor no da para la tabla (tablet en
                 vertical, movil) y docs/UI.md §3 veta el scroll horizontal, asi
                 que cada peligro es una tarjeta con sus seis campos en rejilla.
                 SIN plegado propio: el que pliega es la actividad, igual que en
                 el EPP pliega el trabajador y sus 25 items se ven de un tiron.
                 Dos niveles de plegado obligaban a dos clics para llegar a un
                 campo y dejaban al que abre una actividad mirando una lista de
                 titulos en vez de su AST. -->
            <div v-else class="ff-group__body">
                <article
                    v-for="i in grupo.indices"
                    :id="idFila(`p${i}`)"
                    :key="i"
                    class="ff-row is-open"
                    :class="{ 'is-missing': faltasDe(filas[i]).length > 0 }"
                >
                    <div class="ff-row__head">
                        <span class="ff-row__num">{{ i + 1 }}</span>

                        <span class="ff-row__title">{{ tituloPeligro(filas[i], i) }}</span>

                        <!-- La fila a medias manda: antes decia «Sin evaluar»
                             —o hasta su nivel— y el rechazo del guardado caia
                             por sorpresa. «Incompleto» con rojo, palabra y
                             color juntos (docs/UI.md §5). -->
                        <span v-if="faltasDe(filas[i]).length" class="ff-incomplete">
                            {{ $t('field_work.risk_matrix.incomplete') }}
                        </span>
                        <span v-else-if="nivelDe(filas[i])" class="ff-risk" :class="`is-${tonoNivel(nivelDe(filas[i]))}`">
                            {{ rotuloNivel(nivelDe(filas[i])) }} · {{ filas[i].valor_riesgo }}
                        </span>
                        <span v-else class="ff-risk is-none">{{ $t('field_work.risk_matrix.no_risk') }}</span>
                    </div>

                    <div>
                        <!-- La regla del peligro entero, dicha antes de guardar
                             y pegada a su fila: que columnas faltan, por su
                             nombre. El servidor la repite si aun asi se guarda. -->
                        <p v-if="!readonly && faltasDe(filas[i]).length" class="ff-missing ff-missing--row" role="alert">
                            {{ $t('field_work.risk_matrix.row_missing', { fields: nombresFaltas(filas[i]) }) }}
                        </p>

                        <!-- El orden es el de la tabla de la v1: peligro, riesgo,
                             control, probabilidad, severidad y nivel. La rejilla
                             los pone en dos lineas de tres —la fila del papel
                             leida de izquierda a derecha—, no en el churro
                             vertical de antes: ver la nota de cabecera. -->
                        <div class="ff-row__body ff-row__body--matriz">
                            <div class="ff-cell" :class="{ 'is-missing': faltaCelda(filas[i], 'peligro') }">
                                <label class="ff-label">
                                    {{ $t('field_work.risk_matrix.danger') }}<span class="ff-block__req"> *</span>
                                </label>
                                <CatalogSelect
                                    :value="filas[i].peligro" :options="peligros" :readonly="readonly"
                                    :placeholder="$t('field_work.risk_matrix.search')"
                                    @update:value="cambiar(i, 'peligro', $event)" />
                            </div>

                            <div class="ff-cell" :class="{ 'is-missing': faltaCelda(filas[i], 'riesgo') }">
                                <label class="ff-label">
                                    {{ $t('field_work.risk_matrix.risk') }}<span class="ff-block__req"> *</span>
                                </label>
                                <CatalogSelect
                                    :value="filas[i].riesgo" :options="riesgos" :readonly="readonly"
                                    :placeholder="$t('field_work.risk_matrix.search')"
                                    @update:value="cambiar(i, 'riesgo', $event)" />
                            </div>

                            <div class="ff-cell" :class="{ 'is-missing': faltaCelda(filas[i], 'control') }">
                                <label class="ff-label">
                                    {{ $t('field_work.risk_matrix.control') }}<span class="ff-block__req"> *</span>
                                </label>
                                <CatalogSelect
                                    :value="filas[i].control" :options="controles" :readonly="readonly"
                                    :placeholder="$t('field_work.risk_matrix.search')"
                                    @update:value="cambiar(i, 'control', $event)" />
                            </div>

                            <div class="ff-cell" :class="{ 'is-missing': faltaCelda(filas[i], 'probabilidad') }">
                                <label class="ff-label">
                                    {{ $t('field_work.risk_matrix.probability') }}<span class="ff-block__req"> *</span>
                                </label>
                                <!-- Los chips enseñan el nombre del catalogo si
                                     vino («Podria suceder»), y la clave si no.
                                     Se emite SIEMPRE la clave (p1..p5). -->
                                <div class="ff-chips">
                                    <span v-if="readonly" class="ff-readonly">
                                        {{ filas[i].probabilidad ? rotuloProbabilidad(filas[i].probabilidad) : '—' }}
                                    </span>
                                    <button
                                        v-for="p in (readonly ? [] : probabilidades)" :key="p" type="button"
                                        class="ff-chip" :class="{ 'is-on': filas[i].probabilidad === p }"
                                        :aria-pressed="filas[i].probabilidad === p"
                                        @click="cambiar(i, 'probabilidad', p)">{{ rotuloProbabilidad(p) }}</button>
                                </div>
                            </div>

                            <div class="ff-cell" :class="{ 'is-missing': faltaCelda(filas[i], 'severidad') }">
                                <label class="ff-label">
                                    {{ $t('field_work.risk_matrix.severity') }}<span class="ff-block__req"> *</span>
                                </label>
                                <div class="ff-chips">
                                    <span v-if="readonly" class="ff-readonly">
                                        {{ filas[i].severidad ? rotuloSeveridad(filas[i].severidad) : '—' }}
                                    </span>
                                    <button
                                        v-for="s in (readonly ? [] : severidades)" :key="s" type="button"
                                        class="ff-chip" :class="{ 'is-on': filas[i].severidad === s }"
                                        :aria-pressed="filas[i].severidad === s"
                                        @click="cambiar(i, 'severidad', s)">{{ rotuloSeveridad(s) }}</button>
                                </div>
                            </div>

                            <!-- El nivel es la sexta columna de la v1 y no se
                                 teclea: sale del valor de la matriz y de las
                                 bandas de la plantilla. El asterisco es el de la
                                 tabla vieja — un peligro sin nivel es un peligro
                                 sin evaluar, y eso no cierra el documento. -->
                            <div class="ff-cell">
                                <label class="ff-label">
                                    {{ $t('field_work.risk_matrix.level') }}<span class="ff-block__req"> *</span>
                                </label>
                                <span v-if="nivelDe(filas[i])" class="ff-risk" :class="`is-${tonoNivel(nivelDe(filas[i]))}`">
                                    {{ rotuloNivel(nivelDe(filas[i])) }} · {{ filas[i].valor_riesgo }}
                                </span>
                                <span v-else class="ff-risk is-none">{{ $t('field_work.risk_matrix.no_risk') }}</span>
                            </div>
                        </div>

                        <footer v-if="!readonly" class="ff-row__foot">
                            <Button
                                class="ff-row__del"
                                danger
                                size="large"
                                :title="$t('field_work.risk_matrix.remove_row')"
                                @click="quitar(i)"
                            >
                                <template #icon><DeleteOutlined /></template>
                                {{ $t('field_work.risk_matrix.remove_row') }}
                            </Button>
                        </footer>
                    </div>
                </article>
            </div>

            <!-- El pie solo existe en tarjetas: en la tabla el «+» de la
                 cabecera de Peligro es quien añade (la captura del dueño), y
                 «Quitar esta actividad» ya subio a la cabecera del bloque. -->
            <footer v-if="!readonly && !modoTabla" class="ff-group__foot">
                <Button size="large" @click="agregarPeligro(grupo)">
                    <template #icon><PlusOutlined /></template>
                    {{ $t('field_work.risk_matrix.add_row') }}
                </Button>
            </footer>
            </div>
        </section>

        <Button v-if="!readonly" class="ff-add" size="large" block @click="agregarActividad">
            <template #icon><PlusOutlined /></template>
            {{ $t('field_work.risk_matrix.add_activity') }}
        </Button>
    </div>
</template>
