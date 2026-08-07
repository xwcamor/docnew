<script setup>
/**
 * Modal compartido para crear o renovar una subscription.
 *
 * Modos del modal:
 *   - mode="create":  endpoint store. Soporta kind=paid o kind=trial.
 *   - mode="renew":   endpoint renew. Solo kind=paid (no se renueva con trial).
 *
 * El "kind" decide qué campos mostrar (paid → ends_at + monto, trial → días).
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    Modal, Form, FormItem, Input, InputNumber, Select, SelectOption,
    Radio, RadioGroup, DatePicker, Row, Col, Alert,
} from 'ant-design-vue';
import dayjs from 'dayjs';

const props = defineProps({
    open:           { type: Boolean, default: false },
    mode:           { type: String, default: 'create' }, // 'create' | 'renew'
    tenantSlug:     { type: String, required: true },
    availablePlans: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:open', 'success']);

const PAYMENT_METHODS = ['manual', 'bank_transfer', 'stripe', 'paddle', 'cash', 'other'];

const form = useForm({
    kind:           'paid',
    plan:           'pro',
    starts_at:      null,
    ends_at:        null,
    trial_days:     14,
    amount_paid:    null,
    currency:       'USD',
    payment_method: 'manual',
    notes:          '',
});

// En renew solo aceptamos paid (no tiene sentido renovar con trial).
const allowTrial = computed(() => props.mode === 'create');

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
        form.kind = 'paid';
        form.plan = props.availablePlans[1] ?? props.availablePlans[0] ?? null;
        form.starts_at = dayjs();
        form.ends_at   = dayjs().add(1, 'year');
    }
});

watch(() => form.kind, (kind) => {
    if (kind === 'trial') {
        form.ends_at = null;
        form.amount_paid = 0;
    } else if (!form.ends_at) {
        form.ends_at = dayjs().add(1, 'year');
    }
});

const submit = () => {
    const payload = {
        kind:           form.kind,
        plan:           form.plan,
        starts_at:      form.starts_at?.format('YYYY-MM-DD'),
        ends_at:        form.ends_at?.format('YYYY-MM-DD'),
        trial_days:     form.kind === 'trial' ? form.trial_days : null,
        amount_paid:    form.amount_paid,
        currency:       form.currency,
        payment_method: form.payment_method,
        notes:          form.notes,
    };

    const url = props.mode === 'renew'
        ? route('system_management.tenants.subscriptions.renew', props.tenantSlug)
        : route('system_management.tenants.subscriptions.store', props.tenantSlug);

    form.transform(() => payload).post(url, {
        preserveScroll: true,
        onSuccess: () => {
            emit('success');
            emit('update:open', false);
        },
    });
};

const closeModal = () => {
    if (form.processing) return;
    emit('update:open', false);
};

const title = computed(() =>
    props.mode === 'renew' ? 'subscriptions.renew' : 'subscriptions.create',
);
</script>

<template>
    <Modal
        :open="open"
        :title="$t(title)"
        :confirm-loading="form.processing"
        :ok-text="$t('global.save')"
        :cancel-text="$t('global.cancel')"
        @ok="submit"
        @cancel="closeModal"
        :width="720"
        destroy-on-close
    >
        <Form layout="vertical" @submit.prevent="submit">
            <Alert
                v-if="mode === 'renew'"
                type="info"
                show-icon
                class="mb-3"
                :message="$t('subscriptions.renew_hint')"
            />

            <FormItem v-if="allowTrial" :label="$t('subscriptions.kind')">
                <RadioGroup v-model:value="form.kind" button-style="solid">
                    <Radio value="paid">{{ $t('subscriptions.kind_paid') }}</Radio>
                    <Radio value="trial">{{ $t('subscriptions.kind_trial') }}</Radio>
                </RadioGroup>
                <p class="hint">
                    {{ form.kind === 'trial'
                        ? $t('subscriptions.kind_trial_hint')
                        : $t('subscriptions.kind_paid_hint') }}
                </p>
            </FormItem>

            <Row :gutter="[20, 0]">
                <Col :xs="24" :md="12">
                    <FormItem
                        :label="$t('subscriptions.plan')"
                        required
                        :validate-status="form.errors.plan ? 'error' : ''"
                        :help="form.errors.plan"
                    >
                        <Select v-model:value="form.plan" size="large">
                            <SelectOption v-for="p in availablePlans" :key="p" :value="p">
                                {{ p.toUpperCase() }}
                            </SelectOption>
                        </Select>
                    </FormItem>
                </Col>

                <Col :xs="24" :md="12">
                    <FormItem
                        :label="$t('subscriptions.starts_at')"
                        :validate-status="form.errors.starts_at ? 'error' : ''"
                        :help="form.errors.starts_at"
                    >
                        <DatePicker v-model:value="form.starts_at" size="large" style="width: 100%" />
                    </FormItem>
                </Col>

                <Col v-if="form.kind === 'paid'" :xs="24" :md="12">
                    <FormItem
                        :label="$t('subscriptions.ends_at')"
                        required
                        :validate-status="form.errors.ends_at ? 'error' : ''"
                        :help="form.errors.ends_at"
                    >
                        <DatePicker v-model:value="form.ends_at" size="large" style="width: 100%" />
                    </FormItem>
                </Col>

                <Col v-else :xs="24" :md="12">
                    <FormItem
                        :label="$t('subscriptions.trial_days')"
                        required
                        :validate-status="form.errors.trial_days ? 'error' : ''"
                        :help="form.errors.trial_days"
                    >
                        <InputNumber
                            v-model:value="form.trial_days"
                            :min="1"
                            :max="365"
                            size="large"
                            style="width: 100%"
                        />
                    </FormItem>
                </Col>

                <Col v-if="form.kind === 'paid'" :xs="24" :md="8">
                    <FormItem
                        :label="$t('subscriptions.amount_paid')"
                        :validate-status="form.errors.amount_paid ? 'error' : ''"
                        :help="form.errors.amount_paid"
                    >
                        <InputNumber
                            v-model:value="form.amount_paid"
                            :min="0"
                            :step="0.01"
                            :precision="2"
                            size="large"
                            style="width: 100%"
                        />
                    </FormItem>
                </Col>

                <Col v-if="form.kind === 'paid'" :xs="12" :md="4">
                    <FormItem
                        :label="$t('subscriptions.currency')"
                        :validate-status="form.errors.currency ? 'error' : ''"
                        :help="form.errors.currency"
                    >
                        <Input
                            v-model:value="form.currency"
                            size="large"
                            :maxlength="3"
                            style="text-transform: uppercase;"
                        />
                    </FormItem>
                </Col>

                <Col v-if="form.kind === 'paid'" :xs="24" :md="12">
                    <FormItem
                        :label="$t('subscriptions.payment_method')"
                        :validate-status="form.errors.payment_method ? 'error' : ''"
                        :help="form.errors.payment_method"
                    >
                        <Select v-model:value="form.payment_method" size="large">
                            <SelectOption v-for="m in PAYMENT_METHODS" :key="m" :value="m">
                                {{ $t(`subscriptions.payment_method_options.${m}`) }}
                            </SelectOption>
                        </Select>
                    </FormItem>
                </Col>

                <Col :xs="24">
                    <FormItem
                        :label="$t('subscriptions.notes')"
                        :validate-status="form.errors.notes ? 'error' : ''"
                        :help="form.errors.notes"
                    >
                        <Input.TextArea
                            v-model:value="form.notes"
                            :rows="3"
                            :maxlength="2000"
                            showCount
                        />
                    </FormItem>
                </Col>
            </Row>
        </Form>
    </Modal>
</template>

<style scoped>
.hint {
    font-size: 0.8125rem;
    color: var(--color-text-muted);
    margin: 6px 0 0 0;
    line-height: 1.4;
}
.mb-3 { margin-bottom: 16px; }
</style>
