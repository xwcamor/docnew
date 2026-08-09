<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Space, Tabs, TabPane, Descriptions, DescriptionsItem,
    Table, Tooltip, Empty, Alert,
} from 'ant-design-vue';
import {
    ThunderboltOutlined, HistoryOutlined, PlayCircleOutlined, FileTextOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
dayjs.extend(relativeTime);

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const { isSuper, canSeeAudit } = useAuth();
const { formatDateTime, formatDateTimeFull } = useDateFormat();

const props = defineProps({
    automation: { type: Object, required: true },
    runs:       { type: Array,  default: () => [] },
    activity:   { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
    catalog:    { type: Object, default: () => ({ data_sources: [], actions: [] }) },
});

const isDeleted = computed(() => !!props.automation.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrappers locales para mantener call-sites compactos (fmt/fmtShort en templates).
const fmt = (d) => formatDateTimeFull(d);
const fmtShort = (d) => formatDateTime(d);

const sourceLabel = (key) =>
    props.catalog.data_sources.find(s => s.key === key)?.label ?? key ?? '—';
const actionLabel = (key) =>
    props.catalog.actions.find(a => a.key === key)?.label ?? key ?? '—';

const triggerSummary = computed(() => {
    const c = props.automation.trigger_config ?? {};
    switch (c.kind) {
        case 'daily':   return `${t('automations.trigger_kind_daily')} · ${c.time}`;
        case 'weekly':  return t('automations.trigger_weekly_on',  { day: c.day, time: c.time });
        case 'monthly': return t('automations.trigger_monthly_on', { day: c.day, time: c.time });
        case 'cron':    return t('automations.trigger_cron_on',    { expr: c.expression });
        default:        return '—';
    }
});

/**
 * Los filtros, escritos como se leen. Antes salia `<code>is_active = true</code>`
 * con el nombre de la columna: el catalogo ya trae la etiqueta de cada campo y
 * la lista de operadores, asi que se usa.
 */
const filterFields = computed(() =>
    props.catalog.data_sources.find(s => s.key === props.automation.data_source)?.fields ?? [],
);

const fieldLabel = (key) => filterFields.value.find(f => f.key === key)?.label ?? key;

const valueLabel = (v) => {
    if (v === true)  return t('global.yes');
    if (v === false) return t('global.no');
    if (Array.isArray(v)) return v.join(', ');
    return v === null || v === '' ? '—' : String(v);
};

const opLabel = (op) => (op === 'contains' ? t('automations.op_contains') : op);

/**
 * La configuracion de la accion, en filas legibles. La ficha volcaba el JSON
 * crudo (`{"to":["a@b.com"],"subject":…}`): un supervisor no tiene por que leer
 * JSON para saber a quien le llega su aviso.
 */
const actionRows = computed(() => {
    const c = props.automation.action_config ?? {};
    const rows = [];
    if (c.to)         rows.push({ label: t('automations.cfg_to'), value: [].concat(c.to).join(', ') });
    if (c.recipients) rows.push({
        label: t('automations.cfg_recipients'),
        value: c.recipients === 'tenant_admins'
            ? t('automations.action_in_app_recipients_admins')
            : t('automations.preview_specific_users_count', { n: (c.user_ids ?? []).length }),
    });
    if (c.subject) rows.push({ label: t('automations.cfg_subject'), value: c.subject });
    if (c.title)   rows.push({ label: t('automations.cfg_title'),   value: c.title });
    if (c.body)    rows.push({ label: t('automations.cfg_body'),    value: c.body, pre: true });
    return rows;
});

const runStatusColor = (s) => ({ running: 'blue', success: 'success', failed: 'error' }[s] ?? 'default');

const runNow = () => {
    router.post(route('automation_management.automations.run_now', props.automation.id), {}, {
        preserveScroll: true,
    });
};

const runColumns = computed(() => [
    { title: t('global.created_at'),        dataIndex: 'started_at',      key: 'started', width: 180 },
    { title: t('automations.col_status'),   dataIndex: 'status',          key: 'status',  width: 110 },
    { title: t('automations.col_records'),  dataIndex: 'records_matched', key: 'records', width: 100, align: 'center' },
    { title: t('automations.col_result'),   dataIndex: 'output_summary',  key: 'output',  ellipsis: true },
    { title: t('automations.col_error'),    dataIndex: 'error_message',   key: 'error',   ellipsis: true },
]);
</script>

<template>
    <Head :title="automation.name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('automation_management.automations.index')"
            :title="automation.name"
            :subtitle="automation.description"
            :icon-bg="iconBg"
        >
            <template #icon><ThunderboltOutlined /></template>
            <template #actions>
                <Space wrap>
                    <Tooltip :title="$t('automations.run_now_hint')">
                        <Button @click="runNow"><PlayCircleOutlined /> {{ $t('automations.run_now') }}</Button>
                    </Tooltip>
                    <EntityShowActions
                        module="automations"
                        route-prefix="automation_management"
                        :slug="automation.id"
                        :id="automation.id"
                        :is-deleted="isDeleted"
                        :can-edit="true"
                        :can-delete="true"
                        :can-see-audit="canSeeAudit"
                    />
                </Space>
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(automation.deleted_at) }}</div>
                <div v-if="automation.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ automation.deleter.name }}
                </div>
                <div v-if="automation.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ automation.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="automations" route-prefix="automation_management" />
            </template>
        </Alert>

        <Card class="tabs-card" :bodyStyle="{ padding: '0 16px' }">
            <Tabs default-active-key="general">
                <TabPane key="general">
                    <template #tab>
                        <span><FileTextOutlined /> {{ $t('automations.tab_general') }}</span>
                    </template>

                    <Card :title="$t('automations.section_trigger')" class="info-card" :bodyStyle="{ padding: 0 }">
                        <Descriptions :column="1" bordered :labelStyle="{ width: '200px' }">
                            <DescriptionsItem :label="$t('automations.col_trigger')">{{ triggerSummary }}</DescriptionsItem>
                            <DescriptionsItem :label="$t('automations.col_next_run')">
                                <span v-if="automation.next_run_at">{{ fmt(automation.next_run_at) }}</span>
                                <span v-else class="muted">{{ $t('automations.next_run_none') }}</span>
                            </DescriptionsItem>
                            <DescriptionsItem :label="$t('automations.col_last_run')">{{ fmt(automation.last_run_at) }}</DescriptionsItem>
                            <DescriptionsItem :label="$t('automations.is_active')">
                                <Tag :color="automation.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ automation.is_active ? $t('global.active') : $t('global.inactive') }}
                                </Tag>
                            </DescriptionsItem>
                        </Descriptions>
                    </Card>

                    <Card :title="$t('automations.section_data')" class="info-card" :bodyStyle="{ padding: 0 }">
                        <Descriptions :column="1" bordered :labelStyle="{ width: '200px' }">
                            <DescriptionsItem :label="$t('automations.data_source')">
                                <span v-if="automation.data_source">{{ sourceLabel(automation.data_source) }}</span>
                                <span v-else class="muted">{{ $t('automations.data_source_none') }}</span>
                            </DescriptionsItem>
                            <DescriptionsItem v-if="automation.data_source" :label="$t('automations.filters')">
                                <div v-if="(automation.data_filter?.where ?? []).length === 0" class="muted">
                                    {{ $t('automations.filters_none') }}
                                </div>
                                <ul v-else class="filter-list">
                                    <li v-for="(c, i) in automation.data_filter.where" :key="i">
                                        <strong>{{ fieldLabel(c.field) }}</strong>
                                        {{ opLabel(c.op) }}
                                        <strong>{{ valueLabel(c.value) }}</strong>
                                    </li>
                                </ul>
                            </DescriptionsItem>
                        </Descriptions>
                    </Card>

                    <Card :title="$t('automations.section_action')" class="info-card" :bodyStyle="{ padding: 0 }">
                        <Descriptions :column="1" bordered :labelStyle="{ width: '200px' }">
                            <DescriptionsItem :label="$t('automations.action_type')">
                                <Tag :bordered="false">{{ actionLabel(automation.action_type) }}</Tag>
                            </DescriptionsItem>
                            <DescriptionsItem
                                v-for="row in actionRows"
                                :key="row.label"
                                :label="row.label"
                            >
                                <pre v-if="row.pre" class="config-pre">{{ row.value }}</pre>
                                <span v-else>{{ row.value }}</span>
                            </DescriptionsItem>
                        </Descriptions>
                    </Card>
                </TabPane>

                <TabPane key="runs">
                    <template #tab>
                        <span><HistoryOutlined /> {{ $t('automations.tab_runs') }} ({{ runs.length }})</span>
                    </template>

                    <Empty v-if="runs.length === 0" :description="$t('automations.no_runs', { date: fmt(automation.next_run_at) })" />
                    <Table
                        v-else
                        :data-source="runs"
                        :columns="runColumns"
                        :pagination="false"
                        row-key="id"
                        size="small"
                        :scroll="{ x: 'max-content' }"
                    >
                        <template #bodyCell="{ column, record }">
                            <template v-if="column.key === 'started'">{{ fmtShort(record.started_at) }}</template>
                            <template v-else-if="column.key === 'status'">
                                <Tag :color="runStatusColor(record.status)" :bordered="false">
                                    {{ $t('automations.run_' + record.status) }}
                                </Tag>
                            </template>
                            <template v-else-if="column.key === 'error'">
                                <Tooltip v-if="record.error_message" :title="record.error_message">
                                    <span class="error-cell">{{ record.error_message }}</span>
                                </Tooltip>
                                <span v-else>—</span>
                            </template>
                        </template>
                    </Table>
                </TabPane>

                <TabPane key="history">
                    <template #tab>
                        <span><HistoryOutlined /> {{ $t('global.history') }}</span>
                    </template>
                    <RecordHistory :record-audit="recordAudit" :activity="activity" :can-see-activity="canSeeAudit" />
                </TabPane>
            </Tabs>
        </Card>
    </div>
</template>

<style scoped>
.tabs-card { border-radius: 6px; }
.info-card { margin-bottom: 16px; border-radius: 6px; }
.muted { color: var(--color-text-muted); font-style: italic; }
.config-pre {
    font-family: ui-monospace, Consolas, monospace;
    font-size: 0.8125rem;
    background: var(--color-surface-alt);
    padding: 10px;
    border-radius: 4px;
    margin: 0;
    overflow-x: auto;
}
.error-cell { color: var(--color-danger); font-size: 0.8125rem; }
.filter-list { margin: 0; padding-left: 18px; }
.filter-list li { line-height: 1.7; }
.deleted-alert { margin-bottom: 16px; }

@media (max-width: 767px) {
    :deep(.ant-descriptions-item-label) {
        width: auto !important;
        min-width: 0 !important;
        white-space: normal !important;
        font-weight: 500;
    }
    :deep(.ant-descriptions-item-content) { word-break: break-word; }
}
</style>
