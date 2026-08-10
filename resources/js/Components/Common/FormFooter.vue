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
    // Se mantiene por compatibilidad: la barra SIEMPRE va pegada al pie, que es
    // el estandar (docs/UI.md §8). Los 26 formularios ya la pasaban en `true`.
    floating:       { type: Boolean, default: true },
});

const submitLabel = computed(() => props.isEdit ? 'global.save_changes' : props.createLabelKey);
const submitHint  = computed(() => props.isEdit ? 'global.save_changes_hint' : 'global.create_record_hint');
</script>

<template>
    <div class="sap-actionbar">
        <div class="sap-actionbar__actions">
            <slot name="submit">
                <Tooltip :title="$t(submitHint)">
                    <Button type="primary" html-type="submit" :loading="processing">
                        <SaveOutlined />
                        {{ $t(submitLabel) }}
                    </Button>
                </Tooltip>
            </slot>

            <Tooltip :title="$t('global.cancel_hint')">
                <Link :href="cancelHref">
                    <Button type="default">{{ $t('global.cancel') }}</Button>
                </Link>
            </Tooltip>
        </div>
    </div>
</template>
