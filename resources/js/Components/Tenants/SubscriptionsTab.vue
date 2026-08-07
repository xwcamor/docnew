<script setup>
/**
 * Tab "Suscripción" del Show de Tenants. Reúne:
 *   - Card con la sub activa (o estado vacío si no hay)
 *   - Botones: nueva, renovar, cancelar (con tooltip + spring hover heredados)
 *   - Tabla con histórico de subs anteriores
 *
 * Toda la lógica de crear/renovar/cancelar vive en los modales hijos.
 */
import { computed, ref } from 'vue';
import {
    Card, Tag, Button, Space, Descriptions, DescriptionsItem, Empty, Table,
    Tooltip, Alert,
} from 'ant-design-vue';
import {
    CrownOutlined, PlusOutlined, ReloadOutlined, CloseCircleOutlined,
    ClockCircleOutlined, CheckCircleOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import { useI18n } from '@/Plugins/i18n';
import { useDateFormat } from '@/Composables/useDateFormat';

import SubscriptionFormModal from './SubscriptionFormModal.vue';
import SubscriptionCancelModal from './SubscriptionCancelModal.vue';
import PlansInfoModal from '@/Components/Common/PlansInfoModal.vue';

const { t } = useI18n();
const { formatDate } = useDateFormat();

const props = defineProps({
    tenantSlug:           { type: String, required: true },
    currentPlan:          { type: String, default: 'free' },
    activeSubscription:   { type: Object, default: null },
    subscriptionsHistory: { type: Array,  default: () => [] },
    availablePlans:       { type: Array,  default: () => ['free', 'pro', 'enterprise'] },
    plansComparison:      { type: Array,  default: () => [] },
});

const statusColor = (status) => ({
    trial:     'blue',
    active:    'success',
    expired:   'default',
    suspended: 'warning',
    cancelled: 'default',
}[status] ?? 'default');

const planColor = (plan) => ({
    free:       'default',
    pro:        'blue',
    enterprise: 'gold',
}[plan] ?? 'default');

// Delegamos al composable centralizado: usa el TZ del usuario y el formato
// dd-mm-aaaa global. dayjs sigue importado por otras vistas pero ya no se
// usa para format() — el helper de formato es uno solo para toda la app.
const fmtDate = (d) => formatDate(d);
const fmtMoney = (amount, currency) => {
    if (amount === null || amount === undefined) return '—';
    return `${currency || 'USD'} ${Number(amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
};

const formOpen   = ref(false);
const formMode   = ref('create');
const cancelOpen = ref(false);
const plansOpen  = ref(false);

const openCreate = () => { formMode.value = 'create'; formOpen.value = true; };
const openRenew  = () => { formMode.value = 'renew';  formOpen.value = true; };
const openCancel = () => { cancelOpen.value = true; };
const openPlans  = () => { plansOpen.value = true; };

const showExpirationWarning = computed(() =>
    props.activeSubscription
        && props.activeSubscription.days_remaining > 0
        && props.activeSubscription.days_remaining <= 7,
);

const historyColumns = computed(() => [
    { title: t('subscriptions.plan'),           dataIndex: 'plan',           key: 'plan',       width: 110 },
    { title: t('subscriptions.status'),         dataIndex: 'status',         key: 'status',     width: 110 },
    { title: t('subscriptions.starts_at'),      dataIndex: 'starts_at',      key: 'starts_at',  width: 130 },
    { title: t('subscriptions.ends_at'),        dataIndex: 'ends_at',        key: 'ends_at',    width: 130 },
    { title: t('subscriptions.amount_paid'),    dataIndex: 'amount_paid',    key: 'amount',     width: 140 },
    { title: t('subscriptions.payment_method'), dataIndex: 'payment_method', key: 'method',     width: 130 },
    { title: t('subscriptions.notes'),          dataIndex: 'notes',          key: 'notes',      ellipsis: true },
]);
</script>

<template>
    <div class="subs-tab">
        <!-- Banner si expira pronto -->
        <Alert
            v-if="showExpirationWarning"
            type="warning"
            show-icon
            class="mb-3"
            :message="$t('subscriptions.expires_in_warning', { days: activeSubscription.days_remaining })"
        />

        <!-- Sub activa -->
        <Card :bodyStyle="{ padding: 20 }" class="info-card">
            <template #title>
                <Space>
                    <CrownOutlined />
                    <span>{{ $t('subscriptions.current_title') }}</span>
                </Space>
            </template>
            <template #extra>
                <Space wrap>
                    <Tooltip :title="$t('plans.view_plans_hint')">
                        <Button @click="openPlans">
                            <CrownOutlined /> {{ $t('plans.view_plans') }}
                        </Button>
                    </Tooltip>
                    <Tooltip :title="$t('subscriptions.create_hint')">
                        <Button type="primary" @click="openCreate">
                            <PlusOutlined /> {{ $t('subscriptions.create') }}
                        </Button>
                    </Tooltip>
                    <Tooltip v-if="activeSubscription" :title="$t('subscriptions.renew_hint')">
                        <Button @click="openRenew">
                            <ReloadOutlined /> {{ $t('subscriptions.renew') }}
                        </Button>
                    </Tooltip>
                    <Tooltip v-if="activeSubscription" :title="$t('subscriptions.cancel_hint')">
                        <Button danger @click="openCancel">
                            <CloseCircleOutlined /> {{ $t('subscriptions.cancel') }}
                        </Button>
                    </Tooltip>
                </Space>
            </template>

            <Empty
                v-if="!activeSubscription"
                :description="$t('subscriptions.no_active_hint')"
            >
                <template #image>
                    <ClockCircleOutlined style="font-size: 56px; color: var(--color-text-muted);" />
                </template>
            </Empty>

            <Descriptions v-else :column="{ xs: 1, md: 2 }" bordered :labelStyle="{ width: '180px' }">
                <DescriptionsItem :label="$t('subscriptions.plan')">
                    <Tag :color="planColor(activeSubscription.plan)" :bordered="false">
                        <CheckCircleOutlined /> {{ activeSubscription.plan.toUpperCase() }}
                    </Tag>
                </DescriptionsItem>
                <DescriptionsItem :label="$t('subscriptions.status')">
                    <Tag :color="statusColor(activeSubscription.status)" :bordered="false">
                        {{ $t(`subscriptions.status_${activeSubscription.status}`) }}
                    </Tag>
                </DescriptionsItem>
                <DescriptionsItem :label="$t('subscriptions.starts_at')">
                    {{ fmtDate(activeSubscription.starts_at) }}
                </DescriptionsItem>
                <DescriptionsItem :label="$t('subscriptions.ends_at')">
                    {{ fmtDate(activeSubscription.ends_at) }}
                </DescriptionsItem>
                <DescriptionsItem :label="$t('subscriptions.days_remaining')">
                    <strong>{{ activeSubscription.days_remaining }}</strong>
                </DescriptionsItem>
                <DescriptionsItem v-if="activeSubscription.is_trial" :label="$t('subscriptions.trial_ends_at')">
                    {{ fmtDate(activeSubscription.trial_ends_at) }}
                </DescriptionsItem>
                <DescriptionsItem :label="$t('subscriptions.amount_paid')">
                    {{ fmtMoney(activeSubscription.amount_paid, activeSubscription.currency) }}
                </DescriptionsItem>
                <DescriptionsItem :label="$t('subscriptions.payment_method')">
                    {{ activeSubscription.payment_method
                        ? $t(`subscriptions.payment_method_options.${activeSubscription.payment_method}`)
                        : '—' }}
                </DescriptionsItem>
            </Descriptions>
        </Card>

        <!-- Histórico -->
        <Card :bodyStyle="{ padding: 16 }" class="info-card">
            <template #title>
                <Space>
                    <ClockCircleOutlined />
                    <span>{{ $t('subscriptions.history_title') }}</span>
                    <Tag :bordered="false">{{ subscriptionsHistory.length }}</Tag>
                </Space>
            </template>

            <Empty
                v-if="subscriptionsHistory.length === 0"
                :description="$t('subscriptions.history_empty')"
            />

            <Table
                v-else
                :data-source="subscriptionsHistory"
                :columns="historyColumns"
                :pagination="false"
                row-key="id"
                size="small"
                class="history-table"
            >
                <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'plan'">
                        <Tag :color="planColor(record.plan)" :bordered="false">
                            {{ record.plan.toUpperCase() }}
                        </Tag>
                    </template>
                    <template v-else-if="column.key === 'status'">
                        <Tag :color="statusColor(record.status)" :bordered="false">
                            {{ $t(`subscriptions.status_${record.status}`) }}
                        </Tag>
                    </template>
                    <template v-else-if="column.key === 'starts_at'">{{ fmtDate(record.starts_at) }}</template>
                    <template v-else-if="column.key === 'ends_at'">{{ fmtDate(record.ends_at) }}</template>
                    <template v-else-if="column.key === 'amount'">
                        {{ fmtMoney(record.amount_paid, record.currency) }}
                    </template>
                    <template v-else-if="column.key === 'method'">
                        {{ record.payment_method
                            ? $t(`subscriptions.payment_method_options.${record.payment_method}`)
                            : '—' }}
                    </template>
                    <template v-else-if="column.key === 'notes'">
                        <span class="notes-cell">{{ record.notes || '—' }}</span>
                    </template>
                </template>
            </Table>
        </Card>

        <!-- Modales -->
        <SubscriptionFormModal
            v-model:open="formOpen"
            :mode="formMode"
            :tenant-slug="tenantSlug"
            :available-plans="availablePlans"
        />
        <SubscriptionCancelModal
            v-model:open="cancelOpen"
            :tenant-slug="tenantSlug"
            :subscription="activeSubscription"
        />
        <PlansInfoModal v-model:open="plansOpen" :plans="plansComparison" />
    </div>
</template>

<style scoped>
.subs-tab { padding: 4px 0; }
.info-card { margin-bottom: 16px; border-radius: 6px; }
.mb-3 { margin-bottom: 16px; }
.notes-cell {
    font-size: 0.8125rem;
    color: var(--color-text-muted);
}
.history-table :deep(.ant-table-thead > tr > th) {
    background: var(--color-surface-alt);
    font-weight: 600;
    font-size: 0.8125rem;
}
</style>
