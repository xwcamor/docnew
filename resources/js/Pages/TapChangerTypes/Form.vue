<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Switch, Space, Alert, Row, Col, Select,
} from 'ant-design-vue';
import { UserOutlined, ControlOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tapChangerType:       { type: Object, default: null },
});

const isEdit = computed(() => !!props.tapChangerType);

const form = useForm({
    name:       props.tapChangerType?.name ?? '',
    code:       props.tapChangerType?.code ?? '',
    is_active:  props.tapChangerType?.is_active ?? true,
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.tap_changer_types.update', props.tapChangerType.slug));
    } else {
        form.post(route('business_management.tap_changer_types.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('tap_changer_types.singular') : $t('tap_changer_types.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.tap_changer_types.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('tap_changer_types.record') : $t('tap_changer_types.new')"
            :subtitle="isEdit ? tapChangerType.name : $t('tap_changer_types.create_subtitle')"
        >
            <template #icon><ControlOutlined /></template>
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


                <Row :gutter="[20, 0]">
                    <Col :xs="24" :md="16">
                        <FormItem
                            :label="$t('tap_changer_types.name')"
                            :tooltip="$t('tap_changer_types.name_help')"
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
                                :placeholder="$t('tap_changer_types.name_placeholder')"
                            />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('tap_changer_types.code')"
                            :tooltip="$t('tap_changer_types.code_help')"
                            :validate-status="form.errors.code ? 'error' : ''"
                            :help="form.errors.code"
                        >
                            <Input
                                v-model:value="form.code"
                                size="large"
                                :maxlength="40"
                                :placeholder="$t('tap_changer_types.code')"
                            />
                        </FormItem>
                    </Col>
                    <Col v-if="isEdit" :xs="24" :md="8">
                        <FormItem
                            :label="$t('tap_changer_types.is_active')"
                            :tooltip="$t('tap_changer_types.is_active_help')"
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
                    :cancel-href="route('business_management.tap_changer_types.index')"
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
