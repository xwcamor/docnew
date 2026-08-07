<script setup>
import { semaforoHex } from '@/utils/severity';

/**
 * SemaforoLegend — leyenda de los 5 niveles del semáforo de diagnóstico, con su
 * color. Compartida por las pestañas de pruebas (cromas/furanos/fiquis/fpot).
 * Los tokens de color son los mismos que viven en datos (result_scales.color).
 */
const LEVELS = [
    { token: 'green',  key: 'muy_bueno' },
    { token: 'lime',   key: 'bueno' },
    { token: 'yellow', key: 'medio' },
    { token: 'orange', key: 'malo' },
    { token: 'red',    key: 'muy_malo' },
];
// La etiqueta sale de diagnostics.cond_* (mismo set que usa la grilla).
</script>

<template>
    <div class="sema-legend">
        <span class="sema-legend__lbl">{{ $t('diagnostics.legend') }}</span>
        <span v-for="l in LEVELS" :key="l.token" class="sema-legend__item">
            <span class="sema-legend__dot" :style="{ background: semaforoHex(l.token) }"></span>
            {{ $t('diagnostics.cond_' + l.key) }}
        </span>
    </div>
</template>

<style scoped>
.sema-legend {
    display: flex; flex-wrap: wrap; align-items: center; gap: 6px 16px;
    margin-top: 12px; padding-top: 10px;
    border-top: 1px solid var(--color-border, #eef0f2);
    font-size: 0.78rem; color: var(--color-text-muted, #6A6D70);
}
.sema-legend__lbl { text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; }
.sema-legend__item { display: inline-flex; align-items: center; gap: 6px; color: var(--color-text, #32363a); }
.sema-legend__dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
html[data-theme="dark"] .sema-legend { border-top-color: #3f4448; }
html[data-theme="dark"] .sema-legend__item { color: #e5e6e7; }
</style>
