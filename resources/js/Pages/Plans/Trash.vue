<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Button, Card, Space, Tooltip, Popconfirm, Empty, Tag, Modal, Alert, Input,
} from 'ant-design-vue';
import {
    DeleteOutlined, UndoOutlined, FireOutlined, ExclamationCircleFilled,
} from '@ant-design/icons-vue';
import { resolveIconComponent, resolveColor } from '@/Utils/planAppearance';
import dayjs from 'dayjs';

import AppLayout from '@/Layouts/AppLayout.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import { useDateFormat } from '@/Composables/useDateFormat';
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const { formatDateTime } = useDateFormat();

const props = defineProps({
    plans: { type: Array, required: true },
});

const page = usePage();
const isSuper = page.props.auth?.user?.roles?.includes('super');
if (!isSuper) {
    router.visit(route('system_management.plans.index'));
}

// ─── Restore individual ──────────────────────────────────────────────────
const restoring = ref(null);
const restore = (plan) => {
    restoring.value = plan.id;
    router.post(route('system_management.plans.restore', plan.id), {}, {
        preserveScroll: true,
        onFinish: () => { restoring.value = null; },
    });
};

// ─── Force-delete (hard delete) ──────────────────────────────────────────
const forceOpen       = ref(false);
const forceTarget     = ref(null);
const forceForm       = ref({ name_confirmation: '', reason: '' });
const forceSubmitting = ref(false);
const forceErrors     = ref({});

const openForce = (plan) => {
    forceTarget.value = plan;
    forceForm.value = { name_confirmation: '', reason: '' };
    forceErrors.value = {};
    forceOpen.value = true;
};

const okDisabled = computed(() =>
    !forceTarget.value
    || forceForm.value.name_confirmation !== forceTarget.value.name
    || (forceForm.value.reason?.length ?? 0) < 10
);

const submitForce = () => {
    if (!forceTarget.value) return;
    forceSubmitting.value = true;
    forceErrors.value = {};
    router.delete(
        route('system_management.plans.force_delete', forceTarget.value.id),
        {
            data: forceForm.value,
            preserveScroll: true,
            onSuccess: () => { forceOpen.value = false; },
            onError:   (errs) => { forceErrors.value = errs; },
            onFinish:  () => { forceSubmitting.value = false; },
        },
    );
};

// ─── Columns ─────────────────────────────────────────────────────────────
const columns = computed(() => [
    { title: t('plans.col_plan'),     dataIndex: 'name',                key: 'name',     width: 180, sorter: (a, b) => a.name.localeCompare(b.name) },
    { title: t('plans.tagline'),      dataIndex: 'tagline',             key: 'tagline',  ellipsis: true },
    { title: t('global.deleted_at'),  dataIndex: 'deleted_at',          key: 'deleted_at', width: 180, sorter: (a, b) => new Date(a.deleted_at) - new Date(b.deleted_at), defaultSortOrder: 'descend' },
    { title: t('global.deleted_by'),  dataIndex: 'deleter',             key: 'deleter',    width: 180 },
    { title: t('global.delete_description'), dataIndex: 'deleted_description', key: 'reason', ellipsis: true },
    { title: t('global.actions'),     key: 'actions',                   width: 180, fixed: 'right' },
]);

const planTagColor = (plan) => resolveColor(plan.color);
const planIcon     = (plan) => resolveIconComponent(plan.icon);

const subtitle = computed(() => {
    const word = props.plans.length === 1 ? t('global.record') : t('global.records');
    return `${props.plans.length} ${word} · ${t('global.super_only')}`;
});
</script>

