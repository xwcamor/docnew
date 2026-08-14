<script setup>
/**
 * Una casilla de checklist: la pastilla que cicla al tocarla.
 *
 * QUE SUSTITUYE Y POR QUE
 * -----------------------
 * Cada punto de inspeccion era una fila con su texto a la izquierda y tres
 * botones a la derecha (`AnswerToggle`). Con veinte puntos por herramienta y
 * veinticinco equipos por trabajador eso son cientos de filas identicas, y el
 * dueño del producto lo dijo en una frase: «se siente una hoja de papel». Una
 * tablet no es una hoja de papel — se toca.
 *
 * Aqui el punto Y su respuesta son la MISMA cosa: una pastilla que se toca y
 * cambia de estado. Sin marcar → conforme → no conforme → sin marcar. Como el
 * punto ya no necesita su fila entera, las pastillas caben varias por linea y
 * la lista deja de medir lo que medía.
 *
 * CUANTOS TOQUES TIENE EL CICLO LO DECIDE QUIEN CIERRA LOS HUECOS
 * ---------------------------------------------------------------
 * En el EPP y en la inspeccion de herramientas «No aplica» no entra: al
 * confirmar, el servidor escribe esa palabra en todo lo que quedo en blanco
 * (`FormSubmissionService::cerrarLoSinMarcarComoNoAplica`), asi que la pastilla
 * gris YA significa «no aplica» y tocarla tres veces para llegar a donde estaba
 * seria trabajo por nada.
 *
 * Donde el servidor NO rellena —el banco de preguntas del PTF, que no esta en
 * esa lista de tipos— la misma decision se vuelve del reves: dejar «No aplica»
 * fuera del ciclo la haria imposible de elegir. Por eso se pide con
 * `rellenaAlCerrar`, y no se adivina. Ver `cicloRespuestas()`.
 *
 * POR QUE EL VERDE NO LLEVA LA PALABRA Y EL ROJO SI
 * -------------------------------------------------
 * La regla del repo es que el color nunca va solo (docs/UI.md §5). Se cumple en
 * los dos estados, pero no con lo mismo:
 *
 *   - El ROJO lleva la cruz Y la palabra del catalogo escrita («No conforme»,
 *     «No cumple»). Es el estado que informa: es el que dispara los campos de
 *     correccion, el que cuenta como no conformidad y el que alguien va a leer
 *     en una fotocopia en blanco y negro. Ahi la palabra no se negocia.
 *   - El VERDE se queda con la marca ✔ y el `aria-label`. La pastilla ya lleva
 *     texto —el nombre del punto—, y aqui el color no es el unico portador: el
 *     ✔ es una marca no cromatica, se ve fotocopiado y lo lee quien no
 *     distingue el rojo del verde. Repetir «Conforme» en las veinticinco
 *     pastillas de un trabajador ademas TAPA lo que hay que ver: veinticuatro
 *     verdes con la misma palabra y una roja distinta se recorren mirando
 *     palabras; veinticuatro ✔ y una ✘ con su texto se recorren de un vistazo.
 *     La excepcion es la que tiene que gritar, no la norma.
 *
 * En SOLO LECTURA todos llevan su palabra, la verde tambien: ahi ya no se
 * recorre buscando la excepcion, se lee un documento cerrado y cada casilla
 * tiene que valer por si sola.
 *
 * NO CAMBIA LA FORMA DEL VALOR: emite la etiqueta del catalogo o `null`, que es
 * lo que emitia `AnswerToggle` y lo que esperan el servidor, el PDF y las
 * 14 000 entregas migradas (`CompositeFieldAnswersTest`).
 */
import { computed } from 'vue';
import { CheckOutlined, CloseOutlined, MinusOutlined, UndoOutlined } from '@ant-design/icons-vue';
import { cicloRespuestas, siguienteEnCiclo, tono } from './respuestas';
import { useCatalogos } from '@/Composables/useCatalogos';
import { useI18n } from '@/Plugins/i18n';

