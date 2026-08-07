<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Card, Row, Col, Table, Tag, Button, Select, Tooltip, Modal, Alert } from 'ant-design-vue';
import {
    ArrowLeftOutlined, ThunderboltFilled, FileExcelOutlined, FilePdfOutlined,
    FileTextOutlined, CheckCircleFilled, CloseCircleFilled, BulbOutlined,
} from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { semaforoHex } from '@/utils/severity';

defineOptions({ layout: AppLayout });

const props = defineProps({
    bands:         { type: Object, default: () => ({}) }, // { 4:n, 3:n, 2:n, 1:n, 0:n }
    noData:        { type: Number, default: 0 },
    total:         { type: Number, default: 0 },
    diagnosed:     { type: Number, default: 0 },
    atRisk:        { type: Number, default: 0 },
    worsening:     { type: Number, default: 0 },
    worst:         { type: Array,  default: () => [] },
    worseningList: { type: Array,  default: () => [] },
    customerOptions:  { type: Array,  default: () => [] },
    selectedCustomer: { type: [Number, null], default: null },
    pdfCap:           { type: Number, default: 50 },
});

// Filtro "flota por cliente": recarga el panel scopeado a ese cliente.
const onCustomer = (id) => router.visit(
    route('business_management.transformers.fleet', id ? { customer_id: id } : {}),
    { preserveScroll: true },
);

// ── Reporte de flota: modal con formatos (mismo patrón de checks del index).
// Excel = todos los datos (sin límite). CSV = resumen plano (sin límite).
// PDF = presentable, capado a pdfCap trafos (rojo si la flota lo supera). ──
const reportOpen = ref(false);
const reportFormat = ref('excel');
const reportSubmitting = ref(false);

const reportFormats = computed(() => [
    { key: 'excel', route: 'business_management.transformers.fleet_report_excel', ext: '.xlsx', unlimited: true },
    { key: 'csv',   route: 'business_management.transformers.fleet_report_csv',   ext: '.csv',  unlimited: true },
    { key: 'pdf',   route: 'business_management.transformers.fleet_report_pdf',   ext: '.pdf',  unlimited: false },
]);
// PDF cabe si la flota (del alcance actual) no supera el tope.
const pdfFits = computed(() => props.total <= props.pdfCap);
const formatFits = (key) => key !== 'pdf' || pdfFits.value;

const submitReport = () => {
    const fmt = reportFormats.value.find((f) => f.key === reportFormat.value);
    if (!fmt || !formatFits(fmt.key)) return;
    reportSubmitting.value = true;
    router.post(
        route(fmt.route),
        props.selectedCustomer ? { customer_id: props.selectedCustomer } : {},
        {
            preserveScroll: true, preserveState: true,
            onFinish: () => { reportSubmitting.value = false; reportOpen.value = false; },
        },
    );
};

const { t } = useI18n();
const { ratingLabel, condLabel } = useConditions();

// rating 0-4 → token de color del semáforo.
const RATING_TOKEN = ['red', 'orange', 'yellow', 'lime', 'green'];
const ratingColor = (r) => semaforoHex(RATING_TOKEN[r] ?? 'green');

// Barras del desglose por banda (de mejor a peor para que se lea como semáforo).
const bandRows = computed(() =>
    [4, 3, 2, 1, 0].map((r) => ({
        rating: r,
        label: ratingLabel(r),
        count: props.bands[r] ?? 0,
        color: ratingColor(r),
        pct: props.diagnosed > 0 ? Math.round(((props.bands[r] ?? 0) / props.diagnosed) * 100) : 0,
    })),
);

const riskPct = computed(() =>
    props.diagnosed > 0 ? Math.round((props.atRisk / props.diagnosed) * 100) : 0,
);

const healthColumns = computed(() => [
    { title: t('transformers.serial'), key: 'serial', width: 150 },
    { title: t('transformers.customer'), key: 'customer' },
    { title: t('transformers.health_index'), key: 'health', width: 150, align: 'center' },
    { title: t('transformers.fleet.trend'), key: 'trend', width: 120, align: 'center' },
]);

// La tabla de "empeorando" agrega la proyección (a este ritmo, cuándo cruza
// a la siguiente banda peor).
const worseningColumns = computed(() => [
    ...healthColumns.value,
    { title: t('transformers.fleet.forecast'), key: 'forecast', width: 180, align: 'center' },
]);

