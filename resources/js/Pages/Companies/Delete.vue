<script setup>
import { computed } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { Alert, Tag, Button } from 'ant-design-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeletePage from '@/Components/Common/DeletePage.vue';
import DeleteSummaryRow from '@/Components/Common/DeleteSummaryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    company:    { type: Object, required: true },
    // Lo que cuelga de la empresa: planes y gente vinculada, con su conteo.
    dependents: { type: Object, default: () => ({}) },
    // Por qué NO se puede borrar, ya traducido. Lo calcula el mismo servicio
    // que rechaza el borrado al confirmar, así que no pueden discrepar.
    blockedReason: { type: String, default: null },
});

const hasDependents = computed(() => Object.keys(props.dependents).length > 0);
const blocked       = computed(() => !!props.blockedReason);

const form = useForm({
    deleted_description: '',
});

const submit = () => {
    form.delete(route('business_management.companies.deleteSave', props.company.slug), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="$t('global.delete') + ' — ' + $t('companies.singular')" />

    <DeletePage
        :back-href="route('business_management.companies.index')"
        :title="$t('global.delete') + ' ' + $t('companies.record')"
        :subtitle="company.name"
        v-model="form.deleted_description"
        :error="form.errors.deleted_description"
        :processing="form.processing"
        :disabled="blocked"
        @submit="submit"
    >
        <!--
            Si de la empresa cuelgan planes o gente se dice AQUÍ, antes de pedir
            el motivo, y el botón de eliminar sale deshabilitado: un botón que
            falla al pulsarlo es peor que un botón que no está (docs/UI.md §6).
            La salida que se ofrece es desactivarla, que la saca de los planes
            nuevos sin tocar los firmados.
        -->
        <template #warning>
            <Alert v-if="hasDependents" :type="blocked ? 'error' : 'warning'" show-icon class="del-mb">
                <template #message>{{ $t('companies.delete_dependents_title') }}</template>
                <template #description>
                    <ul class="dependents-list">
                        <li v-if="dependents.work_plans">
                            {{ $t('companies.delete_dependents_plans', { count: dependents.work_plans.count }) }}
                        </li>
                        <li v-if="dependents.people">
                            {{ $t('companies.delete_dependents_people', { count: dependents.people.count }) }}
                        </li>
                    </ul>
                    <p v-if="blocked" class="blocked-reason">{{ blockedReason }}</p>
                    <Link v-if="blocked && company.is_active" :href="route('business_management.companies.edit', company.slug)">
                        <Button type="primary" size="large">{{ $t('global.edit') }}</Button>
                    </Link>
                </template>
            </Alert>
        </template>

        <template #summary>
            <DeleteSummaryRow :label="$t('companies.name')">{{ company.name }}</DeleteSummaryRow>
            <DeleteSummaryRow v-if="company.complete_name" :label="$t('companies.complete_name')">{{ company.complete_name }}</DeleteSummaryRow>
            <DeleteSummaryRow v-if="company.num_doc" :label="$t('companies.num_doc')">
                <code>{{ company.num_doc }}</code>
            </DeleteSummaryRow>
            <DeleteSummaryRow v-if="company.country" :label="$t('companies.country')">{{ company.country.name }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('companies.people_count')">{{ company.people_count ?? 0 }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('companies.plans_count')">{{ company.work_plans_count ?? 0 }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('companies.is_active')">
                <Tag :color="company.is_active ? 'success' : 'default'" :bordered="false">
                    {{ company.is_active ? $t('global.active') : $t('global.inactive') }}
                </Tag>
            </DeleteSummaryRow>
        </template>
    </DeletePage>
</template>

<style scoped>
.del-mb { margin-bottom: 16px; }
.dependents-list { margin: 4px 0 8px 0; padding-left: 20px; font-size: 0.875rem; }
.dependents-list li { line-height: 1.5; }
.blocked-reason { margin: 4px 0 12px 0; line-height: 1.5; font-weight: 500; }
</style>
