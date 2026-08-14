<script setup>
/**
 * Banco de preguntas (PTF, "Pare y Tome 5").
 *
 * Las preguntas vienen del catalogo del sistema anterior. No es una tabla: es
 * una lista de preguntas, que es lo unico que se puede leer de pie y a pleno
 * sol.
 *
 * SE RESPONDE TOCANDO LA PREGUNTA, COMO EN EL EPP Y EN HERRAMIENTAS
 * -----------------------------------------------------------------
 * La pregunta y su respuesta son la misma pastilla (`AnswerCycle`): un toque la
 * deja en «Si», otro en «No», un tercero la devuelve a sin responder. Es la
 * misma mano y el mismo gesto que en los otros dos formatos, y esa es toda la
 * razon: quien llena los cuatro documentos del dia no tiene por que aprender
 * dos maneras de contestar.
 *
 * PERO AQUI EL GRIS NO SIGNIFICA «NO APLICA»
 * ------------------------------------------
 * Y es la diferencia que hay que respetar. En el EPP y en la inspeccion de
 * herramientas, lo que se deja en blanco lo cierra el servidor como «no aplica»
 * al confirmar (`FormSubmissionService::cerrarLoSinMarcarComoNoAplica`, que
 * recorre `person_checklist` y `tool_checklist` — este tipo NO esta ahi). En el
 * PTF una pregunta en blanco es una pregunta **sin responder**: el campo es
 * obligatorio y hay que contestarlas todas.
 *
 * Y de ahi salen las dos diferencias con el EPP:
 *
 *  1. **«No aplica» SI entra en el ciclo** (`:rellena-al-cerrar="false"`). En el
 *     EPP se queda fuera porque el servidor la escribe solo; aqui no la escribe
 *     nadie, asi que dejarla fuera la volveria una respuesta del catalogo
 *     imposible de dar con el dedo. El PTF sembrado no tiene ninguna —se siembra
 *     con «Si» y «No»— pero las entregas migradas si, y un cliente puede traer
 *     la suya.
 *  2. **La leyenda no promete ningun relleno.** Prometer lo que no va a pasar es
 *     peor que callarse.
 *
 * LA PREGUNTA SIN RESPONDER SE MARCA UNA A UNA (`.ff-check.is-missing`). El
 * aviso de arriba dice cuantas faltan y el contador dice cuantas van, pero
 * ninguno de los dos dice CUALES: en una lista de diez frases largas, eso es
 * volver a leerlas todas.
 *
 * UNA POR LINEA, al reves que los otros dos: las preguntas son frases enteras
 * («¿Estan disponibles y comprendidos: la evaluacion de riesgos (ABRA), AST, el
 * alcance del trabajo…?») y en dos columnas cada pastilla se convierte en un
 * parrafo de cinco lineas. Se pide con `--ff-check-min`.
 *
 * FORMA DEL VALOR que emite (UNA sola respuesta, sin `row`):
 *
 *   [{ question, answer }, ...]
 *
 * Es una lista no vacia, que es lo que exige validarValor() para este tipo.
 */
import { computed } from 'vue';
import AnswerCycle from './AnswerCycle.vue';
import { useUltimoToque } from './ultimoToque';
import { agrupar, catalogo, palabrasDelCiclo, respondidos } from './respuestas';
import { useCatalogos } from '@/Composables/useCatalogos';

