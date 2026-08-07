<script setup>
import { useConditions } from '@/Composables/useConditions';
import { semaforoHex } from '@/utils/severity';

/**
 * Chip compacto de estado de una prueba (punto del semáforo + condición).
 * Va en la barra de pestañas para que el veredicto quede SIEMPRE visible,
 * también en las vistas de datos (no solo en "Resumen").
 */
defineProps({
    condition: { type: String, default: null },
    color:     { type: String, default: null },
});

const { condLabel } = useConditions();
</script>

<template>
    <span v-if="condition" class="tstatus" :title="condLabel(condition)">
        <span class="tstatus__dot" :style="{ background: semaforoHex(color) }"></span>
        <span class="tstatus__lbl">{{ condLabel(condition) }}</span>
    </span>
</template>

<style scoped>
.tstatus { display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; font-weight: 600; color: var(--color-text, #32363a); white-space: nowrap; }
.tstatus__dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; flex: none; }
@media (max-width: 480px) { .tstatus__lbl { display: none; } }
</style>
