<script setup>
/**
 * Un boton por respuesta posible (Conforme / No conforme / No aplica, ...).
 *
 * Esto se llena en obra, en una tablet, con guantes y a pleno sol: por eso son
 * botones grandes con icono y color y no un <select>. El color sale del tono de
 * la respuesta, no de su posicion, porque cada formato trae las suyas.
 */
import { CheckOutlined, CloseOutlined, MinusOutlined } from '@ant-design/icons-vue';
import { tono } from './respuestas';

defineProps({
    value:    { type: [String, null], default: null },
    answers:  { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
    /** Etiqueta accesible del grupo (el item que se esta respondiendo). */
    label:    { type: String, default: '' },
});

const emit = defineEmits(['update:value']);

const iconos = { ok: CheckOutlined, bad: CloseOutlined, na: MinusOutlined };
</script>

<template>
    <div class="ff-answers" role="group" :aria-label="label">
        <template v-if="readonly">
            <span v-if="value" class="ff-answer ff-answer--ro" :class="`is-${tono(value)} is-on`">
                <component :is="iconos[tono(value)]" class="ff-answer__icon" />
                <span class="ff-answer__text">{{ value }}</span>
            </span>
            <span v-else class="ff-answer__none">—</span>
        </template>

        <button
            v-for="a in (readonly ? [] : answers)"
            :key="a"
            type="button"
            class="ff-answer"
            :class="[`is-${tono(a)}`, { 'is-on': value === a }]"
            :aria-pressed="value === a"
            @click="emit('update:value', a)"
        >
            <component :is="iconos[tono(a)]" class="ff-answer__icon" />
            <span class="ff-answer__text">{{ a }}</span>
        </button>
    </div>
</template>
