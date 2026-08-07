<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button, Card, Pagination } from 'ant-design-vue';
import { SaveOutlined, UndoOutlined, EditOutlined, ScheduleOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EditAllFooter from '@/Components/Common/EditAllFooter.vue';
import WorkPlansEditAllTable from '@/Components/WorkPlans/WorkPlansEditAllTable.vue';

import { useEditAllDraft } from '@/Composables/useEditAllDraft';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

/**
 * Edit-All de WorkPlans. Mantenemos snapshot `original` + `draft` mutable
 * via composable. Si el usuario navega/recarga, los drafts se pierden
 * (patron SAP).
 */
const props = defineProps({
    work_plans: { type: Object, required: true },
    filters:   { type: Object, required: true },
});

const source = computed(() => props.work_plans.data);
const { draft, isDirty, dirtyCount, dirtyChanges, duplicateRows, discardAll } = useEditAllDraft({
    source,
    // El codigo no se edita aqui, asi que no hay campo unico que vigilar.
    editableFields: ['num_os', 'is_done'],
    uniqueField:    null,
});

const submitting = ref(false);
const saveAll = () => {
    if (dirtyCount.value === 0 || duplicateRows.value.size > 0) return;
    submitting.value = true;
    router.post(
        route('business_management.work_plans.edit_all.update'),
        { changes: dirtyChanges.value },
        {
            preserveScroll: true,
            onFinish: () => { submitting.value = false; },
        },
    );
};

const onPageChange = (page, pageSize) => {
    router.get(
        route('business_management.work_plans.edit_all'),
        { ...props.filters, page, per_page: pageSize },
        { preserveScroll: true, replace: true },
    );
};
</script>

<template>
    <Head :title="$t('work_plans.edit_all_title')" />

    <div class="edit-all sap-form">
        <SectionHeader
            :back-href="route('business_management.work_plans.index')"
            :title="$t('global.edit_all') + ' — ' + $t('work_plans.plural')"
            :subtitle="$t('work_plans.edit_all_subtitle')"
        >
            <template #icon><ScheduleOutlined /></template>
        </SectionHeader>

        <Card :bodyStyle="{ padding: 0 }" class="edit-table-card">
            <WorkPlansEditAllTable
                v-model:draft="draft"
                :is-dirty="isDirty"
                :duplicate-rows="duplicateRows"
            />

        <div v-if="work_plans.total > work_plans.per_page" class="edit-pagination">
            <Pagination
                :current="work_plans.current_page"
                :pageSize="work_plans.per_page"
                :total="work_plans.total"
                :pageSizeOptions="['10', '25', '50', '100']"
                show-size-changer
                @change="onPageChange"
                @show-size-change="onPageChange"
            />
        </div>
        </Card>
        <EditAllFooter
            :discard-label="$t('work_plans.edit_all_discard')"
            :save-label="$t('work_plans.edit_all_save_all')"
            :discard-disabled="dirtyCount === 0"
            :save-disabled="dirtyCount === 0 || duplicateRows.size > 0"
            :submitting="submitting"
            :status-text="dirtyCount > 0 ? $tc('work_plans.edit_all_changes', dirtyCount) : ''"
            @discard="discardAll"
            @save="saveAll"
        />

    </div>
</template>

<style scoped>
.status-bar { margin-bottom: 12px; }
/* La tabla queda como card BLANCA sobre el fondo gris de .sap-form. */
.sap-form .edit-table-card {
    background: var(--color-surface, #fff) !important;
    border: 1px solid var(--color-border, #e8eaed) !important;
    border-radius: 12px;
    box-shadow: 0 1px 2px rgba(16,24,40,0.04), 0 4px 12px rgba(16,24,40,0.04);
}

.pagination {
    display: flex;
    justify-content: center;
    margin-top: 16px;
}
</style>
