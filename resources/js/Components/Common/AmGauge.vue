<script setup>
/**
 * AmGauge — gauge radial (semicírculo) con amCharts 5. Lazy: amCharts se
 * importa dinámicamente al montar, así no infla el bundle de otras páginas.
 * La licencia se aplica una sola vez desde VITE_AMCHARTS_LICENSE (.env).
 *
 * Es un PoC: mide un valor 0..max con un arco coloreado + el número al centro.
 */
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';

const props = defineProps({
    value: { type: Number, default: 0 },
    max:   { type: Number, default: 100 },
    color: { type: String, default: '#1D7044' },
    suffix:{ type: String, default: '%' },
});

const el = ref(null);
let root = null;
let rangeDataItem = null;
let centerLabel = null;
let licensed = false;

function applyLicense(am5) {
    if (licensed) return;
    const key = import.meta.env.VITE_AMCHARTS_LICENSE;
    if (key) am5.addLicense(key);
    licensed = true;
}

onMounted(async () => {
    let am5, am5radar, am5xy, animated;
    try {
        am5 = await import('@amcharts/amcharts5');
        am5radar = await import('@amcharts/amcharts5/radar');
        am5xy = await import('@amcharts/amcharts5/xy');
        animated = (await import('@amcharts/amcharts5/themes/Animated')).default;
    } catch (e) {
        // Si amCharts no carga (red/chunk), el gauge queda vacío en vez de romper.
        return;
    }

    applyLicense(am5);

    root = am5.Root.new(el.value);
    root.setThemes([animated.new(root)]);

    const chart = root.container.children.push(am5radar.RadarChart.new(root, {
        panX: false, panY: false, startAngle: 180, endAngle: 360,
        radius: am5.percent(100), innerRadius: am5.percent(72),
    }));

    const axisRenderer = am5radar.AxisRendererCircular.new(root, {
        innerRadius: am5.percent(72), strokeOpacity: 0,
    });
    axisRenderer.grid.template.set('visible', false);
    axisRenderer.labels.template.set('visible', false);
    axisRenderer.ticks.template.set('visible', false);

    const xAxis = chart.xAxes.push(am5xy.ValueAxis.new(root, {
        min: 0, max: props.max, strictMinMax: true, renderer: axisRenderer,
    }));

    // Pista de fondo (gris).
    const bgItem = xAxis.makeDataItem({ value: 0, endValue: props.max });
    xAxis.createAxisRange(bgItem);
    bgItem.get('axisFill').setAll({ visible: true, fill: am5.color(0xeceff2), fillOpacity: 1 });

    // Arco de valor (coloreado).
    rangeDataItem = xAxis.makeDataItem({ value: 0, endValue: props.value });
    xAxis.createAxisRange(rangeDataItem);
    rangeDataItem.get('axisFill').setAll({ visible: true, fill: am5.color(props.color), fillOpacity: 1 });

    // Número al centro.
    centerLabel = chart.radarContainer.children.push(am5.Label.new(root, {
        centerX: am5.percent(50), centerY: am5.percent(50),
        text: `${Math.round(props.value)}${props.suffix}`,
        fontSize: 26, fontWeight: '700', fill: am5.color(props.color),
    }));
});

watch(() => [props.value, props.color], ([v, c]) => {
    if (rangeDataItem) rangeDataItem.animate({ key: 'endValue', to: v, duration: 600 });
    if (centerLabel) centerLabel.set('text', `${Math.round(v)}${props.suffix}`);
});

onBeforeUnmount(() => { if (root) root.dispose(); });
</script>

<template>
    <div ref="el" class="am-gauge" />
</template>

<style scoped>
.am-gauge { width: 100%; height: 100%; min-height: 160px; }
</style>
