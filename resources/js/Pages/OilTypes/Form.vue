<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Switch, Space, Alert, Row, Col, Select,
} from 'ant-design-vue';
import { UserOutlined, BgColorsOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    oilType:       { type: Object, default: null },
    cloneSources:  { type: Array,  default: () => [] },
});

const isEdit = computed(() => !!props.oilType);

const form = useForm({
    name:             props.oilType?.name ?? '',
    code:             props.oilType?.code ?? '',
    is_active:        props.oilType?.is_active ?? true,
    clone_rules_from: null,   // opcional: copiar reglas de un aceite existente
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.oil_types.update', props.oilType.slug));
    } else {
        form.post(route('business_management.oil_types.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('oil_types.singular') : $t('oil_types.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.oil_types.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('oil_types.record') : $t('oil_types.new')"
            :subtitle="isEdit ? oilType.name : $t('oil_types.create_subtitle')"
        >
            <template #icon><BgColorsOutlined /></template>
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
                            :label="$t('oil_types.name')"
                            :tooltip="$t('oil_types.name_help')"
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
                                :placeholder="$t('oil_types.name_placeholder')"
                            />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('oil_types.code')"
                            :tooltip="$t('oil_types.code_help')"
                            :validate-status="form.errors.code ? 'error' : ''"
                            :help="form.errors.code"
                        >
                            <Input
                                v-model:value="form.code"
                                size="large"
                                :maxlength="40"
                                :placeholder="$t('oil_types.code')"
                            />
                        </FormItem>
                    </Col>
                    <Col v-if="cloneSources.length" :xs="24">
                        <FormItem
                            :label="$t('oil_types.clone_rules')"
                            :tooltip="$t('oil_types.clone_rules_help')"
                            :validate-status="form.errors.clone_rules_from ? 'error' : ''"
                            :help="form.errors.clone_rules_from || $t('oil_types.clone_rules_hint')"
                        >
                            <Select
                                v-model:value="form.clone_rules_from"
                                size="large"
                                allow-clear
                                :placeholder="$t('oil_types.clone_rules_placeholder')"
                                :options="cloneSources.map(o => ({ value: o.id, label: o.name }))"
                                style="max-width: 360px"
                            />
                        </FormItem>
                    </Col>
                    <Col v-if="isEdit" :xs="24" :md="8">
                        <FormItem
                            :label="$t('oil_types.is_active')"
                            :tooltip="$t('oil_types.is_active_help')"
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
                    :cancel-href="route('business_management.oil_types.index')"
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
