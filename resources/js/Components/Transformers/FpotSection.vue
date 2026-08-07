<script setup>
import { computed, ref } from 'vue';
import { Segmented, Tooltip } from 'ant-design-vue';
import DiagnosticGrid from '@/Components/Transformers/DiagnosticGrid.vue';
import SemaforoLegend from '@/Components/Transformers/SemaforoLegend.vue';
import FpotExplainDrawer from '@/Components/Transformers/FpotExplainDrawer.vue';
import FpotDiagnosis from '@/Components/Transformers/FpotDiagnosis.vue';
import TestStatusChip from '@/Components/Transformers/TestStatusChip.vue';
import GasTrends from '@/Components/Transformers/GasTrends.vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { semaforoHex, cellAlertBg, bandOf } from '@/utils/severity';

/**
 * FpotSection — módulo de Factor de Potencia del transformador. Sub-vistas:
 * Ensayos (grilla editable estilo Excel: valor % + temperatura) y Tendencias
 * (evolución del valor con las bandas de la escala). El ⓘ abre la traza del cálculo.
 */
const props = defineProps({
    transformerSlug: { type: String, required: true },
    transformerId:   { type: [Number, String], default: null },
    fpots:           { type: Array,  default: () => [] },
    fpotScale:       { type: Array,  default: () => [] },
    cellAlertSev:    { type: Number, default: 0.6 },
    diagnosis:       { type: Object, default: () => ({}) },
    comments:        { type: Array,  default: () => [] },
    canEdit:         { type: Boolean, default: false },
    laboratories:    { type: Array, default: () => [] },
});

const { t } = useI18n();
const { condLabel } = useConditions();
const view = ref('resumen');

const colorHex = semaforoHex;

const options = [
    { value: 'resumen', payload: { label: t('diagnostics.summary_tab'), help: t('diagnostics.summary_help') } },
    { value: 'ensayos', payload: { label: t('fpot.samples_tab'), help: t('diagnostics.samples_help') } },
    { value: 'tendencias', payload: { label: t('fpot.trends_tab'), help: t('diagnostics.trends_help') } },
];

const numericCols = computed(() => [
    { key: 'value', label: t('fpot.value') },
    { key: 'temperature', label: t('fpot.temperature') },
]);
const seedDiag = (row) => ({ condition: row.condition, color: row.color, rating: row.rating });

// Serie de tendencia: único valor (factor de potencia, %), con las bandas de la escala.
const FPOT_SERIES = [{ key: 'value', label: t('fpot.value'), color: '#0A6ED1' }];
const trendLimits = computed(() => ({ value: props.fpotScale }));

// Alerta de celda (como cromas/fiquis): la celda del VALOR se tinta según la
// banda del semáforo de fpot en que cae. Ámbar leve filtrable por
// cellAlertSev; el rojo (fuera de norma) siempre visible.
const cellStyle = (key, v) => {
    if (key !== 'value' || v === '' || v == null) return null;
    const band = bandOf(props.fpotScale, Number(v));
    return band ? cellAlertBg(band.sev, props.cellAlertSev) : null;
};
const cellTip = (key, v) => {
    if (key !== 'value' || v === '' || v == null) return null;
    const band = bandOf(props.fpotScale, Number(v));
    if (!band) return null;
    const to = band.to === null || band.to === undefined ? '∞' : band.to;
    return `tan δ ${v} % · banda ${band.from}–${to}`;
};

const explainOpen = ref(false);
const explainValue = ref(null);
const openExplain = (row) => {
    explainValue.value = row.value === '' || row.value == null ? null : Number(row.value);
    explainOpen.value = true;
};
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
            <FpotDiagnosis :diagnosis="diagnosis" :transformer-id="transformerId" :comments="comments" />
        </div>

        <div v-show="view === 'ensayos'">
            <DiagnosticGrid
                :numeric-cols="numericCols"
                :required-keys="['value', 'temperature']"
                :samples="fpots"
                :cell-style="cellStyle"
                :cell-tip="cellTip"
                :transformer-slug="transformerSlug"
                batch-route="business_management.transformers.fpot.batch"
                preview-route="business_management.transformers.fpot.preview"
                :can-edit="canEdit"
            :laboratories="laboratories"
                :seed-diag="seedDiag"
                export-name="fpot"
                comment-type="fpot"
                @explain="openExplain"
            >
                <template #title>{{ $t('fpot.title') }} <span class="dg__count">· {{ $tc('transformers.sample_count', (fpots || []).length) }}</span></template>
                <template #diag-head="{ sortBy, caret }">
                    <th class="dg-sort" @click="sortBy('_diag.state')">
                        <Tooltip :title="$t('diagnostics.state_help')">{{ $t('fpot.state') }}</Tooltip><i class="th-caret">{{ caret('_diag.state') }}</i>
                    </th>
                </template>
                <template #diag="{ row }">
                    <td>
                        <span v-if="row._diag && row._diag.condition" class="state">
                            <span class="dot" :style="{ background: colorHex(row._diag.color) }"></span>
                            {{ condLabel(row._diag.condition) }}
                        </span>
                        <span v-else class="muted">—</span>
                    </td>
                </template>
                <template #legend><SemaforoLegend /></template>
            </DiagnosticGrid>
        </div>

        <template v-if="view === 'tendencias'">
            <GasTrends
                :samples="fpots"
                :series="FPOT_SERIES"
                :limits="trendLimits"
                unit="%"
                :no-data="$t('fpot.trends_no_data')"
            >
                <template #hint>{{ $t('fpot.trends_hint') }}</template>
            </GasTrends>
        </template>

        <FpotExplainDrawer
            v-model:open="explainOpen"
            :transformer-slug="transformerSlug"
            :value="explainValue"
        />
    </div>
</template>

<style scoped>
.section__nav { margin-bottom: 18px; }
.section__bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
.section__bar .section__nav { margin-bottom: 0; }
.state { display: inline-flex; align-items: center; gap: 7px; }
.dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
.muted { color: var(--color-text-muted, #9aa0a6); }
.dg-sort { cursor: pointer; user-select: none; }
.dg-sort:hover { color: #0A6ED1; }
.th-caret { font-style: normal; font-size: 0.7rem; margin-left: 4px; color: #0A6ED1; }
</style>
