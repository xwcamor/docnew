<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { Alert, Tag } from 'ant-design-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeletePage from '@/Components/Common/DeletePage.vue';
import DeleteSummaryRow from '@/Components/Common/DeleteSummaryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    approvalRule:   { type: Object, required: true },
    // Aprobaciones de planes que nacieron de esta regla. No las borra nadie:
    // una firma dada es evidencia de que alguien aprobó el trabajo ese día.
    approvalsCount: { type: Number, default: 0 },
});

const form = useForm({ deleted_description: '' });

const submit = () => {
    form.delete(route('business_management.approval_rules.deleteSave', props.approvalRule.slug), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="$t('approval_rules.delete_title')" />

    <DeletePage
        :back-href="route('business_management.approval_rules.index')"
        :title="$t('approval_rules.delete_title')"
        :subtitle="approvalRule.display_name || approvalRule.approver_role_label"
        v-model="form.deleted_description"
        :error="form.errors.deleted_description"
        :processing="form.processing"
        @submit="submit"
    >
        <template #warning>
            <Alert v-if="approvalsCount > 0" type="warning" show-icon class="del-mb">
                <template #message>{{ $t('approval_rules.delete_title') }}</template>
                <template #description>
                    {{ $t('approval_rules.delete_warning_approvals', { count: approvalsCount }) }}
                </template>
            </Alert>
        </template>

        <template #summary>
            <DeleteSummaryRow :label="$t('approval_rules.name')">
                {{ approvalRule.name || $t('approval_rules.name_missing') }}
            </DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('approval_rules.country')">{{ approvalRule.country ?? '—' }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('approval_rules.work_type')">
                <Tag :color="approvalRule.work_type ? 'geekblue' : 'default'" :bordered="false">
                    {{ approvalRule.work_type ?? $t('approval_rules.all_work_types') }}
                </Tag>
            </DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('approval_rules.approver_role')">
                {{ approvalRule.approver_role_label }}
            </DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('approval_rules.priority_level')">{{ approvalRule.priority_level }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('approval_rules.is_required')">
                <Tag :color="approvalRule.is_required ? 'red' : 'default'" :bordered="false">
                    {{ approvalRule.is_required ? $t('approval_rules.required') : $t('approval_rules.optional') }}
                </Tag>
            </DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('approval_rules.is_active')">
                <Tag :color="approvalRule.is_active ? 'success' : 'error'" :bordered="false">
                    {{ approvalRule.is_active ? $t('global.active') : $t('global.inactive') }}
                </Tag>
            </DeleteSummaryRow>
        </template>
    </DeletePage>
</template>

<style scoped>
.del-mb { margin-bottom: 16px; }
</style>
