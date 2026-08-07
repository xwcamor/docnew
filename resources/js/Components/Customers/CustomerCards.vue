<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Empty } from 'ant-design-vue';
import { ClusterOutlined, ThunderboltFilled } from '@ant-design/icons-vue';

/**
 * CustomerCards — vista de tarjetas: una por Subestación, con su ruta, sus
 * transformadores como chips (semáforo de salud) y el color de borde según el
 * peor estado de salud de sus transformadores. Visual y compacta. Solo lectura.
 */
const props = defineProps({
    nodes: { type: Array, default: () => [] },
});

const healthColor = (hi) => {
    if (hi === null || hi === undefined) return '#9aa0a6';
    const v = Number(hi);
    if (v > 85) return '#1D7044';
    if (v > 70) return '#3FA66A';
    if (v > 50) return '#E9A23B';
    if (v > 30) return '#E8833A';
    return '#C8281D';
};

const cards = computed(() => {
    const out = [];
    for (const loc of props.nodes) {
        for (const area of loc.children ?? []) {
            for (const sub of area.children ?? []) {
                const trafos = sub.children ?? [];
                // Peor salud (menor índice) para el color del borde.
                const withHi = trafos.filter((t) => t.health_index !== null && t.health_index !== undefined);
                const worst = withHi.length ? Math.min(...withHi.map((t) => Number(t.health_index))) : null;
                out.push({
                    key: `${sub.type}-${sub.id}`,
                    path: `${loc.name} › ${area.name}`,
                    name: sub.name,
                    transformers: trafos,
                    color: healthColor(worst),
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
        <div v-for="card in cards" :key="card.key" class="cc-card" :style="{ '--c': card.color }">
            <div class="cc-card__head">
                <ClusterOutlined class="cc-card__icon" />
                <div class="cc-card__titles">
                    <div class="cc-card__name">{{ card.name }}</div>
                    <div class="cc-card__path">{{ card.path }}</div>
                </div>
                <span class="cc-card__count">{{ card.transformers.length }}</span>
            </div>

            <div v-if="card.transformers.length" class="cc-card__trafos">
                <Link
                    v-for="t in card.transformers"
                    :key="t.id"
                    :href="route('business_management.transformers.show', t.slug)"
                    class="cc-chip"
                >
                    <ThunderboltFilled class="cc-chip__icon" />
                    {{ t.name }}
                    <span class="cc-chip__dot" :style="{ background: healthColor(t.health_index) }"></span>
                </Link>
            </div>
            <div v-else class="cc-card__empty">{{ $tc('customers.transformers_count', 0) }}</div>
        </div>
    </div>
</template>

<style scoped>
.cc-empty { padding: 24px 0; }
.cc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
.cc-card {
    border: 1px solid var(--color-border, #e5e7eb);
    border-top: 3px solid var(--c, #9aa0a6);
    border-radius: 10px; padding: 14px 16px;
    background: var(--color-surface, #fff);
}
.cc-card__head { display: flex; align-items: center; gap: 10px; }
.cc-card__icon { color: #8254c8; font-size: 1rem; }
.cc-card__titles { flex: 1; min-width: 0; }
.cc-card__name { font-weight: 600; color: var(--color-text, #1f2937); }
.cc-card__path { font-size: 0.76rem; color: var(--color-text-muted, #6A6D70); }
.cc-card__count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 24px; height: 24px; padding: 0 7px; border-radius: 999px;
    background: var(--c, #9aa0a6); color: #fff; font-size: 0.8rem; font-weight: 700;
}
.cc-card__trafos { margin-top: 12px; display: flex; flex-wrap: wrap; gap: 7px; }
.cc-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; border-radius: 999px;
    background: var(--color-surface-alt, #f3f5f7); color: var(--color-text, #32363A);
    font-size: 0.8rem; text-decoration: none; border: 1px solid transparent;
}
.cc-chip:hover { border-color: var(--color-primary, #0A6ED1); color: var(--color-primary, #0A6ED1); }
.cc-chip__icon { color: #E9A23B; font-size: 0.78rem; }
.cc-chip__dot { width: 8px; height: 8px; border-radius: 50%; }
.cc-card__empty { margin-top: 12px; font-size: 0.82rem; color: var(--color-text-muted, #9aa0a6); font-style: italic; }

html[data-theme="dark"] .cc-card { background: #2c3034; border-color: #3f4448; }
html[data-theme="dark"] .cc-card__name { color: #e5e6e7; }
html[data-theme="dark"] .cc-chip { background: #23272b; color: #e5e6e7; }
</style>
