<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button, Card, Pagination, Alert } from 'ant-design-vue';
import { SaveOutlined, UndoOutlined, EditOutlined, TeamOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EditAllFooter from '@/Components/Common/EditAllFooter.vue';
import CustomersEditAllTable from '@/Components/Customers/CustomersEditAllTable.vue';

import { useEditAllDraft } from '@/Composables/useEditAllDraft';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

/**
 * Edit-All de Customers. Mantenemos snapshot `original` + `draft` mutable
 * via composable. Si el usuario navega/recarga, los drafts se pierden
 * (patron SAP).
 */
const props = defineProps({
    customers: { type: Object, required: true },
    filters:   { type: Object, required: true },
});

const source = computed(() => props.customers.data);
const { draft, isDirty, dirtyCount, dirtyChanges, duplicateRows, discardAll } = useEditAllDraft({
    source,
    editableFields: ['name', 'is_active'],
    uniqueField:    'name',
});

const submitting = ref(false);
const saveAll = () => {
    if (dirtyCount.value === 0 || duplicateRows.value.size > 0) return;
    submitting.value = true;
    router.post(
        route('business_management.customers.edit_all.update'),
        { changes: dirtyChanges.value },
        {
            preserveScroll: true,
            onFinish: () => { submitting.value = false; },
        },
    );
};

const onPageChange = (page, pageSize) => {
    router.get(
        route('business_management.customers.edit_all'),
        { ...props.filters, page, per_page: pageSize },
        { preserveScroll: true, replace: true },
    );
};
</script>

<template>
    <Head :title="$t('customers.edit_all_title')" />

    <div class="edit-all sap-form">
        <SectionHeader
            :back-href="route('business_management.customers.index')"
            :title="$t('global.edit_all') + ' — ' + $t('customers.plural')"
            :subtitle="$t('customers.edit_all_subtitle')"
        >
            <template #icon><TeamOutlined /></template>
        </SectionHeader>

        <Alert
            v-if="duplicateRows.size > 0"
            type="error"
            show-icon
            :message="$t('customers.name_unique')"
            class="status-bar"
        />

        <Card :bodyStyle="{ padding: 0 }" class="edit-table-card">
            <CustomersEditAllTable
                v-model:draft="draft"
                :is-dirty="isDirty"
                :duplicate-rows="duplicateRows"
            />

        <div v-if="customers.total > customers.per_page" class="edit-pagination">
            <Pagination
                :current="customers.current_page"
                :pageSize="customers.per_page"
                :total="customers.total"
                :pageSizeOptions="['10', '25', '50', '100']"
                show-size-changer
                @change="onPageChange"
                @show-size-change="onPageChange"
            />
        </div>
        </Card>

        <EditAllFooter
            :discard-label="$t('customers.edit_all_discard')"
            :save-label="$t('customers.edit_all_save_all')"
            :discard-disabled="dirtyCount === 0"
            :save-disabled="dirtyCount === 0 || duplicateRows.size > 0"
            :submitting="submitting"
            :status-text="dirtyCount > 0 ? $tc('customers.edit_all_changes', dirtyCount) : ''"
            @discard="discardAll"
            @save="saveAll"
        />
    </div>
</template>

<style scoped>
.status-bar { margin-bottom: 12px; }
/* La tabla queda como card BLANCA sobre el fondo gris de .sap-form (que por
   defecto vuelve las cards transparentes). */
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
