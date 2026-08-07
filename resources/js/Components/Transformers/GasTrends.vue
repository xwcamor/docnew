<script setup>
import { computed } from 'vue';
import VChart from 'vue-echarts';
import { use } from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, MarkAreaComponent, MarkLineComponent, LegendComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { Empty } from 'ant-design-vue';
import { sevRgba } from '@/utils/severity';
import { axisFromLimits, normLimit } from '@/utils/charts';
import { useI18n } from '@/Plugins/i18n';

/**
 * GasTrends — tendencias "cuadro por cuadro": un gráfico ECharts por gas/parámetro.
 *
 * Eje X = fechas de ensayo, eje Y = valor. Las FRANJAS de fondo son los límites del
 * sistema (bandas de score, sombreadas verde→rojo por severidad) para que se vea de
 * un vistazo en qué nivel cae cada gas y cuándo lo cruzó. Con animación de entrada.
 *
 * Genérico: sirve para cromas (gases, límites por aceite+trafo), y se puede reusar
 * para fiquis/furanos pasándole sus propias bandas.
 */
use([LineChart, GridComponent, TooltipComponent, MarkAreaComponent, MarkLineComponent, LegendComponent, CanvasRenderer]);

const props = defineProps({
    samples: { type: Array,  default: () => [] },       // [{ sample_date, [key]: number|null }]
    series:  { type: Array,  default: () => [] },       // [{ key, label, color, unit? }]
    limits:  { type: Object, default: () => ({}) },     // { [key]: [{ from, to, sev }] }  sev 0=mejor..1=peor
    unit:    { type: String, default: '' },
    noData:  { type: String, default: '' },
    // Alto de cada gráfico (px) y si ocupa todo el ancho (útil para una sola serie).
    chartHeight: { type: Number,  default: 200 },
    fullWidth:   { type: Boolean, default: false },
    // Animación: se desactiva para las instancias de captura (PNG del PDF).
    animate:     { type: Boolean, default: true },
    // Cuadro combinado arriba con TODAS las series y leyenda clickeable (cromas).
    showCombined: { type: Boolean, default: false },
    // Series a ocultar por defecto en el combinado (ej. N2/O2 = enormes, aplanan).
    combinedHidden: { type: Array, default: () => [] },
});

// Instancias de los gráficos, para capturarlos como PNG (reporte PDF).
const chartEls = {};
const setChartEl = (el, key) => { if (el) chartEls[key] = el; };

const grab = (inst, label) => {
    const ec = inst?.chart ?? inst; // vue-echarts proxia la instancia
    if (!ec || typeof ec.getDataURL !== 'function') return null;
    return { label, dataURL: ec.getDataURL({ type: 'png', pixelRatio: 1.5, backgroundColor: '#ffffff' }) };
};

/**
 * Devuelve [{ label, dataURL }] para el reporte PDF: un PNG por serie visible
 * (cada gas/parámetro con sus franjas de límite, como el sistema viejo). El
 * cuadro combinado es solo para la pantalla — en el informe no va.
 */
/**
 * Leyenda del cuadro en el PDF. El PNG que captura echarts NO incluye el
 * título (ese es HTML), así que el blade imprime esta cadena: se compone igual
 * que el título de pantalla (nombre · símbolo · medida). Sin esto, los
 * parámetros cuyo nombre no lleva la medida — Número Ácido, Contenido de Agua,
 * Tensión Interfacial — salían sin unidad en el informe.
 */
const captionFor = (s) => {
    const u = unitFor(s);
    return [s.label, s.sym, u && !s.hideUnit ? `(${u})` : null].filter(Boolean).join(' ');
};

const getImages = () =>
    shownSeries.value.map((s) => grab(chartEls[s.key], captionFor(s))).filter(Boolean);

defineExpose({ getImages });

const { t } = useI18n();
const unitFor = (s) => s.unit || props.unit;

const sorted = computed(() =>
    [...props.samples].sort((a, b) => (a.sample_date < b.sample_date ? -1 : 1)),
);
const dates = computed(() => sorted.value.map((s) => s.sample_date));

// Solo gases con al menos un valor medido (evita cuadros vacíos).
const shownSeries = computed(() =>
    props.series.filter((s) => sorted.value.some((r) => r[s.key] !== null && r[s.key] !== undefined)),
);
const hasData = computed(() => sorted.value.length > 0 && shownSeries.value.length > 0);

