<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Space, Tooltip, Input, Alert, Popconfirm,
} from 'ant-design-vue';
import {
    DeleteOutlined, UndoOutlined, SearchOutlined, FireOutlined,
} from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import RolesForceDeleteModal from '@/Components/Roles/RolesForceDeleteModal.vue';
import RolesTrashBulkBar from '@/Components/Roles/RolesTrashBulkBar.vue';
import { useAuth } from '@/Composables/useAuth';
import { useModuleRestore } from '@/Composables/useModuleRestore';
import { useDateFormat } from '@/Composables/useDateFormat';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

const { formatDateTime } = useDateFormat();

const props = defineProps({
    roles:   { type: Object, required: true },
    filters: { type: Object, required: true },
});

const { isSuper } = useAuth();

// Belt-and-suspenders: la papelera de modulos no-core es super only.
// El backend ya lo gatea con role:super, pero si alguien llega via
// URL directa lo devolvemos al listado.
if (!isSuper.value) {
    router.visit(route('user_management.roles.index'));
}

const searchTerm = ref(props.filters.name ?? '');
const reload = (extra = {}) => {
    router.reload({
        only: ['roles', 'filters'],
        data: {
            name:     searchTerm.value || undefined,
            per_page: props.filters.per_page,
            page:     1,
            ...extra,
        },
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const onSearchEnter = () => reload();

// ─── Restore (individual + bulk) via useModuleRestore composable ───────────
const {
    restoring, restore: restoreRole,
    selectedRowKeys, rowSelection, clearSelection,
    bulkRestoring, bulkRestore,
} = useModuleRestore({
    restoreRouteName:     'user_management.roles.restore',
    bulkRestoreRouteName: 'user_management.roles.bulk_restore',
});

// ─── Force-delete (hard delete con triple guard) ───────────────────────────
const fdModalOpen     = ref(false);
const fdTarget        = ref(null);
const fdForm          = ref({ name_confirmation: '', reason: '' });
const fdSubmitting    = ref(false);
const fdErrors        = ref({});

const openForceDelete = (role) => {
    fdTarget.value  = role;
    fdForm.value    = { name_confirmation: '', reason: '' };
    fdErrors.value  = {};
    fdModalOpen.value = true;
};
const submitForceDelete = () => {
    if (!fdTarget.value) return;
    fdSubmitting.value = true;
    router.delete(route('user_management.roles.force_delete', fdTarget.value.slug ?? fdTarget.value.id), {
        data: fdForm.value,
        preserveScroll: true,
        onSuccess: () => { fdModalOpen.value = false; },
        onError:   (errs) => { fdErrors.value = errs; },
        onFinish:  () => { fdSubmitting.value = false; },
    });
};

const columns = computed(() => [
    { title: 'ID',                              dataIndex: 'id',                  key: 'id',          width: 80,  mobile: { role: 'meta' } },
    { title: t('roles.name'),                   dataIndex: 'name',                key: 'name',        ellipsis: true, mobile: { role: 'title' } },
    { title: t('global.deleted_at'),            dataIndex: 'deleted_at',          key: 'deleted_at',  width: 180, mobile: { role: 'meta' } },
    { title: t('global.deleted_by'),            key: 'deleter',                   width: 200, mobile: { role: 'meta' } },
    { title: t('global.delete_description'),    dataIndex: 'deleted_description', key: 'reason',      ellipsis: true, mobile: { role: 'hidden' } },
    { title: t('global.actions'),               key: 'actions',                   width: 180, fixed: 'right', align: 'right', mobile: { role: 'actions' } },
]);

const tablePagination = computed(() => ({
    current:  props.roles.current_page,
    pageSize: props.roles.per_page,
    total:    props.roles.total,
    showSizeChanger: true,
    pageSizeOptions: ['10', '25', '50', '100', '200'],
}));

const onTableChange = (pag) => reload({ page: pag.current, per_page: pag.pageSize });
</script>

<template>
    <Head :title="$t('global.view_deleted') + ' — ' + $t('roles.plural')" />
    <div class="index-page sap-form trash-page">
        <SectionHeader
            :back-href="route('user_management.roles.index')"
            :title="$t('global.view_deleted') + ' — ' + $t('roles.plural')"
            :subtitle="$t('roles.trash_subtitle') || 'Perfiles eliminados (recuperables por 30 días).'"
        >
            <template #icon><DeleteOutlined /></template>
        </SectionHeader>

        <div class="trash-toolbar">
            <Input
                v-model:value="searchTerm"
                :placeholder="$t('roles.search_placeholder')"
                allow-clear
                class="trash-search"
                @press-enter="onSearchEnter"
                @change="onSearchEnter"
            >
                <template #prefix><SearchOutlined /></template>
            </Input>
        </div>

        <Alert
            v-if="isSuper"
            type="warning"
            show-icon
            class="mb-3"
            :message="$t('roles.trash_super_warning') || 'Como super puedes eliminar permanentemente. Esta acción NO se puede revertir.'"
        />

        <Card :bodyStyle="{ padding: 0 }">

            <ResponsiveTable
                :dataSource="roles.data"
                :view="'table'"
                :scroll="{ x: 'max-content' }"
                :columns="columns"
                :pagination="tablePagination"
                :rowSelection="rowSelection"
                rowKey="id"
                @change="onTableChange"
            >
                <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'name'">
                        <Space wrap>
                            <strong>{{ record.name }}</strong>
                            <Tag v-if="record.tenant_id" color="blue" :bordered="false">{{ record.tenant_name ?? '—' }}</Tag>
                            <Tag v-else color="orange" :bordered="false">{{ $t('roles.tag_global') }}</Tag>
                        </Space>
                    </template>

                    <template v-else-if="column.key === 'deleted_at'">
                        {{ formatDateTime(record.deleted_at) }}
                    </template>

                    <template v-else-if="column.key === 'deleter'">
                        <span v-if="record.deleter">{{ record.deleter.name }}</span>
                        <span v-else class="muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'actions'">
                        <Space :size="4">
                            <Popconfirm
                                :title="$t('global.restore') + '?'"
                                :ok-text="$t('global.restore')"
                                :cancel-text="$t('global.cancel')"
                                placement="topRight"
                                @confirm="restoreRole(record)"
                            >
                                <Tooltip :title="$t('global.restore_hint')">
                                    <Button size="small" type="text" :loading="restoring === record.id">
                                        <UndoOutlined />
                                    </Button>
                                </Tooltip>
                            </Popconfirm>
                            <Tooltip v-if="isSuper" :title="$t('global.force_delete_hint')">
                                <Button size="small" type="text" danger @click="openForceDelete(record)">
                                    <FireOutlined />
                                </Button>
                            </Tooltip>
                        </Space>
                    </template>
                </template>
            </ResponsiveTable>
        </Card>

        <RolesTrashBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :submitting="bulkRestoring"
            @cancel="clearSelection"
            @restore="bulkRestore"
        />

        <RolesForceDeleteModal
            v-model:open="fdModalOpen"
            v-model:form="fdForm"
            :target="fdTarget"
            :submitting="fdSubmitting"
            :errors="fdErrors"
            @confirm="submitForceDelete"
        />
    </div>
</template>

<style scoped>
.index-page { width: 100%; max-width: none; }
.mb-2 { margin-bottom: 8px; }
.mb-3 { margin-bottom: 16px; }
.muted { color: var(--color-text-muted); }
</style>
