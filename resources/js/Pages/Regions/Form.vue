<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Switch, Space, Alert, Row, Col,
} from 'ant-design-vue';
import { GlobalOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    region: { type: Object, default: null },  // null = create, object = edit
});

const isEdit = computed(() => !!props.region);

const form = useForm({
    name:      props.region?.name ?? '',
    is_active: props.region?.is_active ?? true,
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('system_management.regions.update', props.region.slug));
    } else {
        form.post(route('system_management.regions.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('regions.singular') : $t('regions.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('system_management.regions.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('regions.record') : $t('regions.new')"
            :subtitle="isEdit ? region.name : $t('regions.form_create_hint')"
        >
            <template #icon><GlobalOutlined /></template>
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

                <!-- Layout grid: col-12 (24 en Ant) en mobile, distribuye en
                     desktop. Patrón a clonar para módulos con más campos: cada
                     FormItem en su <Col> con span responsive (xs=24 stack,
                     md=12 dos columnas, lg=8 tres columnas, etc.). -->
                <h2 class="form-section-title">{{ $t('global.general_data') }}</h2>

                <Row :gutter="[20, 0]">
                    <Col :xs="24" :md="16">
                        <FormItem
                            :label="$t('regions.name')"
                            :tooltip="$t('regions.name_help')"
                            required
                            :validate-status="form.errors.name ? 'error' : ''"
                            :help="form.errors.name"
                        >
                            <Input
                                v-model:value="form.name"
                                :placeholder="$t('regions.name_placeholder')"
                                size="large"
                                :maxlength="255"
                                showCount
                                autofocus
                            />
                        </FormItem>
                    </Col>

                    <Col v-if="isEdit" :xs="24" :md="8">
                        <FormItem
                            :label="$t('regions.is_active')"
                            :tooltip="$t('regions.is_active_help')"
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

                <!-- Footer actions -->
                <FormFooter
                    :cancel-href="route('system_management.regions.index')"
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
