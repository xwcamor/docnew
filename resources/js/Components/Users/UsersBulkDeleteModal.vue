<script setup>
/** Modal de bulk delete: motivo obligatorio (min 3 chars). */
import { Modal, Input } from 'ant-design-vue';

defineProps({
    open:          { type: Boolean, required: true },
    count:         { type: Number,  required: true },
    submitting:    { type: Boolean, default: false },
    resourceLabel: { type: String,  default: '' },
});

const emit = defineEmits(['update:open', 'confirm']);

const reasonValue = defineModel('reason', { type: String, default: '' });
</script>

<template>
    <Modal
        :open="open"
        :title="$t('global.bulk_delete')"
        :confirm-loading="submitting"
        :ok-text="$t('global.delete')"
        :cancel-text="$t('global.cancel')"
        :ok-button-props="{ danger: true, disabled: (reasonValue?.trim().length ?? 0) < 3 }"
        @update:open="emit('update:open', $event)"
        @ok="emit('confirm')"
    >
        <p class="bulk-msg">
            {{ $t('global.bulk_records_warning', { count, resource: resourceLabel }) }}
        </p>
        <Input.TextArea
            v-model:value="reasonValue"
            :rows="3"
            :placeholder="$t('global.delete_reason_placeholder')"
            :maxlength="1000"
            show-count
        />
    </Modal>
</template>

<style scoped>
.bulk-msg { color: var(--color-text); line-height: 1.5; margin: 0 0 14px 0; }
</style>