<template>
    <Head :title="$t('global.view_deleted') + ' — ' + $t('plans.plural')" />

    <div v-if="isSuper" class="sap-form trash-page">
        <SectionHeader
            :back-href="route('system_management.plans.index')"
            :title="$t('global.view_deleted') + ' — ' + $t('plans.plural')"
            :subtitle="subtitle"
            icon-bg="var(--color-danger)"
        >
            <template #icon><DeleteOutlined /></template>
        </SectionHeader>

        <div class="trash-toolbar">
            <Input
                v-model:value="searchTerm"
                :placeholder="$t('global.search') + '...'"
                allow-clear
                class="trash-search"
            >
                <template #prefix><SearchOutlined /></template>
            </Input>
        </div>

        <Card :bodyStyle="{ padding: 0 }" class="grid-card">
            <ResponsiveTable
                :dataSource="plans"
                :view="'table'"
                :scroll="{ x: 'max-content' }"
                :columns="columns"
                :pagination="false"
                rowKey="id"
            >
                <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'name'">
                        <Tag :color="planTagColor(record)" :bordered="false" class="plan-tag">
                            <component :is="planIcon(record)" v-if="planIcon(record)" />
                            {{ record.name.toUpperCase() }}
                        </Tag>
                    </template>

                    <template v-else-if="column.key === 'tagline'">
                        <span class="muted">{{ record.tagline || '—' }}</span>
                    </template>

                    <template v-else-if="column.key === 'deleted_at'">
                        {{ formatDateTime(record.deleted_at) }}
                    </template>

                    <template v-else-if="column.key === 'deleter'">
                        <span v-if="record.deleter">{{ record.deleter.name }}</span>
                        <span v-else class="muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'reason'">
                        <Tooltip v-if="record.deleted_description" :title="record.deleted_description">
                            <span class="reason-cell">{{ record.deleted_description }}</span>
                        </Tooltip>
                        <span v-else class="muted">{{ $t('global.no_reason') }}</span>
                    </template>

                    <template v-else-if="column.key === 'actions'">
                        <Space :size="4">
                            <Popconfirm
                                :title="$t('global.restore') + '?'"
                                :description="$t('plans.restore_hint')"
                                :ok-text="$t('global.restore')"
                                :cancel-text="$t('global.cancel')"
                                placement="topRight"
                                @confirm="restore(record)"
                            >
                                <Tooltip :title="$t('global.restore_hint')">
                                    <Button size="small" type="text" :loading="restoring === record.id">
                                        <UndoOutlined /> {{ $t('global.restore') }}
                                    </Button>
                                </Tooltip>
                            </Popconfirm>
                            <Tooltip :title="$t('global.force_delete_hint')">
                                <Button size="small" type="text" danger @click="openForce(record)">
                                    <FireOutlined />
                                </Button>
                            </Tooltip>
                        </Space>
                    </template>
                </template>
            </ResponsiveTable>

            <Empty
                v-if="plans.length === 0"
                :description="$t('global.no_deleted_records')"
                style="padding: 48px 16px"
            />
        </Card>

        <!-- Force-delete modal (triple guard: super + nombre exacto + motivo ≥ 10) -->
        <Modal
            v-model:open="forceOpen"
            :title="$t('global.force_delete_title')"
            :ok-text="$t('global.force_delete')"
            :cancel-text="$t('global.cancel')"
            :ok-button-props="{ danger: true, loading: forceSubmitting, disabled: okDisabled }"
            @ok="submitForce"
        >
            <Alert type="error" show-icon class="mb-3">
                <template #message>
                    <ExclamationCircleFilled /> {{ $t('global.force_delete_warning') }}
                </template>
            </Alert>

            <p class="force-msg">
                {{ $t('global.force_delete_name_prompt', { name: forceTarget?.name }) }}
            </p>
            <Input
                v-model:value="forceForm.name_confirmation"
                :placeholder="forceTarget?.name"
                :status="forceErrors.name_confirmation ? 'error' : ''"
                size="large"
                class="mb-3"
            />
            <div v-if="forceErrors.name_confirmation" class="field-error">
                {{ forceErrors.name_confirmation }}
            </div>

            <p class="force-msg">{{ $t('global.force_delete_reason_prompt') }}</p>
            <Input.TextArea
                v-model:value="forceForm.reason"
                :rows="3"
                :placeholder="$t('global.delete_reason_placeholder')"
                :maxlength="500"
                show-count
                :status="forceErrors.reason ? 'error' : ''"
            />
            <div v-if="forceErrors.reason" class="field-error">
                {{ forceErrors.reason }}
            </div>
        </Modal>
    </div>
</template>

<style scoped>
.grid-card :deep(.ant-table-thead > tr > th) {
    background: var(--color-surface-alt);
    color: var(--color-text-strong);
    font-weight: 600;
    font-size: 0.8125rem;
}
.grid-card { border-radius: 6px; transition: box-shadow 0.18s ease; }
.grid-card:hover { box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08); }

.plan-tag { font-weight: 600; letter-spacing: 0.3px; }
.muted { color: var(--color-text-muted); font-style: italic; font-size: 0.8125rem; }
.reason-cell {
    color: var(--color-text-muted);
    font-size: 0.8125rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: inline-block;
    max-width: 100%;
}

.force-msg { color: var(--color-text); line-height: 1.5; margin: 12px 0 8px 0; font-size: 0.875rem; }
.field-error { color: var(--color-danger); font-size: 0.8rem; margin: 4px 0 0 0; }
.mb-3 { margin-bottom: 12px; }
</style>
