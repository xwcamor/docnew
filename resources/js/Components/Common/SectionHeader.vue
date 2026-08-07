<script setup>
/**
 * Header reusable para páginas internas de un módulo (Trash, EditAll, Show, Form).
 * Compone back-link + icono coloreado + título + subtítulo + slot para acciones.
 * NO usar en el Index principal — ese tiene su propio header con SavedViews.
 */
import BackLink from '@/Components/Common/BackLink.vue';

defineProps({
    backHref:  { type: String, default: null },
    title:     { type: String, required: true },
    subtitle:  { type: String, default: '' },
    iconBg:    { type: String, default: 'var(--color-primary)' },
});
</script>

<template>
    <div class="section-header">
        <div class="section-header__title">
            <BackLink v-if="backHref" :href="backHref" />
            <!-- Tinte suave + glifo en el color (NO relleno sólido): un cuadrado
                 relleno del primario con icono blanco usa el mismo lenguaje
                 visual que los botones primarios y parece clickeable. -->
            <div
                class="section-header__icon"
                :style="{ background: `color-mix(in srgb, ${iconBg} 12%, transparent)`, color: iconBg }"
            >
                <slot name="icon" />
            </div>
            <div class="section-header__heading">
                <h1>{{ title }}</h1>
                <slot name="subtitle">
                    <p v-if="subtitle">{{ subtitle }}</p>
                </slot>
            </div>
        </div>
        <div v-if="$slots.actions" class="section-header__actions">
            <slot name="actions" />
        </div>
    </div>
</template>

<style scoped>
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.section-header__title {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    /* min-width: 0 + flex: 1 1 0 permite que el bloque del titulo encoja
       cuando el name del registro es muy largo. Sin esto, h1 no wrappea y
       el flex crece a contenido empujando la pagina hacia la derecha. */
    min-width: 0;
    flex: 1 1 auto;
}
.section-header__heading {
    /* flex item: necesita min-width: 0 para que h1 con overflow-wrap se rompa
       en el ancho del padre, en lugar de empujarlo. */
    min-width: 0;
    flex: 1 1 auto;
}
.section-header__icon {
    width: 40px;
    height: 40px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.section-header__heading h1 {
    font-size: 1.4rem;
    font-weight: 400;
    margin: 0;
    color: var(--color-text);
    line-height: 1.2;
    /* Nombres/titulos largos sin espacios (emails, slugs, "qqqqq...") deben
       wrappear, no empujar el header. */
    word-break: break-word;
    overflow-wrap: anywhere;
}
.section-header__heading p {
    font-size: 0.8125rem;
    color: var(--color-text-muted);
    margin: 2px 0 0 0;
    max-width: 600px;
    word-break: break-word;
    overflow-wrap: anywhere;
}
.section-header__actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    flex-shrink: 0;
}
@media (max-width: 768px) {
    .section-header { flex-direction: column; align-items: stretch; }
    .section-header__heading h1 { font-size: 1.2rem; }
    /* Acciones a la derecha, en fila, debajo del título. */
    .section-header__actions { justify-content: flex-end; }
}
</style>
