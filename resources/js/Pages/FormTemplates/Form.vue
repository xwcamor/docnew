<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Switch, Space, Alert, Row, Col, Select,
} from 'ant-design-vue';
import { FileOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const { t } = useI18n();

const props = defineProps({
    formTemplate:     { type: Object, default: null },
    countryOptions:   { type: Array,  default: () => [] },
    defaultCountryId: { type: [Number, null], default: null },
});

const isEdit = computed(() => !!props.formTemplate);

const form = useForm({
    // El país es NOT NULL en la tabla y este formulario no lo pedía: crear un
    // formato desde la pantalla reventaba con un 23502 de Postgres. Un AST
    // pertenece a un país, como el resto de los catálogos de obra.
    country_id: props.formTemplate?.country_id ?? props.defaultCountryId ?? null,
    name:       props.formTemplate?.name ?? '',
    code:       props.formTemplate?.code ?? '',
    // Cómo se llena. La columna existía y no había forma de elegirla desde la
    // pantalla: todo nacía «con campos», y un documento con campos y sin
    // ninguno no se puede publicar — o sea, nada de lo creado aquí llegaba a un
    // plan. Los campos se definen luego, en «Secciones y campos» desde la ficha.
    kind:       props.formTemplate?.kind ?? 'structured',
    is_active:  props.formTemplate?.is_active ?? true,
});

const kindOptions = computed(() => [
    { value: 'structured',  label: t('form_templates.kind_structured') },
    { value: 'upload_only', label: t('form_templates.kind_upload_only') },
    { value: 'hybrid',      label: t('form_templates.kind_hybrid') },
]);

// Publicado = no se cambia cómo se llena: hay entregas que se rellenaron con
// esa forma. Sale deshabilitado y diciendo por qué, no fallando al guardar.
const kindLocked = computed(() => isEdit.value && props.formTemplate?.status !== 'draft');

// Aviso sólo cuando importa: documento con campos propios y sin ninguno todavía.
const warnNoFieldScreen = computed(() =>
    form.kind !== 'upload_only' && (props.formTemplate?.fields_count ?? 0) === 0,
);

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.form_templates.update', props.formTemplate.slug));
    } else {
        form.post(route('business_management.form_templates.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('form_templates.singular') : $t('form_templates.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.form_templates.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('form_templates.record') : $t('form_templates.new')"
            :subtitle="isEdit ? formTemplate.name : $t('form_templates.create_subtitle')"
        >
            <template #icon><FileOutlined /></template>
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

                <!-- Lo que identifica al documento va arriba: su nombre. -->
                <FormItem
                    :label="$t('form_templates.name')"
                    :tooltip="$t('form_templates.name_help')"
                    required
                    :validate-status="form.errors.name ? 'error' : ''"
                    :help="form.errors.name"
                >
                    <Input
                        v-model:value="form.name"
                        size="large"
                        :maxlength="255"
                        showCount
                        autofocus
                        :placeholder="$t('form_templates.name_placeholder')"
                    />
                </FormItem>

                <!-- Código y país son cortos: van por pares. Una fila sólo se
                     pinta en dos columnas si lleva `form-grid`; parte en lg. -->
                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('form_templates.code')"
                            :tooltip="$t('form_templates.code_help')"
                            :validate-status="form.errors.code ? 'error' : ''"
                            :help="form.errors.code"
                        >
                            <Input
                                v-model:value="form.code"
                                size="large"
                                :maxlength="40"
                                :placeholder="$t('form_templates.code')"
                            />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('form_templates.country')"
                            :tooltip="$t('form_templates.country_help')"
                            required
                            :validate-status="form.errors.country_id ? 'error' : ''"
                            :help="form.errors.country_id"
                        >
                            <Select
                                v-model:value="form.country_id"
                                size="large"
                                show-search
                                :options="countryOptions"
                                :filter-option="(i, o) => String(o.label ?? '').toLowerCase().includes(String(i).toLowerCase())"
                                :placeholder="$t('global.select')"
                            />
                        </FormItem>
                    </Col>
                </Row>

                <!-- Cómo se llena: con campos en la tablet, sólo foto del papel
                     o las dos cosas. Decide qué se le pide al trabajador en
                     obra y si el documento se puede publicar. -->
                <FormItem
                    :label="$t('form_templates.kind')"
                    :tooltip="$t('form_templates.kind_help')"
                    required
                    :validate-status="form.errors.kind ? 'error' : ''"
                    :help="form.errors.kind || (kindLocked ? $t('form_templates.kind_locked_published') : '')"
                >
                    <Select
                        v-model:value="form.kind"
                        size="large"
                        :options="kindOptions"
                        :disabled="kindLocked"
                    />
                </FormItem>

                <Alert
                    v-if="warnNoFieldScreen"
                    type="warning"
                    show-icon
                    :message="$t('form_templates.publish_blocked_no_fields')"
                    class="mb-4"
                />

                <FormItem
                    v-if="isEdit"
                    :label="$t('form_templates.is_active')"
                    :tooltip="$t('form_templates.is_active_help')"
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

                <FormFooter
                    :cancel-href="route('business_management.form_templates.index')"
                    :is-edit="isEdit"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </div>
    </div>
</template>

<style scoped>
.state-label {
    font-size: 0.875rem;
    color: var(--color-text);
    font-weight: 500;
}
.mb-4 { margin-bottom: 16px; }
</style>
