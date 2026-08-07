<script setup>
/**
 * RatingGauge — velocímetro de rating 0..4 (SVG + CSS, sin librería). Arco
 * semicircular con 5 segmentos de color (rojo→verde) y una AGUJA que apunta al
 * rating. La aguja se mueve con una sola transición al montar (rotate por GPU →
 * no afecta performance).
 */
import { ref, computed, onMounted, watch } from 'vue';

const props = defineProps({
    rating: { type: Number, default: 0 },
});

// Segmentos del arco (rojo = peor … verde = mejor), con 2° de separación.
const R = 40, CX = 50, CY = 50;
const pt = (deg) => {
    const a = (deg * Math.PI) / 180;
    return [CX + R * Math.cos(a), CY - R * Math.sin(a)];
};
const arc = (a1, a2) => {
    const [x1, y1] = pt(a1), [x2, y2] = pt(a2);
    return `M${x1.toFixed(2)},${y1.toFixed(2)} A${R},${R} 0 0 1 ${x2.toFixed(2)},${y2.toFixed(2)}`;
};
const segments = [
    { d: arc(178, 146), c: '#C8281D' },
    { d: arc(142, 110), c: '#E2661E' },
    { d: arc(106, 74),  c: '#E9A23B' },
    { d: arc(70, 38),   c: '#5AA82E' },
    { d: arc(34, 2),    c: '#1D7044' },
];

// Aguja: apunta arriba (rating 2) en rot=0; +90° = derecha (rating 4); -90° = izq.
const target = computed(() => (Math.max(0, Math.min(4, props.rating ?? 0)) - 2) * 45);
const rot = ref(-90);
onMounted(() => { requestAnimationFrame(() => { rot.value = target.value; }); });
watch(target, (v) => { rot.value = v; });
</script>

<template>
    <svg viewBox="0 0 100 58" class="gauge">
        <path
            v-for="(s, i) in segments" :key="i" :d="s.d"
            fill="none" :stroke="s.c" stroke-width="11" stroke-linecap="butt"
        />
        <g class="gauge__needle" :style="{ transform: `rotate(${rot}deg)` }">
            <line :x1="CX" :y1="CY" :x2="CX" y2="14" stroke="#32363a" stroke-width="2" stroke-linecap="round" />
        </g>
        <circle :cx="CX" :cy="CY" r="4.5" fill="#32363a" />
    </svg>
</template>

<style scoped>
.gauge { width: 94px; height: 54px; flex-shrink: 0; }
.gauge__needle {
    transform-origin: 50px 50px; transform-box: view-box;
    transition: transform 1s cubic-bezier(0.34, 1.4, 0.5, 1);
}
@media (prefers-reduced-motion: reduce) { .gauge__needle { transition: none; } }
</style>
