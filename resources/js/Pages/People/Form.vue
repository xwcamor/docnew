<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Switch, Space, Alert, Row, Col, Select, DatePicker,
} from 'ant-design-vue';
import { IdcardOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    person:             { type: Object, default: null },
    countryOptions:     { type: Array,  default: () => [] },
    docTypeOptions:     { type: Array,  default: () => [] },
    nationalityOptions: { type: Array,  default: () => [] },
    defaultCountryId:   { type: Number, default: null },
});

const isEdit = computed(() => !!props.person);

const form = useForm({
    name:           props.person?.name ?? '',
    lastname:       props.person?.lastname ?? '',
    doc_type:       props.person?.doc_type ?? 'DNI',
    num_doc:        props.person?.num_doc ?? '',
    // Al crear, por defecto el país del usuario; al editar, el de la persona.
    country_id:     props.person?.country_id ?? props.defaultCountryId ?? null,
    nationality_id: props.person?.nationality_id ?? null,
    birthdate:      props.person?.birthdate ?? null,
    is_active:      props.person?.is_active ?? true,
});

const filterOption = (input, option) =>
    String(option.label ?? '').toLowerCase().includes(String(input).toLowerCase());

// Con `value-format` el DatePicker habla en cadenas, igual que el backend: se
// enlaza el campo directo. El computed que habia en medio convertia a dayjs y
// llamaba a `.format()` sobre una cadena, asi que la fecha se borraba al
// elegirla.

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.people.update', props.person.slug));
    } else {
        form.post(route('business_management.people.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('people.singular') : $t('people.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.people.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('people.record') : $t('people.new')"
            :subtitle="isEdit ? person.full_name : $t('people.create_subtitle')"
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
                    :label="$t('people.name')"
                    :tooltip="$t('people.name_help')"
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
                        :placeholder="$t('people.name_placeholder')"
                    />
                </FormItem>

                <FormItem
                    :label="$t('people.lastname')"
                    :tooltip="$t('people.lastname_help')"
                    required
                    :validate-status="form.errors.lastname ? 'error' : ''"
                    :help="form.errors.lastname"
                >
                    <Input
                        v-model:value="form.lastname"
                        size="large"
                        :maxlength="255"
                        showCount
                        :placeholder="$t('people.lastname_placeholder')"
                    />
                </FormItem>

                <h2 class="form-section-title">{{ $t('people.section_identity') }}</h2>

                <Row :gutter="[20, 0]">
                    <Col :xs="24" :md="10">
                        <FormItem
                            :label="$t('people.doc_type')"
                            :label-col="{ xs: 24, sm: 10 }"
                            :wrapper-col="{ xs: 24, sm: 14 }"
                            required
                            :validate-status="form.errors.doc_type ? 'error' : ''"
                            :help="form.errors.doc_type"
                        >
                            <Select v-model:value="form.doc_type" size="large" :options="docTypeOptions" />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="14">
                        <FormItem
                            :label="$t('people.num_doc')"
                            :tooltip="$t('people.num_doc_help')"
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            required
                            :validate-status="form.errors.num_doc ? 'error' : ''"
                            :help="form.errors.num_doc"
                        >
                            <Input
                                v-model:value="form.num_doc"
                                size="large"
                                :maxlength="20"
                                :placeholder="$t('people.num_doc_placeholder')"
                            />
                        </FormItem>
                    </Col>
                </Row>

                <FormItem
                    :label="$t('people.country')"
                    :tooltip="$t('people.country_help')"
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
                    />
                </FormItem>

                <FormItem
                    v-if="nationalityOptions.length"
                    :label="$t('people.nationality')"
                    :tooltip="$t('people.nationality_help')"
                    :validate-status="form.errors.nationality_id ? 'error' : ''"
                    :help="form.errors.nationality_id"
                >
                    <Select
                        v-model:value="form.nationality_id"
                        size="large"
                        show-search
                        allow-clear
                        :options="nationalityOptions"
                        :filter-option="filterOption"
                        :placeholder="$t('global.select')"
                    />
                </FormItem>

                <FormItem
                    :label="$t('people.birthdate')"
                    :tooltip="$t('people.birthdate_help')"
                    :validate-status="form.errors.birthdate ? 'error' : ''"
                    :help="form.errors.birthdate"
                >
                    <DatePicker v-model:value="form.birthdate" size="large" style="width: 100%" value-format="YYYY-MM-DD" />
                </FormItem>

                <FormItem
                    v-if="isEdit"
                    :label="$t('people.is_active')"
                    :tooltip="$t('people.is_active_help')"
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
                    :cancel-href="route('business_management.people.index')"
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
