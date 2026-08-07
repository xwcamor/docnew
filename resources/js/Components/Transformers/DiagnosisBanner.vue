<script setup>
import { computed } from 'vue';
import { Alert } from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';
import { useDiagnosisVerdict } from '@/Composables/useDiagnosisVerdict';
import { semaforoHex } from '@/utils/severity';

/**
 * DiagnosisBanner — veredicto sintetizado de una prueba (mini-resumen arriba de
 * la pestaña). Toma el dato estructurado de `diagnosisSummary[type]` y compone el
 * texto con criterio de diagnosticador. El "estado" lo manda el método calibrado
 * (DGAF en cromas); Duval solo se nombra si hay falla activa; IEEE va como nota.
 */
const props = defineProps({
    type:    { type: String, required: true }, // cromas | furanos | fiquis | fpot
    verdict: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const { condLabel } = useConditions();
const { verdictReason } = useDiagnosisVerdict();

const v = computed(() => props.verdict || {});
const hasData = computed(() => !!v.value.has_data && v.value.condition != null);

// 'success' | 'warning' | 'error' | 'info' para el tipo de Alert (color del borde).
const alertType = computed(() => {
    const c = v.value.color;
    if (c === 'green' || c === 'lime') return 'success';
    if (c === 'yellow') return 'warning';
    if (c === 'orange' || c === 'red') return 'error';
    return 'info';
});

const headline = computed(() => (hasData.value ? condLabel(v.value.condition) : t('diagnostics.verdict_no_data')));

// Línea de razón según la prueba (misma lógica que el panel del Resumen).
const reason = computed(() => verdictReason(props.type, v.value));
</script>

<template>
    <Alert :type="alertType" show-icon class="diag-banner">
        <template #message>
            <span class="diag-banner__head">
                <span class="diag-banner__dot" :style="{ background: semaforoHex(v.color) }"></span>
                <strong>{{ headline }}</strong>
            </span>
            <span v-if="reason" class="diag-banner__reason"> — {{ reason }}</span>
        </template>
    </Alert>
</template>

<style scoped>
.diag-banner { margin-bottom: 12px; }
.diag-banner__head { display: inline-flex; align-items: center; gap: 7px; }
.diag-banner__dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.diag-banner__reason { color: var(--color-text, #32363a); }
</style>
