<script setup>
import { computed, ref } from 'vue';
import { Tooltip } from 'ant-design-vue';
import DiagnosticGrid from '@/Components/Transformers/DiagnosticGrid.vue';
import SemaforoLegend from '@/Components/Transformers/SemaforoLegend.vue';
import CromasExplainDrawer from '@/Components/Transformers/CromasExplainDrawer.vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { semaforoHex } from '@/utils/severity';
import { cellAlertBg, bandOf } from '@/utils/severity';

/**
 * CromasTab — pestaña "Análisis Cromatográfico": grilla editable estilo Excel
 * (9 gases en ppm). Cada celda se tinta según el límite (banda por aceite+trafo)
 * en que cae. Columnas de diagnóstico: semáforo (DGAF) + condición IEEE C57.104.
 * El ⓘ abre la traza del cálculo. La mecánica vive en DiagnosticGrid.
 */
const props = defineProps({
    transformerSlug: { type: String, required: true },
    cromas:          { type: Array,  default: () => [] },
    limits:          { type: Object, default: () => ({}) },
    // Umbral de severidad (0..1) desde el cual se tinta la celda. Viene de datos
    // (Setting global). Solo se pinta el gas que cae en una banda con sev >= umbral.
    cellAlertSev:    { type: Number, default: 0.6 },
    canEdit:         { type: Boolean, default: false },
    laboratories:    { type: Array, default: () => [] },
});

const { t } = useI18n();
const { condLabel } = useConditions();

const GASES = ['h2', 'o2', 'n2', 'ch4', 'co', 'co2', 'c2h4', 'c2h6', 'c2h2'];
const GAS_LABEL = {
    h2: 'H₂', o2: 'O₂', n2: 'N₂', ch4: 'CH₄', co: 'CO', co2: 'CO₂',
    c2h4: 'C₂H₄', c2h6: 'C₂H₆', c2h2: 'C₂H₂',
};
// Cabecera de 3 líneas: NOMBRE (corto) · SÍMBOLO · UNIDAD. La norma de medición
// (D3612) pasó al tooltip junto con el nombre completo — en la cabecera ocupaba
// una línea sin aportar al que lee la tabla.
const numericCols = computed(() => GASES.map((g) => ({
    key: g,
    label: t('cromas.' + g + '_short'),
    sym: GAS_LABEL[g],
    sub: t('cromas.gas_unit'),
    tip: `${t('cromas.' + g)} · ${t('cromas.gas_norm')}`,
})));

const colorHex = semaforoHex;

const hasLimits = computed(() => Object.keys(props.limits).length > 0);
const cellStyle = (key, v) => {
    if (v === '' || v == null) return null;
    const band = bandOf(props.limits[key], Number(v));
    // Verde (cumple) = limpio · ámbar = cerca del límite · rojo = pasó (siempre
    // visible). cellAlertSev solo filtra el amarillo leve, nunca el rojo.
    return band ? cellAlertBg(band.sev, props.cellAlertSev) : null;
};
const cellTip = (key, v) => {
    if (v === '' || v == null) return null;
    const band = bandOf(props.limits[key], Number(v));
    if (!band) return null;
    const to = band.to === null || band.to === undefined ? '∞' : band.to;
    return `${GAS_LABEL[key]} ${v} ppm · límite ${band.from}–${to} ppm`;
};

const seedDiag = (row) => ({ score: row.score, condition: row.condition, color: row.color });

const explainOpen = ref(false);
const explainRow = ref(null);
const openExplain = (row) => {
    explainRow.value = {
        sample_date: row.sample_date || null,
        ...Object.fromEntries(GASES.map((g) => [g, row[g] === '' || row[g] == null ? null : Number(row[g])])),
    };
    explainOpen.value = true;
};
</script>

<template>
    <div class="cromas">
        <DiagnosticGrid
            :numeric-cols="numericCols"
            :required-keys="GASES"
            :samples="cromas"
            :transformer-slug="transformerSlug"
            batch-route="business_management.transformers.cromas.batch"
            preview-route="business_management.transformers.cromas.preview"
            :can-edit="canEdit"
            :laboratories="laboratories"
            :seed-diag="seedDiag"
            export-name="cromas"
            comment-type="chromatographical"
            :cell-style="cellStyle"
            :cell-tip="cellTip"
            @explain="openExplain"
        >
            <template #title>
                {{ $t('cromas.gases') }}
                <span class="dg__count">· {{ $tc('transformers.sample_count', (cromas || []).length) }}</span>
                <span v-if="hasLimits" class="cromas__norm">· {{ $t('cromas.limits_norm') }}</span>
            </template>
            <template #diag-head="{ sortBy, caret }">
                <th class="dg-sort" @click="sortBy('_diag.state')">
                    <Tooltip :title="$t('diagnostics.state_help')">{{ $t('cromas.state') }}</Tooltip><i class="th-caret">{{ caret('_diag.state') }}</i>
                </th>
            </template>
            <template #diag="{ row }">
                <td>
                    <span v-if="row._diag && row._diag.condition" class="state">
                        <span class="dot" :style="{ background: colorHex(row._diag.color) }"></span>
                        {{ condLabel(row._diag.condition) }}
                    </span>
                    <Tooltip v-else-if="!hasLimits" :title="$t('cromas.diag.no_rules_hint')">
                        <span class="muted">—</span>
                    </Tooltip>
                    <span v-else class="muted">—</span>
                </td>
            </template>
            <template #legend>
                <ul v-if="cromas.length || canEdit" class="cromas__legend">
                    <li v-for="g in GASES" :key="'lg' + g">
                        <strong>{{ GAS_LABEL[g] }}</strong> · {{ $t('cromas.' + g).replace(/\s*\(.*\)$/, '') }}
                    </li>
                </ul>
                <SemaforoLegend />
            </template>
        </DiagnosticGrid>

        <CromasExplainDrawer
            v-model:open="explainOpen"
            :transformer-slug="transformerSlug"
            :row="explainRow"
        />
    </div>
</template>

<style scoped>
.cromas__norm { text-transform: none; letter-spacing: 0; font-weight: 600; color: #0A6ED1; }
.cromas__legend { list-style: none; margin: 12px 0 0; padding: 10px 0 0; border-top: 1px solid var(--color-border, #eef0f2); display: flex; flex-wrap: wrap; gap: 6px 18px; }
.cromas__legend li { font-size: 0.78rem; color: var(--color-text-muted, #6A6D70); }
.cromas__legend strong { color: var(--color-text, #32363a); }
.state { display: inline-flex; align-items: center; gap: 7px; }
.dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
.muted { color: var(--color-text-muted, #9aa0a6); }
.dg-sort { cursor: pointer; user-select: none; }
.dg-sort:hover { color: #0A6ED1; }
.th-caret { font-style: normal; font-size: 0.7rem; margin-left: 4px; color: #0A6ED1; }
</style>
