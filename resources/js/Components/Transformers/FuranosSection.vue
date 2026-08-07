<script setup>
import { ref, computed } from 'vue';
import { Segmented, Tooltip, Alert, Button } from 'ant-design-vue';
import { RiseOutlined, FallOutlined, MinusOutlined } from '@ant-design/icons-vue';
import FuranosTab from '@/Components/Transformers/FuranosTab.vue';
import FuranosDiagnosis from '@/Components/Transformers/FuranosDiagnosis.vue';
import TestStatusChip from '@/Components/Transformers/TestStatusChip.vue';
import GasTrends from '@/Components/Transformers/GasTrends.vue';
import { useI18n } from '@/Plugins/i18n';

/**
 * FuranosSection — módulo de furanos del transformador.
 * Sub-vistas: Ensayos (CRUD de compuestos furánicos) y Tendencias (evolución de
 * los compuestos y el DP). Mismo patrón que cromatografía.
 */
const props = defineProps({
    transformerSlug: { type: String, required: true },
    transformerId:   { type: [Number, String], default: null },
    furanos:         { type: Array,  default: () => [] },
    furanosLimits:   { type: Object, default: () => ({}) },
    comments:        { type: Array,  default: () => [] },
    diagnosis:       { type: Object, default: () => ({}) },
    trend:           { type: Object, default: () => null },
    coRatio:         { type: Object, default: () => null },
    mechanism:       { type: Object, default: () => null },
    oilTreatedAt:    { type: String, default: null },
    paperType:       { type: String, default: null },
    cellAlertSev:    { type: Number, default: 0.6 },
    canEdit:         { type: Boolean, default: false },
    laboratories:    { type: Array, default: () => [] },
});

const { t } = useI18n();
const view = ref('resumen');

// Tasa de generación de 2-FAL (ppb/año) entre las 2 muestras más recientes.
const hasRate = computed(() => props.trend && props.trend.rate !== null && props.trend.rate !== undefined);
const rateIcon = computed(() => ({ up: RiseOutlined, down: FallOutlined, flat: MinusOutlined }[props.trend?.direction] ?? MinusOutlined));
const rateClass = computed(() => `rate--${props.trend?.direction ?? 'flat'}`);

// Abreviatura del furano secundario dominante (mecanismo de degradación).
const COMPOUND_ABBR = { hme: '5HMF', ace: '2ACF', mfu: '5MEF', fua: '2FOL' };

// Color del chip de vida del papel (a más vida consumida, peor).
const lifeBand = computed(() => {
    const p = props.diagnosis?.life_percent;
    if (p == null) return 'ok';
    return p >= 85 ? 'bad' : (p >= 50 ? 'warn' : 'ok');
});

// Aviso de aceite tratado: hay muestras tomadas DESPUÉS del tratamiento/cambio.
const samplesAfterTreatment = computed(() => {
    if (!props.oilTreatedAt) return false;
    return props.furanos.some((s) => s.sample_date && String(s.sample_date).slice(0, 10) >= props.oilTreatedAt);
});
const options = [
    { value: 'resumen', payload: { label: t('diagnostics.summary_tab'), help: t('diagnostics.summary_help') } },
    { value: 'ensayos', payload: { label: t('furanos.samples_tab'), help: t('diagnostics.samples_help') } },
    { value: 'tendencias', payload: { label: t('furanos.trends_tab'), help: t('diagnostics.trends_help') } },
];

// Series de tendencias: 5 compuestos furánicos (ppb) + DP (grado de polimerización).
const FURANO_SERIES = [
    { key: 'fal', label: '2FAL', color: '#C8281D' }, { key: 'hme', label: '5HMF', color: '#E9A23B' },
    { key: 'ace', label: '2ACF', color: '#16A34A' }, { key: 'mfu', label: '5MEF', color: '#0A6ED1' },
    { key: 'fua', label: '2FOL', color: '#8E44AD' },
];
const DP_SERIES = [{ key: 'dp', label: 'DP', color: '#32363a' }];
</script>

