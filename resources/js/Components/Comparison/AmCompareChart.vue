<script setup>
/**
 * AmCompareChart — réplica del demo amCharts 5 "Highlighting line chart series
 * on legend hover" (https://www.amcharts.com/demos/highlighting-line-chart-series-on-legend-hover/),
 * adaptado a la comparación de gases por grupo (una serie por transformador).
 *
 * Igual que el demo: leyenda VERTICAL a la derecha (rightAxesContainer),
 * scrollbars X e Y, y al pasar el mouse por un ítem de la leyenda se resalta su
 * serie y se atenúan las demás (pointerover / pointerout).
 *
 * amCharts lazy + licencia desde VITE_AMCHARTS_LICENSE (como AmGauge).
 */
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';

const props = defineProps({
    // [{ name, color, data: [{ date: 'YYYY-MM-DD', value: number }] }]
    series: { type: Array, default: () => [] },
    height: { type: Number, default: 380 },
});

const el = ref(null);
let root = null;
let chart = null;
let xAxis = null;
let yAxis = null;
let legend = null;
let am5ref = null;
let licensed = false;

function applyLicense(am5) {
    if (licensed) return;
    const key = import.meta.env.VITE_AMCHARTS_LICENSE;
    if (key) am5.addLicense(key);
    licensed = true;
}

const toData = (d) => (d ?? []).map((p) => ({ t: new Date(p.date).getTime(), v: p.value }));

function buildSeries(am5, am5xy) {
    chart.series.clear();
    props.series.forEach((s) => {
        const series = chart.series.push(am5xy.SmoothedXLineSeries.new(root, {
            name: s.name,
            xAxis,
            yAxis,
            valueYField: 'v',
            valueXField: 't',
            tension: 0.8,
            stroke: am5.color(s.color),
            fill: am5.color(s.color),
            legendValueText: '{valueY} ppm',
            tooltip: am5.Tooltip.new(root, {
                pointerOrientation: 'horizontal',
                labelText: '{name}: [bold]{valueY}[/] ppm',
            }),
        }));
        series.strokes.template.setAll({ strokeWidth: 2 });
        series.data.setAll(toData(s.data));
        series.appear();
    });
    if (legend) legend.data.setAll(chart.series.values);
}

onMounted(async () => {
    let am5, am5xy, animated;
    try {
        am5 = await import('@amcharts/amcharts5');
        am5xy = await import('@amcharts/amcharts5/xy');
        animated = (await import('@amcharts/amcharts5/themes/Animated')).default;
    } catch (e) {
        return;
    }
    am5ref = { am5, am5xy };
    applyLicense(am5);

    root = am5.Root.new(el.value);
    root.setThemes([animated.new(root)]);

    chart = root.container.children.push(am5xy.XYChart.new(root, {
        panX: true, panY: true, wheelX: 'panX', wheelY: 'zoomX', pinchZoomX: true,
    }));

    // Cursor (sin zoom por arrastre; guía visible).
    const cursor = chart.set('cursor', am5xy.XYCursor.new(root, { behavior: 'none' }));
    cursor.lineY.set('visible', false);

    // Ejes.
    xAxis = chart.xAxes.push(am5xy.DateAxis.new(root, {
        maxDeviation: 0.2,
        baseInterval: { timeUnit: 'day', count: 1 },
        renderer: am5xy.AxisRendererX.new(root, {}),
        tooltip: am5.Tooltip.new(root, {}),
    }));
    yAxis = chart.yAxes.push(am5xy.ValueAxis.new(root, {
        renderer: am5xy.AxisRendererY.new(root, {}),
    }));

    // Leyenda VERTICAL a la derecha (igual que el demo).
    legend = chart.rightAxesContainer.children.push(am5.Legend.new(root, {
        width: 200,
        paddingLeft: 15,
        height: am5.percent(100),
    }));

    // Resaltar la serie del ítem hover; atenuar el resto.
    legend.itemContainers.template.events.on('pointerover', (e) => {
        const series = e.target.dataItem.dataContext;
        chart.series.each((cs) => {
            if (cs !== series) {
                cs.strokes.template.setAll({ strokeOpacity: 0.15, stroke: am5.color(0x000000) });
            } else {
                cs.strokes.template.setAll({ strokeWidth: 3 });
            }
        });
    });
    legend.itemContainers.template.events.on('pointerout', () => {
        chart.series.each((cs) => {
            cs.strokes.template.setAll({ strokeOpacity: 1, strokeWidth: 2, stroke: cs.get('fill') });
        });
    });

    legend.itemContainers.template.set('width', am5.percent(100));
    legend.valueLabels.template.setAll({ width: am5.percent(100), textAlign: 'right' });

    // Scrollbars X (horizontal) e Y (vertical) como en el demo.
    chart.set('scrollbarX', am5.Scrollbar.new(root, { orientation: 'horizontal' }));
    chart.set('scrollbarY', am5.Scrollbar.new(root, { orientation: 'vertical' }));

    buildSeries(am5, am5xy);
    chart.appear(1000, 100);
});

watch(() => props.series, () => {
    if (root && am5ref) buildSeries(am5ref.am5, am5ref.am5xy);
}, { deep: true });

onBeforeUnmount(() => { if (root) root.dispose(); });
</script>

<template>
    <div ref="el" class="am-cmp" :style="{ height: height + 'px' }" />
</template>

<style scoped>
.am-cmp { width: 100%; }
</style>