const props = defineProps({
    value:    { type: [String, null], default: null },
    /**
     * `config.answers` TAL CUAL, no la lista de valores ya aplanada.
     *
     * Es lo que permite que la pastilla sepa el tono declarado de cada respuesta
     * y su rotulo traducido. Aplanarla antes de bajarla —que es lo que se hacia—
     * deja fuera las dos cosas: un «Rechazado» con `tone: 'bad'` llegaria aqui
     * como una cadena suelta y se pintaria verde.
     */
    answers:  { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
    /** El punto de inspeccion: es el texto que se lee DENTRO de la pastilla. */
    label:    { type: String, default: '' },
    /**
     * Esta es la casilla que se acaba de tocar, asi que se le pinta al lado el
     * boton de deshacer. Lo decide el campo padre —hay uno solo en toda la
     * pantalla— y no la propia pastilla: ver el comentario de `ultimoToque.js`.
     */
    deshacible: { type: Boolean, default: false },
    /**
     * Si el servidor cierra los huecos de este campo como «no aplica» al
     * confirmar. Es lo unico que decide si «No aplica» entra en el ciclo, y va
     * pedido a proposito: la pastilla no puede deducirlo del catalogo —el mismo
     * catalogo con «No aplica» se rellena solo en el EPP y no en el PTF— y
     * adivinarlo mal deja una respuesta imposible de dar con el dedo.
     */
    rellenaAlCerrar: { type: Boolean, default: true },
});

const emit = defineEmits(['update:value', 'deshacer']);

const { t } = useI18n();
const { etiqueta: rotuloDe } = useCatalogos();

/**
 * El tono de la pastilla: el que declara el catalogo, y si no lo declara, el que
 * se deduzca del texto.
 *
 * `null` es 'off' y no 'na': `tono()` clasifica el vacio como «no aplica» —que
 * es lo que acabara siendo al cerrar— pero MIENTRAS SE LLENA un hueco es un
 * hueco, y tiene que verse distinto de un «No aplica» que alguien escribio de
 * verdad en una entrega reabierta.
 */
const clave = computed(() => (props.value ? tono(props.value, { answers: props.answers }) : 'off'));

const ciclo = computed(() => cicloRespuestas(props.answers, { rellenaAlCerrar: props.rellenaAlCerrar }));

/** Sin ciclo que recorrer la pastilla no se toca: no hay a donde llevarla. */
const tocable = computed(() => ! props.readonly && ciclo.value.length > 1);

/**
 * El estado en palabra. Es la del catalogo, nunca una nuestra, y traducida si el
 * catalogo trae traducciones: lo que se GUARDA es el valor, lo que se LEE es su
 * rotulo.
 */
const palabra = computed(() => (
    props.value ? rotuloDe(props.answers, props.value) : t('field_work.checklist_unmarked')
));

/**
 * La palabra sale escrita cuando dice algo que el simbolo no dice solo. Ver la
 * nota larga de arriba: en verde sobra, en rojo es obligatoria, y en solo
 * lectura salen todas.
 */
const conPalabra = computed(() => props.readonly || clave.value === 'bad' || clave.value === 'na');

/**
 * El `aria-label` dice el punto, en que estado esta y a donde lo lleva el
 * siguiente toque. Lo tercero no es adorno: un boton que cambia de significado
 * cada vez que se pulsa no se puede usar a ciegas si no anuncia que hace ahora.
 */
const etiqueta = computed(() => {
    const estado = `${props.label}: ${palabra.value}.`;

    if (! tocable.value) return estado;

    const destino = siguienteEnCiclo(ciclo.value, props.value);

    return `${estado} ${t('field_work.checklist_tap_next', {
        next: destino === null ? t('field_work.checklist_unmarked') : rotuloDe(props.answers, destino),
    })}`;
});

/**
 * El gris no lleva simbolo: lleva el recuadro vacio que dibuja el propio CSS,
 * que es como se ve una casilla sin marcar en cualquier papel. El mapa tiene
 * salida por defecto (docs/UI.md §5-quater): una clave que no este aqui se
 * queda con el recuadro en vez de pintar un hueco.
 */
const iconos = { ok: CheckOutlined, bad: CloseOutlined, na: MinusOutlined };

const icono = computed(() => iconos[clave.value] ?? null);

function tocar() {
    if (! tocable.value) return;

    emit('update:value', siguienteEnCiclo(ciclo.value, props.value));
}
</script>

<template>
    <div class="ff-check">
        <component
            :is="tocable ? 'button' : 'span'"
            :type="tocable ? 'button' : null"
            class="ff-cycle"
            :class="[`is-${clave}`, { 'is-ro': ! tocable }]"
            :aria-label="tocable ? etiqueta : null"
            @click="tocar"
        >
            <span class="ff-cycle__mark" aria-hidden="true">
                <component :is="icono" v-if="icono" />
            </span>

            <span class="ff-cycle__text">
                <span class="ff-cycle__name">{{ label }}</span>
                <!-- La palabra del estado, cuando toca. Sin `aria-hidden`
                     aunque el `aria-label` ya la diga: el label sustituye al
                     contenido, no se suma, asi que no se repite. -->
                <span v-if="conPalabra" class="ff-cycle__state">{{ palabra }}</span>
            </span>
        </component>

        <!--
            DESHACER EL TOQUE DE MAS.

            Es la pega del ciclo y por eso tiene respuesta propia: con tres
            estados, volver al anterior cuesta DOS toques mas, y eso en obra es
            justo cuando se marca lo que no era.

            Sale pegado a la pastilla que se acaba de tocar —donde esta la mano,
            no en el pie de la tarjeta a veinte casillas de distancia— y solo en
            esa: hay uno en toda la pantalla, asi que no hay que acertar cual de
            todos era. Vive DENTRO de la celda de la cuadricula: al salir,
            ninguna pastilla cambia de columna ni de ancho, y lo unico que puede
            reajustarse es el rotulo de la que se acaba de tocar.

            Es del mismo alto que la pastilla —nada de esquinitas— y no es
            pulsacion larga, que no se descubre y no se acierta con guantes.
        -->
        <button
            v-if="deshacible && tocable"
            type="button"
            class="ff-undo"
            :title="$t('field_work.checklist_undo', { item: label })"
            :aria-label="$t('field_work.checklist_undo', { item: label })"
            @click="emit('deshacer')"
        >
            <UndoOutlined aria-hidden="true" />
        </button>
    </div>
</template>