<template>
    <div class="section">
        <div class="section__bar">
            <Segmented v-model:value="view" :options="options" class="section__nav">
                <template #label="{ payload }">
                    <Tooltip :title="payload.help">{{ payload.label }}</Tooltip>
                </template>
            </Segmented>
            <TestStatusChip :condition="diagnosis?.condition" :color="diagnosis?.color" />
        </div>

      <div v-show="view === 'resumen'">
        <!-- Diagnóstico + conclusiones narrativas (última muestra) -->
        <FuranosDiagnosis
            :diagnosis="diagnosis"
            :trend="trend"
            :co-ratio="coRatio"
            :mechanism="mechanism"
            :oil-treated-at="oilTreatedAt"
            :paper-type="paperType"
            :transformer-id="transformerId"
            :comments="comments"
        />

        <!-- Chips de diagnóstico de un vistazo (KPIs) + avisos de interpretación -->
        <div class="fur-strip">
            <Tooltip v-if="diagnosis && diagnosis.dp != null" :title="$t('furanos.dp_chip_help')">
                <span class="fur-kpi"><strong>{{ $t('furanos.dp_chip', { dp: diagnosis.dp }) }}</strong></span>
            </Tooltip>
            <Tooltip v-if="diagnosis && diagnosis.life_percent != null" :title="$t('furanos.life_chip_help')">
                <span class="fur-kpi" :class="`fur-kpi--life-${lifeBand}`">{{ $t('furanos.life_chip', { p: diagnosis.life_percent }) }}</span>
            </Tooltip>
            <Tooltip v-if="diagnosis && diagnosis.fal != null" :title="$t('furanos.fal_chip_help')">
                <span class="fur-kpi">{{ $t('furanos.fal_chip', { fal: diagnosis.fal }) }}</span>
            </Tooltip>
            <Tooltip v-if="hasRate" :title="$t('furanos.rate_help')">
                <span class="fur-rate" :class="rateClass">
                    <component :is="rateIcon" />
                    <span class="fur-rate__lbl">{{ $t('furanos.rate_label') }}:</span>
                    <strong>{{ trend.rate > 0 ? '+' : '' }}{{ trend.rate }} {{ $t('furanos.rate_unit') }}</strong>
                </span>
            </Tooltip>
            <Tooltip v-if="coRatio" :title="$t('furanos.co_ratio_' + coRatio.level)">
                <span class="fur-co" :class="`fur-co--${coRatio.level}`">
                    CO₂/CO = {{ coRatio.ratio }}
                </span>
            </Tooltip>
            <Tooltip v-if="mechanism" :title="$t('furanos.mech_caveat')">
                <span class="fur-mech">
                    {{ COMPOUND_ABBR[mechanism.compound] }}: {{ $t('furanos.mech_' + mechanism.mechanism) }}
                </span>
            </Tooltip>
        </div>

        <Alert
            v-if="samplesAfterTreatment"
            type="warning"
            show-icon
            class="fur-warn"
            :message="$t('furanos.oil_warning_title')"
            :description="$t('furanos.oil_warning_body', { date: oilTreatedAt })"
        />
      </div>

        <FuranosTab
            v-show="view === 'ensayos'"
            :transformer-slug="transformerSlug"
            :furanos="furanos"
            :limits="furanosLimits"
            :cell-alert-sev="cellAlertSev"
            :can-edit="canEdit"
            :laboratories="laboratories"
        />
        <template v-if="view === 'tendencias'">
            <GasTrends
                :samples="furanos"
                :series="FURANO_SERIES"
                :limits="furanosLimits"
                unit="ppb"
                :no-data="$t('furanos.trends_no_data')"
            >
                <template #hint>{{ $t('furanos.trends_hint') }}</template>
            </GasTrends>
            <div class="section__dp">
                <span class="section__dp-lbl">{{ $t('furanos.dp_full') }}</span>
                <GasTrends
                    :samples="furanos"
                    :series="DP_SERIES"
                    :chart-height="360"
                    full-width
                    :no-data="$t('furanos.trends_no_data')"
                />
            </div>
        </template>
    </div>
</template>

<style scoped>
.section__nav { margin-bottom: 18px; }
.section__bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
.section__bar .section__nav { margin-bottom: 0; }
.fur-strip { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 12px; }
.fur-kpi { display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; padding: 4px 12px; border-radius: 999px; background: var(--color-surface-alt, #f5f6f7); cursor: help; font-variant-numeric: tabular-nums; color: var(--color-text, #32363a); }
.fur-kpi--life-warn { color: #E2661E; }
.fur-kpi--life-bad { color: #C8281D; font-weight: 600; }
.fur-rate { display: inline-flex; align-items: center; gap: 7px; font-size: 0.85rem; padding: 4px 12px; border-radius: 999px; background: var(--color-surface-alt, #f5f6f7); }
.fur-rate strong { font-variant-numeric: tabular-nums; }
.fur-rate--up { color: #C8281D; }
.fur-rate--down { color: #1D7044; }
.fur-rate--flat { color: var(--color-text-muted, #6A6D70); }
.fur-co { font-size: 0.85rem; padding: 4px 12px; border-radius: 999px; background: var(--color-surface-alt, #f5f6f7); cursor: help; font-variant-numeric: tabular-nums; }
.fur-co--low { color: #C8281D; }
.fur-co--high { color: #E2661E; }
.fur-co--normal { color: var(--color-text-muted, #6A6D70); }
.fur-mech { font-size: 0.85rem; padding: 4px 12px; border-radius: 999px; background: var(--color-surface-alt, #f5f6f7); color: var(--color-text-muted, #6A6D70); cursor: help; }
.fur-report { margin-left: auto; text-decoration: none; }
/* Fuera de pantalla pero renderizado (necesita tamaño para que ECharts dibuje). */
.fur-capture { position: absolute; left: -10000px; top: 0; width: 560px; height: 220px; overflow: hidden; }
.fur-warn { margin-bottom: 14px; }
.section__dp { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--color-border, #eef0f2); }
.section__dp-lbl { display: block; font-size: 0.8rem; color: var(--color-text-muted, #6A6D70); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 10px; }
html[data-theme="dark"] .section__dp { border-top-color: #3f4448; }
</style>
