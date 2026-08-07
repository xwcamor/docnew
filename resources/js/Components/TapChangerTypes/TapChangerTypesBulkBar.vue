<script setup>
/** Barra de acciones masivas para TapChangerTypes (seleccion multiple). Mobile: sticky al pie. */
import { Button, Space } from 'ant-design-vue';
import {
    CheckCircleOutlined, StopOutlined, DeleteOutlined,
} from '@ant-design/icons-vue';

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
    <div
        class="bulk-bar"
        :class="{ 'bulk-bar--mobile-sticky': isMobile }"
    >
        <span class="bulk-bar__label">
            <strong>{{ count }}</strong>
            {{ count === 1 ? $t('global.selected') : $t('global.selected_plural') }}
        </span>
        <Space wrap>
            <Button size="small" @click="$emit('cancel')">{{ $t('global.cancel') }}</Button>
            <Button
                v-if="canEdit"
                size="small"
                :loading="bulkActivating"
                @click="$emit('set-active', true)"
            >
                <CheckCircleOutlined /> {{ $t('global.bulk_activate') }}
            </Button>
            <Button
                v-if="canEdit"
                size="small"
                :loading="bulkActivating"
                @click="$emit('set-active', false)"
            >
                <StopOutlined /> {{ $t('global.bulk_deactivate') }}
            </Button>
            <Button
                v-if="canDelete"
                size="small"
                danger
                type="primary"
                @click="$emit('delete')"
            >
                <DeleteOutlined /> {{ $t('global.delete') }}
            </Button>
        </Space>
    </div>
</template>
