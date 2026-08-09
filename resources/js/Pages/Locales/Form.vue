<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Switch, Space, Alert, Row, Col, Select,
} from 'ant-design-vue';
import { ReadOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    locale:          { type: Object, default: null },
    languageOptions: { type: Array, default: () => [] },
});

const isEdit = computed(() => !!props.locale);

const form = useForm({
    name:        props.locale?.name ?? '',
    code:        props.locale?.code ?? '',
    language_id: props.locale?.language_id ?? null,
    is_active:   props.locale?.is_active ?? true,
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('system_management.locales.update', props.locale.slug));
    } else {
        form.post(route('system_management.locales.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('locales.singular') : $t('locales.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('system_management.locales.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('locales.record') : $t('locales.new')"
            :subtitle="isEdit ? locale.name : $t('locales.form_create_hint')"
        >
            <template #icon><ReadOutlined /></template>
        </SectionHeader>

        <Card class="form-card" :bodyStyle="{ padding: '24px 28px' }">
            <Form layout="horizontal" :label-col="{ xs: 24, sm: 8, md: 6 }" :wrapper-col="{ xs: 24, sm: 16, md: 13 }" label-align="right" :colon="true" @submit.prevent="submit">

                <Alert
                    v-if="form.hasErrors && Object.keys(form.errors).length > 0"
                    type="error"
                    show-icon
                    :message="$t('global.fix_marked_fields')"
                    class="mb-4"
                />

                <h2 class="form-section-title">{{ $t('global.general_data') }}</h2>


                <!-- `form-grid` es obligatorio para que las columnas se respeten:
                     app.css aplana con !important toda fila que no la lleve, y el
                     formulario se pintaba apilado aunque el template dijera dos
                     columnas. El corte es `lg` (992) a proposito — en tablet
                     vertical se apila. -->
                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('locales.name')"
                            :tooltip="$t('locales.name_help')"
                            required
                            :validate-status="form.errors.name ? 'error' : ''"
                            :help="form.errors.name"
                        >
                            <Input
                                v-model:value="form.name"
                                :placeholder="$t('locales.name_placeholder')"
                                size="large"
                                :maxlength="255"
                                showCount
                                autofocus
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="12" :lg="6">
                        <FormItem
                            :label-col="{ xs: 24, sm: 10 }"
                            :wrapper-col="{ xs: 24, sm: 14 }"
                            :label="$t('locales.code')"
                            :tooltip="$t('locales.code_help')"
                            required
                            :validate-status="form.errors.code ? 'error' : ''"
                            :help="form.errors.code"
                        >
                            <Input
                                v-model:value="form.code"
                                :placeholder="$t('locales.code_placeholder')"
                                size="large"
                                :maxlength="10"
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('locales.language')"
                            :tooltip="$t('locales.language_help')"
                            required
                            :validate-status="form.errors.language_id ? 'error' : ''"
                            :help="form.errors.language_id || $t('locales.language_notice')"
                        >
                            <Select
                                v-model:value="form.language_id"
                                :options="languageOptions"
                                :placeholder="$t('locales.language_placeholder')"
                                size="large"
                                show-search
                                option-filter-prop="label"
                            />
                        </FormItem>
                    </Col>

                    <Col v-if="isEdit" :xs="24" :lg="6">
                        <FormItem
                            :label-col="{ xs: 24, sm: 10 }"
                            :wrapper-col="{ xs: 24, sm: 14 }"
                            :label="$t('locales.is_active')"
                            :tooltip="$t('locales.is_active_help')"
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
                    </Col>
                </Row>

                <FormFooter
                    :cancel-href="route('system_management.locales.index')"
                    :is-edit="isEdit"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </Card>
    </div>
</template>

<style scoped>
.form-card { border-radius: 6px; }
.state-label { font-size: 0.875rem; color: var(--color-text); font-weight: 500; }
.mb-4 { margin-bottom: 16px; }
</style>
