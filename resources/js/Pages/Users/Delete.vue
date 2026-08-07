<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { Tag } from 'ant-design-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeletePage from '@/Components/Common/DeletePage.vue';
import DeleteSummaryRow from '@/Components/Common/DeleteSummaryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    user: { type: Object, required: true },
});

const form = useForm({
    deleted_description: '',
});

const submit = () => {
    form.delete(route('user_management.users.deleteSave', props.user.slug), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="$t('global.delete') + ' — ' + $t('users.singular')" />

    <DeletePage
        :back-href="route('user_management.users.index')"
        :title="$t('global.delete') + ' ' + $t('users.record')"
        :subtitle="user.name"
        v-model="form.deleted_description"
        :error="form.errors.deleted_description"
        :processing="form.processing"
        @submit="submit"
    >
        <template #summary>
            <DeleteSummaryRow :label="$t('users.name')">{{ user.name }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('users.email')">{{ user.email }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('users.is_active')">
                <Tag :color="user.is_active ? 'success' : 'error'" :bordered="false">
                    {{ user.is_active ? $t('global.active') : $t('global.inactive') }}
                </Tag>
            </DeleteSummaryRow>
        </template>
    </DeletePage>
</template>
