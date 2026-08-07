<script setup>
/**
 * FleetDashboard — panel de flota del tenant (admin/operativo). Todos los datos
 * llegan ya filtrados y scope-safe del backend (DashboardController). Aquí solo
 * se visualiza (echarts) y se disparan los filtros vía recarga parcial Inertia.
 */
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { Card, Empty, Select, Button, Tooltip } from 'ant-design-vue';
import { ClearOutlined } from '@ant-design/icons-vue';
import VChart from 'vue-echarts';
import { use, registerMap } from 'echarts/core';
import { PieChart, BarChart, MapChart, HeatmapChart, GaugeChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent, VisualMapComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { usePlanFeatures } from '@/Composables/usePlanFeatures';
import SavedViews from '@/Components/Common/SavedViews.vue';
import LazyMount from '@/Components/Common/LazyMount.vue';

use([PieChart, BarChart, MapChart, HeatmapChart, GaugeChart, GridComponent, TooltipComponent, LegendComponent, VisualMapComponent, CanvasRenderer]);

const { t } = useI18n();
const { condLabel } = useConditions();
const { canUse: canUsePlanFeature } = usePlanFeatures();

const props = defineProps({
    fleet:   { type: Object, required: true },
    filters: { type: Object, default: () => ({ customers: [], types: [] }) },
    active:  { type: Object, default: () => ({ customer: [], type: [], rating: [] }) },
});

// ── Estado de los filtros (inicializado con lo activo del backend) ──
const fCustomer = ref([...(props.active.customer ?? [])]);
const fType     = ref([...(props.active.type ?? [])]);
const fRating   = ref([...(props.active.rating ?? [])]);
const fTenant   = ref([...(props.active.tenant ?? [])]);
const hasTenants = computed(() => (props.filters.tenants ?? []).length > 0);

// Clientes en MAYÚSCULAS y ordenados A→Z (el resto de los filtros se dejan como vienen).
const customerOptions = computed(() =>
    [...(props.filters.customers ?? [])]
        .map((o) => ({ ...o, label: (o.label ?? '').toUpperCase() }))
        .sort((a, b) => a.label.localeCompare(b.label)),
);

const RATING_LABELS = [
    { value: 4, label: t('diagnostics.cond_muy_bueno') },
    { value: 3, label: t('diagnostics.cond_bueno') },
    { value: 2, label: t('diagnostics.cond_medio') },
    { value: 1, label: t('diagnostics.cond_malo') },
    { value: 0, label: t('diagnostics.cond_muy_malo') },
];

const apply = () => {
    router.get(route('dashboard_management.dashboards.index'),
        { f_customer: fCustomer.value, f_type: fType.value, f_rating: fRating.value, f_tenant: fTenant.value },
        { preserveScroll: true, preserveState: true, only: ['fleet', 'fleetActive'] });
};
const hasFilters = computed(() => fCustomer.value.length || fType.value.length || fRating.value.length || fTenant.value.length);
const clearFilters = () => { fCustomer.value = []; fType.value = []; fRating.value = []; fTenant.value = []; apply(); };

// ── Vistas guardadas del dashboard (reusa el SavedViews genérico, módulo
// 'dashboard_fleet'). Solo persistimos los filtros del panel de flota. ──
const dashViewState = computed(() => ({
    filters: { customer: fCustomer.value, type: fType.value, rating: fRating.value, tenant: fTenant.value },
}));
const applyDashView = (state) => {
    const f = state?.filters ?? {};
    fCustomer.value = [...(f.customer ?? [])];
    fType.value     = [...(f.type ?? [])];
    fRating.value   = [...(f.rating ?? [])];
    fTenant.value   = [...(f.tenant ?? [])];
    apply();
};

const kpis = computed(() => props.fleet?.kpis ?? {});

// Gauge "Flota sana %" en echarts (la librería YA está cargada; antes esto
// usaba amCharts = una segunda librería entera solo para este widget).
const gaugeOption = computed(() => {
    const val = Math.round(kpis.value?.sanos_pct ?? 0);
    return {
        series: [{
            type: 'gauge',
            startAngle: 220, endAngle: -40, min: 0, max: 100,
            progress: { show: true, width: 16, roundCap: true, itemStyle: { color: '#1D7044' } },
            axisLine: { roundCap: true, lineStyle: { width: 16, color: [[1, '#eef0f2']] } },
            axisTick: { show: false }, splitLine: { show: false }, axisLabel: { show: false },
            pointer: { show: false }, anchor: { show: false },
            title: { show: false },
            detail: {
                valueAnimation: true, formatter: '{v|{value}%}',
                rich: { v: { fontSize: 30, fontWeight: 700, color: '#1D7044' } },
                offsetCenter: [0, 0],
            },
            data: [{ value: val }],
        }],
    };
});

// ── Dona: estado de salud ──
const healthOption = computed(() => {
    const data = (props.fleet?.health ?? []).map((h) => ({
        name: h.cond === 'sin_dx' ? t('dashboard.fleet.sin_dx') : t('diagnostics.cond_' + h.cond),
        value: h.count, rating: h.rating, itemStyle: { color: h.color },
    }));
    return {
        tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
        legend: { bottom: 0, left: 'center', textStyle: { color: '#6A6D70', fontSize: 11 } },
        series: [{
            type: 'pie', radius: ['48%', '72%'], center: ['50%', '44%'],
            avoidLabelOverlap: true, label: { show: false }, data,
        }],
    };
});
const healthTotal = computed(() => (props.fleet?.health ?? []).reduce((s, h) => s + h.count, 0));

// ── Barras horizontales reutilizables (tipo, marca, país) ──
const barOption = (items, color) => ({
    grid: { left: 4, right: 16, top: 8, bottom: 4, containLabel: true },
    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
    xAxis: { type: 'value', axisLabel: { color: '#9aa0a6' }, splitLine: { lineStyle: { color: '#eef0f2' } } },
    yAxis: { type: 'category', inverse: true, data: items.map((i) => i.name ?? i.country), axisLabel: { color: '#6A6D70' } },
    series: [{ type: 'bar', data: items.map((i) => i.count), itemStyle: { color, borderRadius: [0, 4, 4, 0] }, barMaxWidth: 22 }],
});
const typeOption    = computed(() => barOption(props.fleet?.byType ?? [], '#0A6ED1'));
const brandOption   = computed(() => barOption(props.fleet?.byBrand ?? [], '#5AA82E'));

// ── Mapa de países (choropleth). Necesita un GeoJSON del mundo en
// public/maps/world.json (no se puede traer aquí). Si no está, se muestran las
// barras por país (fallback). Defensivo: cualquier fallo → barras. ──
const mapReady = ref(false);
let mapLoading = false;
// DIFERIDO: el world.json (pesado) se baja SOLO cuando el mapa entra en pantalla
// (lo dispara el @visible del LazyMount que envuelve el mapa). Si nunca scrolleás
// hasta el mapa, nunca se descarga. Mientras tanto se ven las barras por país.
const loadWorldMap = async () => {
    if (mapReady.value || mapLoading) return;
    mapLoading = true;
    try {
        const res = await fetch('/maps/world.json', { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const geo = await res.json();
        registerMap('world', geo);
        mapReady.value = true;
    } catch (e) { /* sin mapa: quedan las barras */ }
    finally { mapLoading = false; }
};
// Puente nombres ES (tabla countries) → nombres del GeoJSON (public/maps/world.json,
// en inglés). Cubre todos los países de la BD; los que no figuran se pasan tal cual.
const NAME_MAP = {
    'Perú': 'Peru', 'México': 'Mexico', 'Panamá': 'Panama', 'Brasil': 'Brazil',
    'Estados Unidos': 'United States of America', 'España': 'Spain', 'Reino Unido': 'United Kingdom',
    'Alemania': 'Germany', 'Francia': 'France', 'Italia': 'Italy', 'Japón': 'Japan',
    'República Dominicana': 'Dominican Republic', 'Trinidad y Tobago': 'Trinidad and Tobago',
};
const mapOption = computed(() => {
    const items = (props.fleet?.byCountry ?? []).filter((c) => c.country && c.country !== '—')
        .map((c) => ({ name: NAME_MAP[c.country] ?? c.country, value: c.count }));
    const max = Math.max(1, ...items.map((i) => i.value));
    return {
        tooltip: { trigger: 'item', formatter: (p) => `${p.name}: ${p.value ?? 0}` },
        visualMap: { min: 0, max, left: 8, bottom: 8, calculable: false, inRange: { color: ['#dbeafe', '#0A6ED1'] }, textStyle: { color: '#6A6D70', fontSize: 10 } },
        series: [{ type: 'map', map: 'world', roam: false, label: { show: false }, emphasis: { label: { show: false }, itemStyle: { areaColor: '#9BD64A' } }, itemStyle: { areaColor: '#f0f2f4', borderColor: '#fff', borderWidth: 0.5 }, data: items }],
    };
});

// ── Barras por país (conteo de trafos) — acompaña al mapa ──
const countryBarsOption = computed(() => {
    const items = (props.fleet?.byCountry ?? []).filter((c) => c.country && c.country !== '—');
    return barOption(items, '#7E57C2');
});
const hasCountry = computed(() => (props.fleet?.byCountry ?? []).filter((c) => c.country && c.country !== '—').length > 0);

// ── Tipos de falla (Duval) — dona con familias y colores propios ──
const FAULT = {
    thermal:   { color: '#E2661E' },
    discharge: { color: '#C8281D' },
    pd:        { color: '#9C27B0' },
    mixed:     { color: '#E9A23B' },
    other:     { color: '#6A6D70' },
    normal:    { color: '#5AA82E' },
};
const hasFault = computed(() => (props.fleet?.byFault ?? []).length > 0);
const faultOption = computed(() => ({
    tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
    legend: { bottom: 0, left: 'center', textStyle: { color: '#6A6D70', fontSize: 11 } },
    series: [{
        type: 'pie', radius: ['46%', '70%'], center: ['50%', '44%'], avoidLabelOverlap: true, label: { show: false },
        data: (props.fleet?.byFault ?? []).map((f) => ({ name: t('dashboard.fleet.fault_' + f.code), value: f.count, itemStyle: { color: FAULT[f.code]?.color ?? '#6A6D70' } })),
    }],
}));

// ── Condición IEEE C57.104 (1 normal … 4 excede) — barras verticales ──
const IEEE_COLOR = { 1: '#1D7044', 2: '#E9A23B', 3: '#E2661E', 4: '#C8281D' };
const hasIeee = computed(() => (props.fleet?.ieeeLevels ?? []).some((l) => l.count > 0));
const ieeeOption = computed(() => {
    const lv = props.fleet?.ieeeLevels ?? [];
    return {
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
        grid: { left: 4, right: 12, top: 14, bottom: 24, containLabel: true },
        xAxis: { type: 'category', data: lv.map((l) => t('dashboard.fleet.ieee_' + l.level)), axisLabel: { color: '#6A6D70', fontSize: 10 } },
        yAxis: { type: 'value', minInterval: 1, axisLabel: { color: '#9aa0a6' }, splitLine: { lineStyle: { color: '#eef0f2' } } },
        series: [{ type: 'bar', data: lv.map((l) => ({ value: l.count, itemStyle: { color: IEEE_COLOR[l.level], borderRadius: [4, 4, 0, 0] } })), barMaxWidth: 48 }],
    };
});

// ── Vida del papel (DP Chendong) — barras por banda; <200 = fin de vida ──
const DP_BUCKETS = [
    { color: '#C8281D' }, { color: '#E2661E' }, { color: '#E9A23B' }, { color: '#5AA82E' }, { color: '#1D7044' },
];
const hasPaper = computed(() => (props.fleet?.paperAging ?? []).some((b) => b.count > 0));
const paperEol = computed(() => props.fleet?.paperEol ?? 0);
const paperOption = computed(() => {
    const ag = props.fleet?.paperAging ?? [];
    return {
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, formatter: (p) => `${p[0].name}: ${p[0].value}` },
        grid: { left: 4, right: 12, top: 14, bottom: 24, containLabel: true },
        xAxis: { type: 'category', data: ag.map((b) => t('dashboard.fleet.dp_' + b.bucket)), axisLabel: { color: '#6A6D70', fontSize: 9, interval: 0 } },
        yAxis: { type: 'value', minInterval: 1, axisLabel: { color: '#9aa0a6' }, splitLine: { lineStyle: { color: '#eef0f2' } } },
        series: [{ type: 'bar', data: ag.map((b) => ({ value: b.count, itemStyle: { color: DP_BUCKETS[b.bucket]?.color, borderRadius: [4, 4, 0, 0] } })), barMaxWidth: 40 }],
    };
});

// ── Tasa de generación de gas (TDCG ppm/mes) ──
const gassing = computed(() => props.fleet?.gassing ?? { active: 0, top: [] });

// ── Matriz de riesgo: condición (HI) × criticidad (clase de tensión) ──
const KV_LABELS = computed(() => [t('dashboard.fleet.kv_dist'), t('dashboard.fleet.kv_sub'), t('dashboard.fleet.kv_trans')]);
const hasMatrix = computed(() => (props.fleet?.riskMatrix ?? []).length > 0);
// El COLOR de cada celda es el RIESGO (no la cantidad): riesgo = severidad de
// condición (4-rating) + criticidad por tensión (kv·0.5). Así "Excelente" queda
// verde aunque tenga muchos equipos, y "Crítico + Transmisión" queda rojo. El
// número dentro de la celda es la cantidad de trafos.
const RISK_COLORS = ['#1D7044', '#5AA82E', '#E9A23B', '#E2661E', '#C8281D']; // banda 0..4
const riskBand = (rating, kv) => {
    const r = (4 - rating) + 0.5 * kv;
    return r < 1 ? 0 : r < 2 ? 1 : r < 3 ? 2 : r < 4 ? 3 : 4;
};
const matrixOption = computed(() => {
    const cells = props.fleet?.riskMatrix ?? [];
    // x = clase de tensión (0..2), y = rating (0 abajo=peor … 4 arriba=mejor).
    const yLabels = [t('diagnostics.cond_muy_malo'), t('diagnostics.cond_malo'), t('diagnostics.cond_medio'), t('diagnostics.cond_bueno'), t('diagnostics.cond_muy_bueno')];
    // 4ª dimensión = banda de riesgo (0..4). El heatmap de echarts necesita un
    // visualMap para pintar las celdas: lo apuntamos a esa dimensión (oculto;
    // la leyenda verde→rojo va aparte). La etiqueta muestra la cantidad (dim 2).
    const data = cells.map((c) => [c.kv, c.rating, c.count, riskBand(c.rating, c.kv)]);
    return {
        tooltip: { position: 'top', formatter: (p) => `${KV_LABELS.value[p.value[0]]} · ${yLabels[p.value[1]]}: ${p.value[2]}` },
        grid: { left: 4, right: 12, top: 10, bottom: 28, containLabel: true },
        xAxis: { type: 'category', data: KV_LABELS.value, axisLabel: { color: '#6A6D70', fontSize: 10 }, splitArea: { show: true } },
        yAxis: { type: 'category', data: yLabels, axisLabel: { color: '#6A6D70', fontSize: 10 }, splitArea: { show: true } },
        visualMap: {
            type: 'piecewise', show: false, dimension: 3, min: 0, max: 4,
            pieces: RISK_COLORS.map((color, i) => ({ value: i, color })),
        },
        series: [{
            type: 'heatmap', data,
            label: { show: true, formatter: (p) => p.value[2], color: '#1f2937', fontSize: 12, fontWeight: 600 },
            itemStyle: { borderColor: '#fff', borderWidth: 2 },
            emphasis: { itemStyle: { shadowBlur: 8, shadowColor: 'rgba(0,0,0,.25)' } },
        }],
    };
});

// ── Riesgo a corto plazo (pronóstico de cromatografía). El detalle vive en
// /fleet; aquí va el resumen + los más próximos a cruzar a peor estado. ──
const shortTermRisk = computed(() => props.fleet?.shortTermRisk ?? { count: 0, items: [] });

// ── Vida remanente del papel (proyección de DP). Predictivo de largo plazo:
// envejecimiento del aislamiento (años hasta DP<200). ──
const paperLife = computed(() => props.fleet?.paperLife ?? { count: 0, items: [] });
const yearsText = (y) => y < 1 ? t('dashboard.fleet.paperlife_soon') : t('dashboard.fleet.paperlife_years', { y: Math.round(y) });
const etaText = (r) => {
    const state = condLabel(r.target);
    return r.months < 1
        ? t('dashboard.fleet.eta_soon', { state })
        : t('dashboard.fleet.eta_months', { state, months: r.months });
};
const goFleet = () => router.visit(route('business_management.transformers.fleet'));

const goTransformer = (slug) => router.visit(route('business_management.transformers.show', slug));
// Navega al MÓDULO de trafos con los filtros activos del dashboard + un extra
// (ej. health_rating). Así "5 críticos" abre la lista ya filtrada a críticos.
const goTransformers = (extra = {}) => {
    router.get(route('business_management.transformers.index'), {
        customer_id: fCustomer.value,
        transformer_type_id: fType.value,
        ...extra,
    });
};
const onSlice = (params) => {
    const r = params?.data?.rating;
    goTransformers(r === null || r === undefined ? {} : { health_rating: [r] });
};
const ratingColor = (r) => ['#C8281D', '#E2661E', '#E9A23B', '#5AA82E', '#1D7044'][r] ?? '#9aa0a6';
</script>

<template>
    <div class="fleet">
        <!-- Selector de vistas guardadas: arriba a la derecha, separado de los
             filtros (como el "selector de vista" de una app). -->
        <div v-if="canUsePlanFeature('saved_views')" class="fleet__topbar">
            <SavedViews
                module="dashboard_fleet"
                :current-state="dashViewState"
                @apply="applyDashView"
                @default-loaded="applyDashView"
            />
        </div>

        <!-- Filtros -->
        <div class="fleet__filters">
            <Select v-if="hasTenants" v-model:value="fTenant" mode="multiple" :options="filters.tenants" :placeholder="t('dashboard.fleet.filter_tenant')"
                    allow-clear show-search option-filter-prop="label" :max-tag-count="2" class="fleet__filter fleet__filter--sm" @change="apply" />
            <Select v-model:value="fCustomer" mode="multiple" :options="customerOptions" :placeholder="t('dashboard.fleet.filter_customer')"
                    allow-clear show-search option-filter-prop="label" :max-tag-count="2" class="fleet__filter" @change="apply" />
            <Select v-model:value="fType" mode="multiple" :options="filters.types" :placeholder="t('dashboard.fleet.filter_type')"
                    allow-clear class="fleet__filter fleet__filter--sm" @change="apply" />
            <Select v-model:value="fRating" mode="multiple" :options="RATING_LABELS" :placeholder="t('dashboard.fleet.filter_rating')"
                    allow-clear class="fleet__filter fleet__filter--sm" @change="apply" />
            <Button v-if="hasFilters" @click="clearFilters"><ClearOutlined /> {{ t('dashboard.fleet.filter_clear') }}</Button>
        </div>

        <!-- KPIs -->
        <div class="fleet__kpis">
            <div class="kpi kpi--link" @click="goTransformers({})"><b>{{ kpis.trafos }}</b><span>{{ t('dashboard.fleet.kpi_trafos') }}</span></div>
            <div class="kpi"><b>{{ kpis.clientes }}</b><span>{{ t('dashboard.fleet.kpi_clientes') }}</span></div>
            <div class="kpi kpi--link" @click="goTransformers({ health_rating: [3, 4] })"><b style="color:#1D7044">{{ kpis.sanos_pct }}%</b><span>{{ t('dashboard.fleet.kpi_sanos') }}</span></div>
            <div class="kpi kpi--link" @click="goTransformers({ health_rating: [0, 1] })"><b style="color:#C8281D">{{ kpis.criticos }}</b><span>{{ t('dashboard.fleet.kpi_criticos') }}</span></div>
            <div class="kpi"><b>{{ kpis.muestras }}</b><span>{{ t('dashboard.fleet.kpi_muestras') }}</span></div>
        </div>

        <!-- Gráficos -->
        <div class="fleet__grid">
            <!-- Gauge de Flota sana % (echarts — misma librería que el resto). -->
            <Card class="fleet__card" :title="t('dashboard.fleet.kpi_sanos')">
                <div class="fleet__chart fleet__chart--donut">
                    <VChart :option="gaugeOption" autoresize />
                </div>
            </Card>

            <Card class="fleet__card" :title="t('dashboard.fleet.health_title')">
                <div class="fleet__chart fleet__chart--donut">
                    <VChart v-if="healthTotal > 0" :option="healthOption" autoresize @click="onSlice" />
                    <Empty v-else :description="t('dashboard.fleet.no_data')" />
                    <div v-if="healthTotal > 0" class="donut-center"><b>{{ healthTotal }}</b><span>{{ t('dashboard.fleet.kpi_trafos') }}</span></div>
                </div>
            </Card>

            <!-- Tipos de falla (Duval) — diagnóstico -->
            <Card class="fleet__card" :title="t('dashboard.fleet.fault_title')">
                <template #extra><Tooltip :title="t('dashboard.fleet.fault_hint')"><span class="muted">?</span></Tooltip></template>
                <div class="fleet__chart fleet__chart--donut"><LazyMount><VChart v-if="hasFault" :option="faultOption" autoresize /><Empty v-else :description="t('dashboard.fleet.no_data')" /></LazyMount></div>
            </Card>

            <!-- Condición IEEE C57.104 por nivel -->
            <Card class="fleet__card" :title="t('dashboard.fleet.ieee_title')">
                <template #extra><Tooltip :title="t('dashboard.fleet.ieee_hint')"><span class="muted">?</span></Tooltip></template>
                <div class="fleet__chart"><LazyMount><VChart v-if="hasIeee" :option="ieeeOption" autoresize /><Empty v-else :description="t('dashboard.fleet.no_data')" /></LazyMount></div>
            </Card>

            <!-- Vida del papel (DP Chendong) -->
            <Card class="fleet__card" :title="t('dashboard.fleet.paper_title')">
                <template #extra>
                    <span v-if="paperEol > 0" class="badge-crit">{{ t('dashboard.fleet.paper_eol', { n: paperEol }) }}</span>
                    <Tooltip v-else :title="t('dashboard.fleet.paper_hint')"><span class="muted">?</span></Tooltip>
                </template>
                <div class="fleet__chart"><LazyMount><VChart v-if="hasPaper" :option="paperOption" autoresize /><Empty v-else :description="t('dashboard.fleet.no_data')" /></LazyMount></div>
            </Card>

            <!-- Matriz de riesgo: condición × criticidad (clase de tensión) -->
            <Card class="fleet__card" :title="t('dashboard.fleet.matrix_title')">
                <template #extra><Tooltip :title="t('dashboard.fleet.matrix_hint')"><span class="muted">?</span></Tooltip></template>
                <div class="fleet__chart fleet__chart--matrix"><LazyMount><VChart v-if="hasMatrix" :option="matrixOption" autoresize /><Empty v-else :description="t('dashboard.fleet.no_data')" /></LazyMount></div>
                <div v-if="hasMatrix" class="risk-legend">
                    <span>{{ t('dashboard.fleet.risk_low') }}</span>
                    <i class="risk-legend__bar" />
                    <span>{{ t('dashboard.fleet.risk_high') }}</span>
                </div>
            </Card>

            <!-- Tasa de generación de gas (TDCG ppm/mes) — alarma IEEE -->
            <Card class="fleet__card" :title="t('dashboard.fleet.gassing_title')">
                <template #extra><span class="muted">{{ t('dashboard.fleet.gassing_active', { n: gassing.active }) }}</span></template>
                <Empty v-if="!gassing.top.length" :description="t('dashboard.fleet.no_data')" />
                <ul v-else class="mini-list">
                    <li v-for="g in gassing.top" :key="g.slug" class="mini-row" @click="goTransformer(g.slug)">
                        <span class="dot" :style="{ background: ratingColor(g.rating) }" />
                        <span class="mini-row__main"><strong>{{ g.serial }}</strong><span class="muted" v-if="g.customer"> · {{ g.customer }}</span></span>
                        <span class="risk-eta">{{ g.rate }} {{ t('dashboard.fleet.ppm_month') }}</span>
                    </li>
                </ul>
            </Card>

            <Card class="fleet__card" :title="t('dashboard.fleet.type_title')">
                <div class="fleet__chart"><LazyMount><VChart v-if="(fleet.byType||[]).length" :option="typeOption" autoresize /><Empty v-else :description="t('dashboard.fleet.no_data')" /></LazyMount></div>
            </Card>
            <Card class="fleet__card" :title="t('dashboard.fleet.brand_title')">
                <div class="fleet__chart"><LazyMount><VChart v-if="(fleet.byBrand||[]).length" :option="brandOption" autoresize /><Empty v-else :description="t('dashboard.fleet.no_data')" /></LazyMount></div>
            </Card>

            <!-- Trafos por país + Mapa de la flota, en la misma línea -->
            <div class="fleet__pair">
                <Card class="fleet__card" :title="t('dashboard.fleet.country_count_title')">
                    <div class="fleet__chart fleet__chart--map"><LazyMount><VChart v-if="hasCountry" :option="countryBarsOption" autoresize /><Empty v-else :description="t('dashboard.fleet.no_data')" /></LazyMount></div>
                </Card>
                <Card class="fleet__card" :title="t('dashboard.fleet.country_title')">
                    <div class="fleet__chart fleet__chart--map">
                        <LazyMount @visible="loadWorldMap">
                            <VChart v-if="mapReady" :option="mapOption" autoresize />
                            <VChart v-else-if="hasCountry" :option="countryBarsOption" autoresize />
                            <Empty v-else :description="t('dashboard.fleet.no_data')" />
                        </LazyMount>
                    </div>
                </Card>
            </div>

            <!-- Riesgo a corto plazo (pronóstico) → detalle accionable en /fleet -->
            <Card class="fleet__card" :title="t('dashboard.fleet.risk_title')">
                <template v-if="shortTermRisk.count" #extra>
                    <a class="risk-link" @click="goFleet">{{ t('dashboard.fleet.risk_link') }}</a>
                </template>
                <Empty v-if="!shortTermRisk.items.length" :description="t('dashboard.fleet.risk_none')" />
                <template v-else>
                    <p class="risk-head">{{ t('dashboard.fleet.risk_summary', { count: shortTermRisk.count }) }}</p>
                    <ul class="mini-list">
                        <li v-for="r in shortTermRisk.items" :key="r.slug" class="mini-row" @click="goTransformer(r.slug)">
                            <span class="dot" :style="{ background: ratingColor(r.rating) }" />
                            <span class="mini-row__main"><strong>{{ r.serial }}</strong><span class="muted" v-if="r.customer"> · {{ r.customer }}</span></span>
                            <span class="risk-eta">{{ etaText(r) }}</span>
                        </li>
                    </ul>
                </template>
            </Card>

            <!-- Vida remanente del papel (proyección de DP) -->
            <Card class="fleet__card" :title="t('dashboard.fleet.paperlife_title')">
                <template #extra><Tooltip :title="t('dashboard.fleet.paperlife_hint')"><span class="muted">?</span></Tooltip></template>
                <Empty v-if="!paperLife.items.length" :description="t('dashboard.fleet.paperlife_none')" />
                <template v-else>
                    <p class="risk-head">{{ t('dashboard.fleet.paperlife_summary', { count: paperLife.count }) }}</p>
                    <ul class="mini-list">
                        <li v-for="p in paperLife.items" :key="p.slug" class="mini-row" @click="goTransformer(p.slug)">
                            <span class="dot" :style="{ background: ratingColor(p.rating) }" />
                            <span class="mini-row__main"><strong>{{ p.serial }}</strong><span class="muted" v-if="p.customer"> · {{ p.customer }}</span></span>
                            <span class="risk-eta">{{ yearsText(p.years) }}<span class="muted" v-if="p.dp"> · DP {{ p.dp }}</span></span>
                        </li>
                    </ul>
                </template>
            </Card>

            <!-- Por workspace (solo super) -->
            <Card v-if="(fleet.byTenant||[]).length" class="fleet__card" :title="t('dashboard.fleet.top_tenants_title')">
                <ul class="mini-list">
                    <li v-for="w in fleet.byTenant" :key="w.name" class="mini-row mini-row--static">
                        <span class="mini-row__main"><strong>{{ w.name }}</strong></span>
                        <span class="muted">{{ w.trafos }} {{ t('dashboard.fleet.col_trafos').toLowerCase() }}</span>
                        <Tooltip v-if="w.criticos > 0" :title="t('dashboard.fleet.criticos_tip', { n: w.criticos })">
                            <span class="badge-crit">{{ w.criticos }}</span>
                        </Tooltip>
                    </li>
                </ul>
            </Card>

            <!-- Top clientes -->
            <Card class="fleet__card" :title="t('dashboard.fleet.top_customers_title')">
                <Empty v-if="!(fleet.topCustomers||[]).length" :description="t('dashboard.fleet.no_data')" />
                <ul v-else class="mini-list">
                    <li v-for="c in fleet.topCustomers" :key="c.name" class="mini-row mini-row--static">
                        <span class="mini-row__main"><strong>{{ c.name }}</strong></span>
                        <span class="muted">{{ c.trafos }} {{ t('dashboard.fleet.col_trafos').toLowerCase() }}</span>
                        <Tooltip v-if="c.criticos > 0" :title="t('dashboard.fleet.criticos_tip', { n: c.criticos })">
                            <span class="badge-crit">{{ c.criticos }}</span>
                        </Tooltip>
                    </li>
                </ul>
            </Card>
        </div>
    </div>
</template>

<style scoped>
.fleet { width: 100%; }
.fleet__topbar { display: flex; justify-content: flex-end; margin-bottom: 12px; }
.fleet__filters { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; align-items: center; }
.fleet__saved-views { margin-left: auto; }
.fleet__filter { min-width: 240px; flex: 1; max-width: 360px; }
.fleet__filter--sm { min-width: 170px; max-width: 220px; flex: 0 0 auto; }

.fleet__kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
.kpi { background: var(--color-surface, #fff); border: 1px solid var(--color-border-soft, #eceff2); border-radius: 8px; padding: 14px 16px; text-align: center; }
.kpi b { display: block; font-size: 1.7rem; font-weight: 700; line-height: 1.1; color: var(--color-text-strong, #1f2937); }
.kpi span { font-size: 0.72rem; color: var(--color-text-muted, #6A6D70); text-transform: uppercase; letter-spacing: .04em; }
.kpi--link { cursor: pointer; transition: box-shadow .15s ease, transform .15s ease; }
.kpi--link:hover { box-shadow: 0 4px 14px rgba(10,110,209,0.12); transform: translateY(-2px); border-color: #cfe2f7; }

/* minmax(0, 1fr): permite que las columnas se encojan por debajo del ancho
   intrínseco del canvas de echarts. Con 1fr (mínimo = min-content) el canvas
   no deja achicar la pista y el grid se desborda al colapsar el sidebar.
   grid-auto-flow: dense → rellena huecos de las cards wide. */
.fleet__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; grid-auto-flow: row dense; }
.fleet__card { border-radius: 8px; min-width: 0; overflow: hidden; }
.fleet__card--wide { grid-column: span 2; }
/* Par "trafos por país + mapa": ocupa toda la fila y se parte en 2 columnas
   iguales (misma línea). Mismo min-width: 0 por el canvas de echarts. */
.fleet__pair { grid-column: span 2; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.fleet__chart { position: relative; height: 240px; min-width: 0; }
.fleet__chart--donut { height: 240px; }
.fleet__chart--map { height: 380px; }
.fleet__chart :deep(canvas) { border-radius: 4px; }
.donut-center { position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%); text-align: center; pointer-events: none; }
.donut-center b { display: block; font-size: 1.5rem; font-weight: 700; color: var(--color-text-strong, #1f2937); }
.donut-center span { font-size: 0.62rem; color: var(--color-text-muted, #9aa0a6); text-transform: uppercase; }

.mini-list { list-style: none; margin: 0; padding: 0; max-height: 240px; overflow: auto; }
.mini-row { display: flex; align-items: center; gap: 10px; padding: 9px 4px; border-bottom: 1px solid var(--color-border-soft, #f0f2f4); font-size: 0.86rem; cursor: pointer; }
.mini-row--static { cursor: default; }
.mini-row:last-child { border-bottom: none; }
.mini-row:hover:not(.mini-row--static) { background: var(--color-surface-alt, #f6f8fa); }
.mini-row__main { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mini-row__hi { font-weight: 700; }
.dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.muted { color: var(--color-text-muted, #6A6D70); font-size: 0.8rem; }
.badge-crit { background: #fdeceb; color: #C8281D; border-radius: 999px; padding: 1px 8px; font-size: 0.72rem; font-weight: 700; }
.risk-head { font-size: 0.82rem; color: var(--color-text-muted, #6A6D70); margin: 0 0 8px; }
.risk-eta { font-size: 0.78rem; font-weight: 600; color: #E2661E; flex-shrink: 0; text-align: right; }
.risk-link { font-size: 0.8rem; color: #0A6ED1; cursor: pointer; }
.fleet__chart--matrix { height: 210px; }
.risk-legend { display: flex; align-items: center; gap: 8px; justify-content: center; margin-top: 6px; font-size: 0.72rem; color: var(--color-text-muted, #6A6D70); }
.risk-legend__bar { width: 120px; height: 8px; border-radius: 4px; background: linear-gradient(90deg, #1D7044, #5AA82E, #E9A23B, #E2661E, #C8281D); }

@media (max-width: 900px) {
    .fleet__grid { grid-template-columns: minmax(0, 1fr); }
    .fleet__card--wide { grid-column: span 1; }
    .fleet__pair { grid-column: span 1; grid-template-columns: minmax(0, 1fr); }
    .fleet__chart--map { height: 300px; }
}
@media (max-width: 640px) {
    /* Mobile: los 3 selects de filtro al MISMO ancho (full), apilados. */
    .fleet__filter, .fleet__filter--sm { min-width: 0; max-width: none; flex: 1 1 100%; }
    .fleet__saved-views { margin-left: 0; flex: 1 1 100%; }
}
</style>
