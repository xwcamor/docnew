<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { Alert, Tag, Space } from 'ant-design-vue';
import { WarningOutlined } from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeletePage from '@/Components/Common/DeletePage.vue';
import DeleteSummaryRow from '@/Components/Common/DeleteSummaryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    role: { type: Object, required: true },
});

const form = useForm({
    deleted_description: '',
    _method: 'delete',
});

const submit = () => {
    form.post(route('user_management.roles.deleteSave', props.role.slug));
};
</script>

<template>
    <Head :title="$t('global.delete') + ' — ' + $t('roles.singular')" />

    <DeletePage
        :back-href="route('user_management.roles.index')"
        :title="$t('global.delete') + ' ' + $t('roles.record')"
        :subtitle="role.name"
        v-model="form.deleted_description"
        :error="form.errors.deleted_description"
        :processing="form.processing"
        :disabled="role.users_count > 0"
        @submit="submit"
    >
        <!-- Bloqueo propio del módulo: no se puede borrar un rol con usuarios. -->
        <template #warning>
            <Alert v-if="role.users_count > 0" type="error" show-icon class="del-mb">
                <template #message>
                    <Space><WarningOutlined /><strong>{{ $t('roles.delete_blocked_title') }}</strong></Space>
                </template>
                <template #description>
                    {{ $t('roles.delete_blocked_users_count', { count: role.users_count }) }}
                </template>
            </Alert>
        </template>

        <template #summary>
            <DeleteSummaryRow :label="$t('roles.name')">{{ role.name }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('roles.description')">{{ role.description || '—' }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('roles.permissions_count')">
                <Tag color="cyan" :bordered="false">{{ role.permissions_count }}</Tag>
            </DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('roles.users_count')">
                <Tag :color="role.users_count > 0 ? 'red' : 'default'" :bordered="false">{{ role.users_count }}</Tag>
            </DeleteSummaryRow>
        </template>
    </DeletePage>
</template>

<style scoped>
.del-mb { margin-bottom: 16px; }
</style>
