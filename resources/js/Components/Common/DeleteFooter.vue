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
    // Se mantiene por compatibilidad: la barra SIEMPRE va pegada al pie
    // (docs/UI.md §8).
    floating:   { type: Boolean, default: true },
});
</script>

<template>
    <div class="sap-actionbar">
        <div class="sap-actionbar__actions">
            <Tooltip :title="$t('global.delete_hint')">
                <Button
                    type="primary"
                    danger
                    html-type="submit"
                    :loading="processing"
                    :disabled="disabled"
                >
                    <DeleteOutlined />
                    {{ $t('global.delete') }}
                </Button>
            </Tooltip>

            <Tooltip :title="$t('global.cancel_hint')">
                <Link :href="cancelHref">
                    <Button type="default">{{ $t('global.cancel') }}</Button>
                </Link>
            </Tooltip>
        </div>
    </div>
</template>
