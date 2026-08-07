<script setup>
/**
 * FormFooter — botones footer estándar (Cancel + Save) para Create/Edit pages.
 *
 * Uso:
 *   <FormFooter
 *       :cancel-href="route('system_management.regions.index')"
 *       :is-edit="isEdit"
 *       :processing="form.processing"
 *       :create-label-key="'regions.new'"
 *   />
 *
 * Si el botón submit necesita lógica custom, usar el slot `submit` en lugar
 * del default.
 */
import { computed } from 'vue';
import { Button, Tooltip } from 'ant-design-vue';
import { Link } from '@inertiajs/vue3';
import { SaveOutlined } from '@ant-design/icons-vue';

const props = defineProps({
    cancelHref:     { type: String,  required: true },
    isEdit:         { type: Boolean, default: false },
    processing:     { type: Boolean, default: false },
    // Label del botón cuando es create (ej. 'regions.new'). Default: 'global.create'.
    createLabelKey: { type: String,  default: 'global.create' },
    // Footer pegado al fondo del viewport (estilo SAP Fiori). Opt-in para probar.
    floating:       { type: Boolean, default: false },
});

const submitLabel = computed(() => props.isEdit ? 'global.save_changes' : props.createLabelKey);
const submitHint  = computed(() => props.isEdit ? 'global.save_changes_hint' : 'global.create_record_hint');
</script>

<template>
    <div class="form-footer" :class="{ 'form-footer--floating': floating }">
        <Tooltip :title="$t('global.cancel_hint')">
            <Link :href="cancelHref">
                <Button :size="floating ? 'middle' : 'large'" type="default">{{ $t('global.cancel') }}</Button>
            </Link>
        </Tooltip>

        <slot name="submit">
            <Tooltip :title="$t(submitHint)">
                <Button
                    type="primary"
                    :size="floating ? 'middle' : 'large'"
                    html-type="submit"
                    :loading="processing"
                >
                    <SaveOutlined />
                    {{ $t(submitLabel) }}
                </Button>
            </Tooltip>
        </slot>
    </div>
</template>

<style scoped>
.form-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--color-border-soft, #E5E5E5);
}
/* Footer flotante estilo SAP Fiori: pegado al fondo del viewport, siempre visible. */
.form-footer--floating {
    position: sticky;
    bottom: 0;
    z-index: 5;
    padding-bottom: 14px;
    background: var(--color-surface, #fff);
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.06);
    /* SAP Fiori: acción primaria (Crear/Guardar) PRIMERO (izq), Cancelar después
       (der), ambos pegados a la derecha. row-reverse + flex-start lo logra sin
       reordenar el DOM. */
    flex-direction: row-reverse;
    justify-content: flex-start;
}
@media (max-width: 768px) {
    .form-footer { flex-direction: column-reverse; }
    .form-footer > * { width: 100%; }
    .form-footer :deep(.ant-btn) { width: 100%; }
}
</style>
