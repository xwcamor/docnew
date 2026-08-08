<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Switch, Space, Alert, Row, Col, Select,
} from 'ant-design-vue';
import { TagsOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

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
    is_active:  props.formTemplate?.is_active ?? true,
});

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
            <template #icon><TagsOutlined /></template>
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
