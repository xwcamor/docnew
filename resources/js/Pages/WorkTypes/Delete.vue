<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { Alert, Tag } from 'ant-design-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeletePage from '@/Components/Common/DeletePage.vue';
import DeleteSummaryRow from '@/Components/Common/DeleteSummaryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    workType: { type: Object, required: true },
    // Planes en obra de este tipo. Al eliminarlo dejan de saber qué formatos se
    // les exigen: es la consecuencia que hay que ver ANTES de escribir el motivo.
    openPlans:  { type: Number, default: 0 },
    plansCount: { type: Number, default: 0 },
    formsCount: { type: Number, default: 0 },
});

const form = useForm({ deleted_description: '' });

const submit = () => {
    form.delete(route('business_management.work_types.deleteSave', props.workType.slug), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="$t('work_types.delete_title')" />

    <DeletePage
        :back-href="route('business_management.work_types.index')"
        :title="$t('work_types.delete_title')"
        :subtitle="workType.label ?? workType.code"
        v-model="form.deleted_description"
        :error="form.errors.deleted_description"
        :processing="form.processing"
        @submit="submit"
    >
        <template #warning>
            <Alert v-if="openPlans > 0" type="warning" show-icon class="del-mb">
                <template #message>{{ $t('work_types.open_plans') }}: {{ openPlans }}</template>
                <template #description>
                    {{ $t('work_types.delete_warning_open_plans', { count: openPlans }) }}
                </template>
            </Alert>
            <Alert v-if="formsCount > 0" type="info" show-icon class="del-mb">
                <template #message>{{ $t('work_types.forms_count') }}: {{ formsCount }}</template>
                <template #description>
                    {{ $t('work_types.delete_warning_forms', { count: formsCount }) }}
                </template>
            </Alert>
        </template>

        <template #summary>
            <DeleteSummaryRow :label="$t('work_types.name')">{{ workType.name ?? '—' }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('work_types.code')">{{ workType.code }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('work_types.country')">{{ workType.country ?? '—' }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('work_types.forms_count')">
                <Tag :color="formsCount > 0 ? 'geekblue' : 'default'" :bordered="false">{{ formsCount }}</Tag>
            </DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('work_plans.plural')">{{ plansCount }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('work_types.is_active')">
                <Tag :color="workType.is_active ? 'success' : 'error'" :bordered="false">
                    {{ workType.is_active ? $t('global.active') : $t('global.inactive') }}
                </Tag>
            </DeleteSummaryRow>
        </template>
    </DeletePage>
</template>

<style scoped>
.del-mb { margin-bottom: 16px; }
</style>
