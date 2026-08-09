<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Form, FormItem, Input, InputNumber, Switch, Space, Alert, Row, Col,
} from 'ant-design-vue';
import { TagsOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    brand:       { type: Object, default: null },
});

const isEdit = computed(() => !!props.brand);

const form = useForm({
    name:       props.brand?.name ?? '',
    code:       props.brand?.code ?? '',
    sort_order: props.brand?.sort_order ?? null,
    is_active:  props.brand?.is_active ?? true,
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.brands.update', props.brand.slug));
    } else {
        form.post(route('business_management.brands.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('brands.singular') : $t('brands.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.brands.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('brands.record') : $t('brands.new')"
            :subtitle="isEdit ? brand.name : $t('brands.create_subtitle')"
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
                    :label="$t('brands.name')"
                    :tooltip="$t('brands.name_help')"
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
                        :placeholder="$t('brands.name_placeholder')"
                    />
                </FormItem>

                <!-- Codigo y orden son cortos: van en pareja. La rejilla parte
                     en `lg` (>=992) — en tablet vertical se apila a proposito.
                     Sin `class="form-grid"` el CSS aplana la fila entera. -->
                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('brands.code')"
                            :tooltip="$t('brands.code_help')"
                            :validate-status="form.errors.code ? 'error' : ''"
                            :help="form.errors.code"
                        >
                            <Input
                                v-model:value="form.code"
                                size="large"
                                :maxlength="40"
                                :placeholder="$t('brands.code')"
                            />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('brands.sort_order')"
                            :tooltip="$t('brands.sort_order_help')"
                            :validate-status="form.errors.sort_order ? 'error' : ''"
                            :help="form.errors.sort_order"
                        >
                            <InputNumber
                                v-model:value="form.sort_order"
                                size="large"
                                :min="0"
                                :max="99999"
                                :precision="0"
                                style="width: 100%"
                                :placeholder="$t('brands.sort_order_placeholder')"
                            />
                        </FormItem>
                    </Col>
                </Row>

                <FormItem
                    v-if="isEdit"
                    :label="$t('brands.is_active')"
                    :tooltip="$t('brands.is_active_help')"
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
                    :cancel-href="route('business_management.brands.index')"
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
