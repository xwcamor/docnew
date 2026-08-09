<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Switch, Space, Alert, Row, Col,
} from 'ant-design-vue';
import { TranslationOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    language: { type: Object, default: null },  // null = create, object = edit
});

const isEdit = computed(() => !!props.language);

const form = useForm({
    name:      props.language?.name      ?? '',
    iso_code:  props.language?.iso_code  ?? '',
    is_active: props.language?.is_active ?? true,
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('system_management.languages.update', props.language.slug));
    } else {
        form.post(route('system_management.languages.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('languages.singular') : $t('languages.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('system_management.languages.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('languages.record') : $t('languages.new')"
            :subtitle="isEdit ? language.name : $t('languages.form_create_hint')"
        >
            <template #icon><TranslationOutlined /></template>
        </SectionHeader>

        <!-- Form card -->
        <Card class="form-card" :bodyStyle="{ padding: '24px 28px' }">
            <Form layout="horizontal" :label-col="{ xs: 24, sm: 8, md: 6 }" :wrapper-col="{ xs: 24, sm: 16, md: 13 }" label-align="right" :colon="true" @submit.prevent="submit">

                <!-- General error banner -->
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
                    <Col :xs="24" :lg="isEdit ? 12 : 16">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('languages.name')"
                            :tooltip="$t('languages.name_help')"
                            required
                            :validate-status="form.errors.name ? 'error' : ''"
                            :help="form.errors.name"
                        >
                            <Input
                                v-model:value="form.name"
                                :placeholder="$t('languages.name_placeholder')"
                                size="large"
                                :maxlength="255"
                                showCount
                                autofocus
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :lg="isEdit ? 6 : 8">
                        <FormItem
                            :label-col="{ xs: 24, sm: 10 }"
                            :wrapper-col="{ xs: 24, sm: 14 }"
                            :label="$t('languages.iso_code')"
                            :tooltip="$t('languages.iso_code_help')"
                            required
                            :validate-status="form.errors.iso_code ? 'error' : ''"
                            :help="form.errors.iso_code || $t('languages.iso_code_notice')"
                        >
                            <Input
                                v-model:value="form.iso_code"
                                :placeholder="$t('languages.iso_code_placeholder')"
                                size="large"
                                :maxlength="10"
                            />
                        </FormItem>
                    </Col>

                    <Col v-if="isEdit" :xs="24" :lg="6">
                        <FormItem
                            :label-col="{ xs: 24, sm: 10 }"
                            :wrapper-col="{ xs: 24, sm: 14 }"
                            :label="$t('languages.is_active')"
                            :tooltip="$t('languages.is_active_help')"
                            :validate-status="form.errors.is_active ? 'error' : ''"
                            :help="form.errors.is_active || $t('languages.is_active_notice')"
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

                <!-- Footer actions -->
                <FormFooter
                    :cancel-href="route('system_management.languages.index')"
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
.state-label {
    font-size: 0.875rem;
    color: var(--color-text);
    font-weight: 500;
}
.mb-4 { margin-bottom: 16px; }
</style>
