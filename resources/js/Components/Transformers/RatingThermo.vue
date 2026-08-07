<script setup>
/**
 * RatingThermo — termómetro de rating 0..4 (SVG + CSS, sin librería). El mercurio
 * sube desde el bulbo con una sola transición al montar (scaleY por GPU → no
 * afecta performance). Coloreado por la condición.
 */
import { ref, computed, onMounted, watch } from 'vue';

const props = defineProps({
    rating: { type: Number, default: 0 },
    color:  { type: String, default: '#9aa0a6' },
});

const target = computed(() => Math.max(0, Math.min(4, props.rating ?? 0)) / 4);
const shown = ref(0);
onMounted(() => { requestAnimationFrame(() => { shown.value = target.value; }); });
watch(target, (v) => { shown.value = v; });
</script>

<template>
    <svg viewBox="0 0 28 72" class="thermo">
        <!-- Vidrio: tubo + bulbo -->
        <rect x="10" y="4" width="8" height="50" rx="4" class="thermo__glass" />
        <circle cx="14" cy="60" r="9" class="thermo__glass" />
        <!-- Mercurio: bulbo (siempre) + columna animada -->
        <circle cx="14" cy="60" r="6" :style="{ fill: color }" />
        <rect
            class="thermo__col" x="11.5" y="9" width="5" height="45" rx="2.5"
            :style="{ fill: color, transform: `scaleY(${shown})` }"
        />
        <text x="14" y="62.5" class="thermo__num">{{ rating }}</text>
    </svg>
</template>

<style scoped>
.thermo { width: 40px; height: 72px; flex-shrink: 0; }
.thermo__glass { fill: var(--color-surface-alt, #eef1f4); }
.thermo__col {
    transform-box: fill-box; transform-origin: bottom;
    transition: transform 0.9s cubic-bezier(0.22, 1, 0.36, 1);
}
.thermo__num { text-anchor: middle; font-size: 8px; font-weight: 800; fill: #fff; }
@media (prefers-reduced-motion: reduce) {
    .thermo__col { transition: none; }
}
</style>
