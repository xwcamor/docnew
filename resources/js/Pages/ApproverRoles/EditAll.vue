<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Button, Card, Pagination, Alert } from 'ant-design-vue';
import { SafetyCertificateOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EditAllFooter from '@/Components/Common/EditAllFooter.vue';
import ApproverRolesEditAllTable from '@/Components/ApproverRoles/ApproverRolesEditAllTable.vue';

import { useEditAllDraft } from '@/Composables/useEditAllDraft';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

/**
 * Edición en masa del catálogo. El borrador vive en memoria: si se navega o se
 * recarga, se pierde — así nadie se encuentra cambios a medias de ayer.
 *
 * El código no se edita aquí: es la clave con la que las reglas nombran al rol,
 * y cambiarlo en una tabla de 50 filas es demasiado fácil de hacer sin querer.
 */
const props = defineProps({
    approver_roles: { type: Object, required: true },
    filters:   { type: Object, required: true },
});

const source = computed(() => props.approver_roles.data);
const { draft, isDirty, dirtyCount, dirtyChanges, duplicateRows, discardAll } = useEditAllDraft({
    source,
    editableFields: ['name_es', 'is_active'],
    uniqueField:    'name_es',
});

const submitting = ref(false);
const saveAll = () => {
    if (dirtyCount.value === 0 || duplicateRows.value.size > 0) return;
    submitting.value = true;
    router.post(
        route('business_management.approver_roles.edit_all.update'),
        { changes: dirtyChanges.value },
        {
            preserveScroll: true,
            onFinish: () => { submitting.value = false; },
        },
    );
};

const onPageChange = (page, pageSize) => {
    router.get(
        route('business_management.approver_roles.edit_all'),
        { ...props.filters, page, per_page: pageSize },
        { preserveScroll: true, replace: true },
    );
};
</script>

<template>
    <Head :title="$t('approver_roles.edit_all_title')" />

    <div class="edit-all sap-form">
        <SectionHeader
            :back-href="route('business_management.approver_roles.index')"
            :title="$t('global.edit_all') + ' — ' + $t('approver_roles.plural')"
            :subtitle="$t('approver_roles.edit_all_subtitle')"
        >
            <template #icon><SafetyCertificateOutlined /></template>
        </SectionHeader>

        <Alert
            v-if="duplicateRows.size > 0"
            type="error"
            show-icon
            :message="$t('approver_roles.edit_all_duplicate_names')"
            class="status-bar"
        />

        <Card :bodyStyle="{ padding: 0 }" class="edit-table-card">
            <ApproverRolesEditAllTable
                v-model:draft="draft"
                :is-dirty="isDirty"
                :duplicate-rows="duplicateRows"
            />

        <div v-if="approver_roles.total > approver_roles.per_page" class="edit-pagination">
            <Pagination
                :current="approver_roles.current_page"
                :pageSize="approver_roles.per_page"
                :total="approver_roles.total"
                :pageSizeOptions="['10', '25', '50', '100']"
                show-size-changer
                @change="onPageChange"
                @show-size-change="onPageChange"
            />
        </div>
        </Card>
        <EditAllFooter
            :discard-label="$t('approver_roles.edit_all_discard')"
            :save-label="$t('approver_roles.edit_all_save_all')"
            :discard-disabled="dirtyCount === 0"
            :save-disabled="dirtyCount === 0 || duplicateRows.size > 0"
            :submitting="submitting"
            :status-text="dirtyCount > 0 ? $tc('approver_roles.edit_all_changes', dirtyCount) : ''"
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
