<script setup>
/**
 * RatingRing — anillo de rating 0..4 (SVG + CSS, sin librería). Liviano: una sola
 * transición de stroke al montar (no hay loop de animación). El arco se colorea
 * por la condición y el número va al centro.
 */
import { ref, computed, onMounted, watch } from 'vue';

const props = defineProps({
    rating: { type: Number, default: 0 },
    color:  { type: String, default: '#9aa0a6' },
});

// Circunferencia para r=15.9 ≈ 100 → dasharray en "porcentaje".
const target = computed(() => Math.max(0, Math.min(4, props.rating ?? 0)) / 4 * 100);
const shown = ref(0);
onMounted(() => { requestAnimationFrame(() => { shown.value = target.value; }); });
watch(target, (v) => { shown.value = v; });
</script>

<template>
    <svg viewBox="0 0 36 36" class="rring">
        <circle class="rring__track" cx="18" cy="18" r="15.9" />
        <circle
            class="rring__fill" cx="18" cy="18" r="15.9"
            transform="rotate(-90 18 18)"
            :style="{ stroke: color, strokeDasharray: shown + ' 100' }"
        />
        <text x="18" y="19.5" class="rring__num" :style="{ fill: color }">{{ rating }}</text>
        <text x="18" y="25" class="rring__den">/4</text>
    </svg>
</template>

<style scoped>
.rring { width: 66px; height: 66px; flex-shrink: 0; }
.rring__track { fill: none; stroke: var(--color-surface-alt, #eef1f4); stroke-width: 3.2; }
.rring__fill {
    fill: none; stroke-width: 3.2; stroke-linecap: round;
    transition: stroke-dasharray 0.9s cubic-bezier(0.22, 1, 0.36, 1);
}
.rring__num { text-anchor: middle; font-size: 11px; font-weight: 800; }
.rring__den { text-anchor: middle; font-size: 4px; fill: var(--color-text-muted, #9aa0a6); font-weight: 600; }
</style>