// Pronóstico en lenguaje llano y accionable (no diagnosticador). Es una
// extrapolación de la tendencia de cromatografía, no una predicción de falla.
const forecastText = (f) => {
    if (!f) return null;
    const state = condLabel(f.target);
    return f.months < 1
        ? t('transformers.fleet.forecast_plain_soon', { state })
        : t('transformers.fleet.forecast_plain', { state, months: Math.round(f.months) });
};

// Tendencia → símbolo + color + etiqueta.
const TREND = {
    worsening: { icon: '▼', color: '#C8281D', key: 'trend_worsening' },
    improving: { icon: '▲', color: '#1D7044', key: 'trend_improving' },
    stable:    { icon: '▬', color: '#8c8c8c', key: 'trend_stable' },
};
const trendMeta = (v) => TREND[v] ?? null;

const goDetail = (slug) => router.visit(route('business_management.transformers.show', slug));
</script>

<template>
    <Head :title="t('transformers.fleet.title')" />

    <div class="fleet">
        <div class="fleet__head">
            <div>
                <Link :href="route('business_management.transformers.index')" class="fleet__back">
                    <ArrowLeftOutlined /> {{ t('transformers.fleet.back') }}
                </Link>
                <h1 class="fleet__title"><ThunderboltFilled /> {{ t('transformers.fleet.title') }}</h1>
                <p class="fleet__sub">{{ t('transformers.fleet.subtitle') }}</p>
            </div>
            <div class="fleet__actions">
                <Select
                    :value="selectedCustomer"
                    :options="customerOptions"
                    :placeholder="t('transformers.fleet.all_customers')"
                    allow-clear
                    show-search
                    option-filter-prop="label"
                    style="min-width: 260px"
                    @change="onCustomer"
                />
                <Button type="primary" @click="reportOpen = true">
                    <FileExcelOutlined /> {{ t('transformers.fleet_report.button') }}
                </Button>
            </div>
        </div>

        <!-- Modal del reporte de flota: elige formato (checks verde/rojo) -->
        <Modal v-model:open="reportOpen" :title="t('transformers.fleet_report.title')"
               :confirm-loading="reportSubmitting" :ok-text="t('transformers.fleet_report.generate')"
               :ok-button-props="{ disabled: !formatFits(reportFormat) }" @ok="submitReport">
            <Alert type="info" show-icon class="report-help">
                <template #icon><BulbOutlined /></template>
                <template #message>{{ t('transformers.fleet_report.help') }}</template>
            </Alert>
            <div class="report-formats">
                <button v-for="f in reportFormats" :key="f.key" type="button" class="report-card"
                        :class="{ 'report-card--active': reportFormat === f.key, 'report-card--bad': !formatFits(f.key) }"
                        :disabled="!formatFits(f.key)" @click="formatFits(f.key) && (reportFormat = f.key)">
                    <FileExcelOutlined v-if="f.key === 'excel'" class="report-card__ic" style="color:#1D7044" />
                    <FilePdfOutlined v-else-if="f.key === 'pdf'" class="report-card__ic" style="color:#C8281D" />
                    <FileTextOutlined v-else class="report-card__ic" style="color:#475569" />
                    <span class="report-card__name">{{ f.key.toUpperCase() }}</span>
                    <span class="report-card__ext">{{ f.ext }}</span>
                    <span class="report-card__status" :class="formatFits(f.key) ? 'is-ok' : 'is-bad'">
                        <template v-if="f.unlimited"><CheckCircleFilled /> {{ t('global.unlimited') }}</template>
                        <template v-else-if="formatFits(f.key)"><CheckCircleFilled /> {{ t('transformers.fleet_report.pdf_max', { n: pdfCap }) }}</template>
                        <template v-else><CloseCircleFilled /> {{ t('transformers.fleet_report.pdf_max', { n: pdfCap }) }}</template>
                    </span>
                </button>
            </div>
        </Modal>

        <!-- KPIs -->
        <Row :gutter="[16, 16]" class="fleet__kpis">
            <Col :xs="12" :sm="6">
                <Card><div class="kpi">
                    <span class="kpi__num">{{ total }}</span>
                    <span class="kpi__lbl">{{ t('transformers.fleet.kpi_total') }}</span>
                </div></Card>
            </Col>
            <Col :xs="12" :sm="6">
                <Card><div class="kpi">
                    <span class="kpi__num">{{ diagnosed }}</span>
                    <span class="kpi__lbl">{{ t('transformers.fleet.kpi_diagnosed') }}
                        <small v-if="noData">· {{ noData }} {{ t('transformers.fleet.kpi_nodata') }}</small>
                    </span>
                </div></Card>
            </Col>
            <Col :xs="12" :sm="6">
                <Card :body-style="{ borderLeft: atRisk > 0 ? '3px solid #C8281D' : '3px solid #1D7044' }">
                    <div class="kpi">
                        <span class="kpi__num" :style="{ color: atRisk > 0 ? '#C8281D' : '#1D7044' }">{{ atRisk }}</span>
                        <span class="kpi__lbl">{{ t('transformers.fleet.kpi_atrisk') }}
                            <small v-if="diagnosed">· {{ riskPct }}%</small>
                        </span>
                    </div>
                </Card>
            </Col>
            <Col :xs="12" :sm="6">
                <Card :body-style="{ borderLeft: worsening > 0 ? '3px solid #E2661E' : '3px solid #1D7044' }">
                    <div class="kpi">
                        <span class="kpi__num" :style="{ color: worsening > 0 ? '#E2661E' : '#1D7044' }">{{ worsening }}</span>
                        <span class="kpi__lbl">▼ {{ t('transformers.fleet.kpi_worsening') }}</span>
                    </div>
                </Card>
            </Col>
        </Row>

        <Row :gutter="[16, 16]">
            <!-- Desglose por banda -->
            <Col :xs="24" :lg="10">
                <Card :title="t('transformers.fleet.breakdown')">
                    <div v-for="b in bandRows" :key="b.rating" class="band">
                        <div class="band__top">
                            <span class="band__dot" :style="{ background: b.color }"></span>
                            <span class="band__name">{{ b.label }}</span>
                            <span class="band__count">{{ b.count }}</span>
                            <span class="band__pct">{{ b.pct }}%</span>
                        </div>
                        <div class="band__bar"><span :style="{ width: b.pct + '%', background: b.color }"></span></div>
                    </div>
                    <p v-if="noData" class="band__nodata">
                        {{ noData }} {{ t('transformers.fleet.nodata_note') }}
                    </p>
                </Card>
            </Col>

            <!-- Peores trafos -->
            <Col :xs="24" :lg="14">
                <Card :title="t('transformers.fleet.worst')" :body-style="{ padding: 0 }">
                    <Table
                        :data-source="worst"
                        :columns="healthColumns"
                        :pagination="false"
                        row-key="slug"
                        size="middle"
                        :custom-row="(r) => ({ onClick: () => goDetail(r.slug), style: 'cursor:pointer' })"
                    >
                        <template #bodyCell="{ column, record }">
                            <template v-if="column.key === 'serial'">
                                <code class="mono">{{ record.serial || record.tag || '—' }}</code>
                            </template>
                            <template v-else-if="column.key === 'customer'">{{ record.customer || '—' }}</template>
                            <template v-else-if="column.key === 'health'">
                                <Tag :color="ratingColor(record.health_rating)" :bordered="false" style="color:#fff; font-weight:600">
                                    {{ Math.round(record.health_index) }}% · {{ ratingLabel(record.health_rating) }}
                                </Tag>
                            </template>
                            <template v-else-if="column.key === 'trend'">
                                <span v-if="trendMeta(record.health_trend)" :style="{ color: trendMeta(record.health_trend).color, fontWeight: 600 }">
                                    {{ trendMeta(record.health_trend).icon }} {{ t('transformers.fleet.' + trendMeta(record.health_trend).key) }}
                                </span>
                                <span v-else style="color:#bbb">—</span>
                            </template>
                            <template v-else-if="column.key === 'forecast'">
                                <span v-if="forecastText(record.forecast)" style="color:#E2661E; font-weight:600">
                                    {{ forecastText(record.forecast) }}
                                </span>
                                <span v-else style="color:#bbb">—</span>
                            </template>
                        </template>
                        <template #emptyText>{{ t('transformers.fleet.no_worst') }}</template>
                    </Table>
                </Card>
            </Col>
        </Row>

        <!-- Empeorando (alerta temprana) -->
        <Row :gutter="[16, 16]" style="margin-top: 16px">
            <Col :xs="24">
                <Card :body-style="{ padding: 0 }">
                    <template #title>▼ {{ t('transformers.fleet.worsening_title') }}</template>
                    <Table
                        :data-source="worseningList"
                        :columns="worseningColumns"
                        :pagination="false"
                        row-key="slug"
                        size="middle"
                        :custom-row="(r) => ({ onClick: () => goDetail(r.slug), style: 'cursor:pointer' })"
                    >
                        <template #bodyCell="{ column, record }">
                            <template v-if="column.key === 'serial'">
                                <code class="mono">{{ record.serial || record.tag || '—' }}</code>
                            </template>
                            <template v-else-if="column.key === 'customer'">{{ record.customer || '—' }}</template>
                            <template v-else-if="column.key === 'health'">
                                <Tag :color="ratingColor(record.health_rating)" :bordered="false" style="color:#fff; font-weight:600">
                                    {{ Math.round(record.health_index) }}% · {{ ratingLabel(record.health_rating) }}
                                </Tag>
                            </template>
                            <template v-else-if="column.key === 'trend'">
                                <span v-if="trendMeta(record.health_trend)" :style="{ color: trendMeta(record.health_trend).color, fontWeight: 600 }">
                                    {{ trendMeta(record.health_trend).icon }} {{ t('transformers.fleet.' + trendMeta(record.health_trend).key) }}
                                </span>
                                <span v-else style="color:#bbb">—</span>
                            </template>
                            <template v-else-if="column.key === 'forecast'">
                                <span v-if="forecastText(record.forecast)" style="color:#E2661E; font-weight:600">
                                    {{ forecastText(record.forecast) }}
                                </span>
                                <span v-else style="color:#bbb">—</span>
                            </template>
                        </template>
                        <template #emptyText>{{ t('transformers.fleet.no_worsening') }}</template>
                    </Table>
                </Card>
            </Col>
        </Row>
    </div>
