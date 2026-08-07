<script setup>
import { computed, ref, inject, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Head, usePage, router } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Alert, Button, Tooltip, message, Dropdown, Menu, MenuItem,
} from 'ant-design-vue';
import {
    ThunderboltOutlined,
    DashboardOutlined, CalendarOutlined, ExperimentOutlined,
    TagsOutlined, ControlOutlined, PartitionOutlined, FilePdfOutlined, FileWordOutlined, DownOutlined,
    DeploymentUnitOutlined, EnvironmentOutlined, AuditOutlined, SwapOutlined, WarningFilled, CheckCircleFilled,
    PieChartOutlined, BgColorsOutlined, FileTextOutlined, PercentageOutlined, HistoryOutlined,
} from '@ant-design/icons-vue';
import GasTrends from '@/Components/Transformers/GasTrends.vue';
import DuvalTab from '@/Components/Transformers/DuvalTab.vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import TransformerGlyph from '@/Components/Transformers/TransformerGlyph.vue';
import RatingGauge from '@/Components/Transformers/RatingGauge.vue';
import TransformerIcon from '@/Components/Transformers/TransformerIcon.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import ShareModal from '@/Components/Sharing/ShareModal.vue';
import CromatografiaSection from '@/Components/Transformers/CromatografiaSection.vue';
import FuranosSection from '@/Components/Transformers/FuranosSection.vue';
import FiquisSection from '@/Components/Transformers/FiquisSection.vue';
import FpotSection from '@/Components/Transformers/FpotSection.vue';
import BitacoraSection from '@/Components/Transformers/BitacoraSection.vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';
import { useViewport } from '@/Composables/useViewport';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { useDiagnosisVerdict } from '@/Composables/useDiagnosisVerdict';
import { semaforoHex } from '@/utils/severity';
import { fiquiHead } from '@/utils/fiquiHeader';

defineOptions({ layout: AppLayout });

onMounted(() => {
    // Auto-caché de gráficos: si el caché del informe está vacío/viejo, captura
    // los echarts (ya montados como instancias ocultas) y los manda en
    // background. Así el PDF del portal compartido — incluso de FLOTA, donde
    // no hay navegador que capture — usa los gráficos reales. Fire-and-forget.
    if (props.chartCacheStale) {
        setTimeout(async () => {
            try {
                const images = await captureReportImages();
                if (images.length) {
                    await window.axios.post(route('business_management.transformers.report_charts', props.transformer.slug), { images });
                }
            } catch (_) { /* silencioso: es solo caché */ }
        }, 1600); // espera a que echarts termine de pintar
    }
});

const props = defineProps({
    transformer: { type: Object, required: true },
    activity:    { type: Array,  default: () => [] },
    activityHasMore: { type: Boolean, default: false },
    activityLimit:   { type: Number, default: 40 },
    recordAudit: { type: Object, default: null },
    diagnostics: { type: Object, default: () => ({ index: null, condition: null, color: null, components: {}, missing: [], partial: false }) },
    cromas:      { type: Array,  default: () => [] },
    cromasLimits: { type: Object, default: () => ({}) },
    cromasLimitsIeee: { type: Object, default: () => ({}) },
    cromasComments: { type: Array, default: () => [] },
    cromasDgaStatus: { type: Object, default: null },
    furanos:     { type: Array,  default: () => [] },
    furanosLimits: { type: Object, default: () => ({}) },
    furanosComments: { type: Array, default: () => [] },
    furanosTrend: { type: Object, default: () => null },
    furanosCoRatio: { type: Object, default: () => null },
    furanosMechanism: { type: Object, default: () => null },
    fiquis:      { type: Array,  default: () => [] },
    fiquisLimits: { type: Object, default: () => ({}) },
    fiquisColumns: { type: Array, default: () => [] },
    fiquisComments: { type: Array, default: () => [] },
    fpots:       { type: Array,  default: () => [] },
    fpotScale:   { type: Array,  default: () => [] },
    laboratories:{ type: Array,  default: () => [] },
    fpotComments: { type: Array, default: () => [] },
    events:      { type: Array,  default: () => [] },
    duvalGeometry: { type: Object, default: () => ({ triangles: {}, pentagons: {} }) },
    cromasNorms: { type: Object, default: () => ({ gases: {}, oil: null, trafo: null }) },
    cellAlertSev: { type: Number, default: 0.6 },
    diagnosisSummary: { type: Object, default: () => ({}) },
    canEditTests:{ type: Boolean, default: false },
    // true = el caché de gráficos del informe está vacío/viejo → esta página
    // lo repuebla en background (alimenta el PDF del portal compartido).
    chartCacheStale: { type: Boolean, default: false },
});

const activeTab = ref('resumen');

// "Ver más" del historial: pide otra tanda (+40) al server, preservando scroll.
const loadingMoreActivity = ref(false);
const loadMoreActivity = () => {
    loadingMoreActivity.value = true;
    router.reload({
        only: ['activity', 'activityHasMore', 'activityLimit'],
        data: { activity_limit: (props.activityLimit || 40) + 40 },
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { loadingMoreActivity.value = false; },
    });
};
// Vista de la card "Datos del transformador": 'visual' (el hero original con
// gauge — default, desde el inicio) | 'grid' (spec-grid detallado). Toggle.
const specsView = ref('visual');

const { can, isSuper, canSeeAudit } = useAuth();
// Rail lateral sticky en pantallas anchas; apilado (estático) cuando no hay ancho.
const { isMobile: narrowTabs } = useViewport(900);

// Items del rail de diagnóstico, con su punto de color (estado) por prueba.
const diagRail = computed(() => {
    const dotFor = (code) => {
        const c = diagCards.value.find((x) => x.code === code);
        return c && c.present ? colorHex(c.color) : null;
    };
    return [
        { key: 'resumen',       icon: PieChartOutlined,   label: t('transformers.summary'), dot: null },
        { key: 'cromatografia', icon: ExperimentOutlined, label: t('cromas.tab'),  dot: dotFor('cromas') },
        { key: 'fiquis',        icon: BgColorsOutlined,   label: t('fiquis.tab'),  dot: dotFor('fiquis') },
        { key: 'furanos',       icon: FileTextOutlined,   label: t('furanos.tab'), dot: dotFor('furanos') },
        { key: 'fpot',          icon: PercentageOutlined, label: t('fpot.tab'),    dot: dotFor('fpot') },
    ];
});

