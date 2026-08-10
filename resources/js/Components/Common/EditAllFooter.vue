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
    <div class="sap-actionbar">
        <span v-if="statusText" class="sap-actionbar__info">{{ statusText }}</span>
        <span class="sap-actionbar__actions">
            <Button type="primary" :loading="submitting" :disabled="saveDisabled" @click="$emit('save')">
                <SaveOutlined /> {{ saveLabel }}
            </Button>
            <Button :disabled="discardDisabled || submitting" @click="$emit('discard')">
                <UndoOutlined /> {{ discardLabel }}
            </Button>
        </span>
    </div>
</template>
