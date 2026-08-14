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
    // Matriz del backend: todos los documentos del país del tipo, con su marca.
    formTemplates:    { type: Array,  default: () => [] },
    openPlans:        { type: Number, default: 0 },
    options:          { type: Object, default: () => ({}) },
    defaultCountryId: { type: [Number, null], default: null },
});

const isEdit = computed(() => !!props.workType);

const form = useForm({
    // El nombre con caída al código: en las filas migradas el código ES el
    // nombre, y un formulario que abre con el campo vacío invita a perderlo.
    name:       props.workType?.name ?? props.workType?.code ?? '',
    code:       props.workType?.code ?? '',
    country_id: props.workType?.country_id ?? props.defaultCountryId ?? null,
    is_active:  props.workType?.is_active ?? true,
    form_templates: props.formTemplates
        .filter((f) => f.is_selected)
        .map((f) => ({ id: f.id, is_required: f.is_required })),
});

/**
 * El catálogo de documentos que se ofrece.
 *
 * Sale del servidor ya resuelto MIENTRAS el país siga siendo el del registro.
 * En cuanto se elige otro país —o en el alta, donde el país se escoge aquí
 * mismo— la matriz se rearma con el catálogo completo filtrado por ese país: si
 * no, la pantalla seguiría ofreciendo los documentos del país anterior, que un
 * plan de este tipo no podría llenar nunca y que el servidor rechaza al guardar.
 */
const catalogo = computed(() => {
    if (isEdit.value && form.country_id === props.workType?.country_id) {
        return props.formTemplates;
    }

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

// Al cambiar de país, un documento del país anterior deja de tener sentido: un
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

// Los errores de una fila de la matriz llegan como `form_templates.0`: mirando
// sólo `form_templates` el formulario se quedaba mudo y parecía que el botón
// Guardar no hacía nada.
const erroresMatriz = computed(() => Object.entries(form.errors)
    .filter(([clave]) => clave.startsWith('form_templates'))
    .map(([, mensaje]) => mensaje)
    .filter((m, i, todos) => m && todos.indexOf(m) === i));

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
            :subtitle="isEdit ? (workType.label ?? workType.code) : $t('work_types.create_subtitle')"
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

                <!-- El país va primero: es lo que decide qué documentos se
                     pueden exigir más abajo. Elegirlos antes no significa nada. -->
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
                        :placeholder="$t('global.select')"
                    />
                </FormItem>

                <!-- El nombre antes que el código: es lo que se lee en pantalla
                     y lo primero que el usuario tiene en la cabeza. El código
                     es la sigla con la que se le cita. -->
                <FormItem
                    :label="$t('work_types.name')"
                    :tooltip="$t('work_types.name_help')"
                    required
                    :validate-status="form.errors.name ? 'error' : ''"
                    :help="form.errors.name"
                >
                    <Input
                        v-model:value="form.name"
                        size="large"
                        :maxlength="255"
                        show-count
                        :placeholder="$t('work_types.name')"
                    />
                </FormItem>

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
                        show-count
                        :placeholder="$t('work_types.code')"
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

                <!-- Los documentos, a ancho completo: es una lista de
                     decisiones, no un campo más de la rejilla etiqueta+control. -->
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

                    <!-- Sin ningún formato marcado, los planes de este tipo no
                         se van a poder cerrar nunca: `WorkPlanCompletionService`
                         exige al menos uno confirmado, y la persona que se topa
                         con eso es la de obra, al final del día y sin saber por
                         qué. Se dice aquí, que es donde se decide.

                         Es un aviso y no un bloqueo a propósito: en el alta se
                         crea el tipo de trabajo antes que sus formatos, y
                         exigirlos aquí sería una trampa de orden. -->
                    <Alert
                        v-if="form.country_id && form.form_templates.length === 0"
                        type="warning"
                        show-icon
                        class="mt-4"
                        :message="$t('work_types.no_forms_title')"
                        :description="$t('work_types.no_forms_warning')"
                    />

                    <Alert v-if="erroresMatriz.length > 0" type="error" show-icon class="mt-4">
                        <template #message>{{ $t('global.fix_marked_fields') }}</template>
                        <template #description>
                            <ul class="err-list">
                                <li v-for="(msg, i) in erroresMatriz" :key="i">{{ msg }}</li>
                            </ul>
                        </template>
                    </Alert>
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
.err-list { margin: 4px 0 0 0; padding-left: 18px; line-height: 1.6; }
.mb-4 { margin-bottom: 16px; }
.mt-4 { margin-top: 16px; }
</style>
