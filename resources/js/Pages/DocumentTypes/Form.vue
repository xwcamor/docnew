<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Form, FormItem, Input, InputNumber, Select, Switch, Space, Alert, Tag, Row, Col,
} from 'ant-design-vue';
import { IdcardOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    documentType: { type: Object, default: null },
    countryOptions:   { type: Array,  default: () => [] },
    scopeOptions:     { type: Array,  default: () => [] },
    defaultCountryId: { type: [Number, String], default: null },
});

const isEdit = computed(() => !!props.documentType);

// Editando, la cabecera dice cuál se está tocando: «CE — Carné de Extranjería».
// Con la sigla sola es fácil creer que se abrió otro.
const editSubtitle = computed(() => props.documentType?.name
    ? `${props.documentType.code} — ${props.documentType.name}`
    : props.documentType?.code);

const form = useForm({
    country_id: props.documentType?.country_id ?? props.defaultCountryId ?? null,
    // Creando, se propone «persona»: es lo que el catálogo llevaba haciendo
    // desde siempre y lo que se da de alta nueve de cada diez veces. Editando
    // manda lo guardado — sin esto, tocar el RUC para corregirle una tilde lo
    // devolvía al ámbito de persona y desaparecía del alta de empresas.
    scope: props.documentType?.scope ?? 'person',
    code: props.documentType?.code ?? '',
    name: props.documentType?.name ?? '',
    min_length: props.documentType?.min_length ?? null,
    max_length: props.documentType?.max_length ?? null,
    allowed_chars: props.documentType?.allowed_chars ?? null,
    is_active: props.documentType?.is_active ?? true,
});

// Los selectores buscan por texto: en obra se teclea el nombre, no se scrollea
// una lista larga con guantes.
const filterOption = (input, option) =>
    String(option.label ?? '').toLowerCase().includes(String(input).toLowerCase());

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.document_types.update', props.documentType.slug));
    } else {
        form.post(route('business_management.document_types.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('document_types.edit_title') : $t('document_types.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.document_types.index')"
            :title="isEdit ? $t('document_types.edit_title') : $t('document_types.new')"
            :subtitle="isEdit ? editSubtitle : $t('document_types.create_subtitle')"
        >
            <template #icon><IdcardOutlined /></template>
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
                    :label="$t('document_types.country')"
                    :tooltip="$t('document_types.country_help')"
                    required
                    :validate-status="form.errors.country_id ? 'error' : ''"
                    :help="form.errors.country_id"
                >
                    <Select
                        v-model:value="form.country_id"
                        size="large"
                        show-search
                        :options="countryOptions"
                        :filter-option="filterOption"
                        :placeholder="$t('global.select')"
                        autofocus
                    />
                </FormItem>

                <!-- A quién pertenece el documento, justo después del país y
                     antes de la sigla. El orden no es estético: el país y el
                     ámbito son lo que decide todo lo que viene debajo — «RUC»
                     con once cifras solo tiene sentido si antes se dijo Perú y
                     empresa. Este campo faltaba entero en la pantalla, así que
                     todo lo dado de alta a mano nacía como documento de persona
                     y al abrir una empresa de Chile el selector salía vacío. -->
                <FormItem
                    :label="$t('document_types.scope')"
                    :tooltip="$t('document_types.scope_help')"
                    required
                    :validate-status="form.errors.scope ? 'error' : ''"
                    :help="form.errors.scope"
                >
                    <Select
                        v-model:value="form.scope"
                        size="large"
                        :options="scopeOptions"
                        :placeholder="$t('global.select')"
                    />
                </FormItem>

                <FormItem
                    :label="$t('document_types.code')"
                    :tooltip="$t('document_types.code_help')"
                    required
                    :validate-status="form.errors.code ? 'error' : ''"
                    :help="form.errors.code"
                >
                    <Input v-model:value="form.code" size="large" :maxlength="20" show-count />
                </FormItem>

                <FormItem
                    :label="$t('document_types.name')"
                    :tooltip="$t('document_types.name_help')"
                    :validate-status="form.errors.name ? 'error' : ''"
                    :help="form.errors.name"
                >
                    <Input v-model:value="form.name" size="large" :maxlength="120" show-count />
                </FormItem>

                <!-- Cuántos caracteres tiene el número. Es ayuda al dar de alta
                     a alguien, NO la condición para buscarlo: el buscador de
                     trabajadores va por coincidencia exacta, porque el volcado
                     tiene dos peruanos con DNI de 7 y una regla de longitud los
                     dejaría fuera del sistema para siempre. -->
                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('document_types.min_length')"
                            :tooltip="$t('document_types.length_help')"
                            :validate-status="form.errors.min_length ? 'error' : ''"
                            :help="form.errors.min_length"
                        >
                            <InputNumber v-model:value="form.min_length" size="large" :min="1" :max="40" style="width: 100%" />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('document_types.max_length')"
                            :tooltip="$t('document_types.length_help')"
                            :validate-status="form.errors.max_length ? 'error' : ''"
                            :help="form.errors.max_length"
                        >
                            <InputNumber v-model:value="form.max_length" size="large" :min="1" :max="40" style="width: 100%" />
                        </FormItem>
                    </Col>
                </Row>

                <!-- Y CUÁLES, no solo cuántos: el largo puede cuadrar y el
                     contenido no. «1111111A» son ocho caracteres y no es un
                     DNI. Con esto puesto, la ficha de la persona no deja ni
                     teclear lo que no encaja. -->
                <FormItem
                    :label="$t('document_types.allowed_chars')"
                    :tooltip="$t('document_types.allowed_chars_help')"
                    :validate-status="form.errors.allowed_chars ? 'error' : ''"
                    :help="form.errors.allowed_chars"
                >
                    <Select
                        v-model:value="form.allowed_chars"
                        size="large"
                        allow-clear
                        :options="[
                            { value: 'digits',       label: $t('document_types.allowed_chars_digits') },
                            { value: 'alphanumeric', label: $t('document_types.allowed_chars_alphanumeric') },
                        ]"
                        :placeholder="$t('document_types.allowed_chars_any')"
                    />
                </FormItem>

                <FormItem
                    v-if="isEdit"
                    :label="$t('document_types.is_active')"
                    :tooltip="$t('document_types.is_active_help')"
                    :validate-status="form.errors.is_active ? 'error' : ''"
                    :help="form.errors.is_active"
                >
                    <Space>
                        <Switch v-model:checked="form.is_active" />
                        <span class="state-label">
                            {{ form.is_active ? $t('global.active') : $t('global.inactive') }}
                        </span>
                        <!-- Cuántos registros dependen ya de esta fila: explica
                             por qué desactivarla no es lo mismo que borrarla. -->
                        <Tag v-if="documentType.usage_count > 0" color="blue" :bordered="false">
                            {{ $t('document_types.usage_count') }}: {{ documentType.usage_count }}
                        </Tag>
                    </Space>
                </FormItem>

                <FormFooter
                    :cancel-href="route('business_management.document_types.index')"
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
.mb-4 { margin-bottom: 16px; }
</style>
