<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Alert } from 'ant-design-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeletePage from '@/Components/Common/DeletePage.vue';
import DeleteSummaryRow from '@/Components/Common/DeleteSummaryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    transformer: { type: Object, required: true },
    dependents: { type: Object, default: () => ({}) },
});

const hasDependents = computed(() => Object.keys(props.dependents).length > 0);

const form = useForm({
    deleted_description: '',
});

const submit = () => {
    form.delete(route('business_management.transformers.deleteSave', props.transformer.slug), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="$t('global.delete') + ' — ' + $t('transformers.singular')" />

    <DeletePage
        :back-href="route('business_management.transformers.index')"
        :title="$t('global.delete') + ' ' + $t('transformers.record')"
        :subtitle="transformer.serial || transformer.tag"
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
            <DeleteSummaryRow v-if="transformer.serial" :label="$t('transformers.serial')">
                <code>{{ transformer.serial }}</code>
            </DeleteSummaryRow>
            <DeleteSummaryRow v-if="transformer.tag" :label="$t('transformers.tag')">
                {{ transformer.tag }}
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
