<script setup>
import { computed } from 'vue';
import { useAuth } from '@/Composables/useAuth';
import { useConditions } from '@/Composables/useConditions';
import { semaforoHex } from '@/utils/severity';
import CommentThread from '@/Components/Comments/CommentThread.vue';

/**
 * TestDiagnosis — shell genérico del bloque "Diagnóstico + Conclusiones" de una
 * prueba. Recibe las frases ya compuestas (con su fuente) y las pinta; debajo
 * incluye el hilo de "Notas del diagnosticador" (comentarios de usuario). Cada
 * prueba (cromas/furanos/fiquis/fpot) arma sus frases y reusa este shell, para
 * que el patrón sea idéntico en todas.
 */
const props = defineProps({
    color:     { type: String, default: null },   // token de semáforo (green…red)
    headline:  { type: String, default: '' },      // condición; vacío = sin datos
    diagLines: { type: Array, default: () => [] },
    conclLines:{ type: Array, default: () => [] },
    // Nivel de acción (0 rutina · 1 seguimiento · 2 investigar · 3 crítico): pinta la
    // línea de estado/urgencia que ENCABEZA la conclusión, resaltada según severidad.
    urgencyLevel: { type: Number, default: null },
    caveats:   { type: Array, default: () => [] },
    foot:      { type: String, default: '' },
    source:    { type: String, default: '' },
    noData:    { type: String, default: '' },
    // Notas del diagnosticador (comentarios sobre el transformer, por contexto).
    transformerId: { type: [Number, String], default: null },
    context:       { type: String, default: null },
    comments:      { type: Array, default: () => [] },
    notesTitle:    { type: String, default: '' },
    notesHint:     { type: String, default: '' },
});

const { can } = useAuth();
const { urgencyLine } = useConditions();
const canSeeComments = can('comments.view');
const hasData = computed(() => !!props.headline);
const stateHex = computed(() => (hasData.value ? semaforoHex(props.color) : '#9aa0a6'));
</script>

<template>
    <div class="tdiag" :style="{ borderLeftColor: stateHex }">
        <template v-if="hasData">
            <div class="tdiag__cols" :class="{ 'tdiag__cols--two': conclLines.length }">
                <div class="tdiag__col">
                    <h4 class="tdiag__title">
                        <span class="tdiag__dot" :style="{ background: stateHex }"></span>
                        {{ $t('furanos.diag.title') }} — <strong>{{ headline }}</strong>
                    </h4>
                    <ul class="tdiag__list">
                        <li v-for="(line, i) in diagLines" :key="'d' + i">{{ line }}</li>
                    </ul>
                    <p v-for="(cav, i) in caveats" :key="'c' + i" class="tdiag__caveat">{{ cav }}</p>
                </div>

                <div v-if="conclLines.length" class="tdiag__col tdiag__col--concl">
                    <h4 class="tdiag__title">{{ $t('furanos.diag.concl') }}</h4>
                    <p v-if="urgencyLevel !== null" class="tdiag__urgency" :class="'tdiag__urgency--' + urgencyLevel">
                        {{ urgencyLine(urgencyLevel) }}
                    </p>
                    <ul class="tdiag__list">
                        <li v-for="(line, i) in conclLines" :key="'r' + i">{{ line }}</li>
                    </ul>
                    <p v-if="source" class="tdiag__source">{{ source }}</p>
                </div>
            </div>

            <p v-if="foot" class="tdiag__foot">{{ foot }}</p>
        </template>
        <p v-else class="tdiag__nodata">{{ noData }}</p>

        <div v-if="canSeeComments && transformerId" class="tdiag__block tdiag__block--concl">
            <CommentThread
                type="transformer"
                :id="transformerId"
                :context="context"
                :comments="comments"
                :title="notesTitle"
                :hint="notesHint"
            />
        </div>
    </div>
</template>

<style scoped>
.tdiag { border: 1px solid var(--color-border, #eef0f2); border-left: 4px solid #9aa0a6; border-radius: 8px; padding: 16px 18px; margin-bottom: 18px; background: var(--color-surface, #fff); }
.tdiag__block { margin-bottom: 14px; }
.tdiag__block--concl { padding-top: 12px; border-top: 1px dashed var(--color-border, #eef0f2); }
/* Diagnóstico (izq) y Conclusiones (der) en paralelo para no alargar la vista. */
.tdiag__cols { display: grid; grid-template-columns: 1fr; gap: 18px; margin-bottom: 14px; }
.tdiag__cols--two { grid-template-columns: 1fr 1fr; }
.tdiag__cols--two .tdiag__col--concl { border-left: 1px dashed var(--color-border, #eef0f2); padding-left: 18px; }
@media (max-width: 760px) {
    .tdiag__cols--two { grid-template-columns: 1fr; }
    .tdiag__cols--two .tdiag__col--concl { border-left: none; padding-left: 0; padding-top: 12px; border-top: 1px dashed var(--color-border, #eef0f2); }
}
.tdiag__title { display: flex; align-items: center; gap: 8px; font-size: 0.92rem; margin: 0 0 8px; color: var(--color-text, #32363a); }
.tdiag__dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.tdiag__list { margin: 0; padding-left: 18px; }
.tdiag__list li { font-size: 0.88rem; line-height: 1.5; color: var(--color-text, #32363a); margin-bottom: 4px; }
.tdiag__caveat { font-size: 0.83rem; color: #E2661E; margin: 8px 0 0; }
/* Línea de estado/urgencia que encabeza la conclusión. Resalte escalado por
   severidad: rutina/seguimiento discretas; investigar (naranja) y crítico (rojo,
   con tinte de fondo) saltan a la vista. */
.tdiag__urgency { font-size: 0.86rem; line-height: 1.45; margin: 0 0 8px; font-weight: 600; }
.tdiag__urgency--0 { color: var(--color-text-muted, #6A6D70); font-weight: 500; }
.tdiag__urgency--1 { color: #B9821B; }
.tdiag__urgency--2 { color: #E2661E; }
.tdiag__urgency--3 { color: #C8281D; font-weight: 800; background: rgba(200, 40, 29, 0.08); border-left: 3px solid #C8281D; padding: 6px 10px; border-radius: 4px; }
.tdiag__source { font-size: 0.78rem; color: var(--color-text-muted, #6A6D70); font-style: italic; margin: 8px 0 0; }
.tdiag__foot { font-size: 0.78rem; color: var(--color-text-muted, #9aa0a6); margin: 6px 0 0; }
.tdiag__nodata { color: var(--color-text-muted, #6A6D70); font-size: 0.9rem; margin: 0; }
html[data-theme="dark"] .tdiag { background: #1f2429; border-color: #3f4448; }
html[data-theme="dark"] .tdiag__block--concl { border-top-color: #3f4448; }
html[data-theme="dark"] .tdiag__cols--two .tdiag__col--concl { border-color: #3f4448; }
</style>