// Con MUCHAS muestras (>10 puntos) las tarjetas chicas del grid quedan
// ilegibles (el eje de tiempo se aplasta): cada tendencia pasa a ocupar la
// fila completa, igual que con la prop fullWidth. Aplica también a las
// instancias ocultas de captura → los PNG del PDF salen anchos (el blade los
// pinta 1 por fila con la misma condición).
const manySamples = computed(() => sorted.value.length > 10);

// Cuadro combinado: todas las series en un gráfico, con leyenda clickeable
// (clic en un gas lo muestra/oculta). Sin franjas de límite (cada gas tiene la
// suya). Por defecto se ocultan las series pesadas (N2/O2) para que se lea.
// Computed (cacheado): NO se reconstruye en cada render. Si fuera una función
// llamada en el template (:option="combinedOption()"), un re-render del padre
// (ej. el polling del inbox cada N segundos) la recrearía con legend.selected
// en los defaults → echarts borraría los gases que el usuario ocultó. Como
// computed, solo cambia si cambian las muestras/series → la leyenda persiste.
// En el cuadro COMBINADO manda el símbolo (H₂, CO₂…): 9 nombres completos no
// entran en la leyenda. El nombre va en el título de cada cuadro individual.
const legendName = (s) => s.sym || s.label;

const combinedOption = computed(() => {
    const hidden = props.combinedHidden;
    const selected = {};
    shownSeries.value.forEach((s) => { selected[legendName(s)] = !hidden.includes(s.key); });

    return {
        animationDuration: props.animate ? 700 : 0,
        animationEasing: 'cubicOut',
        grid: { top: 44, right: 16, bottom: 38, left: 56 },
        tooltip: {
            trigger: 'axis',
            valueFormatter: (v) => (v === null || v === undefined ? '—' : `${v} ${props.unit}`),
        },
        legend: {
            type: 'scroll', top: 6, icon: 'roundRect',
            data: shownSeries.value.map(legendName),
            selected,
            textStyle: { fontSize: 11 },
        },
        xAxis: {
            type: 'category', data: dates.value,
            axisLabel: { fontSize: 10, rotate: dates.value.length > 4 ? 30 : 0, hideOverlap: true },
        },
        yAxis: { type: 'value', axisLabel: { fontSize: 10 } },
        series: shownSeries.value.map((s) => ({
            name: legendName(s), type: 'line',
            data: sorted.value.map((r) => (r[s.key] === undefined ? null : r[s.key])),
            connectNulls: true, smooth: true, symbol: 'circle', symbolSize: 5,
            lineStyle: { color: s.color, width: 2 },
            itemStyle: { color: s.color, borderColor: '#fff', borderWidth: 1 },
        })),
    };
});

// Opciones por gas (mismo motivo: cacheadas para no re-setOption en cada render
// y evitar parpadeo con el polling). optionFor es función (hoisted), ok llamarla.
const optionsByKey = computed(() => {
    const m = {};
    shownSeries.value.forEach((s) => { m[s.key] = optionFor(s); });
    return m;
});

