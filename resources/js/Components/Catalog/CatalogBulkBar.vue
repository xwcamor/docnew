<script setup>
/**
 * Barra de acciones masivas de un catálogo (activar, desactivar, eliminar).
 *
 * Los botones son `size="large"` y no `small`: en tablet, con guantes, un botón
 * de 24px no se acierta (docs/UI.md §3, objetivos de toque de 44px).
 */
import { Button, Space } from 'ant-design-vue';
import { CheckCircleOutlined, StopOutlined, DeleteOutlined } from '@ant-design/icons-vue';

defineProps({
    count:          { type: Number,  required: true },
    isMobile:       { type: Boolean, default: false },
    bulkActivating: { type: Boolean, default: false },
    canEdit:        { type: Boolean, default: false },
    canDelete:      { type: Boolean, default: false },
});

defineEmits(['cancel', 'set-active', 'delete']);
</script>

<template>
    <div class="bulk-bar" :class="{ 'bulk-bar--mobile-sticky': isMobile }">
        <span class="bulk-bar__label">
            <strong>{{ count }}</strong>
            {{ count === 1 ? $t('global.selected') : $t('global.selected_plural') }}
        </span>
        <Space wrap>
            <Button @click="$emit('cancel')">{{ $t('global.cancel') }}</Button>
            <Button v-if="canEdit" :loading="bulkActivating" @click="$emit('set-active', true)">
                <CheckCircleOutlined /> {{ $t('global.bulk_activate') }}
            </Button>
            <Button v-if="canEdit" :loading="bulkActivating" @click="$emit('set-active', false)">
                <StopOutlined /> {{ $t('global.bulk_deactivate') }}
            </Button>
            <Button v-if="canDelete" danger type="primary" @click="$emit('delete')">
                <DeleteOutlined /> {{ $t('global.delete') }}
            </Button>
        </Space>
    </div>
</template>

<style scoped>
/* 44px de alto mínimo en cada acción: es el objetivo de toque con guantes. */
.bulk-bar :deep(.ant-btn) { min-height: 44px; }
</style>
