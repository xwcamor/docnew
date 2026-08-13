<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Switch, Space, Alert, Select,
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
    // Cómo se coloca la hoja al imprimir. Estaba cableado en el generador: salía
    // apaisado si el documento llevaba una matriz de riesgo, y vertical en
    // cualquier otro caso. Acertaba con el AST y fallaba con el resto — un EPP es
    // una cuadrícula de doce columnas y no tiene ninguna matriz. Vacío deja la
    // deducción de antes, así que quien no lo toque no nota el cambio.
    pdf_orientation: props.formTemplate?.pdf_orientation ?? null,
    is_active:  props.formTemplate?.is_active ?? true,
});

const kindOptions = computed(() => [
    { value: 'structured',  label: t('form_templates.kind_structured') },
    { value: 'upload_only', label: t('form_templates.kind_upload_only') },
    { value: 'hybrid',      label: t('form_templates.kind_hybrid') },
]);

const orientationOptions = computed(() => [
    { value: null,        label: t('form_templates.pdf_orientation_auto') },
    { value: 'portrait',  label: t('form_templates.pdf_orientation_portrait') },
    { value: 'landscape', label: t('form_templates.pdf_orientation_landscape') },
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

                <!-- Un campo por línea, y en el orden en que se decide: primero
                     el país —acota qué documentos se pueden exigir y en qué
                     idioma sale el PDF—, luego el código, luego el nombre, y al
                     final cómo se llena y cómo se imprime. No van en pares: son
                     decisiones seguidas, no parejas, y en la tablet una fila
                     partida obliga a leer en zigzag. -->
                <FormItem
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

                <FormItem
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

                <!-- Cómo se imprime. No se bloquea al publicar, al revés que
                     «cómo se llena»: cambiar la orientación no altera nada de lo
                     ya guardado, sólo cómo se coloca la hoja, y un formato con
                     años de entregas que sale mal impreso tiene que poder
                     arreglarse sin sacar una versión nueva. -->
                <FormItem
                    :label="$t('form_templates.pdf_orientation')"
                    :tooltip="$t('form_templates.pdf_orientation_help')"
                    :validate-status="form.errors.pdf_orientation ? 'error' : ''"
                    :help="form.errors.pdf_orientation"
                >
                    <Select
                        v-model:value="form.pdf_orientation"
                        size="large"
                        :options="orientationOptions"
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
