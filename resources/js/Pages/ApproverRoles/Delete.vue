<script setup>
import { computed, ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Alert, Tag, Button } from 'ant-design-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DeletePage from '@/Components/Common/DeletePage.vue';
import DeleteSummaryRow from '@/Components/Common/DeleteSummaryRow.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    approverRole:  { type: Object, required: true },
    // Motivo por el que este rol NO se puede borrar, ya traducido. Viene del
    // servicio: es la misma comprobación que hará el backend al confirmar.
    blockedReason: { type: String, default: null },
});

const blocked = computed(() => !!props.blockedReason);

const form = useForm({ deleted_description: '' });

const submit = () => {
    form.delete(route('business_management.approver_roles.deleteSave', props.approverRole.slug), {
        preserveScroll: true,
    });
};

// La salida que se le ofrece a quien no puede borrar: que deje de ofrecerse
// sin romper las reglas que ya lo nombran.
const deactivating = ref(false);
const deactivate = () => {
    deactivating.value = true;
    router.post(route('business_management.approver_roles.deactivate', props.approverRole.slug), {}, {
        onFinish: () => { deactivating.value = false; },
    });
};
</script>

<template>
    <Head :title="$t('approver_roles.delete_title')" />

    <DeletePage
        :back-href="route('business_management.approver_roles.index')"
        :title="$t('approver_roles.delete_title')"
        :subtitle="approverRole.name_es"
        v-model="form.deleted_description"
        :error="form.errors.deleted_description"
        :processing="form.processing"
        :disabled="blocked"
        @submit="submit"
    >
        <template #warning>
            <!-- Si el rol está en uso o es del sistema, se dice AQUÍ y no
                 después de escribir el motivo: pedir un motivo para un borrado
                 que se va a rechazar es hacer perder el tiempo. -->
            <Alert v-if="blocked" type="error" show-icon class="del-mb">
                <template #message>{{ $t('approver_roles.delete_title') }}</template>
                <template #description>
                    <p class="blocked-reason">{{ blockedReason }}</p>
                    <Button
                        v-if="approverRole.is_active"
                        type="primary"
                        size="large"
                        :loading="deactivating"
                        @click="deactivate"
                    >
                        {{ $t('approver_roles.deactivate_instead') }}
                    </Button>
                </template>
            </Alert>
        </template>

        <template #summary>
            <DeleteSummaryRow :label="$t('approver_roles.code')"><code>{{ approverRole.code }}</code></DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('approver_roles.name_es')">{{ approverRole.name_es }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('approver_roles.name_en')">{{ approverRole.name_en }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('approver_roles.rules_count')">{{ approverRole.rules_count }}</DeleteSummaryRow>
            <DeleteSummaryRow :label="$t('approver_roles.is_active')">
                <Tag :color="approverRole.is_active ? 'success' : 'error'" :bordered="false">
                    {{ approverRole.is_active ? $t('global.active') : $t('global.inactive') }}
                </Tag>
            </DeleteSummaryRow>
        </template>
    </DeletePage>
</template>

<style scoped>
.del-mb { margin-bottom: 16px; }
.blocked-reason { margin: 4px 0 12px 0; line-height: 1.5; }
</style>
