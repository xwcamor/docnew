<script setup>
/**
 * EditAllFooter — franja flotante full-bleed (estilo SAP Fiori) para las páginas
 * "Editar todo": botón Descartar + Guardar pegados al fondo del viewport.
 * Pensado para ir como hijo directo de un contenedor .sap-form (padding 24/16).
 */
import { Button } from 'ant-design-vue';
import { SaveOutlined, UndoOutlined } from '@ant-design/icons-vue';

defineProps({
    discardLabel:    { type: String,  required: true },
    saveLabel:       { type: String,  required: true },
    discardDisabled: { type: Boolean, default: false },
    saveDisabled:    { type: Boolean, default: false },
    submitting:      { type: Boolean, default: false },
    // Texto de estado a la izquierda (ej. "1 cambio pendiente"), estilo SAP Fiori.
    statusText:      { type: String,  default: '' },
});
defineEmits(['discard', 'save']);
</script>

<template>
    <div class="edit-all-footer">
        <span v-if="statusText" class="edit-all-footer__status">{{ statusText }}</span>
        <span class="edit-all-footer__actions">
            <Button :disabled="discardDisabled || submitting" @click="$emit('discard')">
                <UndoOutlined /> {{ discardLabel }}
            </Button>
            <Button type="primary" :loading="submitting" :disabled="saveDisabled" @click="$emit('save')">
                <SaveOutlined /> {{ saveLabel }}
            </Button>
        </span>
    </div>
</template>

<style scoped>
/* Franja flotante: pegada al fondo, full-bleed dentro de .sap-form (padding 24).
   Acción primaria (Guardar) a la derecha (row-reverse). */
.edit-all-footer {
    display: flex;
    align-items: center;
    gap: 12px;
    position: sticky;
    bottom: 0;
    z-index: 5;
    margin: 16px -24px 0;
    padding: 10px 24px 14px;
    background: var(--color-surface, #fff);
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.06);
    border-top: 1px solid var(--color-border-soft, #eceff2);
}
/* Estado a la izquierda; acciones (Descartar/Guardar) a la derecha. */
.edit-all-footer__status { color: var(--color-text-muted, #6A6D70); font-size: 0.84rem; }
.edit-all-footer__actions { display: inline-flex; gap: 12px; margin-left: auto; }
@media (max-width: 768px) {
    .edit-all-footer { margin: 16px -16px 0; padding: 10px 16px 14px; flex-wrap: wrap; }
    .edit-all-footer__status { flex: 1 1 100%; }
    .edit-all-footer__actions { width: 100%; }
    .edit-all-footer__actions :deep(.ant-btn) { flex: 1 1 auto; }
}
</style>
