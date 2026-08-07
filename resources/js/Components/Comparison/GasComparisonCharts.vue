<script setup>
/**
 * GasComparisonCharts — un gráfico por gas, una línea por transformador.
 *
 * Migra los "9 cuadros de tendencia comparados" del sistema viejo. Cada gas con
 * su propio gráfico (amCharts 5, AmCompareChart); leyenda compartida arriba.
 */
import { computed } from 'vue';
import { Empty } from 'ant-design-vue';
import AmCompareChart from '@/Components/Comparison/AmCompareChart.vue';

const props = defineProps({
    // [{ id, label, samples: [{ sample_date, h2, ch4, ... }] }]
    transformers: { type: Array, default: () => [] },
    chartHeight:  { type: Number, default: 380 },
});

const GASES = [
    { key: 'h2',   label: 'H₂' },
    { key: 'ch4',  label: 'CH₄' },
    { key: 'c2h6', label: 'C₂H₆' },
    { key: 'c2h4', label: 'C₂H₄' },
    { key: 'c2h2', label: 'C₂H₂' },
    { key: 'co',   label: 'CO' },
    { key: 'co2',  label: 'CO₂' },
    { key: 'o2',   label: 'O₂' },
    { key: 'n2',   label: 'N₂' },
];

const PALETTE = ['#0A6ED1', '#C8281D', '#1D7044', '#E9A23B', '#8E44AD', '#2AA198', '#D81B60', '#5D4037', '#0097A7', '#7B6F00'];
const colored = computed(() => props.transformers.map((t, i) => ({ ...t, color: PALETTE[i % PALETTE.length] })));

const hasData = computed(() => colored.value.some((t) => (t.samples ?? []).length > 0));

const shownGases = computed(() => GASES.filter((g) =>
    colored.value.some((t) => (t.samples ?? []).some((s) => s[g.key] !== null && s[g.key] !== undefined)),
));

// Series por gas: una serie por transformador con [{date, value}].
const seriesFor = (gasKey) => colored.value.map((t) => ({
    name: t.label,
    color: t.color,
    data: (t.samples ?? [])
        .filter((s) => s.sample_date && s[gasKey] !== null && s[gasKey] !== undefined)
        .map((s) => ({ date: s.sample_date, value: s[gasKey] })),
}));
</script>

<template>
    <div class="gcmp">
        <div v-if="!hasData" class="gcmp__empty"><Empty :description="$t('comparison.no_data')" /></div>

        <div v-else class="gcmp__grid">
            <div v-for="g in shownGases" :key="g.key" class="gcmp__card">
                <div class="gcmp__title">{{ g.label }} <span class="gcmp__unit">(ppm)</span></div>
                <AmCompareChart :series="seriesFor(g.key)" :height="chartHeight" />
            </div>
        </div>
    </div>
</template>

<style scoped>
.gcmp__empty { padding: 32px 0; }
.gcmp__grid { display: grid; grid-template-columns: 1fr; gap: 18px; }
.gcmp__card { border: 1px solid var(--color-border, #e2e6ea); border-radius: 10px; padding: 10px 12px 6px; }
.gcmp__title { font-size: 0.85rem; font-weight: 700; color: var(--color-text, #32363a); margin-bottom: 4px; }
.gcmp__unit { color: var(--color-text-muted, #9aa0a6); font-weight: 500; }
html[data-theme="dark"] .gcmp__card { border-color: #3f4448; }
</style>
