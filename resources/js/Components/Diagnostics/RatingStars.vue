<script setup>
/**
 * RatingStars — calificación en estrellas (idioma-cero). El rating ES la nota:
 * 4 → ★★★★, 0 → ☆☆☆☆. Se usa donde conviene una lectura universal sin texto
 * (editor de reglas). Color opcional para teñir las estrellas llenas.
 */
import { computed } from 'vue';
import { useConditions } from '@/Composables/useConditions';

const props = defineProps({
    rating: { type: [Number, String, null], default: null },
    total: { type: Number, default: 4 },
    color: { type: String, default: '' }, // hex; vacío = ámbar por defecto
    size: { type: Number, default: 15 },
});

const { ratingStars } = useConditions();
const s = computed(() => ratingStars(props.rating, props.total));
const fill = computed(() => props.color || '#E9A23B');
</script>

<template>
    <span class="rstars" :style="{ fontSize: size + 'px' }" :aria-label="`${s.filled}/${s.total}`">
        <span class="rstars__on" :style="{ color: fill }">{{ '★'.repeat(s.filled) }}</span><span class="rstars__off">{{ '☆'.repeat(s.empty) }}</span>
    </span>
</template>

<style scoped>
.rstars { letter-spacing: 1px; line-height: 1; white-space: nowrap; }
.rstars__off { color: #d0d4d9; }
</style>
