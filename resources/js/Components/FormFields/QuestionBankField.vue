<script setup>
/**
 * Banco de preguntas (PTF, "Pare y Tome 5").
 *
 * Las preguntas vienen del catalogo del sistema anterior y se responden Si /
 * No / No aplica. No es una tabla: es una lista de preguntas con sus botones,
 * que es lo unico que se puede leer de pie y a pleno sol.
 *
 * FORMA DEL VALOR que emite (UNA sola respuesta, sin `row`):
 *
 *   [{ question, answer }, ...]
 *
 * Es una lista no vacia, que es lo que exige validarValor() para este tipo.
 */
import { computed } from 'vue';
import AnswerToggle from './AnswerToggle.vue';
import { catalogo, respondidos } from './respuestas';

const props = defineProps({
    field:    { type: Object, required: true },
    value:    { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
    /** El servidor dijo que este campo sigue faltando tras intentar guardar. */
    faltante: { type: Boolean, default: false },
});

const emit = defineEmits(['update:value']);

const config = computed(() => props.field?.config ?? {});
const preguntas = computed(() => catalogo(config.value, 'questions'));
const respuestas = computed(() => catalogo(config.value, 'answers'));

/** Se recompone contra el catalogo vigente: si la plantilla cambio, no se pierde lo respondido. */
const filas = computed(() => {
    const guardadas = Array.isArray(props.value) ? props.value : [];

    if (!preguntas.value.length) return guardadas;

    return preguntas.value.map((question) => ({
        question,
        answer: guardadas.find((g) => g?.question === question)?.answer ?? null,
    }));
});

function responder(question, respuesta) {
    emit('update:value', filas.value.map(
        (f) => (f.question === question ? { ...f, answer: respuesta } : f),
    ));
}

const contestadas = computed(() => respondidos(filas.value.map((f) => ({ answer: f.answer }))));

const sinContestar = (f) => f.answer === null || f.answer === undefined || f.answer === '';

/** Las preguntas que el aviso de `faltante` nombra: las que siguen en blanco. */
const pendientes = computed(() => filas.value.length - contestadas.value);
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

        <ul class="ff-items ff-items--questions">
            <li
                v-for="f in filas"
                :key="f.question"
                class="ff-item"
                :class="{ 'is-missing': faltante && !readonly && sinContestar(f) }"
            >
                <span class="ff-item__name">{{ f.question }}</span>
                <AnswerToggle
                    :value="f.answer" :answers="respuestas" :readonly="readonly" :label="f.question"
                    @update:value="responder(f.question, $event)" />
            </li>
        </ul>
    </div>
</template>
