<script setup>
import TransformerGlyph from '@/Components/Transformers/TransformerGlyph.vue';

defineProps({
    title:        { type: String, required: true },
});
</script>

<template>
    <div class="page-header__title">
        <div class="page-header__icon">
            <TransformerGlyph :animated="false" color="var(--color-primary)" />
        </div>
        <div class="page-header__heading">
            <h1>{{ title }}</h1>
        </div>
    </div>
</template>

<style scoped>
.page-header__title { display: flex; align-items: center; gap: 14px; }
.page-header__icon {
    width: 40px;
    height: 40px;
    border-radius: 4px;
    /* Tinte suave (no relleno sólido): mismo patrón que el resto de headers. */
    background: color-mix(in srgb, var(--color-primary) 12%, transparent);
    color: var(--color-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}
/* El glyph como ícono: chico, blanco, sin el rayo interno ni las chispas. */
.page-header__icon :deep(.tr-art) { width: 26px; height: 26px; }
.page-header__icon :deep(.tr-art__bolt),
.page-header__icon :deep(.tr-art__arcs) { display: none; }
.page-header__title h1 {
    font-size: 1.4rem;
    font-weight: 400;
    margin: 0;
    color: var(--color-text);
    letter-spacing: 0;
}
.page-header__title p {
    font-size: 0.8125rem;
    color: var(--color-text-muted);
    margin: 2px 0 0 0;
}
@media (max-width: 768px) {
    .page-header__title h1 { font-size: 1.25rem; }
}
</style>
