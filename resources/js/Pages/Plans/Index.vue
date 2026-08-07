<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Tooltip,
} from 'ant-design-vue';
import {
    EditOutlined, BankOutlined,
    PlusOutlined, CheckCircleOutlined, CloseCircleOutlined,
    InboxOutlined,
} from '@ant-design/icons-vue';
import { resolveIconComponent, resolveColor } from '@/Utils/planAppearance';
import { useI18n } from '@/Plugins/i18n';

import AppLayout from '@/Layouts/AppLayout.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import ColumnSelector from '@/Components/Common/ColumnSelector.vue';
import PlansPageHeader from '@/Components/Plans/PlansPageHeader.vue';
import PlansActionsCell from '@/Components/Plans/PlansActionsCell.vue';
import { useAuth } from '@/Composables/useAuth';
import { useModuleListMeta } from '@/Composables/useModuleListMeta';
import { useColumnPreferences } from '@/Composables/useColumnPreferences';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const { isSuper } = useAuth();

const props = defineProps({
    plans: { type: Array, required: true },
});

// Row pulse + counter (mismo helper que Regions).
const fakePagination = computed(() => ({
    total: props.plans.length,
    total_unfiltered: props.plans.length,
}));
const hasActiveFilters = ref(false);
const { isHighlighted, counterLabel } = useModuleListMeta({
    pagination: fakePagination,
    hasActiveFilters,
    t,
});

const fmtLimit = (n) => n < 0 ? '∞' : n.toLocaleString();
const fmtMoney = (n, c) => n > 0 ? `${c} ${Number(n).toFixed(2)}` : '—';

// Color e icono ahora vienen de DB (campos `color` e `icon` editables desde
// el form). Si el plan no tiene color guardado, cae a default.
const planTagColor = (plan) => resolveColor(plan.color);
const planIcon     = (plan) => resolveIconComponent(plan.icon);

// Soft-delete → Delete page (motivo obligatorio, mismo patrón Regions).
const goToDelete = (plan) => {
    router.visit(route('system_management.plans.delete', plan.id));
};

// Columnas con sorters client-side. El dataset es chico (4-10 planes típico),
// no hace falta sort server-side.
const allColumns = computed(() => [
    { title: t('plans.col_plan'),          dataIndex: 'name',                   key: 'name',     width: 200, alwaysVisible: true, mobile: { role: 'title' },
      sorter: (a, b) => a.name.localeCompare(b.name) },
    { title: t('plans.tagline'),           dataIndex: 'tagline',                key: 'tagline',  ellipsis: true, mobile: { role: 'meta' },
      sorter: (a, b) => (a.tagline || '').localeCompare(b.tagline || '') },
    { title: t('plans.col_users'),         dataIndex: 'max_users',              key: 'users',    width: 100, align: 'center', mobile: { role: 'meta' },
      sorter: (a, b) => a.max_users - b.max_users },
    { title: t('plans.col_records'),       dataIndex: 'max_records_per_module', key: 'records',  width: 120, align: 'center', mobile: { role: 'meta' },
      sorter: (a, b) => a.max_records_per_module - b.max_records_per_module },
    { title: t('plans.col_api'),           key: 'api',                          width: 80,  align: 'center', mobile: { role: 'meta' },
      sorter: (a, b) => Number(a.features?.api_access ?? 0) - Number(b.features?.api_access ?? 0) },
    { title: t('plans.col_price_monthly'), dataIndex: 'price_monthly',          key: 'monthly',  width: 120, align: 'right', mobile: { role: 'meta' },
      sorter: (a, b) => a.price_monthly - b.price_monthly },
    { title: t('plans.col_price_yearly'),  dataIndex: 'price_yearly',           key: 'yearly',   width: 120, align: 'right', mobile: { role: 'meta' }, defaultHidden: true,
      sorter: (a, b) => a.price_yearly - b.price_yearly },
    { title: t('plans.tab_tenants'),       dataIndex: 'tenants_count',          key: 'tenants',  width: 100, align: 'center', mobile: { role: 'meta' },
      sorter: (a, b) => a.tenants_count - b.tenants_count },
    { title: t('plans.col_status'),        key: 'status',                       width: 100, align: 'center', mobile: { role: 'status' },
      sorter: (a, b) => Number(b.is_active) - Number(a.is_active) },
    { title: t('global.actions'),          key: 'actions',                      width: 130, fixed: 'right', align: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
]);

const { visibleColumnKeys, columns } = useColumnPreferences(allColumns);
</script>

<template>
    <Head :title="$t('plans.index_title')" />

    <div class="sap-index">
        <div class="mi-title" data-tour="module">
            <PlansPageHeader
                :title="$t('plans.index_title')"
            />

            <div class="mi-title__actions">
                <Tooltip v-if="isSuper" :title="$t('global.view_deleted_hint')">
                    <Link :href="route('system_management.plans.trash')">
                        <Button>
                            <InboxOutlined /> {{ $t('global.view_deleted') }}
                        </Button>
                    </Link>
                </Tooltip>
                <ColumnSelector
                    :columns="allColumns"
                    :model-value="visibleColumnKeys"
                    @update:model-value="visibleColumnKeys = $event"
                />
                <Tooltip :title="$t('plans.create_hint')">
                    <Link :href="route('system_management.plans.create')">
                        <Button type="primary">
                            <PlusOutlined /> {{ $t('plans.create') }}
                        </Button>
                    </Link>
                </Tooltip>
            </div>
        </div>

        <Card :bodyStyle="{ padding: 0 }" class="grid-card">
            <ResponsiveTable
                :dataSource="plans"
                :columns="columns"
                :pagination="false"
                :row-class-name="(record) => isHighlighted(record.id) ? 'row-highlight' : ''"
                rowKey="id"
            >
                <template #bodyCell="{ column, record, isMobile }">
                    <template v-if="column.key === 'name'">
                        <Tag :color="planTagColor(record)" :bordered="false" class="plan-tag">
                            <component :is="planIcon(record)" v-if="planIcon(record)" />
                            {{ record.name.toUpperCase() }}
                        </Tag>
                    </template>

                    <template v-else-if="column.key === 'tagline'">
                        <span class="tagline">{{ record.tagline || '—' }}</span>
                    </template>

                    <template v-else-if="column.key === 'users'">
                        <strong>{{ fmtLimit(record.max_users) }}</strong>
                    </template>

                    <template v-else-if="column.key === 'records'">
                        <strong>{{ fmtLimit(record.max_records_per_module) }}</strong>
                    </template>

                    <template v-else-if="column.key === 'api'">
                        <CheckCircleOutlined v-if="record.features?.api_access" class="icon-yes" />
                        <CloseCircleOutlined v-else class="icon-no" />
                    </template>

                    <template v-else-if="column.key === 'monthly'">
                        {{ fmtMoney(record.price_monthly, record.currency) }}
                    </template>

                    <template v-else-if="column.key === 'yearly'">
                        {{ fmtMoney(record.price_yearly, record.currency) }}
                    </template>

                    <template v-else-if="column.key === 'tenants'">
                        <Tooltip :title="$t('plans.tenants_count_hint')">
                            <Tag :color="record.tenants_count > 0 ? 'cyan' : 'default'" :bordered="false">
                                <BankOutlined /> {{ record.tenants_count }}
                            </Tag>
                        </Tooltip>
                    </template>

                    <template v-else-if="column.key === 'status'">
                        <Tag :color="record.is_active ? 'success' : 'error'" :bordered="false">
                            {{ record.is_active ? $t('global.active') : $t('global.inactive') }}
                        </Tag>
                    </template>

                    <PlansActionsCell
                        v-else-if="column.key === 'actions'"
                        :record="record"
                        :is-mobile="isMobile"
                        @delete="goToDelete"
                    />
                </template>
            </ResponsiveTable>
        </Card>
    </div>
</template>

<style scoped>
.grid-card { border-radius: 6px; transition: box-shadow 0.18s ease; }
.grid-card:hover { box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08); }

