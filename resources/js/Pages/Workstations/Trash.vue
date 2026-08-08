<script setup>
import { ref, watch, computed, onBeforeUnmount } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Button, Card, Space, Input, Tooltip, Popconfirm, Empty } from 'ant-design-vue';
import { DeleteOutlined, UndoOutlined, SearchOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import CatalogTrashBulkBar from '@/Components/Catalog/CatalogTrashBulkBar.vue';

import { useModuleRestore } from '@/Composables/useModuleRestore';
import { useDateFormat } from '@/Composables/useDateFormat';
import { useI18n } from '@/Plugins/i18n';
import { workstationsTrashColumns } from './config/trashColumns';

const { t } = useI18n();
const { formatDateTime } = useDateFormat();

defineOptions({ layout: AppLayout });

const props = defineProps({
    workstations: { type: Object, required: true },
    filters: { type: Object, required: true },
});

// La papelera es del super. El backend ya lo hace valer; aquí se evita además
// que quede una pantalla vacía a medio pintar si alguien llega por la URL.
const page = usePage();
const isSuper = page.props.auth?.user?.roles?.includes('super');
if (!isSuper) {
    router.visit(route('business_management.workstations.index'));
}

const searchTerm = ref(props.filters.name ?? '');
let searchTimer = null;
watch(searchTerm, (val) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.reload({
            only: ['workstations', 'filters'],
            data: { name: val || undefined, page: 1 },
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
    restoreRouteName:     'business_management.workstations.restore',
    bulkRestoreRouteName: 'business_management.workstations.bulk_restore',
});

const columns = computed(() => workstationsTrashColumns(t));
const tablePagination = computed(() => ({
    current:  props.workstations.current_page,
    pageSize: props.workstations.per_page,
    total:    props.workstations.total,
    showSizeChanger: true,
    pageSizeOptions: ['10', '25', '50', '100'],
    showTotal: (total, range) => `${range[0]}-${range[1]} ${t('global.of')} ${total}`,
}));

const onTableChange = (pag) => {
    router.reload({
        only: ['workstations', 'filters'],
        data: { page: pag.current, per_page: pag.pageSize, name: searchTerm.value || undefined },
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const subtitle = computed(() => {
    const word = props.workstations.total === 1 ? t('global.record') : t('global.records');
    return `${props.workstations.total} ${word} · ${t('global.super_only')}`;
});
</script>

<template>
    <Head :title="$t('workstations.trash_title')" />

    <div v-if="isSuper" class="sap-form trash-page">
        <SectionHeader
            :back-href="route('business_management.workstations.index')"
            :title="$t('workstations.trash_title')"
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
                size="large"
                class="trash-search"
            >
                <template #prefix><SearchOutlined /></template>
            </Input>
        </div>

        <Card :bodyStyle="{ padding: 0 }" class="grid-card">
            <ResponsiveTable
                :dataSource="workstations.data"
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
                        <span v-if="record.deleter_name">{{ record.deleter_name }}</span>
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

                    <!-- Solo restaurar. Un catálogo de obra puede estar citado en
                         un plan firmado: aquí no se destruye nada de forma
                         definitiva (docs/UI.md §6). -->
                    <template v-else-if="column.key === 'actions'">
                        <Space :size="4">
                            <Popconfirm
                                :title="$t('global.restore') + '?'"
                                :description="$t('global.restore_hint')"
                                :ok-text="$t('global.restore')"
                                :cancel-text="$t('global.cancel')"
                                placement="topRight"
                                @confirm="restore(record)"
                            >
                                <Tooltip :title="$t('global.restore_hint')">
                                    <Button type="text" :loading="restoring === record.id">
                                        <UndoOutlined /> {{ $t('global.restore') }}
                                    </Button>
                                </Tooltip>
                            </Popconfirm>
                        </Space>
                    </template>
                </template>
            </ResponsiveTable>

            <Empty
                v-if="workstations.data.length === 0"
                :description="$t('global.no_deleted_records')"
                style="padding: 48px 16px"
            />
        </Card>

        <CatalogTrashBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :submitting="bulkRestoring"
            @cancel="clearSelection"
            @restore="bulkRestore"
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
.trash-toolbar { margin-bottom: 12px; }
.trash-search { max-width: 340px; }
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
/* Restaurar es la única acción y hay que acertarla con guantes. */
.grid-card :deep(.ant-table-tbody .ant-btn) { min-height: 44px; }
</style>
