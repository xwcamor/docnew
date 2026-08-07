<script setup>
import { computed } from 'vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import TestDiagnosis from '@/Components/Transformers/TestDiagnosis.vue';

/**
 * FuranosDiagnosis — Diagnóstico + Conclusiones de furanos (última muestra).
 * Compone las frases (con su fuente, vía claves i18n) y reusa el shell genérico
 * TestDiagnosis, para que el patrón sea idéntico al de las demás pruebas.
 */
const props = defineProps({
    diagnosis:    { type: Object, default: () => ({}) },
    trend:        { type: Object, default: null },
    coRatio:      { type: Object, default: null },
    mechanism:    { type: Object, default: null },
    oilTreatedAt: { type: String, default: null },
    paperType:    { type: String, default: null },
    transformerId:{ type: [Number, String], default: null },
    comments:     { type: Array, default: () => [] },
});

const { t } = useI18n();
const { condLabel } = useConditions();

const COMPOUND_ABBR = { hme: '5HMF', ace: '2ACF', mfu: '5MEF', fua: '2FOL' };

const d = computed(() => props.diagnosis || {});
const hasData = computed(() => !!d.value.has_data && d.value.condition != null);

const diagLines = computed(() => {
    if (!hasData.value) return [];
    const v = d.value;
    const lines = [t('furanos.diag.value', { fal: v.fal, ppm: v.ppm })];
    if (v.safe_to != null) {
        lines.push(v.exceeds
            ? t('furanos.diag.exceeds', { safe: Math.round(v.safe_to) })
            : t('furanos.diag.within', { safe: Math.round(v.safe_to) }));
    }
    if (v.dp != null) lines.push(t('furanos.diag.dp', { dp: v.dp, life: v.life_percent ?? 0 }));
    lines.push(t('furanos.diag.state', { condition: condLabel(v.condition) }));
    if (props.trend && props.trend.rate != null) lines.push(t('furanos.diag.rate', { rate: props.trend.rate }));
    if (props.coRatio && props.coRatio.level !== 'normal') lines.push(t('furanos.co_ratio_' + props.coRatio.level));
    if (props.mechanism) {
        lines.push(`${COMPOUND_ABBR[props.mechanism.compound] ?? ''}: ${t('furanos.mech_' + props.mechanism.mechanism)}. ${t('furanos.mech_caveat')}`);
    }
    return lines;
});

const caveats = computed(() => {
    const c = [];
    if (props.paperType === 'upgraded') c.push(t('furanos.explain.paper_warning_body'));
    if (props.oilTreatedAt) c.push(t('furanos.oil_warning_body', { date: props.oilTreatedAt }));
    return c;
});

// Escala de acción común (0 rutina · 1 seguimiento · 2 investigar · 3 crítico),
// igual que las otras pruebas. La nota de tendencia en aumento se mantiene aparte.
const RECO_BY_LEVEL = ['reco_routine', 'reco_monitor', 'reco_increase', 'reco_critical'];
const conclLines = computed(() => {
    if (!hasData.value) return [];
    const lvl = d.value.action_level ?? 0;
    const c = [t('furanos.' + RECO_BY_LEVEL[lvl])];
    if (props.trend && props.trend.rising) c.push(t('furanos.reco_rising'));
    return c;
});
</script>

<template>
    <TestDiagnosis
        :color="d.color"
        :headline="hasData ? condLabel(d.condition) : ''"
        :diag-lines="diagLines"
        :concl-lines="conclLines"
        :urgency-level="hasData ? (d.action_level ?? 0) : null"
        :caveats="caveats"
        :source="$t('furanos.reco_source')"
        :foot="$t('furanos.diag.foot')"
        :no-data="$t('furanos.diag.no_data')"
        :transformer-id="transformerId"
        context="diag_furanos"
        :comments="comments"
        :notes-title="$t('comments.notes_title')"
        :notes-hint="$t('comments.notes_hint')"
    />
</template>
