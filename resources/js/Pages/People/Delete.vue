<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Alert, Tag } from 'ant-design-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeletePage from '@/Components/Common/DeletePage.vue';
import DeleteSummaryRow from '@/Components/Common/DeleteSummaryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    person:      { type: Object, required: true },
    dependents: { type: Object, default: () => ({}) },
});

const hasDependents = computed(() => Object.keys(props.dependents).length > 0);

const form = useForm({
    deleted_description: '',
});

const submit = () => {
    form.delete(route('business_management.people.deleteSave', props.person.slug), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="$t('global.delete') + ' — ' + $t('people.singular')" />

    <DeletePage
        :back-href="route('business_management.people.index')"
        :title="$t('global.delete') + ' ' + $t('people.record')"
        :subtitle="person.full_name"
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
                            {{ $tc('global.has_dependents_detail', d.count, { count: d.count, label: d.label }) }}
                        </li>
                    </ul>
                    <p class="dependents-note">{{ $t('global.has_dependents_proceed') }}</p>
                </template>
            </Alert>
        </template>

        <template #summary>
            <DeleteSummaryRow :label="$t('people.full_name')">{{ person.full_name }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('people.document')">
                {{ person.doc_type }} <code>{{ person.num_doc }}</code>
            </DeleteSummaryRow>
            <DeleteSummaryRow v-if="person.country" :label="$t('people.country')">{{ person.country.name }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('people.companies')">{{ person.companies_count ?? 0 }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('people.is_active')">
                <Tag :color="person.is_active ? 'success' : 'error'" :bordered="false">
                    {{ person.is_active ? $t('global.active') : $t('global.inactive') }}
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
