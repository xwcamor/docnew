<script setup>
/**
 * ComparisonMatrix — matriz diagnóstica comparativa "por grupo".
 *
 * Una fila por transformador; columnas con el veredicto de cada método:
 * Índice de Salud, Duval (tipo de falla), IEEE C57.104, Rogers, Doernenburg y
 * generación de gas. Pensada para comparar muchas unidades de un vistazo (lo que
 * los gráficos de líneas no resuelven bien). Los datos vienen cacheados/del motor.
 */
import { computed } from 'vue';
import { Empty } from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';

const props = defineProps({
    rows: { type: Array, default: () => [] },
});
const { t } = useI18n();

// Outlier de triage: el/los trafo(s) con la PEOR condición del grupo, solo si el
// grupo NO es uniforme (hay al menos uno mejor). Es el que "se despega".
const ratings = computed(() => props.rows.map((r) => r.hi_rating).filter((v) => v != null));
const worst = computed(() => (ratings.value.length ? Math.min(...ratings.value) : null));
const best = computed(() => (ratings.value.length ? Math.max(...ratings.value) : null));
const isOutlier = (r) => r.hi_rating != null && r.hi_rating === worst.value && worst.value < best.value;

const RATING_KEY = { 4: 'muy_bueno', 3: 'bueno', 2: 'medio', 1: 'malo', 0: 'muy_malo' };
const RATING_COLOR = { 4: '#1D7044', 3: '#5AA82E', 2: '#E9A23B', 1: '#E2661E', 0: '#C8281D' };
// DGA Status IEEE C57.104-2019: 1=normal(verde) · 2(ámbar) · 3=peor(rojo).
const IEEE_COLOR = { 1: '#1D7044', 2: '#E9A23B', 3: '#C8281D' };
const DUVAL_COLOR = {
    normal: '#1D7044', thermal: '#C8281D', discharge: '#C8281D',
    pd: '#E9A23B', mixed: '#C8281D', other: '#9aa0a6',
};

const hiLabel = (r) => (r.hi_rating == null ? '—' : t('diagnostics.cond_' + RATING_KEY[r.hi_rating]));
const hiColor = (r) => (r.hi_rating == null ? '#9aa0a6' : RATING_COLOR[r.hi_rating]);

const duvalLabel = (r) => (r.duval ? t('comparison.duval_' + r.duval) : '—');
const duvalColor = (r) => (r.duval ? (DUVAL_COLOR[r.duval] ?? '#9aa0a6') : '#9aa0a6');

const ieeeColor = (r) => (r.ieee == null ? '#9aa0a6' : (IEEE_COLOR[r.ieee] ?? '#9aa0a6'));

const ratioLabel = (code) => (code ? t('cromas.ratios.fault.' + code) : t('comparison.no_fault'));
const ratioColor = (code) => (code ? '#E2661E' : '#1D7044');

const num = (v) => (v == null ? '—' : (Math.round(v * 100) / 100));
</script>

<template>
    <div class="cmx">
        <div v-if="!rows.length" class="cmx__empty"><Empty :description="t('comparison.no_data')" /></div>

        <div v-else class="cmx__wrap">
            <table class="cmx__table">
                <thead>
                    <tr>
                        <th class="cmx__th--left">{{ t('comparison.transformer') }}</th>
                        <th>{{ t('comparison.col_hi') }}</th>
                        <th>{{ t('comparison.col_duval') }}</th>
                        <th>{{ t('comparison.col_ieee') }}</th>
                        <th>{{ t('comparison.col_rogers') }}</th>
                        <th>{{ t('comparison.col_doernenburg') }}</th>
                        <th>{{ t('comparison.col_gassing') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id" :class="{ 'cmx__row--outlier': isOutlier(r) }">
                        <td class="cmx__th--left cmx__name">
                            {{ r.label }}
                            <span v-if="isOutlier(r)" class="cmx__flag">{{ t('comparison.outlier') }}</span>
                        </td>
                        <td>
                            <span class="cmx__pill" :style="{ background: hiColor(r) }">{{ hiLabel(r) }}</span>
                            <span v-if="r.hi_index != null" class="cmx__sub">{{ Math.round(r.hi_index) }}%</span>
                        </td>
                        <td><span class="cmx__pill" :style="{ background: duvalColor(r) }">{{ duvalLabel(r) }}</span></td>
                        <td>
                            <span v-if="r.ieee != null" class="cmx__pill" :style="{ background: ieeeColor(r) }">{{ t('comparison.ieee_cond', { n: r.ieee }) }}</span>
                            <span v-else>—</span>
                        </td>
                        <td><span class="cmx__pill cmx__pill--soft" :style="{ color: ratioColor(r.rogers) }">{{ ratioLabel(r.rogers) }}</span></td>
                        <td><span class="cmx__pill cmx__pill--soft" :style="{ color: ratioColor(r.doernenburg) }">{{ ratioLabel(r.doernenburg) }}</span></td>
                        <td class="cmx__numcell">{{ num(r.gassing) }} <i v-if="r.gassing != null">ppm/mes</i></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="cmx__note">{{ t('comparison.matrix_note') }}</p>
    </div>
</template>

<style scoped>
.cmx__empty { padding: 32px 0; }
.cmx__wrap { overflow-x: auto; }
.cmx__table { width: 100%; border-collapse: collapse; font-size: 0.86rem; }
.cmx__table th { text-align: center; padding: 8px 10px; border-bottom: 2px solid var(--color-border, #e5e7eb); color: var(--color-text-muted, #6A6D70); font-weight: 600; white-space: nowrap; background: var(--color-surface-alt, #f6f7f9); }
.cmx__table td { text-align: center; padding: 9px 10px; border-bottom: 1px solid var(--color-border, #eef0f2); }
.cmx__th--left { text-align: left !important; }
.cmx__name { font-weight: 600; color: var(--color-text, #32363a); white-space: nowrap; }
.cmx__row--outlier td { background: #C8281D0c; }
.cmx__row--outlier td.cmx__name { box-shadow: inset 3px 0 0 #C8281D; }
.cmx__flag { display: inline-block; margin-left: 8px; padding: 1px 7px; border-radius: 999px; background: #C8281D; color: #fff; font-size: 0.68rem; font-weight: 700; vertical-align: middle; }
.cmx__pill { display: inline-block; padding: 2px 10px; border-radius: 999px; color: #fff; font-weight: 600; font-size: 0.78rem; white-space: nowrap; }
.cmx__pill--soft { background: transparent; color: inherit; font-weight: 600; padding: 2px 0; }
.cmx__sub { display: block; font-size: 0.72rem; color: var(--color-text-muted, #9aa0a6); margin-top: 2px; }
.cmx__numcell i { font-style: normal; color: var(--color-text-muted, #9aa0a6); font-size: 0.74rem; }
.cmx__note { margin: 12px 0 0; font-size: 0.78rem; color: var(--color-text-muted, #6A6D70); }
</style>
