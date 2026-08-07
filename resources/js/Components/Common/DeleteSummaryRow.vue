<script setup>
/**
 * Fila del resumen del registro en una página de eliminación (DeletePage).
 * Estilo uniforme para TODOS los módulos: etiqueta a la izquierda, valor a la
 * derecha. El valor va en el slot por defecto (texto, <code>, <Tag>, etc.).
 */
defineProps({
    label: { type: String, required: true },
});
</script>

<template>
    <div class="record-summary__row">
        <span class="record-summary__label">{{ label }}</span>
        <span class="record-summary__value"><slot /></span>
    </div>
</template>

<style scoped>
.record-summary__row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    font-size: 0.875rem;
    min-width: 0;
}
.record-summary__label { color: var(--color-text-muted, #6A6D70); font-weight: 500; flex-shrink: 0; }
.record-summary__value {
    color: var(--color-text, #32363A);
    text-align: right;
    word-break: break-word;
    overflow-wrap: anywhere;
    min-width: 0;
    flex: 1 1 auto;
}
</style>
