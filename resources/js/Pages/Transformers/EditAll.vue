<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button, Card, Pagination, Alert } from 'ant-design-vue';
import { SaveOutlined, UndoOutlined, EditOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EditAllFooter from '@/Components/Common/EditAllFooter.vue';
import TransformerIcon from '@/Components/Transformers/TransformerIcon.vue';
import TransformersEditAllTable from '@/Components/Transformers/TransformersEditAllTable.vue';

import { useEditAllDraft } from '@/Composables/useEditAllDraft';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

/**
 * Edit-All de Transformers. Mantenemos snapshot `original` + `draft` mutable
 * via composable. Si el usuario navega/recarga, los drafts se pierden
 * (patron SAP).
 */
const props = defineProps({
    transformers: { type: Object, required: true },
    filters:   { type: Object, required: true },
});

const source = computed(() => props.transformers.data);
const { draft, isDirty, dirtyCount, dirtyChanges, duplicateRows, discardAll } = useEditAllDraft({
    source,
    editableFields: ['serial'],
    uniqueField:    null,
});

const submitting = ref(false);
const saveAll = () => {
    if (dirtyCount.value === 0 || duplicateRows.value.size > 0) return;
    submitting.value = true;
    router.post(
        route('business_management.transformers.edit_all.update'),
        { changes: dirtyChanges.value },
        {
            preserveScroll: true,
            onFinish: () => { submitting.value = false; },
        },
    );
};

const onPageChange = (page, pageSize) => {
    router.get(
        route('business_management.transformers.edit_all'),
        { ...props.filters, page, per_page: pageSize },
        { preserveScroll: true, replace: true },
    );
};
</script>

<template>
    <Head :title="$t('transformers.edit_all_title')" />

    <div class="edit-all sap-form">
        <SectionHeader
            :back-href="route('business_management.transformers.index')"
            :title="$t('global.edit_all') + ' — ' + $t('transformers.plural')"
            :subtitle="$t('transformers.edit_all_subtitle')"
        >
            <template #icon><TransformerIcon /></template>
        </SectionHeader>

        <Alert
            v-if="duplicateRows.size > 0"
            type="error"
            show-icon
            :message="$t('transformers.name_unique')"
            class="status-bar"
        />

        <Card :bodyStyle="{ padding: 0 }" class="edit-table-card">
            <TransformersEditAllTable
                v-model:draft="draft"
                :is-dirty="isDirty"
                :duplicate-rows="duplicateRows"
            />

        <div v-if="transformers.total > transformers.per_page" class="edit-pagination">
            <Pagination
                :current="transformers.current_page"
                :pageSize="transformers.per_page"
                :total="transformers.total"
                :pageSizeOptions="['10', '25', '50', '100']"
                show-size-changer
                @change="onPageChange"
                @show-size-change="onPageChange"
            />
        </div>
        </Card>
        <EditAllFooter
            :discard-label="$t('transformers.edit_all_discard')"
            :save-label="$t('transformers.edit_all_save_all')"
            :discard-disabled="dirtyCount === 0"
            :save-disabled="dirtyCount === 0 || duplicateRows.size > 0"
            :submitting="submitting"
            :status-text="dirtyCount > 0 ? $tc('transformers.edit_all_changes', dirtyCount) : ''"
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
