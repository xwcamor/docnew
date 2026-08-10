<script setup>
/**
 * Índice de las filas de un campo compuesto: cuánto va, quién va y cómo saltar.
 *
 * Es lo que en el papel daba la tabla y aquí se había perdido. El EPP de papel
 * es una cuadrícula de trabajadores × items: mirándola de lejos se ve de golpe
 * a quién le falta una casilla, porque las filas están una encima de otra. Al
 * pasarlo a una tarjeta por trabajador —que es lo único legible en una tablet—
 * esa vista de conjunto desapareció: quedaron siete bloques idénticos de 25
 * botones y para saber si a Ana le faltaba algo había que bajar hasta ella.
 *
 * Aquí vuelve, pero como resumen y no como cuadrícula: una pastilla por fila
 * con su nombre y su estado **en palabra** —«Completo», «Faltan 3», «Sin
 * empezar», «No conforme»—, que es lo que el color no puede decir solo (§5 de
 * docs/UI.md: hay quien no distingue el rojo del verde, y al sol se pierde
 * cualquier matiz).
 *
 * Las pastillas fluyen y se reparten el ancho, no van en una barra lateral
 * fija: a 768 px una columna lateral se come media tablet, y aquí simplemente
 * pasan a ocupar una o dos por línea sin scroll horizontal.
 *
 * Es de presentación pura: no toca el valor del campo, sólo dice a qué fila
 * quiere ir el usuario.
 */
import { computed } from 'vue';
import { Button } from 'ant-design-vue';
import { DownOutlined, UpOutlined } from '@ant-design/icons-vue';

const props = defineProps({
    /** Una por fila: { key, label, state: ok|warn|bad|off, stateText }. */
    rows:    { type: Array, default: () => [] },
    /** Clave de la fila abierta ahora mismo, para marcarla en el índice. */
    active:  { type: [String, Number, null], default: null },
    /** Titular del avance: «3 de 7 trabajadores completos». */
    summary: { type: String, default: '' },
    /** Detalle bajo el titular: «92 de 175 controles». */
    detail:  { type: String, default: '' },
    /** Parte hecha, de 0 a 1. Mueve la barra. */
    ratio:   { type: Number, default: 0 },
    allOpen: { type: Boolean, default: false },
    label:   { type: String, default: '' },
});

const emit = defineEmits(['select', 'toggle-all']);

const ancho = computed(() => `${Math.round(Math.min(1, Math.max(0, props.ratio)) * 100)}%`);
</script>

<template>
    <nav class="ff-nav" :aria-label="label">
        <div class="ff-nav__top">
            <div class="ff-nav__sum">
                <p class="ff-nav__lead">{{ summary }}</p>
                <p v-if="detail" class="ff-nav__detail">{{ detail }}</p>
            </div>

            <Button class="ff-nav__all" size="large" @click="emit('toggle-all')">
                <template #icon><component :is="allOpen ? UpOutlined : DownOutlined" /></template>
                {{ allOpen ? $t('field_work.progress.collapse_all') : $t('field_work.progress.expand_all') }}
            </Button>
        </div>

        <!-- La barra acompaña al texto, nunca lo sustituye: el número de al
             lado es el que se lee al sol. -->
        <div class="ff-nav__meter" aria-hidden="true">
            <span class="ff-nav__meter-fill" :style="{ width: ancho }"></span>
        </div>

        <ul class="ff-nav__chips">
            <li v-for="(r, i) in rows" :key="r.key">
                <button
                    type="button"
                    class="ff-chipnav"
                    :class="[`is-${r.state}`, { 'is-active': r.key === active }]"
                    :aria-current="r.key === active ? 'true' : undefined"
                    @click="emit('select', r.key)"
                >
                    <span class="ff-chipnav__num">{{ i + 1 }}</span>
                    <span class="ff-chipnav__body">
                        <span class="ff-chipnav__name">{{ r.label }}</span>
                        <span class="ff-chipnav__state">{{ r.stateText }}</span>
                    </span>
                </button>
            </li>
        </ul>
    </nav>
</template>
