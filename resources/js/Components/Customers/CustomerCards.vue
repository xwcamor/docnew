<script setup>
import { computed } from 'vue';
import { Empty } from 'ant-design-vue';
import { ClusterOutlined } from '@ant-design/icons-vue';

/**
 * CustomerCards — vista de tarjetas: una por Subestación, con su ruta.
 * Visual y compacta. Solo lectura.
 *
 * Traía del sistema viejo unos chips de transformadores con semáforo de salud
 * y el borde teñido por el peor de ellos. El payload de la jerarquía ya no
 * lleva transformadores, así que TODA tarjeta salía con el contador en 0, el
 * borde gris y la clave sin traducir `customers.transformers_count` de pie de
 * tarjeta. Purgado.
 */
const props = defineProps({
    nodes: { type: Array, default: () => [] },
});

const cards = computed(() => {
    const out = [];
    for (const loc of props.nodes) {
        for (const area of loc.children ?? []) {
            for (const sub of area.children ?? []) {
                out.push({
                    key: `${sub.type}-${sub.id}`,
                    path: `${loc.name} › ${area.name}`,
                    name: sub.name,
                });
            }
        }
    }
    return out;
});
</script>

<template>
    <div v-if="!cards.length" class="cc-empty">
        <Empty :description="$t('customers.empty_hierarchy')" />
    </div>
    <div v-else class="cc-grid">
        <div v-for="card in cards" :key="card.key" class="cc-card">
            <div class="cc-card__head">
                <ClusterOutlined class="cc-card__icon" />
                <div class="cc-card__titles">
                    <div class="cc-card__name">{{ card.name }}</div>
                    <div class="cc-card__path">{{ card.path }}</div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.cc-empty { padding: 24px 0; }
.cc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
.cc-card {
    border: 1px solid var(--color-border, #e5e7eb);
    border-top: 3px solid #8254c8;
    border-radius: 10px; padding: 14px 16px;
    background: var(--color-surface, #fff);
}
.cc-card__head { display: flex; align-items: center; gap: 10px; }
.cc-card__icon { color: #8254c8; font-size: 1rem; }
.cc-card__titles { flex: 1; min-width: 0; }
.cc-card__name { font-weight: 600; color: var(--color-text, #1f2937); }
.cc-card__path { font-size: 0.76rem; color: var(--color-text-muted, #6A6D70); }

html[data-theme="dark"] .cc-card { background: #2c3034; border-color: #3f4448; }
html[data-theme="dark"] .cc-card__name { color: #e5e6e7; }
</style>
