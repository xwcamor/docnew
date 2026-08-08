<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Alert, Tag, Button } from 'ant-design-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeletePage from '@/Components/Common/DeletePage.vue';
import DeleteSummaryRow from '@/Components/Common/DeleteSummaryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    workstation: { type: Object, required: true },
    // Por qué esta fila NO se puede borrar, ya traducido. Viene del servicio:
    // es la misma comprobación que hará el backend al confirmar.
    blockedReason: { type: String, default: null },
});

const blocked = computed(() => !!props.blockedReason);

const form = useForm({ deleted_description: '' });

const submit = () => {
    form.delete(route('business_management.workstations.deleteSave', props.workstation.slug), {
        preserveScroll: true,
    });
};

// La salida que se le ofrece a quien no puede borrar: que deje de ofrecerse
// sin tocar lo que ya la nombra.
const deactivating = ref(false);
const deactivate = () => {
    deactivating.value = true;
    router.post(route('business_management.workstations.deactivate', props.workstation.slug), {}, {
        onFinish: () => { deactivating.value = false; },
    });
};
</script>

<template>
    <Head :title="$t('workstations.delete_title')" />

    <DeletePage
        :back-href="route('business_management.workstations.index')"
        :title="$t('workstations.delete_title')"
        :subtitle="workstation.name"
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
                <template #message>{{ $t('workstations.delete_title') }}</template>
                <template #description>
                    <p class="blocked-reason">{{ blockedReason }}</p>
                    <Button
                        v-if="workstation.is_active"
                        type="primary"
                        size="large"
                        :loading="deactivating"
                        @click="deactivate"
                    >
                        {{ $t('workstations.deactivate_instead') }}
                    </Button>
                </template>
            </Alert>
        </template>

        <template #summary>
            <DeleteSummaryRow :label="$t('workstations.name')">{{ workstation.name }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('workstations.work_location_id')">{{ workstation.work_location }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('workstations.usage_count')">{{ workstation.usage_count }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('workstations.is_active')">
                <Tag :color="workstation.is_active ? 'success' : 'error'" :bordered="false">
                    {{ workstation.is_active ? $t('global.active') : $t('global.inactive') }}
                </Tag>
            </DeleteSummaryRow>
        </template>
    </DeletePage>
</template>

<style scoped>
.del-mb { margin-bottom: 16px; }
.blocked-reason { margin: 4px 0 12px 0; line-height: 1.5; }
</style>
