<script setup>
import { ref, computed } from 'vue';
import { Segmented, Tooltip, Button } from 'ant-design-vue';
import { TableOutlined } from '@ant-design/icons-vue';
import CromasTab from '@/Components/Transformers/CromasTab.vue';
import GasTrends from '@/Components/Transformers/GasTrends.vue';
import DuvalTab from '@/Components/Transformers/DuvalTab.vue';
import KeyGasTab from '@/Components/Transformers/KeyGasTab.vue';
import RatiosTab from '@/Components/Transformers/RatiosTab.vue';
import CromasLimitsModal from '@/Components/Transformers/CromasLimitsModal.vue';
import CromasDiagnosis from '@/Components/Transformers/CromasDiagnosis.vue';
import IeeeDgaStatus from '@/Components/Transformers/IeeeDgaStatus.vue';
import TestStatusChip from '@/Components/Transformers/TestStatusChip.vue';
import { useI18n } from '@/Plugins/i18n';

/**
 * CromatografiaSection — módulo de cromatografía (DGA) del transformador.
 * Agrupa las tres vistas de los gases disueltos: Ensayos (CRUD), Tendencias y
 * Duval (triángulo + pentágono). Duval y Tendencias son interpretaciones de los
 * mismos gases, por eso viven dentro de cromatografía, no como pestañas hermanas.
 */
const props = defineProps({
    transformerSlug: { type: String, required: true },
    cromas:          { type: Array,  default: () => [] },
    cromasLimits:    { type: Object, default: () => ({}) },
    cromasLimitsIeee:{ type: Object, default: () => ({}) },
    cromasNorms:     { type: Object, default: () => ({ gases: {}, oil: null, trafo: null }) },
    duvalGeometry:   { type: Object, default: () => ({ triangles: {}, pentagons: {} }) },
    cellAlertSev:    { type: Number, default: 0.6 },
    transformerId:   { type: [Number, String], default: null },
    diagnosis:       { type: Object, default: () => ({}) },
    dgaStatus:       { type: Object, default: null },
    comments:        { type: Array,  default: () => [] },
    canEdit:         { type: Boolean, default: false },
    laboratories:    { type: Array, default: () => [] },
});

const { t } = useI18n();
const view = ref('resumen');

// Norma de los límites (colorea la tabla y las franjas de tendencias). IEC usa
// valores típicos por aceite+trafo; IEEE C57.104-2019 usa Tabla 1 (percentil 90,
// verde/ámbar) y Tabla 2 (percentil 95, ámbar/rojo), por O2/N2 + edad.
const normMode = ref('iec');
const normOptions = [
    { label: 'IEC 60599', value: 'iec' },
    { label: 'IEEE C57.104-2019', value: 'ieee' },
];
const activeLimits = computed(() => (normMode.value === 'ieee' ? props.cromasLimitsIeee : props.cromasLimits));

// Botón "Límites" → popup comparativo IEEE/IEC. Toma la última muestra para
// colorear cada gas medido contra su límite.
const limitsOpen = ref(false);
const latestSample = computed(() => {
    if (!props.cromas.length) return null;
    return [...props.cromas].sort((a, b) => String(b.sample_date).localeCompare(String(a.sample_date)))[0];
});
const options = [
    { value: 'resumen', payload: { label: t('diagnostics.summary_tab'), help: t('diagnostics.summary_help') } },
    { value: 'ensayos', payload: { label: t('cromas.samples_tab'), help: t('diagnostics.samples_help') } },
    { value: 'tendencias', payload: { label: t('cromas.trends_tab'), help: t('diagnostics.trends_help') } },
    { label: t('cromas.duval_tab'),   value: 'duval' },
    { label: t('cromas.keygas_tab'),  value: 'gasclave' },
    { label: t('cromas.ratios_tab'),  value: 'ratios' },
];

