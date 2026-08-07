<script setup>
import { ref, watch, computed, onBeforeUnmount } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Button, Card, Space, Input, Tooltip, Popconfirm, Empty,
} from 'ant-design-vue';
import {
    DeleteOutlined, UndoOutlined, SearchOutlined, FireOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';

import AppLayout from '@/Layouts/AppLayout.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import TransformersTrashBulkBar from '@/Components/Transformers/TransformersTrashBulkBar.vue';
import TransformersForceDeleteModal from '@/Components/Transformers/TransformersForceDeleteModal.vue';

import { useModuleRestore } from '@/Composables/useModuleRestore';
import { useDateFormat } from '@/Composables/useDateFormat';
import { useI18n } from '@/Plugins/i18n';
import { transformersTrashColumns } from './config/trashColumns';

const { t } = useI18n();
const { formatDateTime } = useDateFormat();

defineOptions({ layout: AppLayout });

const props = defineProps({
    transformers: { type: Object, required: true },
    filters:   { type: Object, required: true },
});

const page = usePage();
const isSuper = page.props.auth?.user?.roles?.includes('super');
if (!isSuper) {
    router.visit(route('business_management.transformers.index'));
}

const searchTerm = ref(props.filters.serial ?? '');
let searchTimer = null;
watch(searchTerm, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.reload({
            only: ['transformers', 'filters'],
            data: { serial: val || undefined, page: 1 },
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});
onBeforeUnmount(() => clearTimeout(searchTimer));

const {
    restoring, restore,
    selectedRowKeys, rowSelection, clearSelection,
    bulkRestoring, bulkRestore,
} = useModuleRestore({
    restoreRouteName:     'business_management.transformers.restore',
    bulkRestoreRouteName: 'business_management.transformers.bulk_restore',
});

const forceDeleteOpen       = ref(false);
const forceDeleteTarget     = ref(null);
const forceDeleteForm       = ref({ name_confirmation: '', reason: '' });
const forceDeleteSubmitting = ref(false);
const forceDeleteErrors     = ref({});

const openForceDelete = (transformer) => {
    forceDeleteTarget.value = transformer;
    forceDeleteForm.value = { name_confirmation: '', reason: '' };
    forceDeleteErrors.value = {};
    forceDeleteOpen.value = true;
};

const submitForceDelete = () => {
    if (!forceDeleteTarget.value) return;
    forceDeleteSubmitting.value = true;
    forceDeleteErrors.value = {};
    router.delete(
        route('business_management.transformers.force_delete', forceDeleteTarget.value.slug),
        {
            data: forceDeleteForm.value,
            preserveScroll: true,
            onSuccess: () => { forceDeleteOpen.value = false; },
            onError:   (errs) => { forceDeleteErrors.value = errs; },
            onFinish:  () => { forceDeleteSubmitting.value = false; },
        },
    );
};

const columns = computed(() => transformersTrashColumns(t));
const tablePagination = computed(() => ({
    current:  props.transformers.current_page,
    pageSize: props.transformers.per_page,
    total:    props.transformers.total,
    showSizeChanger: true,
    pageSizeOptions: ['10', '25', '50', '100'],
    showTotal: (total, range) => `${range[0]}-${range[1]} ${t('global.of')} ${total}`,
}));

const onTableChange = (pag) => {
    router.reload({
        only: ['transformers', 'filters'],
        data: { page: pag.current, per_page: pag.pageSize, serial: searchTerm.value || undefined },
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const subtitle = computed(() => {
    const word = props.transformers.total === 1 ? t('global.record') : t('global.records');
    return `${props.transformers.total} ${word} · ${t('global.super_only')}`;
});
</script>

<template>
    <Head :title="$t('global.view_deleted') + ' — ' + $t('transformers.plural')" />

    <div v-if="isSuper" class="sap-form trash-page">
        <SectionHeader
            :back-href="route('business_management.transformers.index')"
            :title="$t('global.view_deleted') + ' — ' + $t('transformers.plural')"
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
                :dataSource="transformers.data"
                :view="'table'"
                :scroll="{ x: 'max-content' }"
                :columns="columns"
                :pagination="tablePagination"
                :rowSelection="rowSelection"
                rowKey="id"
                @change="onTableChange"
            >
                <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'deleter'">
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
                                :description="$t('transformers.restore_hint')"
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
                                <Button size="small" type="text" danger @click="openForceDelete(record)">
                                    <FireOutlined />
                                </Button>
                            </Tooltip>
                        </Space>
                    </template>
                </template>
            </ResponsiveTable>

            <Empty
                v-if="transformers.data.length === 0"
                :description="$t('global.no_deleted_records')"
                style="padding: 48px 16px"
            />
        </Card>

        <TransformersTrashBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :submitting="bulkRestoring"
            @cancel="clearSelection"
            @restore="bulkRestore"
        />

        <TransformersForceDeleteModal
            v-model:open="forceDeleteOpen"
            v-model:form="forceDeleteForm"
            :target="forceDeleteTarget"
            :submitting="forceDeleteSubmitting"
            :errors="forceDeleteErrors"
            @confirm="submitForceDelete"
        />
    </div>
</template>

<style scoped>
.grid-card :deep(.ant-table-thead > tr > th) {
    background: var(--color-surface-alt);
    color: var(--color-text-strong);
    font-weight: 600;
    font-size: 0.8125rem;
}

.text-muted { color: var(--color-text-dim); font-style: italic; }
.reason-cell {
    color: var(--color-text-muted);
    font-size: 0.8125rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    display: inline-block;
    max-width: 100%;
}
</style>
