<script setup>
/**
 * Lo que se ve cuando la tabla de un catálogo no devuelve nada.
 *
 * Distingue los dos casos porque no se arreglan igual: si hay filtros puestos,
 * lo que falta es quitarlos; si no los hay, lo que falta es crear la primera
 * fila. Un «Sin resultados» a secas deja al usuario sin saber cuál de las dos.
 *
 * `module` nombra a la vez el namespace de traducción y la ruta de creación:
 * así los cinco catálogos comparten esta pantalla sin parametrizar nada más.
 */
import { Button, Space } from 'ant-design-vue';
import { Link } from '@inertiajs/vue3';
import { PlusOutlined } from '@ant-design/icons-vue';

defineProps({
    module:      { type: String,  required: true },
    routePrefix: { type: String,  default: 'business_management' },
    hasFilters:  { type: Boolean, default: false },
    canCreate:   { type: Boolean, default: false },
});

defineEmits(['clear-filters']);
</script>

<template>
    <div class="empty-state">
        <!-- Sin slot no hay glifo de 56px, sólo su hueco: el bloque se pinta
             únicamente cuando hay algo que enseñar (igual que SectionHeader). -->
        <div v-if="$slots.icon" class="empty-state__icon"><slot name="icon" /></div>
        <h3>{{ hasFilters ? $t('global.no_results') : $t('global.no_records') }}</h3>
        <p>{{ hasFilters ? $t('global.try_adjust_filters') : $t(`${module}.empty_hint`) }}</p>
        <Space wrap>
            <Button v-if="hasFilters" size="large" @click="$emit('clear-filters')">
                {{ $t('global.clear') }} {{ $t('global.filters').toLowerCase() }}
            </Button>
            <Link v-else-if="canCreate" :href="route(`${routePrefix}.${module}.create`)">
                <Button type="primary" size="large">
                    <PlusOutlined /> {{ $t(`${module}.new`) }}
                </Button>
            </Link>
        </Space>
    </div>
</template>

<style scoped>
.empty-state {
    text-align: center;
    padding: 56px 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}
.empty-state__icon {
    font-size: 56px;
    color: var(--color-icon-soft);
    margin-bottom: 8px;
    line-height: 1;
}
.empty-state h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text);
}
.empty-state p {
    margin: 0 0 12px 0;
    color: var(--color-text-muted);
    font-size: 0.875rem;
    max-width: 42ch;
}
</style>
