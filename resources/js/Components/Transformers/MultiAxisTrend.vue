<script setup>
import { computed } from 'vue';
import VChart from 'vue-echarts';
import { use } from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent, DataZoomComponent, ToolboxComponent, MarkLineComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { Empty } from 'ant-design-vue';
import { LineChartOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import { axisFromLimits } from '@/utils/charts';

use([LineChart, GridComponent, TooltipComponent, LegendComponent, DataZoomComponent, ToolboxComponent, MarkLineComponent, CanvasRenderer]);

/**
 * MultiAxisTrend — gráfico de tendencia multi-eje (echarts). Cada serie tiene su
 * propio eje Y (con su unidad y color), slider + zoom. Para fiquis: agrupa varios
 * parámetros de distinta unidad en un solo cuadro. NO se captura para el PDF.
 */
const props = defineProps({
    samples:   { type: Array,  default: () => [] },
    series:    { type: Array,  default: () => [] }, // [{ key, label, color, unit }]
    limits:    { type: Object, default: () => ({}) },
});

const { t } = useI18n();

const sorted = computed(() => [...props.samples].sort((a, b) => (a.sample_date < b.sample_date ? -1 : 1)));
const dates = computed(() => sorted.value.map((r) => r.sample_date));
const activeSeries = computed(() =>
    props.series.filter((s) => sorted.value.some((r) => r[s.key] !== null && r[s.key] !== undefined && r[s.key] !== '')));
const hasData = computed(() => dates.value.length > 0 && activeSeries.value.length > 0);

const title = computed(() => {
    const labels = activeSeries.value.map((s) => s.label);
    if (labels.length === 0) return '';
    const joined = labels.length > 1
        ? `${labels.slice(0, -1).join(', ')} ${t('fiquis.trend_and')} ${labels[labels.length - 1]}`
        : labels[0];
    return t('fiquis.trend_chart_title', { params: joined });
});

const option = computed(() => {
    const ser = activeSeries.value;
    const unitOf = Object.fromEntries(ser.map((s) => [s.label, s.unit]));

    // Eje de CADA parámetro acotado a SUS límites normativos (utils/charts.js).
    // Antes la prop `limits` se recibía y no se usaba: cada eje se auto-escalaba
    // al dato y el cuadro se estiraba sin que se entendiera dónde está la norma.
    const axisOf = Object.fromEntries(ser.map((s) => [
        s.key,
        axisFromLimits(sorted.value.map((r) => (r[s.key] === '' || r[s.key] == null ? null : Number(r[s.key]))), props.limits[s.key]),
    ]));

    let rightIdx = 0;
    const yAxis = ser.map((s, i) => {
        const right = i > 0;
        const offset = right ? rightIdx++ * 52 : 0;
        return {
            type: 'value', scale: true, position: right ? 'right' : 'left', offset,
            max: axisOf[s.key]?.max,
            axisLine: { show: true, lineStyle: { color: s.color } },
            axisTick: { show: true, lineStyle: { color: s.color } },
            axisLabel: { fontSize: 9, color: s.color, formatter: (v) => `${v}${s.unit ? ' ' + s.unit : ''}` },
            splitLine: { show: i === 0, lineStyle: { color: '#eef0f2' } },
        };
    });
    const rightCount = ser.length - 1;
    return {
        animationDuration: 600,
        grid: { left: 6, right: 12 + Math.max(0, rightCount - 1) * 52, top: 48, bottom: 30, containLabel: true },
        // Leyenda: SOLO una línea de color (roundRect finito), sin el anillo que
        // echarts dibuja por defecto para series line.
        legend: { top: 2, type: 'scroll', icon: 'roundRect', itemWidth: 18, itemHeight: 3, textStyle: { fontSize: 11 }, data: ser.map((s) => s.label) },
        tooltip: {
            trigger: 'axis',
            formatter: (ps) => {
                const head = ps[0]?.axisValueLabel ?? '';
                const rows = ps.map((p) => `${p.marker}${p.seriesName}: <strong>${p.value ?? '—'}</strong>${unitOf[p.seriesName] ? ' ' + unitOf[p.seriesName] : ''}`).join('<br>');
                return `${head}<br>${rows}`;
            },
        },
        dataZoom: [
            { type: 'slider', top: 24, height: 14, start: 0, end: 100 },
            { type: 'inside' },
        ],
        toolbox: { right: 8, top: 0, feature: { dataZoom: { yAxisIndex: 'none' }, restore: {} }, iconStyle: { borderColor: '#8a9099' } },
        xAxis: {
            type: 'category', data: dates.value, boundaryGap: false,
            axisLabel: { fontSize: 9, rotate: dates.value.length > 6 ? 30 : 0, hideOverlap: true },
        },
        yAxis,
        series: ser.map((s, i) => ({
            // symbol 'circle' = punto RELLENO del color de la serie (el default
            // de echarts es emptyCircle: anillo hueco).
            name: s.label, type: 'line', smooth: true, showSymbol: true, symbol: 'circle', symbolSize: 6, connectNulls: true,
            yAxisIndex: i, itemStyle: { color: s.color }, lineStyle: { color: s.color, width: 2 },
            data: sorted.value.map((r) => (r[s.key] === '' || r[s.key] == null ? null : Number(r[s.key]))),
            // Límites que el dato YA superó: quedan DENTRO del cuadro, así que
            // se marcan con una línea punteada del color de su parámetro (cada
            // una sobre SU eje). Los límites que están por encima del dato no
            // llevan línea: ahí el tope del eje ya ES el límite.
            markLine: {
                silent: true, symbol: 'none',
                lineStyle: { type: 'dashed', color: s.color, width: 1, opacity: 0.55 },
                label: { show: false },
                data: (axisOf[s.key]?.crossed ?? []).map((e) => ({ yAxis: e })),
            },
        })),
    };
});
</script>

<template>
    <div class="mat lift">
        <div class="mat__head">
            <span class="mat__title"><LineChartOutlined /> {{ title }}</span>
        </div>
        <VChart v-if="hasData" class="mat__chart" :option="option" autoresize />
        <div v-else class="mat__empty"><Empty :description="$t('fiquis.trends_no_data')" /></div>
    </div>
</template>

<style scoped>
.mat {
    border: 1px solid var(--color-border, #e2e6ea); border-radius: 12px; padding: 14px 14px 8px;
    background: var(--color-surface, #fff); box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04); margin-bottom: 18px;
}
.mat__head { display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
.mat__title { font-size: 0.9rem; font-weight: 700; color: var(--color-text, #32363a); display: inline-flex; align-items: center; gap: 6px; }
.mat__chart { width: 100%; height: 300px; }
.mat__empty { padding: 24px 0; }
html[data-theme="dark"] .mat { border-color: #3f4448; background: #24282c; }
</style>
