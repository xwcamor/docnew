<script setup>
import { ref, watch, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Button, Card, Space, Input, Tooltip, Popconfirm, Empty,
} from 'ant-design-vue';
import {
    DeleteOutlined, UndoOutlined, SearchOutlined, FireOutlined,
} from '@ant-design/icons-vue';
import UserAvatar from '@/Components/Common/UserAvatar.vue';
import dayjs from 'dayjs';

import AppLayout from '@/Layouts/AppLayout.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import UsersForceDeleteModal from '@/Components/Users/UsersForceDeleteModal.vue';
import UsersTrashBulkBar from '@/Components/Users/UsersTrashBulkBar.vue';
import { useModuleRestore } from '@/Composables/useModuleRestore';
import { useDateFormat } from '@/Composables/useDateFormat';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();
const { formatDateTime } = useDateFormat();

defineOptions({ layout: AppLayout });

const props = defineProps({
    users:   { type: Object, required: true },
    filters: { type: Object, required: true },
});

const page = usePage();

const isSuper = page.props.auth?.user?.roles?.includes('super');
if (!isSuper) {
    router.visit(route('user_management.users.index'));
}

const searchTerm = ref(props.filters.name ?? '');
let searchTimer = null;
watch(searchTerm, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.reload({
            only: ['users', 'filters'],
            data: { name: val || undefined, page: 1 },
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

// ─── Restore (individual + bulk) via useModuleRestore composable ───────────
const {
    restoring, restore,
    selectedRowKeys, rowSelection, clearSelection,
    bulkRestoring, bulkRestore,
} = useModuleRestore({
    restoreRouteName:     'user_management.users.restore',
    bulkRestoreRouteName: 'user_management.users.bulk_restore',
});

// ─── Force-delete (hard delete con triple guard) ───────────────────────────
const forceOpen       = ref(false);
const forceTarget     = ref(null);
const forceForm       = ref({ name_confirmation: '', reason: '' });
const forceSubmitting = ref(false);
const forceErrors     = ref({});

const openForce = (user) => {
    forceTarget.value = user;
    forceForm.value = { name_confirmation: '', reason: '' };
    forceErrors.value = {};
    forceOpen.value = true;
};

const submitForce = () => {
    forceSubmitting.value = true;
    router.delete(
        route('user_management.users.force_delete', forceTarget.value.slug),
        {
            data: forceForm.value,
            preserveScroll: true,
            onSuccess: () => { forceOpen.value = false; },
            onError:   (errs) => { forceErrors.value = errs; },
            onFinish:  () => { forceSubmitting.value = false; },
        },
    );
};


const columns = computed(() => [
    { title: t('users.id'),         dataIndex: 'id',                  key: 'id',          width: 80,  mobile: { role: 'meta' } },
    { title: t('users.name'),       dataIndex: 'name',                key: 'name',        ellipsis: true, mobile: { role: 'title' } },
    { title: t('users.email'),      dataIndex: 'email',               key: 'email',       ellipsis: true, mobile: { role: 'subtitle' } },
    { title: t('users.deleted_by'), dataIndex: ['deleter', 'name'],   key: 'deleter',     width: 180, mobile: { role: 'meta' } },
    { title: t('users.deleted_at'), dataIndex: 'deleted_at',          key: 'deleted_at',  width: 180, mobile: { role: 'meta' } },
    { title: t('users.reason'),     dataIndex: 'deleted_description', key: 'reason',      ellipsis: true, mobile: { role: 'hidden' } },
    { title: t('global.actions'),   key: 'actions',                   width: 140, fixed: 'right', mobile: { role: 'actions' } },
]);

const tablePagination = {
    current:  props.users.current_page,
    pageSize: props.users.per_page,
    total:    props.users.total,
    showSizeChanger: true,
    pageSizeOptions: ['10', '25', '50', '100'],
    showTotal: (total, range) => `${range[0]}-${range[1]} de ${total}`,
};

const onTableChange = (pag) => {
    router.reload({
        only: ['users', 'filters'],
        data: {
            page:     pag.current,
            per_page: pag.pageSize,
            name:     searchTerm.value || undefined,
        },
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};
</script>

<template>
    <Head :title="$t('users.trash_title')" />

    <div v-if="isSuper" class="sap-form trash-page">
        <SectionHeader
            :back-href="route('user_management.users.index')"
            :title="$t('users.trash_title')"
            :subtitle="$t('users.trash_subtitle')"
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
                :dataSource="users.data"
                :view="'table'"
                :scroll="{ x: 'max-content' }"
                :columns="columns"
                :pagination="tablePagination"
                :rowSelection="rowSelection"
                rowKey="id"
                @change="onTableChange"
            >
                <template #bodyCell="{ column, record, isMobile }">
                    <template v-if="column.key === 'name'">
                        <Space :size="8">
                            <UserAvatar :photo="record.photo" :name="record.name" :size="28" :updated-at="record.updated_at" />
                            <span>{{ record.name }}</span>
                        </Space>
                    </template>

                    <template v-else-if="column.key === 'deleter'">
                        <span v-if="record.deleter">{{ record.deleter.name }}</span>
                        <span v-else class="text-muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'deleted_at'">
                        {{ formatDateTime(record.deleted_at) }}
                    </template>

                    <template v-else-if="column.key === 'reason'">
                        <Tooltip v-if="record.deleted_description" :title="record.deleted_description">
                            <span class="reason-cell">{{ record.deleted_description }}</span>
                        </Tooltip>
                        <span v-else class="text-muted">{{ $t('global.no_reason') }}</span>
                    </template>

                    <template v-else-if="column.key === 'actions'">
                        <Space :size="4">
                            <Popconfirm
                                :title="$t('global.restore') + '?'"
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
                v-if="users.data.length === 0"
                :description="$t('global.no_deleted_records')"
                style="padding: 48px 16px"
            />
        </Card>

        <UsersTrashBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :submitting="bulkRestoring"
            @cancel="clearSelection"
            @restore="bulkRestore"
        />

        <UsersForceDeleteModal
            v-model:open="forceOpen"
            v-model:form="forceForm"
            :target="forceTarget"
            :submitting="forceSubmitting"
            :errors="forceErrors"
            @confirm="submitForce"
        />
    </div>
</template>

<style scoped>
.page-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 16px; margin-bottom: 16px; flex-wrap: wrap;
}
.page-header__title { display: flex; align-items: center; gap: 14px; }
.back-link {
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; border-radius: 4px; color: #6A6D70;
    transition: background 0.12s ease, color 0.12s ease;
}
.back-link:hover { background: #f1f5f9; color: #0A6ED1; }
.page-header__icon {
    width: 40px; height: 40px; border-radius: 4px;
    background: #BB0000; color: white;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.page-header h1 { font-size: 1.4rem; font-weight: 400; margin: 0; color: #32363A; }
.page-header p { font-size: 0.8125rem; color: #6A6D70; margin: 2px 0 0 0; }

.grid-card :deep(.ant-table-thead > tr > th) {
    background: #F8FAFC; color: #334155; font-weight: 600; font-size: 0.8125rem;
}
.text-muted { color: #9aa0a6; font-style: italic; }
.reason-cell {
    color: #6A6D70; font-size: 0.8125rem;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    display: inline-block; max-width: 100%;
}

@media (max-width: 768px) {
    .page-header { flex-direction: column; align-items: stretch; }
    .page-header h1 { font-size: 1.2rem; }
}
</style>

<style>
html[data-theme="dark"] .page-header h1 { color: #e5e6e7; }
html[data-theme="dark"] .page-header p  { color: #a8aaae; }
html[data-theme="dark"] .back-link:hover { background: #313a44; }
html[data-theme="dark"] .grid-card .ant-table-thead > tr > th {
    background: #2c3034 !important; color: #e5e6e7 !important;
}
</style>
