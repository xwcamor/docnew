<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeletePage from '@/Components/Common/DeletePage.vue';
import DeleteSummaryRow from '@/Components/Common/DeleteSummaryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    message: { type: Object, required: true },
});

const form = useForm({
    deleted_description: '',
});

const submit = () => {
    form.delete(route('communication.messages.deleteSave', props.message.slug), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="$t('global.delete') + ' — ' + $t('messages.singular')" />

    <DeletePage
        :back-href="route('communication.messages.show', message.slug)"
        :title="$t('messages.delete_title')"
        :subtitle="message.subject"
        v-model="form.deleted_description"
        :error="form.errors.deleted_description"
        :processing="form.processing"
        @submit="submit"
    >
        <template #summary>
            <DeleteSummaryRow :label="$t('messages.subject')">{{ message.subject }}</DeleteSummaryRow>
        </template>
    </DeletePage>
</template>
