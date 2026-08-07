<script setup>
import { computed } from 'vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import TestDiagnosis from '@/Components/Transformers/TestDiagnosis.vue';

/**
 * FiquisDiagnosis — Diagnóstico + Conclusiones del fisicoquímico (IEEE C57.106,
 * por aceite + clase de tensión). Nombra los parámetros fuera de rango. Compone
 * las frases (con fuente) y reusa el shell genérico.
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

// Parámetros con score 3-4 (malo/muy malo) → fuera de rango.
const badParams = computed(() => (v.value.params || []).filter((p) => (p.score ?? 1) >= 3));

const diagLines = computed(() => {
    if (!hasData.value) return [];
    const classLabel = v.value.class ? t('fiquis.diag.class_' + v.value.class) : t('fiquis.diag.class_na');
    const lines = [t('fiquis.diag.state', { condition: condLabel(v.value.condition), class: classLabel })];
    if (badParams.value.length) {
        const list = badParams.value.map((p) => `${t('fiquis.' + p.key)} (${p.value})`).join(', ');
        lines.push(t('fiquis.diag.params_bad', { list }));
    } else {
        lines.push(t('fiquis.diag.params_ok'));
    }
    // Parámetros en ámbar (acercándose al límite, sin pasarlo): hace coherente el
    // diagnóstico con las celdas ámbar de la tabla.
    if (Array.isArray(v.value.approaching) && v.value.approaching.length) {
        lines.push(t('fiquis.diag.params_near', { list: v.value.approaching.join(', ') }));
    }
    return lines;
});

const conclLines = computed(() => {
    if (!hasData.value) return [];
    // Escala de acción común (0 rutina · 1 seguimiento · 2+ investigar) del backend.
    const lvl = v.value.action_level ?? 0;
    const key = lvl >= 2 ? 'concl_investigate' : (lvl === 1 ? 'concl_watch' : 'concl_routine');
    const lines = [t('fiquis.diag.' + key)];
    if (Array.isArray(v.value.approaching) && v.value.approaching.length) {
        lines.push(t('fiquis.diag.concl_approaching', { list: v.value.approaching.join(', ') }));
    }
    return lines;
});

// Mensaje explícito de por qué no hay diagnóstico (no "—" mudo).
const noDataMsg = computed(() => {
    const reason = v.value.reason;
    if (reason === 'no_oil') return t('fiquis.diag.no_oil');
    if (reason === 'no_table') return t('fiquis.diag.no_table');
    if (reason === 'no_params') return t('fiquis.diag.no_params');
    return t('fiquis.diag.no_data');
});
</script>

<template>
    <TestDiagnosis
        :color="v.color"
        :headline="hasData ? condLabel(v.condition) : ''"
        :diag-lines="diagLines"
        :concl-lines="conclLines"
        :urgency-level="hasData ? (v.action_level ?? 0) : null"
        :source="$t('fiquis.diag.concl_source')"
        :foot="$t('fiquis.diag.foot')"
        :no-data="noDataMsg"
        :transformer-id="transformerId"
        context="diag_fiquis"
        :comments="comments"
        :notes-title="$t('comments.notes_title')"
        :notes-hint="$t('comments.notes_hint')"
    />
</template>
