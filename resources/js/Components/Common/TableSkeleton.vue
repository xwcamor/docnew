<script setup>
/**
 * TableSkeleton — placeholder visual mientras carga un listado.
 *
 * Muestra cards-fantasma (mobile) o filas-fantasma (desktop) con animación
 * shimmer. Mejora la "percepción de rapidez" — el usuario ve estructura
 * en lugar de pantalla blanca, así no le parece que la app está colgada.
 *
 * Usage:
 *   <TableSkeleton v-if="initialLoading" :rows="6" />
 *   <ResponsiveTable v-else ... />
 */

defineProps({
    rows:    { type: Number, default: 8 },
    columns: { type: Number, default: 5 },
});
</script>

<template>
    <div class="skeleton">
        <!-- Desktop: filas tipo tabla con celdas sombreadas -->
        <div class="skeleton__desktop">
            <div class="skeleton__head">
                <div
                    v-for="n in columns"
                    :key="`h-${n}`"
                    class="skeleton__cell skeleton__cell--head shimmer"
                />
            </div>
            <div
                v-for="r in rows"
                :key="`r-${r}`"
                class="skeleton__row"
            >
                <div
                    v-for="c in columns"
                    :key="`c-${r}-${c}`"
                    class="skeleton__cell shimmer"
                />
            </div>
        </div>

        <!-- Mobile: cards apiladas tipo lista -->
        <div class="skeleton__mobile">
            <div
                v-for="r in rows"
                :key="`mc-${r}`"
                class="skeleton__card"
            >
                <div class="skeleton__line skeleton__line--title shimmer" />
                <div class="skeleton__line skeleton__line--meta shimmer" />
                <div class="skeleton__line skeleton__line--actions shimmer" />
            </div>
        </div>
    </div>
</template>

<style scoped>
.skeleton {
    width: 100%;
}

/* Desktop variant — visible >= 768px */
.skeleton__desktop { display: none; }
@media (min-width: 768px) {
    .skeleton__desktop { display: block; }
    .skeleton__mobile  { display: none; }
}

.skeleton__head {
    display: flex;
    gap: 1px;
    background: var(--color-surface-alt, #F8FAFC);
    padding: 8px;
    border-radius: 6px 6px 0 0;
}
.skeleton__row {
    display: flex;
    gap: 1px;
    padding: 8px;
    border-bottom: 1px solid #F0F0F0;
}
.skeleton__row:last-child { border-bottom: 0; }

.skeleton__cell {
    flex: 1;
    height: 16px;
    border-radius: 3px;
    background: #E8ECEF;
}
.skeleton__cell--head {
    height: 14px;
    background: #DDE3E8;
}

/* Mobile variant — visible < 768px */
.skeleton__mobile {
    display: flex;
    flex-direction: column;
    background: #fff;
    border: 1px solid #E5E5E5;
    border-radius: 6px;
    overflow: hidden;
}
.skeleton__card {
    padding: 12px 14px;
    border-bottom: 1px solid #F0F0F0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.skeleton__card:last-child { border-bottom: 0; }
.skeleton__line {
    height: 12px;
    border-radius: 3px;
    background: #E8ECEF;
}
.skeleton__line--title   { width: 60%; height: 14px; }
.skeleton__line--meta    { width: 40%; height: 10px; }
.skeleton__line--actions { width: 30%; height: 10px; align-self: flex-end; }

/* Shimmer animation — barrido de izq a der sutil */
.shimmer {
    position: relative;
    overflow: hidden;
}
.shimmer::after {
    content: '';
    position: absolute;
    inset: 0;
    transform: translateX(-100%);
    background: linear-gradient(
        90deg,
        rgba(255, 255, 255, 0) 0,
        rgba(255, 255, 255, 0.45) 50%,
        rgba(255, 255, 255, 0) 100%
    );
    animation: shimmer 1.4s ease-in-out infinite;
}
@keyframes shimmer {
    100% { transform: translateX(100%); }
}
</style>

<style>
/* Dark mode (no scoped) */
html[data-theme="dark"] .skeleton__head { background: #2c3034; }
html[data-theme="dark"] .skeleton__row  { border-bottom-color: #3f4448; }
html[data-theme="dark"] .skeleton__cell { background: #313a44; }
html[data-theme="dark"] .skeleton__cell--head { background: #3a444f; }
html[data-theme="dark"] .skeleton__mobile {
    background: #29313a;
    border-color: #3f4448;
}
html[data-theme="dark"] .skeleton__card { border-bottom-color: #3f4448; }
html[data-theme="dark"] .skeleton__line { background: #313a44; }
html[data-theme="dark"] .shimmer::after {
    background: linear-gradient(
        90deg,
        rgba(255, 255, 255, 0) 0,
        rgba(255, 255, 255, 0.08) 50%,
        rgba(255, 255, 255, 0) 100%
    );
}
</style>
