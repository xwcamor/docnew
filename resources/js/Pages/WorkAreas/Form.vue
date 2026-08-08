<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Form, FormItem, Input, Select, Switch, Space, Alert, Tag } from 'ant-design-vue';
import { ClusterOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    workArea: { type: Object, default: null },
    countryOptions:   { type: Array,  default: () => [] },
    defaultCountryId: { type: [Number, String], default: null },
});

const isEdit = computed(() => !!props.workArea);

const form = useForm({
    country_id: props.workArea?.country_id ?? props.defaultCountryId ?? null,
    name: props.workArea?.name ?? '',
    is_active: props.workArea?.is_active ?? true,
});

// Los selectores buscan por texto: en obra se teclea el nombre, no se scrollea
// una lista larga con guantes.
const filterOption = (input, option) =>
    String(option.label ?? '').toLowerCase().includes(String(input).toLowerCase());

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.work_areas.update', props.workArea.slug));
    } else {
        form.post(route('business_management.work_areas.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('work_areas.edit_title') : $t('work_areas.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.work_areas.index')"
            :title="isEdit ? $t('work_areas.edit_title') : $t('work_areas.new')"
            :subtitle="isEdit ? workArea.name : $t('work_areas.create_subtitle')"
        >
            <template #icon><ClusterOutlined /></template>
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
                    :label="$t('work_areas.country')"
                    :tooltip="$t('work_areas.country_help')"
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
                        autofocus
                    />
                </FormItem>

                <FormItem
                    :label="$t('work_areas.name')"
                    :tooltip="$t('work_areas.name_help')"
                    required
                    :validate-status="form.errors.name ? 'error' : ''"
                    :help="form.errors.name"
                >
                    <Input v-model:value="form.name" size="large" :maxlength="120" show-count />
                </FormItem>

                <FormItem
                    v-if="isEdit"
                    :label="$t('work_areas.is_active')"
                    :tooltip="$t('work_areas.is_active_help')"
                    :validate-status="form.errors.is_active ? 'error' : ''"
                    :help="form.errors.is_active"
                >
                    <Space>
                        <Switch v-model:checked="form.is_active" />
                        <span class="state-label">
                            {{ form.is_active ? $t('global.active') : $t('global.inactive') }}
                        </span>
                        <!-- Cuántos registros dependen ya de esta fila: explica
                             por qué desactivarla no es lo mismo que borrarla. -->
                        <Tag v-if="workArea.usage_count > 0" color="blue" :bordered="false">
                            {{ $t('work_areas.usage_count') }}: {{ workArea.usage_count }}
                        </Tag>
                    </Space>
                </FormItem>

                <FormFooter
                    :cancel-href="route('business_management.work_areas.index')"
                    :is-edit="isEdit"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </div>
    </div>
</template>

<style scoped>
.state-label { font-size: 0.875rem; color: var(--color-text); font-weight: 500; }
.mb-4 { margin-bottom: 16px; }
</style>
