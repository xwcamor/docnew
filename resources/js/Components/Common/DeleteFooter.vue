<script setup>
/**
 * DeleteFooter — botones footer estándar (Cancel + Delete) para Delete pages.
 *
 * Uso:
 *   <DeleteFooter
 *       :cancel-href="route('system_management.regions.index')"
 *       :processing="form.processing"
 *       :disabled="form.deleted_description.trim().length < 3"
 *   />
 */
import { Button, Tooltip } from 'ant-design-vue';
import { Link } from '@inertiajs/vue3';
import { DeleteOutlined } from '@ant-design/icons-vue';

defineProps({
    cancelHref: { type: String,  required: true },
    processing: { type: Boolean, default: false },
    disabled:   { type: Boolean, default: false },
    // Footer flotante full-bleed estilo SAP Fiori (igual que el form de editar).
    floating:   { type: Boolean, default: false },
});
</script>

<template>
    <div class="form-footer" :class="{ 'form-footer--floating': floating }">
        <Tooltip :title="$t('global.cancel_hint')">
            <Link :href="cancelHref">
                <Button :size="floating ? 'middle' : 'large'" type="default">{{ $t('global.cancel') }}</Button>
            </Link>
        </Tooltip>
        <Tooltip :title="$t('global.delete_hint')">
            <Button
                type="primary"
                danger
                :size="floating ? 'middle' : 'large'"
                html-type="submit"
                :loading="processing"
                :disabled="disabled"
            >
                <DeleteOutlined />
                {{ $t('global.delete') }}
            </Button>
        </Tooltip>
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
/* Footer flotante estilo SAP Fiori: pegado al fondo del viewport, siempre visible
   (mismo patrón que FormFooter; el full-bleed lo da .sap-form en app.css). */
.form-footer--floating {
    position: sticky;
    bottom: 0;
    z-index: 5;
    padding-bottom: 14px;
    background: var(--color-surface, #fff);
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.06);
    /* Acción primaria (Eliminar) a la derecha, Cancelar a su izquierda. */
    flex-direction: row-reverse;
    justify-content: flex-start;
}
@media (max-width: 768px) {
    .form-footer { flex-direction: column-reverse; }
    .form-footer > * { width: 100%; }
    .form-footer :deep(.ant-btn) { width: 100%; }
}
</style>
