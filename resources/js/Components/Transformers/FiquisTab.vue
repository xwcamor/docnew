<script setup>
import { computed, ref } from 'vue';
import { Tooltip } from 'ant-design-vue';
import DiagnosticGrid from '@/Components/Transformers/DiagnosticGrid.vue';
import SemaforoLegend from '@/Components/Transformers/SemaforoLegend.vue';
import FiquisExplainDrawer from '@/Components/Transformers/FiquisExplainDrawer.vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { semaforoHex } from '@/utils/severity';
import { cellAlertBg, bandOf } from '@/utils/severity';
import { fiquiAstm, fiquiHead, fiquiFullName } from '@/utils/fiquiHeader';

/**
 * FiquisTab — pestaña "Fisicoquímico": grilla editable estilo Excel. Solo se
 * muestran los parámetros que aplican al aceite del transformador (las claves de
 * los límites). Cada celda se tinta según la banda en que cae. Columna de
 * diagnóstico: semáforo. El ⓘ abre la traza del cálculo.
 */
const props = defineProps({
    transformerSlug: { type: String, required: true },
    fiquis:          { type: Array,  default: () => [] },
    limits:          { type: Object, default: () => ({}) },
    // Columnas desde datos: [{ key, astm, unit, mode }] en orden de presentación.
    columns:         { type: Array,  default: () => [] },
    // Umbral de severidad (0..1) desde el cual se tinta la celda (Setting global,
    // mismo que cromas/furanos). Por debajo no se pinta (no se ven los verdes).
    cellAlertSev:    { type: Number, default: 0.6 },
    canEdit:         { type: Boolean, default: false },
    laboratories:    { type: Array, default: () => [] },
});

const { t } = useI18n();
const { condLabel } = useConditions();

const colorHex = semaforoHex;

const hasLimits = computed(() => Object.keys(props.limits).length > 0);
// Qué columnas se muestran lo decide el backend (según aceite + clase de
// tensión); sus TEXTOS salen de los archivos de idioma. Cabecera de 3 líneas:
//   1. NOMBRE    "Rigidez Dieléctrica"
//   2. NORMA     "ASTM D1816"
//   3. MEDIDA    "kV/2.0 mm"   ← con la condición de ensayo
const numericCols = computed(() => props.columns.map((c) => {
    const metodo = fiquiAstm(t, c);
    const medida = fiquiHead(t, c);
    return {
        key: c.key,
        label: t('fiquis.' + c.key),   // línea 1: nombre
        sub: metodo,                    // línea 2: norma
        sub2: medida,                   // línea 3: medida + condición de ensayo
        // Nombre con la condición pegada, SOLO para el .xlsx: en una hoja suelta
        // no hay tooltip ni columna vecina, y dos "Rigidez Dieléctrica" seguidas
        // no se distinguen.
        full: fiquiFullName(t, c.key),
        tip: [t('fiquis.' + c.key), metodo, medida].filter(Boolean).join(' — '),
    };
}));
const paramKeys = computed(() => props.columns.map((c) => c.key));

// Rigidez y factor de potencia son OPCIONALES por los dos métodos (D1816/D877 ·
// 25/100 °C). Con cualquiera de los dos alcanza —el motor sustituye—, pero
// tampoco se exige uno: hay ensayos donde el laboratorio no corrió ninguno, y
// obligar a llenar la celda es lo que llevó al sistema viejo a rellenarla con 0.
// Ausente no penaliza; el peso dinámico deja la propiedad fuera del promedio.
const OPCIONALES = ['rig', 'rig877', 'pot', 'pot100'];
const requiredKeys = computed(() => paramKeys.value.filter((k) => !OPCIONALES.includes(k)));

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
    const u = t('fiquis.' + key + '_unit');
    return `${t('fiquis.' + key)} ${v} ${u} · límite ${band.from}–${to} ${u}`;
};

const seedDiag = (row) => ({ score: row.score, condition: row.condition, color: row.color });

const explainOpen = ref(false);
const explainValues = ref(null);
const openExplain = (row) => {
    explainValues.value = Object.fromEntries(paramKeys.value.map((p) => [p, row[p] === '' || row[p] == null ? null : Number(row[p])]));
    explainOpen.value = true;
};
</script>

<template>
    <div class="fiquis">
        <DiagnosticGrid
            :numeric-cols="numericCols"
            :required-keys="requiredKeys"
            :samples="fiquis"
            :transformer-slug="transformerSlug"
            batch-route="business_management.transformers.fiquis.batch"
            preview-route="business_management.transformers.fiquis.preview"
            :can-edit="canEdit"
            :laboratories="laboratories"
            :seed-diag="seedDiag"
            export-name="fiquis"
            comment-type="fiqui"
            :cell-style="cellStyle"
            :cell-tip="cellTip"
            @explain="openExplain"
        >
            <template #title>
                {{ $t('fiquis.params') }}
                <span class="dg__count">· {{ $tc('transformers.sample_count', (fiquis || []).length) }}</span>
                <span v-if="hasLimits" class="fiquis__norm">· {{ $t('fiquis.limits_norm') }}</span>
            </template>
            <template #diag-head="{ sortBy, caret }">
                <th class="dg-sort" @click="sortBy('_diag.state')">
                    <Tooltip :title="$t('diagnostics.state_help')">{{ $t('fiquis.state') }}</Tooltip><i class="th-caret">{{ caret('_diag.state') }}</i>
                </th>
            </template>
            <template #diag="{ row }">
                <td>
                    <span v-if="row._diag && row._diag.condition" class="state">
                        <span class="dot" :style="{ background: colorHex(row._diag.color) }"></span>
                        {{ condLabel(row._diag.condition) }}
                    </span>
                    <Tooltip v-else-if="!hasLimits" :title="$t('fiquis.diag.no_table_hint')">
                        <span class="muted">—</span>
                    </Tooltip>
                    <span v-else class="muted">—</span>
                </td>
            </template>
            <template #legend><SemaforoLegend /></template>
        </DiagnosticGrid>

        <FiquisExplainDrawer
            v-model:open="explainOpen"
            :transformer-slug="transformerSlug"
            :values="explainValues"
        />
    </div>
</template>

<style scoped>
.fiquis__norm { font-weight: 600; color: #0A6ED1; text-transform: none; letter-spacing: 0; }
.state { display: inline-flex; align-items: center; gap: 7px; }
.dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
.muted { color: var(--color-text-muted, #9aa0a6); }
.dg-sort { cursor: pointer; user-select: none; }
.dg-sort:hover { color: #0A6ED1; }
.th-caret { font-style: normal; font-size: 0.7rem; margin-left: 4px; color: #0A6ED1; }
</style>
