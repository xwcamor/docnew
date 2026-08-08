<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Alert, Tag, Button } from 'ant-design-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeletePage from '@/Components/Common/DeletePage.vue';
import DeleteSummaryRow from '@/Components/Common/DeleteSummaryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    workArea: { type: Object, required: true },
    // Por qué esta fila NO se puede borrar, ya traducido. Viene del servicio:
    // es la misma comprobación que hará el backend al confirmar.
    blockedReason: { type: String, default: null },
});

const blocked = computed(() => !!props.blockedReason);

const form = useForm({ deleted_description: '' });

const submit = () => {
    form.delete(route('business_management.work_areas.deleteSave', props.workArea.slug), {
        preserveScroll: true,
    });
};

// La salida que se le ofrece a quien no puede borrar: que deje de ofrecerse
// sin tocar lo que ya la nombra.
const deactivating = ref(false);
const deactivate = () => {
    deactivating.value = true;
    router.post(route('business_management.work_areas.deactivate', props.workArea.slug), {}, {
        onFinish: () => { deactivating.value = false; },
    });
};
</script>

<template>
    <Head :title="$t('work_areas.delete_title')" />

    <DeletePage
        :back-href="route('business_management.work_areas.index')"
        :title="$t('work_areas.delete_title')"
        :subtitle="workArea.name"
        v-model="form.deleted_description"
        :error="form.errors.deleted_description"
        :processing="form.processing"
        :disabled="blocked"
        @submit="submit"
    >
        <template #warning>
            <!-- Si la fila está en uso se dice AQUÍ y no después de escribir el
                 motivo: pedir un motivo para un borrado que se va a rechazar es
                 hacer perder el tiempo. -->
            <Alert v-if="blocked" type="error" show-icon class="del-mb">
                <template #message>{{ $t('work_areas.delete_title') }}</template>
                <template #description>
                    <p class="blocked-reason">{{ blockedReason }}</p>
                    <Button
                        v-if="workArea.is_active"
                        type="primary"
                        size="large"
                        :loading="deactivating"
                        @click="deactivate"
                    >
                        {{ $t('work_areas.deactivate_instead') }}
                    </Button>
                </template>
            </Alert>
        </template>

        <template #summary>
            <DeleteSummaryRow :label="$t('work_areas.name')">{{ workArea.name }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('work_areas.country')">{{ workArea.country }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('work_areas.usage_count')">{{ workArea.usage_count }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('work_areas.is_active')">
                <Tag :color="workArea.is_active ? 'success' : 'error'" :bordered="false">
                    {{ workArea.is_active ? $t('global.active') : $t('global.inactive') }}
                </Tag>
            </DeleteSummaryRow>
        </template>
    </DeletePage>
</template>

<style scoped>
.del-mb { margin-bottom: 16px; }
.blocked-reason { margin: 4px 0 12px 0; line-height: 1.5; }
</style>
