<script setup>
/**
 * TransformerGlyph — dibujo del transformador, adaptativo y reutilizable.
 *
 * - `shape`  define la FIGURA (dato del catálogo de tipos): tank | pole | dry.
 * - `phases` define el nº de boquillas: single | two | three.
 * - `color`  tinta el trazo/bornes (normalmente el color del índice de salud).
 * - `animated` enciende las chispas/latido (off para listados/íconos chicos).
 *
 * Se usa en el detalle del trafo (grande, animado) y como vista previa/ícono
 * en el catálogo de Tipos de transformador.
 */
import { computed } from 'vue';

const props = defineProps({
    shape:    { type: String,  default: 'tank' },
    phases:   { type: String,  default: 'three' },
    color:    { type: String,  default: '#0A6ED1' },
    animated: { type: Boolean, default: true },
});

// Boquillas según fases (1/2/3), centradas.
const bushings = computed(() => {
    const n = { single: 1, two: 2, three: 3 }[props.phases] ?? 3;
    const center = 60, gap = 23;
    return Array.from({ length: n }, (_, i) => center + (i - (n - 1) / 2) * gap);
});

// Arcos eléctricos entre boquillas (con 1 boquilla, una descarga corta).
const arcs = computed(() => {
    const b = bushings.value;
    if (b.length <= 1) {
        const x = b[0] ?? 60;
        return [`M${x},13 L${x - 3},9 L${x + 3},6 L${x},1`];
    }
    const out = [];
    for (let i = 0; i < b.length - 1; i++) {
        const x1 = b[i], x2 = b[i + 1], mid = (x1 + x2) / 2;
        out.push(`M${x1},13 L${(x1 + mid) / 2},6 L${mid},13 L${(mid + x2) / 2},5 L${x2},13`);
    }
    return out;
});
</script>

<template>
    <svg
        class="tr-art"
        :class="{ 'is-animated': animated }"
        viewBox="0 0 120 120"
        aria-hidden="true"
        :style="{ '--glyph-color': color }"
    >
        <defs>
            <filter id="tr-glow" x="-50%" y="-50%" width="200%" height="200%">
                <feGaussianBlur stdDeviation="1" result="b" />
                <feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge>
            </filter>
        </defs>

        <!-- Cuerpo según la FIGURA del tipo -->
        <template v-if="shape === 'pole'">
            <!-- Distribución: poste + lata cilíndrica ("palito") -->
            <rect class="tr-art__pole" x="22" y="8" width="5" height="104" rx="2" />
            <rect class="tr-art__pole" x="27" y="56" width="18" height="8" rx="2" />
            <rect class="tr-art__tank" x="44" y="34" width="38" height="62" rx="17" />
        </template>
        <template v-else-if="shape === 'dry'">
            <!-- Seco / encapsulado: marco abierto con 3 bobinas a la vista -->
            <rect class="tr-art__pole" x="26" y="34" width="68" height="6" rx="2" />
            <rect class="tr-art__pole" x="26" y="92" width="68" height="6" rx="2" />
            <rect class="tr-art__pole" x="26" y="34" width="6" height="64" rx="2" />
            <rect class="tr-art__pole" x="88" y="34" width="6" height="64" rx="2" />
            <rect class="tr-art__tank" x="38" y="44" width="12" height="44" rx="6" />
            <rect class="tr-art__tank" x="54" y="44" width="12" height="44" rx="6" />
            <rect class="tr-art__tank" x="70" y="44" width="12" height="44" rx="6" />
        </template>
        <template v-else>
            <!-- Potencia / Horno: tanque + radiadores -->
            <rect class="tr-art__tank" x="24" y="34" width="72" height="66" rx="8" />
            <g class="tr-art__fins">
                <line x1="24" y1="46" x2="24" y2="88" />
                <line x1="96" y1="46" x2="96" y2="88" />
            </g>
            <rect class="tr-art__base" x="34" y="100" width="52" height="6" rx="2" />
        </template>

        <!-- Boquillas según el número de fases -->
        <g class="tr-art__bushings">
            <template v-for="(x, i) in bushings" :key="i">
                <rect :x="x - 3" y="14" width="6" height="20" rx="2" />
                <circle :cx="x" cy="13" r="4" />
            </template>
        </g>

        <path class="tr-art__bolt" d="M64 48 L52 70 L60 70 L54 92 L74 64 L65 64 L72 48 Z" />

        <!-- Arcos eléctricos entre boquillas (crepitan, con glow) -->
        <g class="tr-art__arcs">
            <path
                v-for="(d, i) in arcs"
                :key="i"
                class="tr-art__arc"
                :d="d"
                :style="{ animationDelay: (i * 0.3) + 's' }"
            />
        </g>
    </svg>
</template>

<style scoped>
.tr-art { width: 100%; height: 100%; display: block; }
.tr-art__tank { fill: rgba(10,110,209,0.04); stroke: var(--glyph-color, #0A6ED1); stroke-width: 2.4; }
.tr-art__bushings rect { fill: #c8ccd1; }
.tr-art__bushings circle { fill: var(--glyph-color, #0A6ED1); filter: url(#tr-glow); }
.tr-art__fins line { stroke: #c8ccd1; stroke-width: 2.4; stroke-linecap: round; }
.tr-art__base { fill: #c8ccd1; }
.tr-art__pole { fill: #c8ccd1; }
.tr-art__bolt { fill: var(--glyph-color, #0A6ED1); }
.tr-art__arc {
    fill: none;
    stroke: var(--glyph-color, #0A6ED1);
    stroke-width: 1.5;
    stroke-linecap: round;
    stroke-linejoin: round;
    filter: url(#tr-glow);
}
/* Animaciones solo cuando animated=true. */
.tr-art.is-animated .tr-art__bushings circle { animation: tr-term-pulse 1.8s ease-in-out infinite; }
.tr-art.is-animated .tr-art__bolt { animation: bolt-flicker 2.6s infinite; }
.tr-art.is-animated .tr-art__arc { animation: tr-arc-flicker 1.7s infinite; }
@keyframes tr-term-pulse { 0%,100% { opacity: 0.6; } 50% { opacity: 1; } }
@keyframes bolt-flicker { 0%,100% { opacity: 1; } 45% { opacity: 0.85; } 50% { opacity: 0.4; } 55% { opacity: 0.9; } }
@keyframes tr-arc-flicker { 0%,18%,22%,100% { opacity: 0.12; } 20%,55%,60% { opacity: 1; } 57% { opacity: 0.45; } }
@media (prefers-reduced-motion: reduce) {
    .tr-art.is-animated .tr-art__arc,
    .tr-art.is-animated .tr-art__bushings circle,
    .tr-art.is-animated .tr-art__bolt { animation: none; }
}
</style>
