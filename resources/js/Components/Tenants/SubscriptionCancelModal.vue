<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Modal, Form, FormItem, Input, Radio, RadioGroup, Alert } from 'ant-design-vue';

const props = defineProps({
    open:       { type: Boolean, default: false },
    tenantSlug: { type: String, required: true },
    subscription: { type: Object, default: null },
});

const emit = defineEmits(['update:open', 'success']);

const form = useForm({
    mode:   'cancel',
    reason: '',
});

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
        form.mode = 'cancel';
    }
});

const submit = () => {
    if (!props.subscription) return;
    form.post(
        route('system_management.tenants.subscriptions.cancel', [props.tenantSlug, props.subscription.id]),
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('success');
                emit('update:open', false);
            },
        },
    );
};

const closeModal = () => {
    if (form.processing) return;
    emit('update:open', false);
};

const okText = computed(() => form.mode === 'suspend' ? 'subscriptions.suspend' : 'subscriptions.cancel');
</script>

<template>
    <Modal
        :open="open"
        :title="$t('subscriptions.cancel')"
        :confirm-loading="form.processing"
        :ok-text="$t(okText)"
        :ok-button-props="{ danger: true }"
        :cancel-text="$t('global.cancel')"
        @ok="submit"
        @cancel="closeModal"
        :width="560"
        destroy-on-close
    >
        <Form layout="vertical" @submit.prevent="submit">
            <Alert
                v-if="form.mode === 'suspend'"
                type="warning"
                show-icon
                class="mb-3"
                :message="$t('subscriptions.suspend_hint')"
            />
            <Alert
                v-else
                type="info"
                show-icon
                class="mb-3"
                :message="$t('subscriptions.cancel_hint')"
            />

            <FormItem :label="$t('subscriptions.cancel_mode')">
                <RadioGroup v-model:value="form.mode">
                    <Radio value="cancel">{{ $t('subscriptions.cancel_mode_cancel') }}</Radio>
                    <Radio value="suspend">{{ $t('subscriptions.cancel_mode_suspend') }}</Radio>
                </RadioGroup>
            </FormItem>

            <FormItem
                :label="$t('subscriptions.cancel_reason')"
                required
                :validate-status="form.errors.reason ? 'error' : ''"
                :help="form.errors.reason"
            >
                <Input.TextArea
                    v-model:value="form.reason"
                    :rows="4"
                    :placeholder="$t('subscriptions.cancel_reason_placeholder')"
                    :maxlength="500"
                    showCount
                    autofocus
                />
            </FormItem>
        </Form>
    </Modal>
</template>

<style scoped>
.mb-3 { margin-bottom: 16px; }
</style>
