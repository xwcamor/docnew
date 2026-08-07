<script setup>
/** Modal de bulk delete: motivo obligatorio (mín 3 chars). */
import { Modal, Alert, Input } from 'ant-design-vue';

defineProps({
    open:         { type: Boolean, required: true },
    count:        { type: Number,  required: true },
    submitting:   { type: Boolean, default: false },
    errorMsg:     { type: String,  default: '' },
    resourceLabel: { type: String, default: '' },
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
        :ok-button-props="{ danger: true }"
        @update:open="emit('update:open', $event)"
        @ok="emit('confirm')"
    >
        <p class="bulk-msg">
            {{ $t('global.about_to_delete', { count, resource: resourceLabel }) }}
        </p>

        <Alert
            v-if="errorMsg"
            type="error"
            :message="errorMsg"
            class="mb-3"
            show-icon
        />

        <label class="bulk-label">
            {{ $t('global.deleted_reason') }} <span class="required">*</span>
        </label>
        <Input.TextArea
            v-model:value="reasonValue"
            :rows="3"
            :placeholder="$t('global.delete_reason_placeholder')"
            :maxlength="1000"
            showCount
        />
    </Modal>
</template>

<style scoped>
.bulk-msg { color: var(--color-text); line-height: 1.5; margin: 0 0 14px 0; }
.bulk-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text);
    margin-bottom: 6px;
}
.required { color: var(--color-input-error); }
.mb-3 { margin-bottom: 12px; }
</style>