.plan-tag { font-weight: 600; letter-spacing: 0.3px; }
.tagline { color: var(--color-text-muted); font-size: 0.8125rem; }
.icon-yes { color: #52c41a; font-size: 18px; }
.icon-no  { color: #d9d9d9; font-size: 18px; }

/* Estética igual que Regions/Languages. */
.grid-card :deep(.ant-table-thead > tr > th) {
    background: var(--color-surface-alt);
    color: var(--color-text-strong);
    font-weight: 600;
    font-size: 0.8125rem;
}
.grid-card :deep(.ant-table-tbody > tr) {
    transition: background-color 0.15s ease;
    /* Stagger entrance: cada fila aparece con un pequeño delay (mismo patrón Regions). */
    animation: row-fade-in 0.32s ease-out both;
}
.grid-card :deep(.ant-table-tbody > tr:hover > td) { background: var(--color-surface-hover) !important; }

.grid-card :deep(.ant-table-tbody > tr:nth-child(1))  { animation-delay: 0ms; }
.grid-card :deep(.ant-table-tbody > tr:nth-child(2))  { animation-delay: 30ms; }
.grid-card :deep(.ant-table-tbody > tr:nth-child(3))  { animation-delay: 60ms; }
.grid-card :deep(.ant-table-tbody > tr:nth-child(4))  { animation-delay: 90ms; }
.grid-card :deep(.ant-table-tbody > tr:nth-child(5))  { animation-delay: 120ms; }
.grid-card :deep(.ant-table-tbody > tr:nth-child(6))  { animation-delay: 150ms; }
.grid-card :deep(.ant-table-tbody > tr:nth-child(7))  { animation-delay: 180ms; }
.grid-card :deep(.ant-table-tbody > tr:nth-child(8))  { animation-delay: 210ms; }
.grid-card :deep(.ant-table-tbody > tr:nth-child(9))  { animation-delay: 240ms; }
.grid-card :deep(.ant-table-tbody > tr:nth-child(10)) { animation-delay: 270ms; }

@keyframes row-fade-in {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Hover-to-reveal de acciones (patrón Notion/Linear, igual que Regions). */
.grid-card :deep(.ant-table-tbody .row-actions-desktop) {
    opacity: 0.45;
    transition: opacity 0.15s ease;
}
.grid-card :deep(.ant-table-tbody > tr:hover .row-actions-desktop),
.grid-card :deep(.ant-table-tbody .row-actions-desktop:focus-within) {
    opacity: 1;
}

</style>
