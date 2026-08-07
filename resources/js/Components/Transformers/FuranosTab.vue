<script setup>
import { computed, ref } from 'vue';
import { Tooltip } from 'ant-design-vue';
import DiagnosticGrid from '@/Components/Transformers/DiagnosticGrid.vue';
import SemaforoLegend from '@/Components/Transformers/SemaforoLegend.vue';
import FuranosExplainDrawer from '@/Components/Transformers/FuranosExplainDrawer.vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { semaforoHex, cellAlertBg, bandOf } from '@/utils/severity';

/**
 * FuranosTab — pestaña "Furanos": grilla editable estilo Excel (compuestos
 * furánicos en ppb). Columnas de diagnóstico: DP (Chendong) + semáforo (por 2-FAL).
 * El ⓘ abre la traza del cálculo. La mecánica de la grilla vive en DiagnosticGrid.
 */
const props = defineProps({
    transformerSlug: { type: String, required: true },
    furanos:         { type: Array,  default: () => [] },
    // Bandas de límite por compuesto ({ fal: [{from,to,sev}] }). Solo el 2FAL
    // diagnostica, así que es el único con banda; el resto son informativos.
    limits:          { type: Object, default: () => ({}) },
    // Umbral de severidad (0..1) desde el cual se tinta la celda del valor.
    // Viene de datos (Setting global). Bajarlo hace que se pinten más bandas
    // (incluido el verde de "bueno"); subirlo deja solo lo grave.
    cellAlertSev:    { type: Number, default: 0.6 },
    canEdit:         { type: Boolean, default: false },
    laboratories:    { type: Array, default: () => [] },
});

const { t } = useI18n();
const { condLabel } = useConditions();

const COMPOUNDS = ['fal', 'hme', 'ace', 'mfu', 'fua'];
const LABEL = { fal: '2FAL', hme: '5HMF', ace: '2ACF', mfu: '5MEF', fua: '2FOL' };
const numericCols = computed(() => COMPOUNDS.map((c) => ({ key: c, label: LABEL[c], tip: t('furanos.' + c) })));

const colorHex = semaforoHex;
const seedDiag = (row) => ({ dp: row.dp, condition: row.condition, color: row.color });

// Tinte de la celda del valor según la banda donde cae (solo 2FAL tiene banda).
// Solo se pinta si la severidad alcanza el umbral: por debajo, la celda queda
// limpia porque el valor está en rango aceptable.
const cellStyle = (key, v) => {
    if (v === '' || v == null) return null;
    const band = bandOf(props.limits[key], Number(v));
    // Verde (cumple) = limpio · ámbar = cerca del límite · rojo = pasó (siempre
    // visible). cellAlertSev solo filtra el amarillo leve, nunca el rojo.
    return band ? cellAlertBg(band.sev, props.cellAlertSev) : null;
};

const explainOpen = ref(false);
const explainFal = ref(null);
const openExplain = (row) => {
    explainFal.value = row.fal === '' || row.fal == null ? null : Number(row.fal);
    explainOpen.value = true;
};
</script>

<template>
    <div class="furanos">
        <DiagnosticGrid
            :numeric-cols="numericCols"
            :required-keys="['fal']"
            :samples="furanos"
            :transformer-slug="transformerSlug"
            batch-route="business_management.transformers.furanos.batch"
            preview-route="business_management.transformers.furanos.preview"
            :can-edit="canEdit"
            :laboratories="laboratories"
            :seed-diag="seedDiag"
            :cell-style="cellStyle"
            export-name="furanos"
            comment-type="furano"
            @explain="openExplain"
        >
            <template #title>{{ $t('furanos.compounds') }} <span class="dg__count">· {{ $tc('transformers.sample_count', (furanos || []).length) }}</span></template>
            <template #diag-head="{ sortBy, caret }">
                <th class="dg-sort" @click="sortBy('_diag.dp')">
                    <Tooltip :title="$t('furanos.dp_full')">{{ $t('furanos.dp') }}</Tooltip><i class="th-caret">{{ caret('_diag.dp') }}</i>
                </th>
                <th class="dg-sort" @click="sortBy('_diag.state')">
                    <Tooltip :title="$t('diagnostics.state_help')">{{ $t('furanos.state') }}</Tooltip><i class="th-caret">{{ caret('_diag.state') }}</i>
                </th>
            </template>
            <template #diag="{ row }">
                <td><strong>{{ row._diag && row._diag.dp != null ? row._diag.dp : '—' }}</strong></td>
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

        <FuranosExplainDrawer
            v-model:open="explainOpen"
            :transformer-slug="transformerSlug"
            :fal="explainFal"
        />
    </div>
</template>

<style scoped>
.state { display: inline-flex; align-items: center; gap: 7px; }
.dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
.muted { color: var(--color-text-muted, #9aa0a6); }
.dg-sort { cursor: pointer; user-select: none; }
.dg-sort:hover { color: #0A6ED1; }
.th-caret { font-style: normal; font-size: 0.7rem; margin-left: 4px; color: #0A6ED1; }
</style>
