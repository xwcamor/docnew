<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Alert, Tag } from 'ant-design-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeletePage from '@/Components/Common/DeletePage.vue';
import DeleteSummaryRow from '@/Components/Common/DeleteSummaryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    brand:      { type: Object, required: true },
    dependents: { type: Object, default: () => ({}) },
});

const hasDependents = computed(() => Object.keys(props.dependents).length > 0);

const form = useForm({
    deleted_description: '',
});

const submit = () => {
    form.delete(route('business_management.brands.deleteSave', props.brand.slug), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="$t('global.delete') + ' — ' + $t('brands.singular')" />

    <DeletePage
        :back-href="route('business_management.brands.index')"
        :title="$t('global.delete') + ' ' + $t('brands.record')"
        :subtitle="brand.name"
        v-model="form.deleted_description"
        :error="form.errors.deleted_description"
        :processing="form.processing"
        @submit="submit"
    >
        <!-- Registros dependientes: advertencia propia del módulo. -->
        <template #warning>
            <Alert v-if="hasDependents" type="error" show-icon class="del-mb">
                <template #message>{{ $t('global.has_dependents_warning') }}</template>
                <template #description>
                    <ul class="dependents-list">
                        <li v-for="(d, key) in dependents" :key="key">
                            {{ $t('global.has_dependents_detail', { count: d.count, label: d.label }) }}
                        </li>
                    </ul>
                    <p class="dependents-note">{{ $t('global.has_dependents_proceed') }}</p>
                </template>
            </Alert>
        </template>

        <template #summary>
            <DeleteSummaryRow :label="$t('brands.name')">{{ brand.name }}</DeleteSummaryRow>
            <DeleteSummaryRow v-if="brand.code" :label="$t('brands.code')">
                <code>{{ brand.code }}</code>
            </DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('brands.is_active')">
                <Tag :color="brand.is_active ? 'success' : 'error'" :bordered="false">
                    {{ brand.is_active ? $t('global.active') : $t('global.inactive') }}
                </Tag>
            </DeleteSummaryRow>
        </template>
    </DeletePage>
</template>

<style scoped>
.del-mb { margin-bottom: 16px; }
.dependents-list { margin: 4px 0 8px 0; padding-left: 20px; font-size: 0.875rem; }
.dependents-list li { line-height: 1.5; }
.dependents-note { margin: 4px 0 0 0; font-size: 0.78rem; color: var(--color-text-muted); font-style: italic; }
</style>
