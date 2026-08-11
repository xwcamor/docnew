<script setup>
/**
 * Cabecera del listado de un catálogo de obra.
 *
 * Es una sola por los cinco catálogos —sedes, puestos, áreas, cargos y
 * nacionalidades— en vez de cinco copias con el icono cambiado. Cuando se
 * escribieron por separado en otros módulos, cada una acabó con un tamaño de
 * título distinto y se veía de golpe al pasar de una a otra (docs/UI.md §4-bis).
 */
defineProps({
    title: { type: String, required: true },
});
</script>

<template>
    <div class="page-header__title">
        <!-- Mismo criterio que SectionHeader: el recuadro es de relleno sólido,
             así que sin glifo dentro queda un cuadrado azul de 40x40 y nada
             más. Se pinta sólo cuando hay slot que meter. -->
        <div v-if="$slots.icon" class="page-header__icon">
            <slot name="icon" />
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
    background: var(--color-primary);
    color: var(--color-text-on-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}
.page-header__title h1 {
    font-size: 1.4rem;
    font-weight: 400;
    margin: 0;
    color: var(--color-text);
    letter-spacing: 0;
}
@media (max-width: 768px) {
    .page-header__title h1 { font-size: 1.25rem; }
}
</style>
