<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Form, FormItem, Input, Select, Switch, Space, Alert, Card } from 'ant-design-vue';
import { ToolOutlined, WarningOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/Plugins/i18n';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';
import WorkTypeFormatsPicker from '@/Components/WorkTypes/WorkTypeFormatsPicker.vue';

defineOptions({ layout: AppLayout });

const { t } = useI18n();

const props = defineProps({
    workType:         { type: Object, default: null },
    // Matriz del backend: todos los formatos del país del tipo, con su marca.
    formTemplates:    { type: Array,  default: () => [] },
    openPlans:        { type: Number, default: 0 },
    options:          { type: Object, default: () => ({}) },
    defaultCountryId: { type: [Number, null], default: null },
});

const isEdit = computed(() => !!props.workType);

const form = useForm({
    code:       props.workType?.code ?? '',
    country_id: props.workType?.country_id ?? props.defaultCountryId ?? null,
    is_active:  props.workType?.is_active ?? true,
    form_templates: props.formTemplates
        .filter((f) => f.is_selected)
        .map((f) => ({ id: f.id, is_required: f.is_required })),
});

/**
 * El catálogo de formatos que se ofrece.
 *
 * En el alta el país se elige en el propio formulario, así que la matriz se
 * arma en el cliente a partir del catálogo completo; en la edición viene ya
 * resuelta del servidor. En los dos casos sale del backend: qué formatos existen
 * es un dato, no una constante del frontend.
 */
const catalogo = computed(() => {
    if (isEdit.value) return props.formTemplates;

    return (props.options.form_templates ?? [])
        .filter((f) => f.country_id === form.country_id)
        .map((f) => ({
            id: f.value,
            code: f.code,
            name: f.label,
            status: f.status,
            is_published: f.is_published,
        }));
});

// Al cambiar de país, un formato del país anterior deja de tener sentido: un
// plan de este tipo nunca podría llenarlo.
watch(() => form.country_id, (nuevo, viejo) => {
    if (viejo !== undefined && nuevo !== viejo) form.form_templates = [];
});

// Lo que el usuario acaba de mover, para que la barra de guardar no sea la
// única señal de que hay algo pendiente.
const original = ref(JSON.stringify(
    props.formTemplates.filter((f) => f.is_selected).map((f) => ({ id: f.id, is_required: f.is_required })),
));
const formatosTocados = computed(() => JSON.stringify(form.form_templates) !== original.value);

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.work_types.update', props.workType.slug));
    } else {
        form.post(route('business_management.work_types.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('work_types.edit_title') : $t('work_types.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.work_types.index')"
            :title="isEdit ? $t('work_types.edit_title') : $t('work_types.new')"
            :subtitle="$t('work_types.create_subtitle')"
        >
            <template #icon><ToolOutlined /></template>
        </SectionHeader>

        <div class="form-body">
            <Form
                layout="horizontal"
                :label-col="{ xs: 24, sm: 8, md: 6 }"
                :wrapper-col="{ xs: 24, sm: 16, md: 13 }"
                label-align="right"
                :colon="true"
                @submit.prevent="submit"
            >
                <Alert
                    v-if="form.hasErrors && Object.keys(form.errors).length > 0"
                    type="error"
                    show-icon
                    :message="$t('global.fix_marked_fields')"
                    class="mb-4"
                />

                <h2 class="form-section-title">{{ $t('global.general_data') }}</h2>

                <FormItem
                    :label="$t('work_types.code')"
                    :tooltip="$t('work_types.code_help')"
                    required
                    :validate-status="form.errors.code ? 'error' : ''"
                    :help="form.errors.code"
                >
                    <Input
                        v-model:value="form.code"
                        size="large"
                        :maxlength="255"
                        :placeholder="$t('work_types.code')"
                    />
                </FormItem>

                <FormItem
                    :label="$t('work_types.country')"
                    :tooltip="$t('work_types.country_help')"
                    required
                    :validate-status="form.errors.country_id ? 'error' : ''"
                    :help="form.errors.country_id"
                >
                    <Select
                        v-model:value="form.country_id"
                        size="large"
                        show-search
                        option-filter-prop="label"
                        :options="options.countries ?? []"
                        :placeholder="$t('work_types.country')"
                    />
                </FormItem>

                <FormItem
                    v-if="isEdit"
                    :label="$t('work_types.is_active')"
                    :tooltip="$t('work_types.is_active_help')"
                    :validate-status="form.errors.is_active ? 'error' : ''"
                    :help="form.errors.is_active"
                >
                    <Space>
                        <Switch v-model:checked="form.is_active" />
                        <span class="state-label">
                            {{ form.is_active ? $t('global.active') : $t('global.inactive') }}
                        </span>
                    </Space>
                </FormItem>

                <!-- Los formatos, a ancho completo: es una lista de decisiones,
                     no un campo más de la rejilla de etiqueta+control. -->
                <h2 class="form-section-title">{{ $t('work_types.forms_title') }}</h2>

                <Card :bodyStyle="{ padding: 16 }" class="forms-card">
                    <!-- Cuántos planes en obra alcanza el cambio. Se dice antes
                         de guardar y no después: después ya no es un aviso. -->
                    <Alert
                        v-if="isEdit && formatosTocados && openPlans > 0"
                        type="warning"
                        show-icon
                        class="mb-4"
                    >
                        <template #icon><WarningOutlined /></template>
                        <template #message>{{ $t('work_types.open_plans') }}: {{ openPlans }}</template>
                        <template #description>
                            {{ $tc('work_types.open_plans_warning', openPlans) }}
                        </template>
                    </Alert>
                    <Alert
                        v-else-if="isEdit && formatosTocados"
                        type="info"
                        show-icon
                        class="mb-4"
                        :message="$t('work_types.open_plans_none')"
                    />

                    <Alert
                        v-if="!form.country_id"
                        type="info"
                        show-icon
                        :message="$t('work_types.country_help')"
                    />
                    <WorkTypeFormatsPicker
                        v-else
                        v-model="form.form_templates"
                        :templates="catalogo"
                    />

                    <p v-if="form.errors.form_templates" class="field-error">{{ form.errors.form_templates }}</p>
                </Card>

                <FormFooter
                    :cancel-href="route('business_management.work_types.index')"
                    :is-edit="isEdit"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </div>
    </div>
</template>

<style scoped>
.state-label { font-size: 0.875rem; color: var(--color-text); font-weight: 500; }
.forms-card { margin-bottom: 16px; border-radius: 10px; }
.field-error { color: var(--color-danger); font-size: 0.8rem; margin: 8px 0 0 0; }
.mb-4 { margin-bottom: 16px; }
</style>