</template>

<style scoped>
.fleet { padding: 4px; }
.fleet__head { margin-bottom: 18px; display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
.fleet__actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.report-help { margin-bottom: 14px; }
.report-formats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.report-card {
    display: flex; flex-direction: column; align-items: center; gap: 4px;
    padding: 14px 8px; border: 1.5px solid #e5e7eb; border-radius: 8px;
    background: #fff; cursor: pointer; transition: border-color .15s, box-shadow .15s;
}
.report-card:hover:not(:disabled) { border-color: #0A6ED1; }
.report-card--active { border-color: #0A6ED1; box-shadow: 0 0 0 2px rgba(10,110,209,.15); }
.report-card--bad { opacity: .6; cursor: not-allowed; }
.report-card__ic { font-size: 1.8rem; }
.report-card__name { font-weight: 700; font-size: .9rem; }
.report-card__ext { font-size: .7rem; color: #9aa0a6; font-family: ui-monospace, monospace; }
.report-card__status { display: inline-flex; align-items: center; gap: 4px; font-size: .68rem; font-weight: 600; margin-top: 2px; }
.report-card__status.is-ok { color: #1D7044; }
.report-card__status.is-bad { color: #C8281D; }
.fleet__back { font-size: 0.85rem; color: var(--color-text-muted, #6A6D70); }
.fleet__title { font-size: 1.5rem; font-weight: 700; margin: 6px 0 2px; display: flex; align-items: center; gap: 8px; color: #0A6ED1; }
.fleet__sub { color: var(--color-text-muted, #6A6D70); margin: 0; }
.fleet__kpis { margin-bottom: 16px; }
.kpi { display: flex; flex-direction: column; gap: 2px; }
.kpi__num { font-size: 2rem; font-weight: 700; line-height: 1; color: #32363A; }
.kpi__lbl { font-size: 0.85rem; color: var(--color-text-muted, #6A6D70); }
.band { margin-bottom: 12px; }
.band__top { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; }
.band__dot { width: 11px; height: 11px; border-radius: 50%; flex: none; }
.band__name { flex: 1; color: #32363A; }
.band__count { font-weight: 700; color: #32363A; }
.band__pct { width: 42px; text-align: right; color: var(--color-text-muted, #6A6D70); }
.band__bar { height: 7px; border-radius: 4px; background: #EFF1F3; margin-top: 5px; overflow: hidden; }
.band__bar span { display: block; height: 100%; border-radius: 4px; transition: width .3s; }
.band__nodata { font-size: 0.8rem; color: var(--color-text-muted, #6A6D70); margin: 10px 0 0; }
.mono { font-family: ui-monospace, monospace; font-size: 0.85rem; }
html[data-theme="dark"] .kpi__num, html[data-theme="dark"] .band__name, html[data-theme="dark"] .band__count { color: #e5e6e7; }
</style>
