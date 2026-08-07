<script setup>
/** Barra de acciones masivas para Roles. Mobile: sticky al pie. */
import { Button, Space } from 'ant-design-vue';
import { CheckOutlined, CloseOutlined, DeleteFilled } from '@ant-design/icons-vue';

defineProps({
    count:     { type: Number,  required: true },
    isMobile:  { type: Boolean, default: false },
    canEdit:   { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
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
                @click="$emit('set-active', true)"
            >
                <CheckOutlined /> {{ $t('global.bulk_activate') }}
            </Button>
            <Button
                v-if="canEdit"
                size="small"
                @click="$emit('set-active', false)"
            >
                <CloseOutlined /> {{ $t('global.bulk_deactivate') }}
            </Button>
            <Button
                v-if="canDelete"
                size="small"
                danger
                type="primary"
                @click="$emit('delete')"
            >
                <DeleteFilled /> {{ $t('global.delete') }}
            </Button>
        </Space>
    </div>
</template>
