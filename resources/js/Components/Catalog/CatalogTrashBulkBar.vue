<script setup>
/** Barra de la papelera: desde aquí solo se restaura, nunca se borra del todo. */
import { Button, Space } from 'ant-design-vue';
import { UndoOutlined } from '@ant-design/icons-vue';

defineProps({
    count:      { type: Number,  required: true },
    submitting: { type: Boolean, default: false },
});

defineEmits(['cancel', 'restore']);
</script>

<template>
    <div class="bulk-bar">
        <span class="bulk-bar__label">
            <strong>{{ count }}</strong>
            {{ count === 1 ? $t('global.selected') : $t('global.selected_plural') }}
        </span>
        <Space wrap>
            <Button @click="$emit('cancel')">{{ $t('global.cancel') }}</Button>
            <Button type="primary" ghost :loading="submitting" @click="$emit('restore')">
                <UndoOutlined /> {{ $t('global.bulk_restore') }}
            </Button>
        </Space>
    </div>
</template>
