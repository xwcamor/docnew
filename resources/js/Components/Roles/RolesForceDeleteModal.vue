<script setup>
/**
 * Force-delete modal con triple guarda:
 *  1) Confirmacion de nombre (deben tipear el name exacto).
 *  2) Motivo obligatorio (min 10 chars).
 *  3) Solo super llega aquí (gated en backend + UI).
 */
import { Modal, Alert, Input } from 'ant-design-vue';
import { ExclamationCircleFilled } from '@ant-design/icons-vue';

defineProps({
    target:     { type: Object, default: null },
    submitting: { type: Boolean, default: false },
    errors:     { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:open', 'confirm']);

const openModel = defineModel('open', { type: Boolean, required: true });
const form      = defineModel('form',  { type: Object,  required: true });

const okDisabled = (target) => !target
    || form.value.name_confirmation !== target.name
    || (form.value.reason?.length ?? 0) < 10;
</script>

<template>
    <Modal
        v-model:open="openModel"
        :title="$t('global.force_delete_title')"
        :ok-text="$t('global.force_delete')"
        :cancel-text="$t('global.cancel')"
        :ok-button-props="{
            danger: true,
            loading: submitting,
            disabled: okDisabled(target),
        }"
        @ok="emit('confirm')"
    >
        <Alert type="error" show-icon class="mb-3">
            <template #message>
                <ExclamationCircleFilled /> {{ $t('global.force_delete_warning') }}
            </template>
        </Alert>

        <p class="force-msg">
            {{ $t('global.force_delete_name_prompt', { name: target?.name }) }}
        </p>
        <Input
            v-model:value="form.name_confirmation"
            :placeholder="target?.name"
            :status="errors.name_confirmation ? 'error' : ''"
            size="large"
            class="mb-3"
        />
        <div v-if="errors.name_confirmation" class="field-error">
            {{ errors.name_confirmation }}
        </div>

        <p class="force-msg">{{ $t('global.force_delete_reason_prompt') }}</p>
        <Input.TextArea
            v-model:value="form.reason"
            :rows="3"
            :placeholder="$t('global.delete_reason_placeholder')"
            :maxlength="500"
            show-count
            :status="errors.reason ? 'error' : ''"
        />
        <div v-if="errors.reason" class="field-error">
            {{ errors.reason }}
        </div>
    </Modal>
</template>

<style scoped>
.force-msg { color: var(--color-text); line-height: 1.5; margin: 12px 0 8px 0; font-size: 0.875rem; }
.field-error { color: var(--color-danger); font-size: 0.8rem; margin: 4px 0 0 0; }
.mb-3 { margin-bottom: 12px; }
</style>