// Series del gráfico de tendencias (los 9 gases, en ppm). Cada cuadro se titula
// con el NOMBRE del gas + su símbolo (misma jerarquía que la tabla de ensayos);
// el cuadro combinado usa solo el símbolo, que es lo que entra en la leyenda.
const GAS_SYM = {
    h2: 'H₂', o2: 'O₂', n2: 'N₂', ch4: 'CH₄', co: 'CO', co2: 'CO₂',
    c2h4: 'C₂H₄', c2h6: 'C₂H₆', c2h2: 'C₂H₂',
};
const GAS_COLOR = {
    h2: '#C8281D', o2: '#D81B60', n2: '#5D4037', ch4: '#E9A23B', co: '#7F8C8D',
    co2: '#16A34A', c2h4: '#0A6ED1', c2h6: '#2AA198', c2h2: '#8E44AD',
};
const GAS_SERIES = Object.keys(GAS_SYM).map((g) => ({
    key: g,
    label: t('cromas.' + g + '_short'),
    sym: GAS_SYM[g],
    color: GAS_COLOR[g],
}));

</script>

<template>
    <div class="section">
        <div class="section__bar">
            <Segmented v-model:value="view" :options="options" class="section__nav">
                <template #label="{ payload }">
                    <Tooltip :title="payload.help">{{ payload.label }}</Tooltip>
                </template>
            </Segmented>
            <div class="section__right">
                <TestStatusChip :condition="diagnosis?.condition" :color="diagnosis?.color" />
                <template v-if="view !== 'resumen'">
                    <Tooltip v-if="view === 'ensayos' || view === 'tendencias'" :title="$t('cromas.norm_help')">
                        <Segmented v-model:value="normMode" :options="normOptions" size="small" />
                    </Tooltip>
                    <Tooltip :title="$t('cromas.limits_help')">
                        <Button size="small" @click="limitsOpen = true">
                            <template #icon><TableOutlined /></template>{{ $t('cromas.limits_btn') }}
                        </Button>
                    </Tooltip>
                </template>
            </div>
        </div>

        <!-- Resumen: diagnóstico + conclusiones + IEEE (interpretación) -->
        <div v-show="view === 'resumen'">
            <CromasDiagnosis :diagnosis="diagnosis" :transformer-id="transformerId" :comments="comments" :dga-status="dgaStatus" />
            <div v-if="dgaStatus" class="section__ieee">
                <IeeeDgaStatus :dga="dgaStatus" :transformer-slug="transformerSlug" />
            </div>
        </div>

        <CromasLimitsModal v-model:open="limitsOpen" :norms="cromasNorms" :latest="latestSample" />

        <CromasTab
            v-show="view === 'ensayos'"
            :transformer-slug="transformerSlug"
            :cromas="cromas"
            :limits="activeLimits"
            :cell-alert-sev="cellAlertSev"
            :can-edit="canEdit"
            :laboratories="laboratories"
        />
        <transition name="vrise">
            <GasTrends
                v-if="view === 'tendencias'"
                :samples="cromas"
                :series="GAS_SERIES"
                :limits="activeLimits"
                unit="ppm"
                show-combined
                :combined-hidden="['o2', 'n2']"
                :no-data="$t('cromas.trends_no_data')"
            >
                <template #hint>{{ $t('cromas.trends_hint') }}</template>
            </GasTrends>
        </transition>
        <transition name="vrise">
            <DuvalTab v-if="view === 'duval'" :cromas="cromas" :geometry="duvalGeometry" />
        </transition>
        <transition name="vrise">
            <KeyGasTab v-if="view === 'gasclave'" :cromas="cromas" />
        </transition>
        <transition name="vrise">
            <RatiosTab v-if="view === 'ratios'" :cromas="cromas" />
        </transition>
    </div>
</template>

<style scoped>
.section__ieee { border: 1px solid var(--color-border, #e5e7eb); border-radius: 10px; padding: 14px 16px; margin: 14px 0 4px; background: var(--color-surface-alt, #fafbfc); }
.section__bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
.section__right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.section__nav { margin-bottom: 0; }
.cromas-capture { position: absolute; left: -10000px; top: 0; width: 720px; height: 340px; overflow: hidden; }
</style>
