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
    company:       { type: Object, default: null },
});

const isEdit = computed(() => !!props.company);

const form = useForm({
    name:       props.company?.name ?? '',
    num_doc:       props.company?.num_doc ?? '',
    is_active:  props.company?.is_active ?? true,
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.companies.update', props.company.slug));
    } else {
        form.post(route('business_management.companies.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('companies.singular') : $t('companies.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.companies.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('companies.record') : $t('companies.new')"
            :subtitle="isEdit ? company.name : $t('companies.create_subtitle')"
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
                    :label="$t('companies.name')"
                    :tooltip="$t('companies.name_help')"
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
                        :placeholder="$t('companies.name_placeholder')"
                    />
                </FormItem>

                <FormItem
                    :label="$t('companies.num_doc')"
                    :tooltip="$t('companies.num_doc_help')"
                    :validate-status="form.errors.num_doc ? 'error' : ''"
                    :help="form.errors.num_doc"
                >
                    <Input
                        v-model:value="form.num_doc"
                        size="large"
                        :maxlength="40"
                        :placeholder="$t('companies.num_doc')"
                    />
                </FormItem>

                <FormItem
                    v-if="isEdit"
                    :label="$t('companies.is_active')"
                    :tooltip="$t('companies.is_active_help')"
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
                    :cancel-href="route('business_management.companies.index')"
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
