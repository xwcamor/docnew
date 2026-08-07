<script setup>
import { ref, computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Button, Card, Space, Tooltip, Popconfirm, Empty, Tag, Modal, Alert, Input,
} from 'ant-design-vue';
import {
    DeleteOutlined, UndoOutlined, FireOutlined, ExclamationCircleFilled,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import { useI18n } from '@/Plugins/i18n';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const { formatDateTime } = useDateFormat();

const props = defineProps({
    automations: { type: Object, required: true },
});

const page = usePage();
const isSuper = page.props.auth?.user?.roles?.includes('super');
if (!isSuper) router.visit(route('automation_management.automations.index'));

const restoring = ref(null);
const restore = (a) => {
    restoring.value = a.id;
    router.post(route('automation_management.automations.restore', a.id), {}, {
        preserveScroll: true,
        onFinish: () => { restoring.value = null; },
    });
};

const forceOpen       = ref(false);
const forceTarget     = ref(null);
const forceForm       = ref({ name_confirmation: '', reason: '' });
const forceSubmitting = ref(false);
const forceErrors     = ref({});

const openForce = (a) => {
    forceTarget.value = a;
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
    forceSubmitting.value = true;
    router.delete(
        route('automation_management.automations.force_delete', forceTarget.value.id),
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
    { title: t('automations.col_name'),     dataIndex: 'name',                key: 'name' },
    { title: t('global.deleted_at'),        dataIndex: 'deleted_at',          key: 'deleted_at', width: 180 },
    { title: t('global.deleted_by'),        key: 'deleter',                   width: 180 },
    { title: t('global.delete_description'), dataIndex: 'deleted_description', key: 'reason', ellipsis: true },
    { title: t('global.actions'),           key: 'actions',                   width: 180, fixed: 'right' },
]);
</script>

<template>
    <Head :title="$t('global.view_deleted') + ' — ' + $t('automations.plural')" />

    <div v-if="isSuper" class="sap-form trash-page">
        <SectionHeader
            :back-href="route('automation_management.automations.index')"
            :title="$t('global.view_deleted') + ' — ' + $t('automations.plural')"
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

        <Card :bodyStyle="{ padding: 0 }">
            <ResponsiveTable
                :dataSource="automations.data"
                :view="'table'"
                :scroll="{ x: 'max-content' }"
                :columns="columns"
                :pagination="false"
                rowKey="id"
            >
                <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'deleted_at'">
                        {{ formatDateTime(record.deleted_at) }}
                    </template>
                    <template v-else-if="column.key === 'deleter'">
                        {{ record.deleter?.name ?? '—' }}
                    </template>
                    <template v-else-if="column.key === 'actions'">
                        <Space :size="4">
                            <Popconfirm
                                :title="$t('global.restore') + '?'"
                                :ok-text="$t('global.restore')"
                                :cancel-text="$t('global.cancel')"
                                @confirm="restore(record)"
                            >
                                <Button size="small" type="text" :loading="restoring === record.id">
                                    <UndoOutlined /> {{ $t('global.restore') }}
                                </Button>
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

            <Empty v-if="automations.data.length === 0" :description="$t('global.no_deleted_records')" style="padding: 48px 16px" />
        </Card>

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
            <p>{{ $t('global.force_delete_name_prompt', { name: forceTarget?.name }) }}</p>
            <Input v-model:value="forceForm.name_confirmation" :placeholder="forceTarget?.name" size="large" class="mb-3" />
            <p>{{ $t('global.force_delete_reason_prompt') }}</p>
            <Input.TextArea v-model:value="forceForm.reason" :rows="3" :maxlength="500" show-count />
        </Modal>
    </div>
</template>

<style scoped>
.mb-3 { margin-bottom: 12px; }
</style>
