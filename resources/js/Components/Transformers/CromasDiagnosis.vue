<script setup>
import { computed } from 'vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import TestDiagnosis from '@/Components/Transformers/TestDiagnosis.vue';

/**
 * CromasDiagnosis — Diagnóstico + Conclusiones de cromatografía (DGA). El estado
 * lo manda el DGAF (IEC 60599); Duval/Gas Clave solo si hay falla activa; IEEE
 * C57.104 va como segunda lectura. Compone frases (con fuente) y las pasa al shell.
 */
const props = defineProps({
    diagnosis:     { type: Object, default: () => ({}) },
    transformerId: { type: [Number, String], default: null },
    comments:      { type: Array, default: () => [] },
    dgaStatus:     { type: Object, default: null },
});

const { t } = useI18n();
const { condLabel } = useConditions();

const v = computed(() => props.diagnosis || {});
const hasData = computed(() => !!v.value.has_data && v.value.condition != null);

const diagLines = computed(() => {
    if (!hasData.value) return [];
    const d = v.value;
    const lines = [];
    lines.push(t('cromas.diag.state', { score: d.score ?? '—', condition: condLabel(d.condition) }));
    if (d.active_fault) {
        lines.push(t('cromas.diag.fault'));
        if (d.fault_zone) lines.push(t('cromas.diag.fault_duval', { zone: t('cromas.duval.' + d.fault_zone) }));
        const over = (d.over || []).map((o) => o.field).filter(Boolean);
        if (over.length) lines.push(t('cromas.diag.gases_over', { gases: over.join(', ') }));
    } else {
        lines.push(t('cromas.diag.no_fault'));
    }
    // Gases en ámbar (cerca del típico, sin pasarlo): hace coherente el diagnóstico
    // con las celdas ámbar de la tabla.
    if (Array.isArray(d.approaching) && d.approaching.length) {
        lines.push(t('cromas.diag.gases_near', { gases: d.approaching.join(', ') }));
    }
    if (d.co2co_level === 'low') lines.push(t('diagnostics.verdict_co_low', { r: d.co2co }));
    else if (d.co2co_level === 'high') lines.push(t('diagnostics.verdict_co_high', { r: d.co2co }));
    // Métodos clásicos en el resumen (mismo set que la tabla de métodos). Duval ya
    // va en la línea de falla; Rogers/Doernenburg solo emiten veredicto con falla
    // activa (relaciones IEC 60599); IEEE C57.104 es la segunda lectura por norma.
    const verdict = (code) => (code ? t('cromas.ratios.fault.' + code) : t('cromas.ratios.na'));
    lines.push(`${t('cromas.ratios.rogers')}: ${verdict(d.rogers)}`);
    lines.push(`${t('cromas.ratios.doernenburg')}: ${verdict(d.doernenburg)}`);
    if (props.dgaStatus && props.dgaStatus.status) {
        lines.push(`IEEE C57.104: ${props.dgaStatus.label ?? ('DGA ' + props.dgaStatus.status)}`);
    }
    return lines;
});

const conclLines = computed(() => {
    if (!hasData.value) return [];
    const d = v.value;
    const lines = [];
    if (d.active_fault) {
        lines.push(t('cromas.diag.concl_fault'));
        // Coherencia titular↔recomendación: si hay un gas sobre su típico pero el DGAF
        // global sigue bueno (rating ≥3), aclarar que la alerta es puntual y no
        // contradice el estado "Bueno/Muy Bueno" del titular.
        if ((d.rating ?? 4) >= 3) lines.push(t('cromas.diag.concl_fault_mild', { condition: condLabel(d.condition) }));
    } else if ((d.rating ?? 4) >= 3) {
        lines.push(t('cromas.diag.concl_routine'));
    } else {
        lines.push(t('cromas.diag.concl_watch'));
    }
    // Gases acercándose al límite (celda ámbar): hace coherente la conclusión con
    // la tabla cuando ningún gas pasó pero hay celdas en ámbar.
    if (Array.isArray(d.approaching) && d.approaching.length) {
        lines.push(t('cromas.diag.concl_approaching', { gases: d.approaching.join(', ') }));
    }
    return lines;
});

// Mensaje explícito de por qué no hay diagnóstico (no "—" mudo).
const noDataMsg = computed(() => {
    const reason = v.value.reason;
    if (reason === 'no_oil') return t('cromas.diag.no_oil');
    if (reason === 'no_rules') return t('cromas.diag.no_rules');
    if (reason === 'no_gases') return t('cromas.diag.no_gases');
    return t('cromas.diag.no_data');
});
</script>

<template>
    <TestDiagnosis
        :color="v.color"
        :headline="hasData ? condLabel(v.condition) : ''"
        :diag-lines="diagLines"
        :concl-lines="conclLines"
        :urgency-level="hasData ? (v.action_level ?? 0) : null"
        :source="$t('cromas.diag.concl_source')"
        :foot="$t('cromas.diag.foot')"
        :no-data="noDataMsg"
        :transformer-id="transformerId"
        context="diag_cromas"
        :comments="comments"
        :notes-title="$t('comments.notes_title')"
        :notes-hint="$t('comments.notes_hint')"
    />
</template>