// Cambia de sección y lleva el inicio de la sección a la vista (como el mockup).
const objpageTop = ref(null);
const go = (key) => {
    activeTab.value = key;
    nextTick(() => objpageTop.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
};
const { formatDateTimeFull } = useDateFormat();
const { t } = useI18n();
const { condLabel, ratingLabel } = useConditions();
const { verdictParts } = useDiagnosisVerdict();

const fmt = (d) => formatDateTimeFull(d);
const isDeleted = computed(() => !!props.transformer.deleted_at);
const iconBg = computed(() => (isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)'));

// ── Índice de salud (en vivo desde diagnostics, con fallback al caché) ──
const hi = computed(() => {
    const v = props.diagnostics?.index ?? props.transformer.health_index;
    return v === null || v === undefined ? null : Number(v);
});
const hasHi = computed(() => hi.value !== null);

const hiColor = computed(() => {
    const v = hi.value;
    if (v === null) return '#9aa0a6';
    // Misma paleta de 5 niveles que la leyenda del semáforo (utils/severity).
    if (v > 85) return semaforoHex('green');
    if (v > 70) return semaforoHex('lime');
    if (v > 50) return semaforoHex('yellow');
    if (v > 30) return semaforoHex('orange');
    return semaforoHex('red');
});
const hiState = computed(() => {
    // En vivo: el motor da la condición como texto-código. Persistido: el trafo
    // guarda el rating numérico (health_rating). Ambos se traducen al idioma.
    if (props.diagnostics?.condition) return condLabel(props.diagnostics.condition);
    const r = props.transformer.health_rating;
    return (r !== null && r !== undefined) ? ratingLabel(r) : t('transformers.no_diagnostic');
});

// Mini-conclusión del Índice de Salud: estado global + qué prueba reduce más el
// índice (influencia = aporte% × (4 − rating), datos reales del HI) + acción
// sugerida por tramo. NO inventa: solo lee los componentes del HI (peso/rating/
// aporte) y el índice; si el motor no incluye una prueba (p.ej. fpot con
// hi_enabled=false) no la considera.
// Escala de acción ÚNICA (espeja TransformerShowPayload::ACTION_KEYS):
// 0=rutina · 1=seguimiento · 2=investigar · 3=crítico.
const ACTION_KEYS = ['routine', 'watch', 'investigate', 'critical'];
// Nivel de acción por el TRAMO del HI, alineado con las bandas de estado
// (>85 Muy Bueno…≤30 Muy Malo): >70 rutina · >50 seguimiento · >30 investigar · ≤30 crítico.
const hiActionLevel = (v) => (v > 70 ? 0 : v > 50 ? 1 : v > 30 ? 2 : 3);
// Nivel de acción de una prueba: lo trae el verdict del backend (misma escala). Cruza
// el componente del HI con su diagnóstico para captar señales que el rating ponderado
// enmascara (ej. cromas con falla activa pero DGAF bueno).
const testActionLevel = (code) => props.diagnosisSummary?.[code]?.action_level ?? 0;

const healthConcl = computed(() => {
    if (!hasHi.value) return null;
    const comps = components.value.filter((c) => c.present && c.rating != null);
    if (!comps.length) return null;
    const lines = [t('transformers.health_concl_state', { index: Math.round(hi.value), state: hiState.value })];
    const reducers = comps
        .filter((c) => c.rating < 4)
        .map((c) => ({ name: c.name, infl: (c.share ?? 0) * (4 - c.rating) }))
        .filter((r) => r.infl > 0)
        .sort((a, b) => b.infl - a.infl);
    // Pruebas que exigen acción (investigar+) aunque NO bajen el índice (rating alto
    // con falla activa) — evita que el resumen diga "todo bien" mientras una card alerta.
    const flagged = comps.filter((c) => testActionLevel(c.code) >= 2).map((c) => c.name);
    if (reducers.length) {
        const max = reducers[0].infl;
        const top = reducers.filter((r) => Math.abs(r.infl - max) < 0.05).map((r) => r.name);
        lines.push(t('transformers.health_concl_driver', { tests: top.join(', ') }));
    } else if (flagged.length) {
        lines.push(t('transformers.health_concl_flagged', { tests: flagged.join(', ') }));
    } else {
        lines.push(t('transformers.health_concl_all_good'));
    }
    // Acción global = la PEOR entre el tramo del HI y las pruebas individuales. Así el
    // resumen nunca recomienda menos que la prueba más comprometida (coherencia).
    const worst = comps.reduce((m, c) => Math.max(m, testActionLevel(c.code)), hiActionLevel(hi.value));
    lines.push(t('transformers.health_concl_' + ACTION_KEYS[worst]));
    return lines;
});

// Tendencia de salud (alarma temprana): chip ▼/▲/▬ en el hero.
const TREND = {
    worsening: { icon: '▼', cls: 'trend--down', key: 'trend_worsening' },
    improving: { icon: '▲', cls: 'trend--up',   key: 'trend_improving' },
    stable:    { icon: '▬', cls: 'trend--flat', key: 'trend_stable' },
};
const trend = computed(() => TREND[props.transformer.health_trend] ?? null);

const GAUGE_R = 42;
const GAUGE_C = 2 * Math.PI * GAUGE_R;
const gaugeOffset = computed(() => {
    const pct = hasHi.value ? Math.max(0, Math.min(100, hi.value)) : 0;
    return GAUGE_C * (1 - pct / 100);
});

// ── Componentes de diagnóstico (paneles) ──────────────────────────────
const components = computed(() => Object.values(props.diagnostics?.components ?? {}));

// Cards del resumen: las pruebas del HI + Factor de Potencia. Si fpot ya está en
// el HI (hi_enabled activado), NO se duplica; si no, se agrega aparte.
// Cuántos ensayos hay cargados de cada prueba. Sale de los mismos arrays que
// alimentan las tablas, así que no puede desincronizarse de lo que se ve.
const sampleCount = (code) => ({
    cromas:  props.cromas,
    fiquis:  props.fiquis,
    furanos: props.furanos,
    fpot:    props.fpots,
}[code] ?? []).length;

const diagCards = computed(() => {
    const cards = [...components.value];
    if (!cards.some((c) => c.code === 'fpot')) {
        const fp = props.diagnosisSummary?.fpot ?? {};
        cards.push({
            code: 'fpot',
            name: t('fpot.tab'),
            present: !!fp.has_data,
            condition: fp.condition ?? null,
            color: fp.color ?? null,
            rating: fp.rating ?? null,
            share: null,
            date: fp.date ?? null,
        });
    }
    return cards;
});

// ¿La última muestra de esta prueba pasó algún límite? (badge en la card)
const exceedsLimit = (code) => !!props.diagnosisSummary?.[code]?.exceeds;
// Detalle de lo que se pasó: [{ field, value, limit, unit }].
const overItems = (code) => props.diagnosisSummary?.[code]?.over ?? [];
// Veredicto separado en estado + detalles (para el pie de la card).
const vp = (code) => verdictParts(code, props.diagnosisSummary?.[code]);

// Norma del límite por prueba (evita confundir IEC con IEEE en el detalle).
const OVER_NORMS = { cromas: 'IEC 60599', fiquis: 'IEEE C57.106' };
const overNorm = (code) => OVER_NORMS[code] ?? '';

// Métodos de diagnóstico de cromas (para el card): IEC 60599 (Duval), Rogers,
// Doernenburg e IEEE C57.104 (Estado). Mismos datos que el panel de la pestaña.
const cromasMethods = computed(() => {
    const v = props.diagnosisSummary?.cromas;
    if (!v || !v.has_data || v.condition == null) return [];
    const verdict = (code) => (code ? t('cromas.ratios.fault.' + code) : t('cromas.ratios.na'));
    const rows = [
        { label: t('cromas.duval_tab'), value: v.fault_zone ? t('cromas.duval.' + v.fault_zone) : t('diagnostics.verdict_no_fault') },
        { label: t('cromas.ratios.rogers'), value: verdict(v.rogers) },
        { label: t('cromas.ratios.doernenburg'), value: verdict(v.doernenburg) },
    ];
    const dga = props.cromasDgaStatus;
    if (dga && dga.status) rows.push({ label: 'IEEE C57.104', value: dga.label ?? ('DGA ' + dga.status) });
    return rows;
});
const isPartial = computed(() => !!props.diagnostics?.partial && components.value.some(c => c.present));

const colorHex = semaforoHex;

// Figura del dibujo = DATO del catálogo de tipos (campo `shape`). El componente
// TransformerGlyph dibuja la silueta y las boquillas según fases.
const shape = computed(() => props.transformer.transformer_type?.shape || 'tank');


// ── Specs ─────────────────────────────────────────────────────────────
const age = computed(() => {
    const y = props.transformer.manufacture_year;
    return y ? new Date().getFullYear() - Number(y) : null;
});
const num = (v, suffix = '') => (v === null || v === undefined || v === '' ? '—' : `${v}${suffix}`);

// Regla del conmutador (misma que el Form): DETC → marca + modelo;
// OLTC → marca + modelo + tecnología; sin tipo/"-" → nada. Gatea el DISPLAY
// para que datos residuales (ej. tecnología guardada cuando el tipo era OLTC
// y luego se cambió a DETC) no se muestren.
const tapCode = computed(() => (props.transformer.tap_changer_type?.code || '').toLowerCase());
const showTapDetails = computed(() => tapCode.value === 'oltc' || tapCode.value === 'detc');
const showTapTechnology = computed(() => tapCode.value === 'oltc');

// Cadena Ubicación › Área › Subestación (cada parte con flag deleted).
const pathParts = computed(() => {
    const p = props.transformer.substation_path;
    if (!p) return [];
    return [p.location, p.area, p.substation].filter(Boolean);
});
const pathText = computed(() =>
    pathParts.value.map((p) => p.name + (p.deleted ? ` ${t('transformers.deleted_suffix')}` : '')).join(' › ')
);

// ── Informe consolidado en PDF ─────────────────────────────────────────────
// Capturas ocultas (siempre montadas, independientes de la pestaña activa) para
// poder generar el informe completo sin abrir cada tab.
const CROMAS_SERIES = [
    { key: 'h2', label: 'H₂', color: '#C8281D' }, { key: 'o2', label: 'O₂', color: '#D81B60' },
    { key: 'n2', label: 'N₂', color: '#5D4037' }, { key: 'ch4', label: 'CH₄', color: '#E9A23B' },
    { key: 'co', label: 'CO', color: '#7F8C8D' }, { key: 'co2', label: 'CO₂', color: '#16A34A' },
    { key: 'c2h4', label: 'C₂H₄', color: '#0A6ED1' }, { key: 'c2h6', label: 'C₂H₆', color: '#2AA198' },
    { key: 'c2h2', label: 'C₂H₂', color: '#8E44AD' },
// Las capturas del PDF llevan el mismo título que la pantalla: nombre + símbolo.
].map((s) => ({ ...s, sym: s.label, label: t('cromas.' + s.key + '_short') }));
// Series de fiquis para las capturas del PDF: se derivan de las columnas que
// resolvió el servidor (mismo criterio que la pestaña), NO de una lista fija.
// Antes eran 5 clavadas con las etiquetas en español a mano: el informe se
// quedaba sin los parámetros de método alterno (rigidez D877, FP a 100 °C) y
// en inglés salían los títulos en español.
const FIQUIS_COLOR = {
    rig: '#0A6ED1', rig877: '#41B0D8', ten: '#2AA198',
    acid: '#C8281D', wat: '#E9A23B', pot: '#8E44AD', pot100: '#C39BD3',
};
const FIQUIS_SERIES = computed(() => {
    const cols = props.fiquisColumns ?? [];
    const veces = {};
    cols.forEach((c) => { const n = t('fiquis.' + c.key); veces[n] = (veces[n] ?? 0) + 1; });

    return cols.map((c) => {
        const nombre = t('fiquis.' + c.key);
        // Mismo criterio que la pestaña: la norma sola no alcanza (los dos
        // factores de potencia son D924), así que distingue la línea de unidad.
        const repetido = veces[nombre] > 1;
        const distintivo = fiquiHead(t, c);
        return {
            key: c.key,
            label: repetido && distintivo ? `${nombre} — ${distintivo}` : nombre,
            hideUnit: repetido && !!distintivo,
            color: FIQUIS_COLOR[c.key] ?? '#6A6D70',
            unit: c.unit,
        };
    });
});
// Tendencia del DP (furanos) para el informe: misma serie que la pestaña.
const DP_SERIES = [{ key: 'dp', label: 'DP', color: '#32363a' }];
const furanosCapture = ref(null);
const cromasCapture = ref(null);
const fiquisCapture = ref(null);
const duvalCapture = ref(null);
const reporting = ref(false);

// Captura los gráficos del navegador (echarts → PNG) para el informe. Se reusa
// para el PDF logueado y para cachearlos al compartir (mismas imágenes reales).
const captureReportImages = async () => {
    const duvalImgs = (await duvalCapture.value?.getReportImages?.()) ?? [];
    return [
        ...(cromasCapture.value?.getImages?.() ?? []).map((i) => ({ ...i, group: 'cromas' })),
        ...duvalImgs.map((i) => ({ ...i, group: 'duval' })),
        ...(fiquisCapture.value?.getImages?.() ?? []).map((i) => ({ ...i, group: 'fiquis' })),
        ...(furanosCapture.value?.getImages?.() ?? []).map((i) => ({ ...i, group: 'furanos' })),
    ];
};

const downloadReport = async () => {
    if (reporting.value) return;
    reporting.value = true;
    try {
        const images = await captureReportImages();
        const { data } = await window.axios.post(
            route('business_management.transformers.report', props.transformer.slug),
            { images },
            { responseType: 'blob' },
        );
        const url = URL.createObjectURL(data);
        const a = document.createElement('a');
        a.href = url;
        a.download = `informe-${props.transformer.serial || props.transformer.slug}.pdf`;
        document.body.appendChild(a); a.click(); a.remove();
        URL.revokeObjectURL(url);
    } finally {
        reporting.value = false;
    }
};

// Borrador editable en Word. Mismo contenido, pero sin QR ni firmas: es
// material de trabajo, no el entregable. No pasa por aprobación.
const draftingWord = ref(false);
const downloadWordDraft = async () => {
    if (draftingWord.value) return;
    draftingWord.value = true;
    try {
        const images = await captureReportImages();
        const { data } = await window.axios.post(
            route('business_management.transformers.report_word', props.transformer.slug),
            { images },
            { responseType: 'blob' },
        );
        const url = URL.createObjectURL(data);
        const a = document.createElement('a');
        a.href = url;
        a.download = `informe-${props.transformer.serial || props.transformer.slug}.docx`;
        document.body.appendChild(a); a.click(); a.remove();
        URL.revokeObjectURL(url);
    } finally {
        draftingWord.value = false;
    }
};

// ── Flujo de aprobación (etapa 2 de firmas) ─────────────────────────────────
// Si el workspace EXIGE aprobación, el informe no se descarga directo: se envía
// a aprobación (los firmantes lo revisan/firman y recién ahí queda oficial).
const requiresApproval = computed(() => !!usePage().props.approvals?.requires_approval);
const sendingApproval = ref(false);

const sendForApproval = async () => {
    if (sendingApproval.value) return;
    sendingApproval.value = true;
    try {
        const images = await captureReportImages();
        router.post(
            route('business_management.transformers.send_for_approval', props.transformer.slug),
            { images },
            {
                preserveScroll: true,
                onError: () => message.error(t('global.error')),
                onFinish: () => { sendingApproval.value = false; },
            },
        );
    } catch (e) {
        sendingApproval.value = false;
    }
};
</script>

<template>
    <Head :title="transformer.serial || transformer.tag" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.transformers.index')"
            :title="transformer.serial || transformer.tag"
            :icon-bg="iconBg"
        >
            <template #icon><TransformerIcon /></template>
            <template #subtitle>
                <Space :size="6">
                    <span v-if="transformer.customer" class="hero-client">{{ transformer.customer.name }}</span>
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                </Space>
            </template>
            <template #actions>
                <Space :size="8">
                    <!-- Accion principal de un clic (PDF oficial o enviar a
                         aprobacion) + menu con el borrador editable. Un segundo
                         boton suelto competiria con el entregable formal. -->
                    <Dropdown.Button
                        type="primary"
                        :loading="reporting || sendingApproval"
                        @click="requiresApproval ? sendForApproval() : downloadReport()"
                    >
                        <template #icon><DownOutlined /></template>
                        <template #overlay>
                            <Menu>
                                <MenuItem key="word" :disabled="draftingWord" @click="downloadWordDraft">
                                    <FileWordOutlined /> {{ $t('transformers.report.draft_word') }}
                                </MenuItem>
                                <Menu.Item key="word_help" disabled class="rep-menu__help">
                                    {{ $t('transformers.report.draft_word_help') }}
                                </Menu.Item>
                            </Menu>
                        </template>
                        <AuditOutlined v-if="requiresApproval" />
                        <FilePdfOutlined v-else />
                        <span class="btn-txt">
                            {{ requiresApproval ? $t('transformers.report.send_approval') : $t('transformers.report.open') }}
                        </span>
                    </Dropdown.Button>
                    <ShareModal v-if="!isDeleted" scope-type="transformer" :scope-id="transformer.id" :chart-provider="captureReportImages" />
                    <EntityShowActions
                        module="transformers"
                        route-prefix="business_management"
                        :slug="transformer.slug"
                        :id="transformer.id"
                        :is-deleted="isDeleted"
                        :can-edit="can('transformers.edit')"
                        :can-delete="can('transformers.delete')"
                        :can-see-audit="canSeeAudit"
                        :lock="transformer.lock"
                    />
                </Space>
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(transformer.deleted_at) }}</div>
                <div v-if="transformer.deleter"><strong>{{ $t('global.deleted_by') }}:</strong> {{ transformer.deleter.name }}</div>
                <div v-if="transformer.deleted_description"><strong>{{ $t('global.delete_description') }}:</strong> {{ transformer.deleted_description }}</div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="transformers" route-prefix="business_management" />
            </template>
        </Alert>

        <!-- Capturas ocultas (siempre montadas) para el informe consolidado. -->
        <div class="tr-report-capture" aria-hidden="true">
            <GasTrends v-if="cromas.length" ref="cromasCapture" :samples="cromas" :series="CROMAS_SERIES"
                :limits="cromasLimits" unit="ppm" show-combined :combined-hidden="['o2', 'n2']" :animate="false" />
            <GasTrends v-if="fiquis.length" ref="fiquisCapture" :samples="fiquis" :series="FIQUIS_SERIES"
                :limits="fiquisLimits" :animate="false" />
            <GasTrends v-if="furanos.length" ref="furanosCapture" :samples="furanos" :series="DP_SERIES"
                :animate="false" />
            <DuvalTab v-if="cromas.length" ref="duvalCapture" :cromas="cromas"
                        :laboratories="laboratories" :geometry="duvalGeometry" />
        </div>

        <!-- Tabs de nivel superior estándar: Detalles (diagnóstico + rail) / Historial. -->
        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
        <template #general>
        <!-- ════════════════ Datos del transformador (full width) ════════════════ -->
                <!-- ── Datos del transformador (compacto, 2 columnas) ── -->
                <Card class="info-card specs-card">
                    <template #title>
                        <div class="specs-card__head">
                            <span class="specs-card__title"><TransformerIcon /> {{ $t('transformers.specs_summary') }}</span>
                            <Tooltip :title="$t('transformers.change_view')">
                                <Button size="small" class="specs-toggle" @click="specsView = specsView === 'grid' ? 'visual' : 'grid'">
                                    <SwapOutlined /> <span class="btn-txt">{{ $t('transformers.change_view') }}</span>
                                </Button>
                            </Tooltip>
                        </div>
                    </template>

                    <!-- Vista visual: el hero original (gauge + chips). -->
                    <div v-if="specsView === 'visual'" class="tr-hero" :style="{ '--hi-color': hiColor }">
                    <div class="tr-hero__art">
                    <TransformerGlyph :shape="shape" :phases="transformer.phases || 'three'" :color="hiColor" />
                    </div>

                    <div class="tr-hero__id">
                    <div class="tr-hero__serial">{{ transformer.serial || transformer.tag }}</div>
                    <div class="tr-hero__meta">
                    <span v-if="transformer.tag">TAG {{ transformer.tag }}</span>
                    <span v-if="transformer.transformer_type">· {{ transformer.transformer_type.name }}</span>
                    <span v-if="transformer.oil_type">· {{ transformer.oil_type.name }}</span>
                    </div>
                    <div class="tr-hero__chips">
                    <Tooltip :title="$t('transformers.voltage_kv')">
                    <span class="kpi"><ThunderboltOutlined /> {{ num(transformer.voltage_kv) }} <i>kV</i></span>
                    </Tooltip>
                    <Tooltip :title="$t('transformers.power_mva')">
                    <span class="kpi"><DashboardOutlined /> {{ num(transformer.power_mva) }} <i>MVA</i></span>
                    </Tooltip>
                    <Tooltip :title="$t('transformers.manufacture_year')">
                    <span class="kpi">
                    <CalendarOutlined /> {{ num(transformer.manufacture_year) }}
                    <i v-if="age !== null">· {{ age }} {{ $t('transformers.years') }}</i>
                    </span>
                    </Tooltip>
                    <Tooltip v-if="transformer.phases" :title="$t('transformers.phases')">
                    <span class="kpi"><PartitionOutlined /> {{ $t('transformers.phases_' + transformer.phases) }}</span>
                    </Tooltip>
                    <Tooltip v-if="transformer.brand" :title="$t('transformers.brand')">
                    <span class="kpi"><TagsOutlined /> {{ transformer.brand.name }}</span>
                    </Tooltip>
                    <Tooltip v-if="transformer.tap_changer_type" :title="$t('transformers.tap_changer_type')">
                    <span class="kpi"><ControlOutlined /> {{ transformer.tap_changer_type.name }}</span>
                    </Tooltip>
                    <Tooltip v-if="transformer.connection_type" :title="$t('transformers.connection_type')">
                    <span class="kpi"><DeploymentUnitOutlined /> {{ transformer.connection_type.name }}</span>
                    </Tooltip>
                    </div>
                    <div v-if="transformer.customer || transformer.substation_path" class="tr-hero__loc">
                    <EnvironmentOutlined />
                    <span v-if="transformer.customer">{{ transformer.customer.name }}</span>
                    <span v-if="pathText" class="tr-hero__loc-path">{{ pathText }}</span>
                    </div>
                    </div>

                    <div class="tr-hero__gauge">
                    <svg viewBox="0 0 100 100" class="gauge" :class="{ 'gauge--empty': !hasHi }">
                    <title v-if="!hasHi">{{ $t('global.no_data') }}</title>
                    <circle class="gauge__track" cx="50" cy="50" :r="GAUGE_R" />
                    <circle
                    v-if="hasHi"
                    class="gauge__value"
                    cx="50" cy="50" :r="GAUGE_R"
                    :stroke="hiColor"
                    :stroke-dasharray="GAUGE_C"
                    :stroke-dashoffset="gaugeOffset"
                    />
                    <text x="50" y="47" class="gauge__num">{{ hasHi ? Math.round(hi) : '—' }}</text>
                    <text v-if="hasHi" x="50" y="62" class="gauge__pct">%</text>
                    </svg>
                    <div class="tr-hero__gauge-label">
                    <span class="dot" :style="{ background: hiColor }"></span>
                    {{ hiState }}
                    </div>
                    <div class="tr-hero__gauge-cap">{{ $t('transformers.health_index') }}</div>
                    <span v-if="trend" class="tr-trend" :class="trend.cls">
                    {{ trend.icon }} {{ $t('transformers.fleet.' + trend.key) }}
                    </span>
                    </div>
                    </div>

                    <!-- Vista detallada: spec-grid (imagen 1, estándar). -->
                    <dl v-else class="spec-grid">
                        <div class="spec"><dt>{{ $t('transformers.serial') }}</dt><dd>{{ transformer.serial || '—' }}</dd></div>
                        <div class="spec"><dt>TAG</dt><dd>{{ transformer.tag || '—' }}</dd></div>
                        <div class="spec"><dt>{{ $t('transformers.customer') }}</dt><dd>{{ transformer.customer?.name || '—' }}</dd></div>
                        <div v-if="transformer.substation_path" class="spec spec--wide">
                            <dt>{{ $t('transformers.location_path') }}</dt>
                            <dd class="path">
                                <template v-for="(part, i) in pathParts" :key="i">
                                    <span v-if="i > 0" class="path__sep">›</span>
                                    <span :class="{ 'path__del': part.deleted }">
                                        {{ part.name }}<em v-if="part.deleted"> {{ $t('transformers.deleted_suffix') }}</em>
                                    </span>
                                </template>
                            </dd>
                        </div>
                        <div class="spec"><dt>{{ $t('transformers.transformer_type') }}</dt><dd>{{ transformer.transformer_type?.name || '—' }}</dd></div>
                        <div class="spec"><dt>{{ $t('transformers.oil_type') }}</dt><dd>{{ transformer.oil_type?.name || '—' }}</dd></div>
                        <div class="spec"><dt>{{ $t('transformers.voltage_kv') }}</dt><dd>{{ num(transformer.voltage_kv) }}</dd></div>
                        <div class="spec"><dt>{{ $t('transformers.power_mva') }}</dt><dd>{{ num(transformer.power_mva) }}</dd></div>
                        <div class="spec">
                            <dt>{{ $t('transformers.manufacture_year') }}</dt>
                            <dd>{{ num(transformer.manufacture_year) }}<span v-if="age !== null" class="muted"> · {{ age }} {{ $t('transformers.years') }}</span></dd>
                        </div>
                        <div class="spec">
                            <dt>{{ $t('transformers.age') }}</dt>
                            <dd>{{ age !== null ? `${age} ${$t('transformers.years')}` : '—' }}</dd>
                        </div>
                        <div class="spec"><dt>{{ $t('transformers.brand') }}</dt><dd>{{ transformer.brand?.name || '—' }}</dd></div>
                        <div class="spec"><dt>{{ $t('transformers.tap_changer_type') }}</dt><dd>{{ transformer.tap_changer_type?.name || '—' }}</dd></div>
                        <div v-if="showTapDetails && transformer.tap_changer_brand" class="spec"><dt>{{ $t('transformers.tap_changer_brand') }}</dt><dd>{{ transformer.tap_changer_brand.name }}</dd></div>
                        <div v-if="showTapDetails && transformer.tap_changer_model" class="spec"><dt>{{ $t('transformers.tap_changer_model') }}</dt><dd>{{ transformer.tap_changer_model.name }}</dd></div>
                        <div v-if="showTapTechnology && transformer.tap_changer_technology" class="spec"><dt>{{ $t('transformers.tap_changer_technology') }}</dt><dd>{{ transformer.tap_changer_technology.name }}</dd></div>
                        <div class="spec"><dt>{{ $t('transformers.connection_type') }}</dt><dd>{{ transformer.connection_type?.name || '—' }}</dd></div>
                        <div class="spec"><dt>{{ $t('transformers.preservation') }}</dt><dd>{{ transformer.preservation?.name || '—' }}</dd></div>
                        <div class="spec"><dt>{{ $t('transformers.phases') }}</dt><dd>{{ transformer.phases ? $t('transformers.phases_' + transformer.phases) : '—' }}</dd></div>
                        <div class="spec"><dt>{{ $t('transformers.paper_type') }}</dt><dd>{{ transformer.paper_type ? $t('transformers.paper_type_' + transformer.paper_type) : '—' }}</dd></div>
                        <div class="spec"><dt>{{ $t('transformers.oil_treated_at') }}</dt><dd>{{ transformer.oil_treated_at || '—' }}</dd></div>
                        <div v-if="isSuper" class="spec"><dt>ID</dt><dd>{{ transformer.id }}</dd></div>
                        <div v-if="isSuper" class="spec"><dt>Slug</dt><dd><code class="muted">{{ transformer.slug }}</code></dd></div>
                    </dl>
                </Card>

        <!-- ════ Object page: rail lateral sticky + contenido ════ -->
        <div ref="objpageTop" class="objpage" :class="{ 'objpage--narrow': narrowTabs }">
            <aside class="tr-rail">
                <div class="tr-rail__group">{{ $t('transformers.group_diagnostic') }}</div>
                <button
                    v-for="item in diagRail" :key="item.key" type="button"
                    class="tr-rail__item" :class="{ on: activeTab === item.key }" @click="go(item.key)"
                >
                    <component :is="item.icon" class="tr-rail__ic" />
                    <span class="tr-rail__lbl">{{ item.label }}</span>
                    <span v-if="item.dot" class="sdot" :style="{ background: item.dot }"></span>
                </button>
                <div class="tr-rail__group">{{ $t('transformers.group_record') }}</div>
                <button type="button" class="tr-rail__item" :class="{ on: activeTab === 'bitacora' }" @click="go('bitacora')">
                    <HistoryOutlined class="tr-rail__ic" />
                    <span class="tr-rail__lbl">{{ $t('bitacora.tab') }}</span>
                </button>
            </aside>

            <div class="tr-content">
            <div v-show="activeTab === 'resumen'">
                <Alert
                    v-if="isPartial"
                    type="info"
                    show-icon
                    class="partial-notice"
                    :message="$t('transformers.partial_notice')"
                />

                <!-- ── Conclusión del Índice de Salud (estado + driver + acción) ── -->
                <div v-if="healthConcl" class="hi-concl">
                    <div class="hi-concl__title">{{ $t('transformers.health_concl_title') }}</div>
                    <ul class="hi-concl__list">
                        <li v-for="(l, i) in healthConcl" :key="i">{{ l }}</li>
                    </ul>
                </div>

                <!-- ── Resumen de diagnóstico: grid de paneles por prueba ── -->
                <div class="diag-grid">
                    <div
                        v-for="c in diagCards"
                        :key="c.code"
                        class="diag-card"
                        :class="{ 'diag-card--empty': !c.present, 'diag-card--over': c.present && exceedsLimit(c.code) }"
                        :style="c.present ? { '--c': colorHex(c.color) } : {}"
                    >
                        <div class="diag-card__top">
                            <span class="diag-card__name">{{ c.name }}</span>
                        </div>

                        <template v-if="c.present">
                            <div class="diag-card__body">
                                <RatingGauge :rating="c.rating" />
                                <div class="diag-card__info">
                                    <div class="diag-card__cond" :style="{ color: colorHex(c.color) }">{{ condLabel(c.condition) }}</div>
                                    <div class="diag-card__meta">
                                        <Tooltip v-if="c.share !== null" :title="$t('transformers.share_help')">
                                            <span class="diag-card__share">{{ $t('transformers.test_weight') }} <b>{{ c.share }}%</b></span>
                                        </Tooltip>
                                        <span class="diag-card__date"><CalendarOutlined /> {{ $t('transformers.last_sample') }}: {{ c.date ?? '—' }}</span>
                                        <span class="diag-card__count">{{ $tc('transformers.sample_count', sampleCount(c.code)) }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Pie único: lo que se pasó (rojo) · o el estado (verde si OK) + detalles. -->
                            <div v-if="overItems(c.code).length" class="diag-card__foot diag-card__over">
                                <div class="diag-card__over-h">
                                    <WarningFilled /> {{ $t('transformers.over_limit') }}
                                    <span v-if="overNorm(c.code)" class="diag-card__over-norm">· {{ overNorm(c.code) }}</span>
                                </div>
                                <div v-for="(o, i) in overItems(c.code)" :key="i" class="diag-card__over-row">
                                    <span class="diag-card__over-f">{{ o.field }}</span>
                                    <span class="diag-card__over-v">{{ o.value }} <i>{{ o.unit }}</i></span>
                                    <span class="diag-card__over-l">{{ $t('transformers.limit') }} {{ o.limit ?? '—' }}</span>
                                </div>
                            </div>
                            <div v-else class="diag-card__foot">
                                <p v-if="vp(c.code).status" class="diag-card__status" :class="{ 'is-ok': vp(c.code).ok }" :style="vp(c.code).ok ? {} : { color: colorHex(c.color) }">
                                    <CheckCircleFilled v-if="vp(c.code).ok" /> {{ vp(c.code).status }}
                                </p>
                                <p v-for="(d, i) in vp(c.code).details" :key="i" class="diag-card__detail">{{ d }}</p>
                            </div>

                            <!-- Métodos de diagnóstico (solo cromas): Rogers, Doernenburg, IEC, IEEE. -->
                            <div v-if="c.code === 'cromas' && cromasMethods.length" class="diag-card__methods">
                                <div v-for="(m, i) in cromasMethods" :key="i" class="diag-card__method">
                                    <span class="diag-card__method-l">{{ m.label }}</span>
                                    <span class="diag-card__method-v">{{ m.value }}</span>
                                </div>
                            </div>
                        </template>
                        <template v-else>
                            <div class="diag-card__empty">{{ $t('transformers.no_sample') }}</div>
                        </template>
                    </div>
                </div>
            </div>

            <div v-if="activeTab === 'cromatografia'">
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <CromatografiaSection
                        :transformer-slug="transformer.slug"
                        :transformer-id="transformer.id"
                        :laboratories="laboratories"
                        :cromas="cromas"
                        :cromas-limits="cromasLimits"
                        :cromas-limits-ieee="cromasLimitsIeee"
                        :cromas-norms="cromasNorms"
                        :duval-geometry="duvalGeometry"
                        :cell-alert-sev="cellAlertSev"
                        :diagnosis="diagnosisSummary.cromas"
                        :dga-status="cromasDgaStatus"
                        :comments="cromasComments"
                        :can-edit="canEditTests"
                    />
                </Card>
            </div>

            <div v-if="activeTab === 'fiquis'">
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <FiquisSection
                        :transformer-slug="transformer.slug"
                        :transformer-id="transformer.id"
                        :fiquis="fiquis"
                        :laboratories="laboratories"
                        :fiquis-limits="fiquisLimits"
                        :fiquis-columns="fiquisColumns"
                        :cell-alert-sev="cellAlertSev"
                        :diagnosis="diagnosisSummary.fiquis"
                        :comments="fiquisComments"
                        :can-edit="canEditTests"
                    />
                </Card>
            </div>

            <div v-if="activeTab === 'furanos'">
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <FuranosSection
                        :transformer-slug="transformer.slug"
                        :transformer-id="transformer.id"
                        :furanos="furanos"
                        :laboratories="laboratories"
                        :furanos-limits="furanosLimits"
                        :comments="furanosComments"
                        :diagnosis="diagnosisSummary.furanos"
                        :trend="furanosTrend"
                        :co-ratio="furanosCoRatio"
                        :mechanism="furanosMechanism"
                        :oil-treated-at="transformer.oil_treated_at"
                        :paper-type="transformer.paper_type"
                        :cell-alert-sev="cellAlertSev"
                        :can-edit="canEditTests"
                    />
                </Card>
            </div>

            <div v-if="activeTab === 'fpot'">
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <FpotSection
                        :transformer-slug="transformer.slug"
                        :transformer-id="transformer.id"
                        :cell-alert-sev="cellAlertSev"
                        :fpots="fpots"
                        :laboratories="laboratories"
                        :fpot-scale="fpotScale"
                        :diagnosis="diagnosisSummary.fpot"
                        :comments="fpotComments"
                        :can-edit="canEditTests"
                    />
                </Card>
            </div>

            <div v-if="activeTab === 'bitacora'">
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <BitacoraSection
                        :transformer-slug="transformer.slug"
                        :events="events"
                        :can-edit="canEditTests"
                    />
                </Card>
            </div>
            </div><!-- /tr-content -->
        </div><!-- /objpage -->
        </template>

        <template #history>
            <RecordHistory
                :record-audit="recordAudit"
                :activity="activity"
                :can-see-activity="canSeeAudit"
                :has-more="activityHasMore"
                :loading-more="loadingMoreActivity"
                @load-more="loadMoreActivity"
            />
        </template>
        </EntityShowTabs>
    </div>
</template>

<style scoped>
/* Nota del menu del informe: es explicacion, no una opcion clickeable. */
.rep-menu__help { font-size:0.75rem; color:var(--color-text-muted,#6A6D70); white-space:normal; max-width:260px; cursor:default; }
.show-page { /* full width */ }
.tr-report { display: flex; justify-content: flex-end; margin: 4px 0 12px; }
.tr-report-capture { position: absolute; left: -10000px; top: 0; width: 720px; overflow: hidden; }
.tab-lbl { display: inline-flex; align-items: center; gap: 6px; }
.tr-tabs :deep(.ant-tabs-tab) { padding: 10px 16px; font-size: 0.9375rem; }

/* ════ Object page: rail lateral sticky + contenido (grid, NO Ant tabs) ════
   Grid de 2 columnas con align-items:start → el rail toma su altura natural y
   queda sticky/flotante mientras se hace scroll del contenido. (Robusto: no
   depende de las internals de Ant Tabs, que rompían el sticky.) */
.objpage { display: grid; grid-template-columns: 248px 1fr; gap: 16px; align-items: start; scroll-margin-top: 60px; }
/* Apilado en pantallas chicas. Doble clase para ganarle al media query de
   1100px de abajo (a igual especificidad ganaba el último y en móvil seguían
   las 2 columnas, con el contenido aplastado a ~130px). */
.objpage.objpage--narrow { grid-template-columns: 1fr; }
.objpage--narrow .tr-rail { position: static; max-height: none; }
@media (max-width: 1100px) { .objpage { grid-template-columns: 212px 1fr; } }

.tr-rail {
    position: sticky;
    /* Justo debajo del header sticky (shell-bar + topnav). --tr-sticky-h lo
       expone el layout según el modo de navegación (top/side). */
    top: calc(var(--tr-sticky-h, 44px) + 12px);
    background: var(--color-surface, #fff);
    border: 1px solid var(--color-border, #eef0f2);
    border-radius: 12px;
    padding: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    max-height: calc(100vh - var(--tr-sticky-h, 44px) - 24px);
    overflow-y: auto;
}
.tr-rail__group {
    font-size: 0.66rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
    color: var(--color-text-muted, #6A6D70); padding: 12px 12px 6px;
}
.tr-rail__group:first-child { padding-top: 4px; }
.tr-rail__item {
    display: flex; align-items: center; gap: 10px; width: 100%;
    padding: 9px 11px; border: 0; background: transparent; cursor: pointer;
    border-radius: 8px; font: inherit; font-size: 0.9rem; font-weight: 500;
    color: var(--color-text, #32363A); text-align: left; position: relative;
}
.tr-rail__item:hover { background: var(--color-surface-hover, #f4f7fb); }
.tr-rail__item.on { background: #eaf3fc; color: var(--color-primary, #0A6ED1); font-weight: 700; }
.tr-rail__item.on::before {
    content: ""; position: absolute; left: 0; top: 7px; bottom: 7px; width: 3px;
    border-radius: 3px; background: var(--color-primary, #0A6ED1);
}
.tr-rail__ic { width: 18px; text-align: center; opacity: 0.9; flex-shrink: 0; }
.tr-rail__lbl { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tr-rail__n {
    font-size: 0.7rem; color: var(--color-text-muted, #6A6D70);
    background: var(--color-surface-alt, #f5f6f7); border-radius: 999px;
    padding: 1px 7px; font-weight: 600;
}
.sdot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; display: inline-block; }
.tr-content { min-width: 0; }                               /* permite que el contenido encoja */
/* El rail vive dentro del tab "Detalles" de EntityShowTabs (Ant Tabs). Sus
   content-holders no deben recortar, o el sticky se rompería. */
.show-page :deep(.ant-tabs-content-holder),
.show-page :deep(.ant-tabs-content),
.show-page :deep(.ant-tabs-tabpane) { overflow: visible; }
.muted { color: var(--color-text-muted, #6A6D70); font-size: 0.8125rem; }
.deleted-alert { margin-bottom: 16px; }
.partial-notice { margin-bottom: 16px; border-radius: 8px; }
.info-card { margin-bottom: 16px; border-radius: 8px; }
.hero-client { color: var(--color-text-muted, #6A6D70); font-weight: 500; }
.section-title { margin: 4px 0 12px; font-size: 1.05rem; font-weight: 600; color: var(--color-text, #1f2937); }

/* ════════════ HERO — estilo SAP Fiori (object page header, claro) ════════════ */
.tr-hero {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 28px;
    padding: 20px 24px;
    margin-bottom: 18px;
    border-radius: 8px;
    color: var(--color-text, #32363A);
    background: var(--color-surface, #fff);
    border: 1px solid var(--color-border, #e5e5e5);
    /* Filo de color (health) a la izquierda — acento Fiori, no fondo. */
    border-left: 4px solid var(--hi-color, #0A6ED1);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
    overflow: hidden;
}
.tr-hero__art { width: 116px; height: 116px; flex-shrink: 0; }
.tr-hero__id { min-width: 0; }
.tr-hero__serial { font-size: 1.6rem; font-weight: 700; letter-spacing: 0.2px; line-height: 1.1; color: var(--color-text, #32363A); }
.tr-hero__meta { margin-top: 4px; color: var(--color-text-muted, #6A6D70); font-size: 0.9rem; display: flex; flex-wrap: wrap; gap: 6px; }
.tr-hero__chips { margin-top: 16px; display: flex; flex-wrap: wrap; gap: 10px; }
.kpi { display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; border-radius: 6px; background: var(--color-surface-alt, #f5f6f7); border: 1px solid var(--color-border, #e5e5e5); font-size: 1rem; font-weight: 600; color: var(--color-text, #32363A); transition: transform 0.14s ease, box-shadow 0.14s ease, border-color 0.14s ease; }
.kpi:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(15,23,42,0.08); border-color: var(--hi-color, #0A6ED1); }
.kpi i { font-style: normal; font-weight: 400; color: var(--color-text-muted, #6A6D70); font-size: 0.82rem; }
.kpi :deep(.anticon) { color: var(--hi-color, #0A6ED1); }
/* Línea de ubicación (cliente › subestación) */
.tr-hero__loc { margin-top: 10px; display: inline-flex; align-items: center; gap: 7px; font-size: 0.88rem; color: var(--color-text-muted, #6A6D70); }
.tr-hero__loc :deep(.anticon) { color: var(--hi-color, #0A6ED1); }
.tr-hero__loc-path { padding-left: 8px; border-left: 1px solid var(--color-border, #e5e5e5); }
/* Chip de tendencia bajo el gauge */
.tr-trend { display: inline-flex; align-items: center; gap: 4px; font-size: 0.72rem; font-weight: 700; padding: 2px 9px; border-radius: 999px; text-transform: uppercase; letter-spacing: 0.04em; }
.trend--down { color: #C8281D; background: rgba(200,40,29,0.10); }
.trend--up   { color: #1D7044; background: rgba(29,112,68,0.10); }
.trend--flat { color: #6A6D70; background: rgba(106,109,112,0.10); }
.tr-hero__gauge { display: flex; flex-direction: column; align-items: center; gap: 6px; }
.gauge { width: 104px; height: 104px; transform: rotate(-90deg); }
.gauge--empty { cursor: help; }
.gauge__track { fill: none; stroke: var(--color-border, #e9ebee); stroke-width: 8; }
.gauge__value { fill: none; stroke-width: 8; stroke-linecap: round; transition: stroke-dashoffset 0.9s ease; }
.gauge__num { transform: rotate(90deg); transform-origin: 50px 50px; text-anchor: middle; fill: var(--color-text, #32363A); font-size: 1.9rem; font-weight: 700; }
.gauge__pct { transform: rotate(90deg); transform-origin: 50px 50px; text-anchor: middle; fill: var(--color-text-muted, #6A6D70); font-size: 0.8rem; }
.tr-hero__gauge-label { display: inline-flex; align-items: center; gap: 7px; font-weight: 600; font-size: 0.95rem; color: var(--color-text, #32363A); }
.tr-hero__gauge-cap { color: var(--color-text-muted, #6A6D70); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px; }

.dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; flex-shrink: 0; }

/* ════════════ Grid de paneles de diagnóstico ════════════ */
.hi-concl {
    border: 1px solid var(--color-border, #eef0f2); border-left: 4px solid var(--color-primary, #0A6ED1);
    border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; background: var(--color-surface, #fff);
}
.hi-concl__title { font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--color-text, #32363a); margin-bottom: 6px; }
.hi-concl__list { margin: 0; padding-left: 18px; }
.hi-concl__list li { font-size: 0.88rem; line-height: 1.5; color: var(--color-text, #32363a); margin-bottom: 3px; }
html[data-theme="dark"] .hi-concl { background: #1f2429; border-color: #3f4448; }
.diag-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 18px;
    margin-bottom: 22px;
}
.diag-card {
    position: relative;
    background: var(--color-surface, #fff);
    border: 1px solid var(--color-border, #e8ebee);
    border-radius: 16px;
    padding: 20px 22px;
    overflow: hidden;
    transition: box-shadow 0.18s ease, transform 0.18s ease, border-color 0.18s ease;
    animation: diagCardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
}
/* Entrada escalonada (CSS puro, una sola corrida — no afecta performance). */
.diag-card:nth-child(2) { animation-delay: 0.06s; }
.diag-card:nth-child(3) { animation-delay: 0.12s; }
.diag-card:nth-child(4) { animation-delay: 0.18s; }
.diag-card:nth-child(5) { animation-delay: 0.24s; }
@keyframes diagCardIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}
@media (prefers-reduced-motion: reduce) {
    .diag-card { animation: none; }
}
/* Prueba que pasó algún límite: borde de aviso + bloque de detalle al final. */
.diag-card--over { border-color: #C8281D66; }
/* Pie único: una sola línea horizontal arriba del bloque (qué pasó / razón / ok). */
.diag-card__foot { margin: 14px 0 0; padding-top: 12px; border-top: 1px solid var(--color-border, #eef0f2); }
/* Estado (titular): verde si está bien; coloreado por condición si no. */
.diag-card__status { display: flex; align-items: center; gap: 6px; margin: 0; font-size: 0.84rem; font-weight: 700; }
.diag-card__status.is-ok { color: #1D7044; }
.diag-card__status.is-ok :deep(.anticon) { color: #1D7044; }
/* Detalles (DP, vida, valor, IEEE…): líneas secundarias, una por renglón. */
.diag-card__detail { margin: 5px 0 0; font-size: 0.78rem; line-height: 1.4; color: var(--color-text-muted, #6A6D70); }
/* Métodos de diagnóstico (cromas): mini-tabla método → veredicto. */
.diag-card__methods { margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--color-border, #eef0f2); }
.diag-card__method { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; padding: 3px 0; font-size: 0.78rem; }
.diag-card__method-l { color: var(--color-text-muted, #6A6D70); font-weight: 600; white-space: nowrap; }
.diag-card__method-v { color: var(--color-text, #1f2937); text-align: right; }
.diag-card__over-h { display: inline-flex; align-items: center; gap: 5px; font-size: 0.76rem; font-weight: 700; color: #C8281D; margin-bottom: 6px; }
.diag-card__over-norm { color: var(--color-text-muted, #9aa0a6); font-weight: 600; }
.diag-card__over-row { display: flex; align-items: baseline; gap: 8px; font-size: 0.82rem; padding: 2px 0; }
.diag-card__over-f { font-weight: 700; color: var(--color-text, #1f2937); min-width: 64px; }
.diag-card__over-v { color: #C8281D; font-weight: 600; }
.diag-card__over-v i { font-style: normal; color: var(--color-text-muted, #9aa0a6); font-weight: 400; font-size: 0.74rem; }
.diag-card__over-l { margin-left: auto; color: var(--color-text-muted, #6A6D70); font-size: 0.76rem; }
/* Tinte sutil del color de condición + barra superior de acento. */
.diag-card::before {
    content: ""; position: absolute; inset: 0 0 auto 0; height: 3px; background: var(--c, #e5e7eb);
}
.diag-card:hover { box-shadow: 0 8px 22px rgba(17,24,39,0.10); transform: translateY(-2px); border-color: var(--c, #e5e7eb); }
.diag-card--empty { opacity: 0.72; }
.diag-card--empty::before { background: var(--color-border, #e5e7eb); }
.diag-card__top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin-top: 4px; }
.diag-card__name { font-weight: 700; font-size: 1.02rem; color: var(--color-text, #1f2937); letter-spacing: 0.01em; }
/* Gauge (anillo) + info al lado. */
.diag-card__body { display: flex; align-items: center; gap: 16px; margin-top: 14px; }
.diag-card__info { flex: 1; min-width: 0; }
.diag-card__cond { font-size: 1.1rem; font-weight: 800; }
.diag-card__meta { display: flex; align-items: center; gap: 12px; margin-top: 7px; font-size: 0.78rem; color: var(--color-text-muted, #6A6D70); flex-wrap: wrap; }
.diag-card__share { font-weight: 700; color: var(--c, #6A6D70); }
.diag-card__share i { font-style: normal; font-weight: 400; color: var(--color-text-muted, #9aa0a6); }
.diag-card__count { color: var(--color-text-muted, #6A6D70); }
.diag-card__date { display: inline-flex; align-items: center; gap: 5px; }
.diag-card__reason { font-size: 0.78rem; line-height: 1.4; color: var(--color-text-muted, #6A6D70); }
.diag-card__empty { margin-top: 16px; color: var(--color-text-muted, #9aa0a6); font-size: 0.85rem; font-style: italic; }

/* ════════════ Datos del transformador (tiles) ════════════ */
.spec-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 0; }
.spec {
    display: flex; flex-direction: column; gap: 3px;
    padding: 11px 13px; border-radius: 10px;
    background: var(--color-surface-alt, #f7f9fb);
    border: 1px solid transparent;
    transition: border-color 0.15s ease, background 0.15s ease;
}
.spec:hover { border-color: var(--color-border, #e2e6ea); background: var(--color-surface, #fff); }
.spec dt { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-muted, #8a9099); font-weight: 600; }
/* Peso normal (no negrita): mismo look que .spec-cell__value de las demás fichas. */
.spec dd { margin: 0; font-size: 0.95rem; font-weight: 400; color: var(--color-text, #1f2937); }
.spec--wide { grid-column: 1 / -1; }
/* Card "Datos del transformador": cabecera con toggle + vista visual (chips). */
.specs-card__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.specs-card__title { display: inline-flex; align-items: center; gap: 8px; }
.specs-toggle { display: inline-flex; align-items: center; gap: 6px; }
.specs-visual { display: flex; flex-direction: column; gap: 14px; }
.specs-visual__id { display: flex; flex-direction: column; gap: 2px; }
.specs-visual__serial { font-size: 1.15rem; font-weight: 700; color: var(--color-text, #1f2937); }
.specs-visual__sub { font-size: 0.85rem; color: var(--color-text-muted, #6A6D70); }
.specs-visual__chips { display: flex; flex-wrap: wrap; gap: 10px; }
.specs-visual__loc { display: inline-flex; align-items: center; gap: 6px; font-size: 0.9rem; color: var(--color-text, #32363A); }
.specs-visual__loc :deep(.anticon) { color: var(--color-primary, #0A6ED1); }
.path { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-weight: 600; }
.path__sep { color: var(--color-text-muted, #9aa0a6); font-weight: 400; }
.path__del { color: #C8281D; text-decoration: line-through; }
.path__del em { font-style: normal; text-decoration: none; font-size: 0.78rem; margin-left: 2px; }

@media (max-width: 860px) {
    .tr-hero { grid-template-columns: 1fr; justify-items: center; text-align: center; gap: 18px; }
    .tr-hero__chips, .tr-hero__meta { justify-content: center; }
    .spec-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 560px) { .spec-grid { grid-template-columns: 1fr; } }
</style>

<style>
html[data-theme="dark"] .diag-card { background: #2c3034; border-color: #3f4448; }
html[data-theme="dark"] .diag-card__name { color: #e5e6e7; }
html[data-theme="dark"] .diag-card__bar { background: #3a4044; }
html[data-theme="dark"] .spec { background: #2a2f33; }
html[data-theme="dark"] .spec:hover { background: #31373b; border-color: #3f4448; }
html[data-theme="dark"] .spec dd { color: #e5e6e7; }
html[data-theme="dark"] .section-title { color: #e5e6e7; }
</style>
