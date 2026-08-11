<script setup>
/**
 * DeletePage — estructura ESTÁNDAR de una página de eliminación (soft-delete con
 * motivo), igual para todos los módulos:
 *   - .sap-form: franja blanca full-bleed del título (arriba) + cuerpo sobre gris.
 *   - SectionHeader con icono de papelera rojo.
 *   - Alert de advertencia ESTÁNDAR (mismo texto en todos; sin promesas de
 *     restauración — eso depende de permisos).
 *   - Slot #warning para advertencias propias del módulo (ej. dependientes).
 *   - Slot #summary con los datos del registro (filas DeleteSummaryRow).
 *   - Textarea de motivo + DeleteFooter flotante (2ª franja blanca).
 *   - Botón Eliminar se habilita solo cuando el motivo tiene ≥ minReason chars
 *     (y no haya un `disabled` extra del módulo).
 *
 * v-model = el texto del motivo (form.deleted_description). @submit al enviar.
 */
import { computed } from 'vue';
import { Form, FormItem, Input, Alert } from 'ant-design-vue';
import { DeleteOutlined } from '@ant-design/icons-vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import DeleteFooter from '@/Components/Common/DeleteFooter.vue';

const props = defineProps({
    backHref:   { type: String,  required: true },
    title:      { type: String,  required: true },
    subtitle:   { type: String,  default: '' },
    modelValue: { type: String,  default: '' },
    error:      { type: String,  default: '' },
    processing: { type: Boolean, default: false },
    // Condición extra del módulo para deshabilitar (ej. rol con usuarios). Se
    // combina (OR) con la del motivo mínimo.
    disabled:   { type: Boolean, default: false },
    minReason:  { type: Number,  default: 3 },
});
const emit = defineEmits(['update:modelValue', 'submit']);

const deleteDisabled = computed(() =>
    props.disabled || (props.modelValue ?? '').trim().length < props.minReason,
);
</script>

<template>
    <div class="delete-page sap-form">
        <SectionHeader
            :back-href="backHref"
            :title="title"
            :subtitle="subtitle"
            icon-bg="var(--color-danger)"
        >
            <template #icon><DeleteOutlined /></template>
        </SectionHeader>

        <!-- `.form-body`, no una Card: la barra del pie (DeleteFooter) sangra hasta
             los bordes con los `--bar-bleed-*` que app.css declara para `.form-body`;
             metida en una tarjeta salia 28px corta por lado y despegada 24px del
             fondo en TODAS las papeleras a la vez (docs/UI.md §8). -->
        <div class="form-body">
            <Alert type="warning" show-icon class="del-mb">
                <template #message>{{ $t('global.delete_confirm_title') }}</template>
                <template #description>{{ $t('global.delete_confirm_body') }}</template>
            </Alert>

            <!-- Advertencias propias del módulo (ej. registros dependientes). -->
            <slot name="warning" />

            <div v-if="$slots.summary" class="record-summary">
                <slot name="summary" />
            </div>

            <Form layout="vertical" @submit.prevent="emit('submit')">
                <!-- Campos extra antes del motivo (ej. confirmar el asunto en Messages). -->
                <slot name="extra" />

                <FormItem
                    :label="$t('global.delete_description')"
                    required
                    :validate-status="error ? 'error' : ''"
                    :help="error"
                >
                    <Input.TextArea
                        :value="modelValue"
                        @update:value="emit('update:modelValue', $event)"
                        :rows="4"
                        :placeholder="$t('global.delete_reason_placeholder')"
                        :maxlength="1000"
                        showCount
                        autofocus
                    />
                </FormItem>

                <DeleteFooter
                    :cancel-href="backHref"
                    :processing="processing"
                    :disabled="deleteDisabled"
                    floating
                />
            </Form>
        </div>
    </div>
</template>

<style scoped>
.record-summary {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 14px 16px;
    background: var(--color-surface-alt, #f7f8fa);
    border: 1px solid var(--color-border-strong, #e1e3e5);
    border-radius: 6px;
    margin-bottom: 20px;
}
.del-mb { margin-bottom: 16px; }
</style>
