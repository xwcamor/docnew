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
</script>

<template>
    <div class="ff-field">
        <p class="ff-count ff-count--head" :class="{ 'is-done': contestadas === filas.length }">
            {{ contestadas }}/{{ filas.length }}
        </p>

        <ul class="ff-items ff-items--questions">
            <li v-for="f in filas" :key="f.question" class="ff-item">
                <span class="ff-item__name">{{ f.question }}</span>
                <AnswerToggle
                    :value="f.answer" :answers="respuestas" :readonly="readonly" :label="f.question"
                    @update:value="responder(f.question, $event)" />
            </li>
        </ul>
    </div>
</template>
