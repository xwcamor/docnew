<script setup>
import { computed } from 'vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import TestDiagnosis from '@/Components/Transformers/TestDiagnosis.vue';

/**
 * FpotDiagnosis — Diagnóstico + Conclusiones de Factor de Potencia (tan δ).
 * El estado sale de la escala configurada; tan δ sube con humedad/envejecimiento
 * del aislamiento. Compone las frases y reusa el shell genérico.
 */
const props = defineProps({
    diagnosis:     { type: Object, default: () => ({}) },
    transformerId: { type: [Number, String], default: null },
    comments:      { type: Array, default: () => [] },
});

const { t } = useI18n();
const { condLabel } = useConditions();

const v = computed(() => props.diagnosis || {});
const hasData = computed(() => !!v.value.has_data && v.value.condition != null);

const diagLines = computed(() => {
    if (!hasData.value) return [];
    const d = v.value;
    const lines = [t('fpot.diag.value', { v: d.value, condition: condLabel(d.condition) })];
    if (d.exceeds && d.limit != null) lines.push(t('fpot.diag.over', { limit: d.limit }));
    else if (d.near && d.limit != null) lines.push(t('fpot.diag.near', { limit: d.limit }));
    return lines;
});

const conclLines = computed(() => {
    if (!hasData.value) return [];
    const d = v.value;
    // Escala de acción común (0 rutina · 1 seguimiento · 2+ investigar) del backend.
    const lvl = d.action_level ?? 0;
    const key = lvl >= 2 ? 'concl_investigate' : (lvl === 1 ? 'concl_watch' : 'concl_routine');
    const lines = [t('fpot.diag.' + key)];
    if (d.near && (d.rating ?? 4) >= 3 && d.limit != null) {
        lines.push(t('fpot.diag.concl_approaching', { limit: d.limit }));
    }
    return lines;
});
</script>

<template>
    <TestDiagnosis
        :color="v.color"
        :headline="hasData ? condLabel(v.condition) : ''"
        :diag-lines="diagLines"
        :concl-lines="conclLines"
        :urgency-level="hasData ? (v.action_level ?? 0) : null"
        :source="$t('fpot.diag.concl_source')"
        :foot="$t('fpot.diag.foot')"
        :no-data="$t('fpot.diag.no_data')"
        :transformer-id="transformerId"
        context="diag_fpot"
        :comments="comments"
        :notes-title="$t('comments.notes_title')"
        :notes-hint="$t('comments.notes_hint')"
    />
</template>
