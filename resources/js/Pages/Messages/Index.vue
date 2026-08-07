<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Card, Button, Input, Select, SelectOption, Tag, Table, Pagination, Tooltip, Empty, Space,
    Tabs, TabPane,
} from 'ant-design-vue';
import {
    MessageOutlined, PlusOutlined, EditOutlined, EyeOutlined, DeleteOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
dayjs.extend(relativeTime);

import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/Plugins/i18n';
import { useViewport } from '@/Composables/useViewport';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const { isMobile } = useViewport(768);

const props = defineProps({
    messages: { type: Object, required: true },
    filters:  { type: Object, required: true },
});

const subject       = ref(props.filters.subject ?? '');
const audienceType  = ref(props.filters.audience_type ?? '');

// Tab activo derivado del server — "all" | "active" | "inactive".
const activeTab = computed(() => {
    if (props.filters.is_active === true)  return 'active';
    if (props.filters.is_active === false) return 'inactive';
    return 'all';
});

const buildQuery = (overrides = {}) => ({
    subject:       subject.value || undefined,
    audience_type: audienceType.value || undefined,
    is_active:     activeTab.value === 'active'   ? 1
                 : activeTab.value === 'inactive' ? 0
                 : undefined,
    ...overrides,
});

const applyFilters = () => {
    router.get(route('communication.messages.index'), buildQuery(), { preserveState: true, preserveScroll: true });
};

const clearFilters = () => {
    subject.value = '';
    audienceType.value = '';
    router.get(route('communication.messages.index'), {}, { preserveState: true, preserveScroll: true });
};

const switchTab = (key) => {
    router.get(route('communication.messages.index'), buildQuery({
        is_active: key === 'active' ? 1 : key === 'inactive' ? 0 : undefined,
    }), { preserveState: true, preserveScroll: true });
};

const onPage = (page) => {
    router.get(route('communication.messages.index'), {
        ...props.filters,
        page,
    }, { preserveState: true, preserveScroll: true });
};

const audienceColor = (type) => {
    if (type === 'global') return 'blue';
    if (type === 'tenant') return 'cyan';
    if (type === 'user')   return 'purple';
    return 'default';
};

const audienceLabel = (type) => {
    if (type === 'global') return t('messages.audience_global');
    if (type === 'tenant') return t('messages.audience_tenant');
    if (type === 'user')   return t('messages.audience_user');
    return type;
};

const statusLabel = (row) => {
    if (!row.published_at) return t('messages.status_draft');
    if (row.expires_at && dayjs(row.expires_at).isBefore(dayjs())) return t('messages.status_expired');
    return t('messages.status_published');
};

const statusColor = (row) => {
    if (!row.published_at) return 'default';
    if (row.expires_at && dayjs(row.expires_at).isBefore(dayjs())) return 'red';
    return row.is_active ? 'green' : 'orange';
};

const formatDateRel = (d) => d ? dayjs(d).fromNow() : '-';

const list = computed(() => props.messages.data ?? []);
const meta = computed(() => ({
    current_page: props.messages.current_page ?? 1,
    per_page:     props.messages.per_page ?? 10,
    total:        props.messages.total ?? 0,
}));
</script>

<template>
    <Head :title="t('messages.plural')" />

    <div class="sap-index messages-index">
        <div class="mi-title" data-tour="module">
            <div class="page-header__title">
                <div class="page-header__icon">
                    <MessageOutlined />
                </div>
                <div class="page-header__heading">
                    <h1>{{ t('messages.plural') }}</h1>
                </div>
            </div>
            <div class="mi-title__actions">
                <Link :href="route('communication.messages.create')">
                    <Button type="primary">
                        <template #icon><PlusOutlined /></template>
                        {{ t('messages.new_message') }}
                    </Button>
                </Link>
            </div>
        </div>

        <Card>
            <Tabs :active-key="activeTab" @change="switchTab" class="messages-tabs">
                <TabPane key="all"      :tab="t('messages.tab_all')" />
                <TabPane key="active"   :tab="t('global.active')" />
                <TabPane key="inactive" :tab="t('global.inactive')" />
            </Tabs>

            <div class="filters">
                <Input
                    v-model:value="subject"
                    :placeholder="t('messages.filter_subject')"
                    style="max-width: 280px"
                    allow-clear
                    @press-enter="applyFilters"
                />
                <Select v-model:value="audienceType" :placeholder="t('messages.filter_audience')" style="width:180px" allow-clear>
                    <SelectOption value="">{{ t('messages.filter_audience') }}</SelectOption>
                    <SelectOption value="global">{{ t('messages.audience_global') }}</SelectOption>
                    <SelectOption value="tenant">{{ t('messages.audience_tenant') }}</SelectOption>
                    <SelectOption value="user">{{ t('messages.audience_user') }}</SelectOption>
                </Select>
                <Button @click="applyFilters">{{ t('global.apply') }}</Button>
                <Button type="text" @click="clearFilters">{{ t('global.clear') }}</Button>
            </div>

            <div v-if="list.length === 0" class="empty-state">
                <Empty :description="t('messages.messages_empty_title')">
                    <Link :href="route('communication.messages.create')">
                        <Button type="primary">{{ t('messages.new_message') }}</Button>
                    </Link>
                </Empty>
            </div>

            <Table
                v-else-if="!isMobile"
                :data-source="list"
                :pagination="false"
                row-key="id"
                size="middle"
                bordered
                :scroll="{ x: 'max-content' }"
            >
                <Table.Column :title="t('messages.subject')" data-index="subject">
                    <template #default="{ record }">
                        <Link :href="route('communication.messages.show', record.slug)" class="row-link">
                            <strong>{{ record.subject }}</strong>
                        </Link>
                    </template>
                </Table.Column>
                <Table.Column :title="t('messages.audience')" align="center" :width="140">
                    <template #default="{ record }">
                        <Tag :color="audienceColor(record.audience_type)" :bordered="false">
                            {{ audienceLabel(record.audience_type) }}
                        </Tag>
                    </template>
                </Table.Column>
                <Table.Column :title="t('messages.recipients_count')" align="center" :width="120">
                    <template #default="{ record }">{{ record.recipients_count ?? 0 }}</template>
                </Table.Column>
                <Table.Column :title="t('messages.read_count')" align="center" :width="120">
                    <template #default="{ record }">
                        {{ record.read_count ?? 0 }} / {{ record.recipients_count ?? 0 }}
                    </template>
                </Table.Column>
                <Table.Column :title="t('messages.replies_count')" align="center" :width="100">
                    <template #default="{ record }">{{ record.replies_count ?? 0 }}</template>
                </Table.Column>
                <Table.Column :title="t('global.status')" align="center" :width="120">
                    <template #default="{ record }">
                        <Tag :color="statusColor(record)" :bordered="false">{{ statusLabel(record) }}</Tag>
                    </template>
                </Table.Column>
                <Table.Column :title="t('messages.created_at')" align="center" :width="140">
                    <template #default="{ record }">
                        {{ formatDateRel(record.created_at) }}
                    </template>
                </Table.Column>
                <Table.Column :title="t('global.actions')" align="center" :width="140">
                    <template #default="{ record }">
                        <Space :size="4">
                            <Tooltip :title="t('global.view')">
                                <Link :href="route('communication.messages.show', record.slug)">
                                    <Button type="text" size="small"><EyeOutlined /></Button>
                                </Link>
                            </Tooltip>
                            <Tooltip :title="t('global.edit')">
                                <Link :href="route('communication.messages.edit', record.slug)">
                                    <Button type="text" size="small"><EditOutlined /></Button>
                                </Link>
                            </Tooltip>
                            <Tooltip :title="t('global.delete')">
                                <Link :href="route('communication.messages.delete', record.slug)">
                                    <Button type="text" size="small" danger><DeleteOutlined /></Button>
                                </Link>
                            </Tooltip>
                        </Space>
                    </template>
                </Table.Column>
            </Table>

            <ul v-else class="msg-cards">
                <li v-for="record in list" :key="record.id" class="msg-card">
                    <div class="msg-card__head">
                        <Link :href="route('communication.messages.show', record.slug)" class="row-link">
                            <strong>{{ record.subject }}</strong>
                        </Link>
                        <Tag :color="statusColor(record)" :bordered="false">{{ statusLabel(record) }}</Tag>
                    </div>
                    <div class="msg-card__tags">
                        <Tag :color="audienceColor(record.audience_type)" :bordered="false">
                            {{ audienceLabel(record.audience_type) }}
                        </Tag>
                    </div>
                    <div class="msg-card__meta">
                        <span><strong>{{ record.read_count ?? 0 }} / {{ record.recipients_count ?? 0 }}</strong> {{ t('messages.read_count').toLowerCase() }}</span>
                        <span v-if="(record.replies_count ?? 0) > 0">· {{ record.replies_count }} {{ t('messages.replies_count').toLowerCase() }}</span>
                        <span>· {{ formatDateRel(record.created_at) }}</span>
                    </div>
                    <div class="msg-card__actions">
                        <Link :href="route('communication.messages.show', record.slug)">
                            <Button size="small"><template #icon><EyeOutlined /></template>{{ t('global.view') }}</Button>
                        </Link>
                        <Link :href="route('communication.messages.edit', record.slug)">
                            <Button size="small"><template #icon><EditOutlined /></template>{{ t('global.edit') }}</Button>
                        </Link>
                        <Link :href="route('communication.messages.delete', record.slug)">
                            <Button size="small" danger><template #icon><DeleteOutlined /></template>{{ t('global.delete') }}</Button>
                        </Link>
                    </div>
                </li>
            </ul>

            <div v-if="meta.total > meta.per_page" class="pagination-row">
                <Pagination
                    :current="meta.current_page"
                    :total="meta.total"
                    :page-size="meta.per_page"
                    :show-size-changer="false"
                    @change="onPage"
                />
            </div>
        </Card>
    </div>
</template>

<style scoped>
.filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.empty-state { padding: 40px 0; }
.row-link { color: var(--color-primary, #0a6ed1); text-decoration: none; }
.row-link:hover { text-decoration: underline; }
.pagination-row { display:flex; justify-content:flex-end; padding-top:14px; }

.msg-cards { list-style: none; padding: 0; margin: 0; display:flex; flex-direction:column; gap:10px; }
.msg-card {
    border: 1px solid var(--color-border-soft, #e5e5e5);
    border-radius: 6px;
    background: var(--color-surface, #fff);
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.msg-card__head { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }
.msg-card__head .row-link { flex:1; min-width:0; word-break: break-word; }
.msg-card__tags { display:flex; gap:6px; flex-wrap:wrap; }
.msg-card__meta { font-size: 0.82rem; color:#666; display:flex; flex-wrap:wrap; gap:4px; }
.msg-card__actions { display:flex; gap:6px; flex-wrap:wrap; padding-top:6px; border-top: 1px solid var(--color-border-soft, #e5e5e5); }

@media (max-width: 767px) {
    .filters :deep(.ant-input),
    .filters :deep(.ant-select) { width: 100% !important; max-width: 100% !important; }
}
</style>
