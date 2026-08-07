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
    person:       { type: Object, default: null },
});

const isEdit = computed(() => !!props.person);

const form = useForm({
    name:       props.person?.name ?? '',
    num_doc:       props.person?.num_doc ?? '',
    is_active:  props.person?.is_active ?? true,
});

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
            :subtitle="isEdit ? person.name : $t('people.create_subtitle')"
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
                    :label="$t('people.num_doc')"
                    :tooltip="$t('people.num_doc_help')"
                    :validate-status="form.errors.num_doc ? 'error' : ''"
                    :help="form.errors.num_doc"
                >
                    <Input
                        v-model:value="form.num_doc"
                        size="large"
                        :maxlength="40"
                        :placeholder="$t('people.num_doc')"
                    />
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
