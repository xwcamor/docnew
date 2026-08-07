<script setup>
import { ref, watch, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Button, Card, Space, Tag, Select, SelectOption, Input, DatePicker,
    Drawer, Descriptions, DescriptionsItem, Tooltip, Empty,
} from 'ant-design-vue';
import {
    AuditOutlined, EyeOutlined, ReloadOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';

import AppLayout from '@/Layouts/AppLayout.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import ColumnSelector from '@/Components/Common/ColumnSelector.vue';
import { usePageLoading } from '@/Composables/usePageLoading';
import { useColumnPreferences } from '@/Composables/useColumnPreferences';
import { useDateFormat } from '@/Composables/useDateFormat';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();
const { formatDateTime } = useDateFormat();

defineOptions({ layout: AppLayout });

const props = defineProps({
    logs:    { type: Object, required: true },
    modules: { type: Array,  required: true },
    events:  { type: Array,  required: true },
    users:   { type: Array,  default: () => [] },
    filters: { type: Object, required: true },
});

const page = usePage();

// Belt-and-suspenders: redirect if user isn't super or admin.
const userRoles = page.props.auth?.user?.roles ?? [];
const canAccess = userRoles.includes('super') || userRoles.includes('admin');
if (!canAccess) {
    router.visit('/');
}

// ─── Filtros locales ───────────────────────────────────────────────────────
const localFilters = ref({
    module:       props.filters.module || undefined,
    event:        props.filters.event || undefined,
    user_id:      props.filters.user_id ? Number(props.filters.user_id) : undefined,
    auditable_id: props.filters.auditable_id || '',
    date_range:   (props.filters.date_from && props.filters.date_to)
        ? [dayjs(props.filters.date_from), dayjs(props.filters.date_to)]
        : null,
});

const reload = (extra = {}) => {
    router.reload({
        only: ['logs', 'filters'],
        data: {
            module:       localFilters.value.module || undefined,
            event:        localFilters.value.event || undefined,
            user_id:      localFilters.value.user_id || undefined,
            auditable_id: localFilters.value.auditable_id || undefined,
            date_from:    localFilters.value.date_range?.[0]?.format('YYYY-MM-DD') ?? undefined,
            date_to:      localFilters.value.date_range?.[1]?.format('YYYY-MM-DD') ?? undefined,
            per_page:     props.filters.per_page,
            page:         1,
            ...extra,
        },
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

// Debounced reload on filter change
let timer = null;
watch(localFilters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => reload(), 300);
}, { deep: true });

const clearAll = () => {
    localFilters.value = {
        module: undefined, event: undefined, user_id: undefined, auditable_id: '', date_range: null,
    };
};

// ─── Drawer de detalle ─────────────────────────────────────────────────────
const drawerOpen = ref(false);
const selectedLog = ref(null);
const openDetails = (log) => { selectedLog.value = log; drawerOpen.value = true; };

// Pretty-print JSON for old/new values.
const prettyJson = (obj) => {
    if (!obj || Object.keys(obj).length === 0) return null;
    return JSON.stringify(obj, null, 2);
};

// Color de tag según evento
const eventColor = (event) => ({
    created:        'green',
    updated:        'blue',
    deleted:        'orange',
    force_deleted:  'red',
    restored:       'cyan',
    login:          'purple',
    logout:         'default',
}[event] || 'default');

const eventLabel = (event) => ({
    created:        t('audit_logs.event_created'),
    updated:        t('audit_logs.event_updated'),
    deleted:        t('audit_logs.event_deleted'),
    force_deleted:  t('audit_logs.event_force_deleted'),
    restored:       t('audit_logs.event_restored'),
    login:          t('audit_logs.event_login'),
    logout:         t('audit_logs.event_logout'),
}[event] || event);

// ─── Columnas ──────────────────────────────────────────────────────────────
const allColumns = computed(() => [
    { title: t('audit_logs.col_date'),   dataIndex: 'created_at',    key: 'created_at', width: 170, alwaysVisible: true, mobile: { role: 'meta' } },
    { title: t('audit_logs.col_event'),  dataIndex: 'event',         key: 'event',      width: 130, mobile: { role: 'status' } },
    { title: t('audit_logs.col_module'), dataIndex: 'module',        key: 'module',     width: 120, mobile: { role: 'meta' } },
    { title: t('audit_logs.col_record'), dataIndex: 'auditable_id',  key: 'auditable_id', width: 110, mobile: { role: 'meta' } },
    { title: t('audit_logs.col_user'),   dataIndex: ['user', 'name'], key: 'user',      width: 200, mobile: { role: 'title' } },
    { title: t('audit_logs.col_url'),    dataIndex: 'url',           key: 'url',        ellipsis: true, mobile: { role: 'subtitle' } },
    { title: t('global.actions'),         key: 'actions',             width: 60,  fixed: 'right', alwaysVisible: true, mobile: { role: 'actions' } },
]);
const { visibleColumnKeys, columns } = useColumnPreferences(allColumns);

const tablePagination = computed(() => ({
    current:  props.logs.current_page,
    pageSize: props.logs.per_page,
    total:    props.logs.total,
    showSizeChanger: true,
    pageSizeOptions: ['10', '25', '50', '100', '200'],
    showTotal: (total, range) => `${range[0]}-${range[1]} ${t('global.of') || 'de'} ${total}`,
}));

const onTableChange = (pag) => {
    reload({ page: pag.current, per_page: pag.pageSize });
};

// ─── Loading ───────────────────────────────────────────────────────────────
// `propKey='logs'` filtra el polling cross-módulo del inbox (cada 4s en
// AppLayout): solo activamos el spinner cuando realmente se está
// refrescando este listado.
const { loading: tableLoading } = usePageLoading('/audit_logs', 'logs');
</script>

<template>
    <Head :title="$t('audit_logs.title')" />

    <div v-if="canAccess" class="sap-index">
        <!-- Header -->
        <div class="mi-title" data-tour="module">
            <div class="page-header__title">
                <div class="page-header__icon">
                    <AuditOutlined />
                </div>
                <div class="page-header__heading">
                    <h1>{{ $t('audit_logs.title') }}</h1>
                    <p>{{ logs.total }} {{ logs.total === 1 ? $t('audit_logs.events_singular') : $t('audit_logs.events_plural') }} · {{ $t('audit_logs.visible_for') }}</p>
                </div>
            </div>

            <div class="mi-title__actions">
                <Space wrap>
                    <ColumnSelector
                        :columns="allColumns"
                        v-model="visibleColumnKeys"
                        storage-key="audit_logs"
                    />
                    <Button @click="clearAll">
                        <ReloadOutlined /> {{ $t('audit_logs.clear_filters') }}
                    </Button>
                </Space>
            </div>
        </div>

        <!-- Filtros: una sola barra responsive (hace wrap en pantallas chicas). -->
        <Card class="filters-card" :bodyStyle="{ padding: '14px 16px' }">
            <Space wrap :size="12" style="width: 100%">
                <Select
                    v-model:value="localFilters.module"
                    :placeholder="$t('audit_logs.filter_module')"
                    allow-clear
                    style="width: 180px"
                >
                    <SelectOption v-for="m in modules" :key="m" :value="m">{{ m }}</SelectOption>
                </Select>

                <Select
                    v-model:value="localFilters.event"
                    :placeholder="$t('audit_logs.filter_event')"
                    allow-clear
                    style="width: 180px"
                >
                    <SelectOption v-for="e in events" :key="e" :value="e">
                        {{ eventLabel(e) }}
                    </SelectOption>
                </Select>

                <Select
                    v-model:value="localFilters.user_id"
                    :placeholder="$t('audit_logs.filter_user')"
                    allow-clear
                    show-search
                    option-filter-prop="label"
                    style="width: 240px"
                    :options="users"
                />

                <Input
                    v-model:value="localFilters.auditable_id"
                    :placeholder="$t('audit_logs.filter_record_id')"
                    allow-clear
                    style="width: 140px"
                    type="number"
                />

                <DatePicker.RangePicker
                    v-model:value="localFilters.date_range"
                    style="width: 260px"
                    :placeholder="[$t('audit_logs.filter_from'), $t('audit_logs.filter_to')]"
                />
            </Space>
        </Card>

        <!-- Tabla -->
        <Card :bodyStyle="{ padding: 0 }" class="grid-card">
            <ResponsiveTable
                :dataSource="logs.data"
                :columns="columns"
                :pagination="tablePagination"
                :loading="tableLoading"
                rowKey="id"
                @change="onTableChange"
                @row-click="openDetails"
            >
                <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'created_at'">
                        {{ formatDateTime(record.created_at) }}
                    </template>

                    <template v-else-if="column.key === 'event'">
                        <Tag :color="eventColor(record.event)" :bordered="false">
                            {{ eventLabel(record.event) }}
                        </Tag>
                    </template>

                    <template v-else-if="column.key === 'module'">
                        <Tag :bordered="false">{{ record.module ?? '—' }}</Tag>
                    </template>

                    <template v-else-if="column.key === 'user'">
                        <div v-if="record.user">
                            <div class="user-name">{{ record.user.name }}</div>
                            <div class="user-email">{{ record.user.email }}</div>
                        </div>
                        <span v-else class="text-muted">{{ $t('audit_logs.system') }}</span>
                    </template>

                    <template v-else-if="column.key === 'url'">
                        <Tooltip v-if="record.url" :title="record.url">
                            <a
                                :href="record.url"
                                class="url-cell"
                                @click.stop
                            >
                                {{ $t('audit_logs.go_to_record') }}
                            </a>
                        </Tooltip>
                        <span v-else class="text-muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'actions'">
                        <Tooltip :title="$t('audit_logs.view_detail')">
                            <Button size="small" type="text" @click.stop="openDetails(record)">
                                <EyeOutlined />
                            </Button>
                        </Tooltip>
                    </template>
                </template>

                <template #empty>
                    <div class="empty-state">
                        <AuditOutlined class="empty-state__icon" />
                        <h3>{{ $t('audit_logs.empty_title') }}</h3>
                        <p>{{ $t('audit_logs.empty_desc') }}</p>
                    </div>
                </template>
            </ResponsiveTable>
        </Card>

        <!-- Drawer de detalle -->
        <Drawer
            v-model:open="drawerOpen"
            :title="$t('audit_logs.drawer_title')"
            :width="560"
            placement="right"
        >
            <template v-if="selectedLog">
                <Descriptions :column="1" bordered>
                    <DescriptionsItem :label="$t('audit_logs.detail_id')">{{ selectedLog.id }}</DescriptionsItem>
                    <DescriptionsItem :label="$t('audit_logs.detail_date')">
                        {{ formatDateTime(selectedLog.created_at) }}
                    </DescriptionsItem>
                    <DescriptionsItem :label="$t('audit_logs.detail_event')">
                        <Tag :color="eventColor(selectedLog.event)" :bordered="false">
                            {{ eventLabel(selectedLog.event) }}
                        </Tag>
                    </DescriptionsItem>
                    <DescriptionsItem :label="$t('audit_logs.detail_module')">{{ selectedLog.module ?? '—' }}</DescriptionsItem>
                    <DescriptionsItem :label="$t('audit_logs.detail_model')">
                        <code>{{ selectedLog.auditable_type }}</code>
                    </DescriptionsItem>
                    <DescriptionsItem :label="$t('audit_logs.detail_record_id')">{{ selectedLog.auditable_id ?? '—' }}</DescriptionsItem>
                    <DescriptionsItem :label="$t('audit_logs.detail_user')">
                        <div v-if="selectedLog.user">
                            {{ selectedLog.user.name }}
                            <span class="user-email">({{ selectedLog.user.email }})</span>
                        </div>
                        <span v-else class="text-muted">{{ $t('audit_logs.system') }}</span>
                    </DescriptionsItem>
                    <DescriptionsItem :label="$t('audit_logs.detail_url')">
                        <a v-if="selectedLog.url" :href="selectedLog.url" class="url-detail">
                            {{ selectedLog.url }}
                        </a>
                        <span v-else class="text-muted">—</span>
                    </DescriptionsItem>
                    <DescriptionsItem :label="$t('audit_logs.detail_ip')">{{ selectedLog.ip_address ?? '—' }}</DescriptionsItem>
                    <DescriptionsItem :label="$t('audit_logs.detail_ua')">
                        <span class="ua">{{ selectedLog.user_agent ?? '—' }}</span>
                    </DescriptionsItem>
                </Descriptions>

                <div v-if="prettyJson(selectedLog.old_values)" class="json-block">
                    <h4>{{ $t('audit_logs.old_values') }}</h4>
                    <pre>{{ prettyJson(selectedLog.old_values) }}</pre>
                </div>

                <div v-if="prettyJson(selectedLog.new_values)" class="json-block">
                    <h4>{{ $t('audit_logs.new_values') }}</h4>
                    <pre>{{ prettyJson(selectedLog.new_values) }}</pre>
                </div>
            </template>
        </Drawer>
    </div>
</template>

<style scoped>
.filters-card { margin-bottom: 16px; }

.grid-card :deep(.ant-table-thead > tr > th) {
    background: #F8FAFC;
    color: #334155;
    font-weight: 600;
    font-size: 0.8125rem;
}
.grid-card :deep(.ant-table-tbody > tr) { cursor: pointer; }
.grid-card :deep(.ant-table-tbody > tr:hover > td) { background: #F5F9FE !important; }

.user-name  { font-weight: 500; color: #32363A; font-size: 0.875rem; }
.user-email { color: #6A6D70; font-size: 0.75rem; }
.url-cell   { color: #0A6ED1; font-size: 0.8125rem; font-family: ui-monospace, monospace; }
.url-detail { font-size: 0.75rem; color: #0A6ED1; word-break: break-all; }
.ua         { font-size: 0.75rem; color: #6A6D70; word-break: break-all; }
.text-muted { color: #9aa0a6; font-style: italic; }

.json-block {
    margin-top: 18px;
}
.json-block h4 {
    margin: 0 0 6px 0;
    font-size: 0.875rem;
    font-weight: 600;
    color: #32363A;
}
.json-block pre {
    margin: 0;
    padding: 12px;
    background: #F8FAFC;
    border: 1px solid #E5E5E5;
    border-radius: 4px;
    font-size: 0.75rem;
    overflow-x: auto;
    line-height: 1.4;
}

.empty-state {
    text-align: center;
    padding: 56px 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}
.empty-state__icon { font-size: 56px; color: #b8c5d0; margin-bottom: 8px; }
.empty-state h3 { margin: 0; font-size: 1rem; font-weight: 600; color: #32363A; }
.empty-state p { margin: 0; color: #6A6D70; font-size: 0.875rem; }

</style>

<style>
html[data-theme="dark"] .page-header h1 { color: #e5e6e7; }
html[data-theme="dark"] .page-header p  { color: #a8aaae; }
html[data-theme="dark"] .grid-card .ant-table-thead > tr > th {
    background: #2c3034 !important;
    color: #e5e6e7 !important;
}
html[data-theme="dark"] .json-block pre {
    background: #2c3034;
    border-color: #3f4448;
    color: #e5e6e7;
}
</style>