const props = defineProps({
    field:    { type: Object, required: true },
    value:    { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
    /** El servidor dijo que este campo sigue faltando tras intentar guardar. */
    faltante: { type: Boolean, default: false },
});

const emit = defineEmits(['update:value']);

const { locale, etiqueta } = useCatalogos();

const config = computed(() => props.field?.config ?? {});
const preguntas = computed(() => catalogo(config.value, 'questions'));

/**
 * El texto que se LEE de una pregunta. Lo que se guarda es su valor —que es lo
 * que casa con las entregas ya llenadas— y lo que se lee puede venir traducido.
 */
const rotulo = (question) => etiqueta(config.value?.questions, question);
/**
 * El catálogo de respuestas EN CRUDO: es lo que baja a `AnswerCycle` y tiene que
 * llevar dentro el tono declarado y las traducciones. Ver `PersonChecklistField`.
 */
const respuestas = computed(() => config.value?.answers ?? []);

/** Se recompone contra el catalogo vigente: si la plantilla cambio, no se pierde lo respondido. */
const filas = computed(() => {
    const guardadas = Array.isArray(props.value) ? props.value : [];

    if (!preguntas.value.length) return guardadas;

    return preguntas.value.map((question) => ({
        question,
        answer: guardadas.find((g) => g?.question === question)?.answer ?? null,
    }));
});

/** Las palabras del ciclo, para la leyenda. Salen del catalogo, no de aqui. */
const pista = computed(() => palabrasDelCiclo(respuestas.value, locale.value));

const { ultimo, anotar, olvidar, esUltimo } = useUltimoToque();

function escribir(question, respuesta) {
    emit('update:value', filas.value.map(
        (f) => (f.question === question ? { ...f, answer: respuesta } : f),
    ));
}

/** Un toque: se apunta de donde venia, para poder deshacerlo. */
function responder(question, respuesta) {
    anotar(0, question, filas.value.find((f) => f.question === question)?.answer ?? null);
    escribir(question, respuesta);
}

/** Deshacer el ultimo toque. Un solo nivel, como en los otros dos. */
function deshacer() {
    if (! ultimo.value) return;

    const { item, anterior } = ultimo.value;

    olvidar();
    escribir(item, anterior);
}

const contestadas = computed(() => respondidos(filas.value.map((f) => ({ answer: f.answer }))));

const sinContestar = (f) => f.answer === null || f.answer === undefined || f.answer === '';

/** Las preguntas que el aviso de `faltante` nombra: las que siguen en blanco. */
const pendientes = computed(() => filas.value.length - contestadas.value);

/**
 * Las preguntas repartidas en sus grupos, como el papel de la v1: «1. ¡DETENTE
 * y piensa antes de actuar!» con su icono, y sus preguntas debajo.
 *
 * Es la MISMA `agrupar()` del EPP —una vista sobre el catalogo, nunca otra
 * lista— asi que lo que ningun grupo reclame sale igual, al final y sin
 * rotulo, y sin `config.groups` hay un solo grupo sin nombre con todo dentro:
 * exactamente lo que se pintaba antes.
 */
const grupos = computed(() => agrupar(preguntas.value, config.value?.groups, locale.value));

const conGrupos = computed(() => grupos.value.some((g) => g.name || g.image));

/** Las filas de un grupo, en el orden del grupo. */
const filasDe = (grupo) => grupo.items
    .map((q) => filas.value.find((f) => f.question === q))
    .filter(Boolean);
</script>

<template>
    <div class="ff-field">
        <!-- El guardado dejo el campo pendiente: en rojo y con la cuenta.
             Las preguntas concretas quedan marcadas fila a fila, abajo. -->
        <p v-if="faltante && !readonly && pendientes" class="ff-missing" role="alert">
            {{ $tc('field_work.progress.required_questions', pendientes) }}
        </p>

        <!-- Con sustantivo, como el resto de compuestos: «3/25» a secas no
             decia 3 de 25 QUE, y es el unico avance que tiene este campo. -->
        <p class="ff-count ff-count--head" :class="{ 'is-done': contestadas === filas.length }">
            {{ $t('field_work.progress.questions_done', { done: contestadas, total: filas.length }) }}
        </p>

        <!-- Como se contesta, dicho antes de contestar nada. Ninguna de las dos
             variantes promete relleno al cerrar, porque aqui no lo hay: el gris
             es «sin responder». La que se elige depende de si el catalogo trae
             «no aplica», que aqui es un toque mas del ciclo y no una palabra que
             escriba el servidor. Ver la nota de arriba. -->
        <p v-if="pista && !readonly" class="ff-hint">
            {{ $t(pista.na ? 'field_work.checklist_hint_na_tocable' : 'field_work.checklist_hint_no_na', pista) }}
        </p>

        <!-- Por grupos cuando el formato los trae —el papel de la v1 iba asi,
             con su icono por bloque— y de corrido cuando no. Una pastilla por
             linea en los dos casos: son frases enteras y en dos columnas cada
             una se convierte en un parrafo. -->
        <section v-for="(grupo, g) in grupos" :key="g" class="ff-qgroup">
            <h4 v-if="conGrupos && (grupo.name || grupo.image)" class="ff-qgroup__head">
                <img v-if="grupo.image" :src="grupo.image" alt="" class="ff-qgroup__icon">
                <span v-if="grupo.name">{{ grupo.name }}</span>
            </h4>

            <ul class="ff-checks ff-checks--wide">
                <li
                    v-for="f in filasDe(grupo)"
                    :key="f.question"
                    class="ff-check"
                    :class="{ 'is-missing': faltante && !readonly && sinContestar(f) }"
                >
                    <AnswerCycle
                        :value="f.answer"
                        :answers="respuestas"
                        :readonly="readonly"
                        :label="rotulo(f.question)"
                        :rellena-al-cerrar="false"
                        :deshacible="esUltimo(0, f.question)"
                        @update:value="responder(f.question, $event)"
                        @deshacer="deshacer"
                    />
                </li>
            </ul>
        </section>
    </div>
</template>
