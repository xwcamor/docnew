<script setup>
/**
 * Barra de acciones masivas de un catálogo (activar, desactivar, eliminar).
 *
 * Los botones van sin `size`, o sea tamaño por defecto, igual que en el resto
 * de barras: así el alto de la franja coincide con el de los pies de formulario.
 * El mínimo táctil de 44px que antes forzaba esta ficha con CSS propio lo pone
 * ya `.bulk-bar` en app.css, donde hace falta —de 768px para abajo, que es la
 * tablet en obra de docs/UI.md §3—.
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