function optionFor(s) {
    const values = sorted.value.map((r) => (r[s.key] === undefined ? null : r[s.key]));
    const bands = props.limits[s.key] ?? [];
    // Tope del eje derivado de los LÍMITES (ver utils/charts.js): el cuadro no
    // se estira y las franjas se leen. Nunca recorta el dato.
    const { max: yMax, edges } = axisFromLimits(values, bands);

    // Franjas de límite (sombreado por severidad), recortadas a yMax.
    const areas = bands
        .filter((b) => b.from < yMax)
        .map((b) => ([
            { yAxis: b.from, itemStyle: { color: sevRgba(b.sev, 0.14) } },
            { yAxis: Math.min(b.to ?? yMax, yMax) },
        ]));
    // Líneas de límite (las que entran en el rango visible). La de NORMA va
    // rotulada ("Límite 150") para que el cuadro diga el número, no solo lo
    // insinúe con una raya; el resto de cortes quedan como guías mudas.
    const norma = normLimit(bands);
    const lines = edges.filter((e) => e <= yMax).map((e) => (
        e === norma
            ? {
                yAxis: e,
                lineStyle: { type: 'dashed', color: '#C8281D', width: 1.2, opacity: 0.85 },
                label: {
                    // DEBAJO de la línea: el límite suele coincidir con el tope
                    // del eje y por arriba el rótulo se sale del cuadro.
                    show: true, position: 'insideEndBottom',
                    formatter: `${t('diagnostics.chart_limit')} ${e}`,
                    fontSize: 10, color: '#C8281D', fontWeight: 'bold',
                    backgroundColor: 'rgba(255,255,255,0.78)', borderRadius: 3, padding: [2, 4, 2, 4],
                },
            }
            : { yAxis: e }
    ));

    return {
        animationDuration: props.animate ? 800 : 0,
        animationEasing: 'cubicOut',
        grid: { top: 10, right: 12, bottom: 38, left: 46 },
        tooltip: {
            trigger: 'axis',
            valueFormatter: (v) => (v === null || v === undefined ? '—' : `${v} ${unitFor(s)}`),
        },
        xAxis: {
            type: 'category', data: dates.value,
            axisLabel: { fontSize: 10, rotate: dates.value.length > 4 ? 30 : 0, hideOverlap: true },
        },
        yAxis: { type: 'value', max: yMax, axisLabel: { fontSize: 10 } },
        series: [{
            name: s.label, type: 'line', data: values, connectNulls: true,
            smooth: true, symbol: 'circle', symbolSize: 7,
            lineStyle: { color: s.color, width: 2.5 },
            itemStyle: { color: s.color, borderColor: '#fff', borderWidth: 1.5 },
            markArea: { silent: true, data: areas },
            markLine: {
                silent: true, symbol: 'none',
                lineStyle: { type: 'dashed', color: '#9aa0a6', width: 1, opacity: 0.6 },
                label: { show: false },
                data: lines,
            },
        }],
    };
}
</script>

<template>
    <div class="gtrends">
        <p v-if="$slots.hint || unit" class="gtrends__hint"><slot name="hint" /></p>

        <div v-if="!hasData" class="gtrends__empty">
            <Empty :description="noData" />
        </div>

        <template v-else>
            <!-- Cuadro combinado: todos los gases, leyenda clickeable. -->
            <div v-if="showCombined" class="gtrends__card gtrends__card--combined lift">
                <div class="gtrends__title">
                    {{ $t('cromas.trends_all_gases') }}
                    <span class="gtrends__hinttag">{{ $t('cromas.trends_all_hint') }}</span>
                </div>
                <VChart :ref="(el) => setChartEl(el, '__combined')" class="gtrends__chart gtrends__chart--combined" :option="combinedOption" autoresize />
            </div>

            <div class="gtrends__grid" :class="{ 'gtrends__grid--full': fullWidth || manySamples }">
                <div v-for="s in shownSeries" :key="s.key" class="gtrends__card lift">
                <div class="gtrends__title">
                    <span class="gtrends__dot" :style="{ background: s.color }"></span>{{ s.label }}
                    <span v-if="s.sym" class="gtrends__sym">{{ s.sym }}</span>
                    <!-- hideUnit: el título ya la trae dentro del distintivo
                         (ej. "Factor de Potencia — 25 °C. %"). -->
                    <span v-if="unitFor(s) && !s.hideUnit" class="gtrends__unit">({{ unitFor(s) }})</span>
                </div>
                    <VChart :ref="(el) => setChartEl(el, s.key)" class="gtrends__chart" :style="{ height: chartHeight + 'px' }" :option="optionsByKey[s.key]" autoresize />
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
.gtrends__hint { font-size: 0.82rem; color: var(--color-text-muted, #6A6D70); margin: 0 0 14px; }
.gtrends__empty { padding: 24px 0; }
.gtrends__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
.gtrends__grid--full { grid-template-columns: 1fr; }
.gtrends__card { border: 1px solid var(--color-border, #e2e6ea); border-radius: 10px; padding: 10px 12px 6px; }
.gtrends__title { font-size: 0.82rem; font-weight: 700; color: var(--color-text, #32363a); display: flex; align-items: center; gap: 7px; margin-bottom: 4px; }
.gtrends__unit { color: var(--color-text-muted, #9aa0a6); font-weight: 500; }
/* Símbolo químico junto al nombre del gas (Hidrógeno H₂). */
.gtrends__sym { font-weight: 700; color: var(--color-text, #32363a); }
.gtrends__dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.gtrends__chart { height: 200px; width: 100%; }
.gtrends__card--combined { margin-bottom: 16px; }
.gtrends__chart--combined { height: 320px; }
.gtrends__hinttag { font-weight: 500; font-size: 0.74rem; color: var(--color-text-muted, #9aa0a6); margin-left: 8px; }

html[data-theme="dark"] .gtrends__card { border-color: #3f4448; }
</style>
