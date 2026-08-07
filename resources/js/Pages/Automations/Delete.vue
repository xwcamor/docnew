<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeletePage from '@/Components/Common/DeletePage.vue';
import DeleteSummaryRow from '@/Components/Common/DeleteSummaryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    automation: { type: Object, required: true },
});

const form = useForm({ deleted_description: '' });

const submit = () => {
    form.delete(route('automation_management.automations.deleteSave', props.automation.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="$t('global.delete') + ' — ' + $t('automations.singular')" />

    <DeletePage
        :back-href="route('automation_management.automations.index')"
        :title="$t('global.delete') + ' ' + $t('automations.record')"
        :subtitle="automation.name"
        v-model="form.deleted_description"
        :error="form.errors.deleted_description"
        :processing="form.processing"
        @submit="submit"
    >
        <template #summary>
            <DeleteSummaryRow :label="$t('automations.name')">{{ automation.name }}</DeleteSummaryRow>
        </template>
    </DeletePage>
</template>
