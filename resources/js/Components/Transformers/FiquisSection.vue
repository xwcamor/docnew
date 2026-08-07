<script setup>
import { computed, ref } from 'vue';
import { Segmented, Tooltip, Button } from 'ant-design-vue';
import FiquisTab from '@/Components/Transformers/FiquisTab.vue';
import FiquisDiagnosis from '@/Components/Transformers/FiquisDiagnosis.vue';
import TestStatusChip from '@/Components/Transformers/TestStatusChip.vue';
import GasTrends from '@/Components/Transformers/GasTrends.vue';
import MultiAxisTrend from '@/Components/Transformers/MultiAxisTrend.vue';
import { useI18n } from '@/Plugins/i18n';
import { fiquiHead } from '@/utils/fiquiHeader';

/**
 * FiquisSection — módulo fisicoquímico del transformador.
 * Sub-vistas: Ensayos (CRUD) y Tendencias (evolución de los parámetros).
 * Mismo patrón que cromatografía/furanos.
 */
const props = defineProps({
    transformerSlug: { type: String, required: true },
    transformerId:   { type: [Number, String], default: null },
    fiquis:          { type: Array,  default: () => [] },
    fiquisLimits:    { type: Object, default: () => ({}) },
    fiquisColumns:   { type: Array,  default: () => [] },
    cellAlertSev:    { type: Number, default: 0.6 },
    diagnosis:       { type: Object, default: () => ({}) },
    comments:        { type: Array,  default: () => [] },
    canEdit:         { type: Boolean, default: false },
    laboratories:    { type: Array, default: () => [] },
});

const { t } = useI18n();
const view = ref('resumen');
const options = [
    { value: 'resumen', payload: { label: t('diagnostics.summary_tab'), help: t('diagnostics.summary_help') } },
    { value: 'ensayos', payload: { label: t('fiquis.samples_tab'), help: t('diagnostics.samples_help') } },
    { value: 'tendencias', payload: { label: t('fiquis.trends_tab'), help: t('diagnostics.trends_help') } },
];

// Color por parámetro. Los métodos alternos de una misma propiedad (rigidez
// D877, factor de potencia a 100 °C) llevan un tono de la misma familia.
const COLOR = {
    rig: '#0A6ED1', rig877: '#41B0D8',   // rigidez dieléctrica (kV)
    ten: '#2AA198',                       // tensión interfacial
    acid: '#C8281D',                      // acidez
    wat: '#E9A23B',                       // contenido de agua
    pot: '#8E44AD', pot100: '#C39BD3',    // factor de potencia (%)
};

/**
 * Series de tendencias derivadas de las COLUMNAS que resolvió el servidor para
 * este aceite + clase de tensión (`fiquisColumns`), no de una lista fija.
 *
 * Antes eran 5 parámetros clavados en el código: los que tienen método alterno
 * (rigidez D877, factor de potencia a 100 °C) salían en la tabla de ensayos
 * pero NO tenían tendencia. Al derivarlas de los datos, cualquier parámetro que
 * se agregue a las reglas aparece solo — y un aceite que mida un juego distinto
 * de parámetros muestra los suyos, no los de mineral.
 */
const SERIES = computed(() => {
    const cols = props.fiquisColumns ?? [];
    // Dos parámetros pueden compartir NOMBRE (las dos rigideces, los dos
    // factores de potencia). Solo en ese caso el título lleva la norma ASTM,
    // para distinguirlos sin ensuciar los títulos que ya son únicos.
    const veces = {};
    cols.forEach((c) => { const n = t('fiquis.' + c.key); veces[n] = (veces[n] ?? 0) + 1; });

    return cols.map((c) => {
        const nombre = t('fiquis.' + c.key);
        // Cuando el nombre se repite, lo que distingue NO es la norma sola: los
        // dos factores de potencia comparten D924 y solo cambia la temperatura.
        // Por eso el distintivo es la línea de unidad completa ("25 °C. %",
        // "kV/2.0 mm"), que ya trae la condición. Como esa línea incluye la
        // unidad, el título no la repite aparte (hideUnit).
        const repetido = veces[nombre] > 1;
        const distintivo = fiquiHead(t, c);
        return {
            key: c.key,
            label: repetido && distintivo ? `${nombre} — ${distintivo}` : nombre,
            hideUnit: repetido && !!distintivo,
            color: COLOR[c.key] ?? '#6A6D70',
            unit: c.unit,
        };
    });
});

// Tendencias agrupadas multi-eje (2 cuadros, como los pidió el usuario):
// rigidez + agua + factor de potencia (con bandas IEEE), y tensión + acidez.
// Son una VISTA GENERAL curada: el detalle por parámetro (abajo) los trae todos.
const GROUP1 = computed(() => SERIES.value.filter((s) => ['rig', 'wat', 'pot'].includes(s.key)));
const GROUP2 = computed(() => SERIES.value.filter((s) => ['ten', 'acid'].includes(s.key)));

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
            <FiquisDiagnosis :diagnosis="diagnosis" :transformer-id="transformerId" :comments="comments" />
        </div>

        <FiquisTab
            v-show="view === 'ensayos'"
            :transformer-slug="transformerSlug"
            :fiquis="fiquis"
            :limits="fiquisLimits"
            :columns="fiquisColumns"
            :cell-alert-sev="cellAlertSev"
            :can-edit="canEdit"
            :laboratories="laboratories"
        />
        <transition name="vrise">
            <div v-if="view === 'tendencias'">
                <!-- Tendencias agrupadas multi-eje (overview) -->
                <MultiAxisTrend :samples="fiquis" :series="GROUP1" :limits="fiquisLimits" />
                <MultiAxisTrend :samples="fiquis" :series="GROUP2" :limits="fiquisLimits" />

                <!-- Detalle por parámetro (lo que ya existía) -->
                <GasTrends
                    :samples="fiquis"
                    :series="SERIES"
                    :limits="fiquisLimits"
                    :no-data="$t('fiquis.trends_no_data')"
                >
                    <template #hint>{{ $t('fiquis.trends_hint') }}</template>
                </GasTrends>
            </div>
        </transition>
    </div>
</template>

<style scoped>
.section__bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
.section__nav { margin-bottom: 0; }
.fiquis-capture { position: absolute; left: -10000px; top: 0; width: 560px; overflow: hidden; }
</style>
