<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Form, FormItem, Input, Select, Switch, Space, Alert, Tag } from 'ant-design-vue';
import { BlockOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    workstation: { type: Object, default: null },
    workLocationOptions: { type: Array, default: () => [] },
});

const isEdit = computed(() => !!props.workstation);

const form = useForm({
    work_location_id: props.workstation?.work_location_id ?? null,
    name: props.workstation?.name ?? '',
    is_active: props.workstation?.is_active ?? true,
});

// Los selectores buscan por texto: en obra se teclea el nombre, no se scrollea
// una lista larga con guantes.
const filterOption = (input, option) =>
    String(option.label ?? '').toLowerCase().includes(String(input).toLowerCase());

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.workstations.update', props.workstation.slug));
    } else {
        form.post(route('business_management.workstations.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('workstations.edit_title') : $t('workstations.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.workstations.index')"
            :title="isEdit ? $t('workstations.edit_title') : $t('workstations.new')"
            :subtitle="isEdit ? workstation.name : $t('workstations.create_subtitle')"
        >
            <template #icon><BlockOutlined /></template>
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

                <!-- La sede va primero y no después: el puesto pertenece a
                     ella, y al registrar un plan solo se ofrecen los puestos de
                     la sede elegida. Poner el nombre antes invitaría a teclear
                     un puesto que luego acaba colgado de la sede equivocada. -->
                <FormItem
                    :label="$t('workstations.work_location_id')"
                    :tooltip="$t('workstations.work_location_help')"
                    required
                    :validate-status="form.errors.work_location_id ? 'error' : ''"
                    :help="form.errors.work_location_id"
                >
                    <Select
                        v-model:value="form.work_location_id"
                        size="large"
                        show-search
                        :options="workLocationOptions"
                        :filter-option="filterOption"
                        :placeholder="$t('global.select')"
                        autofocus
                    />
                </FormItem>

                <FormItem
                    :label="$t('workstations.name')"
                    :tooltip="$t('workstations.name_help')"
                    required
                    :validate-status="form.errors.name ? 'error' : ''"
                    :help="form.errors.name"
                >
                    <Input v-model:value="form.name" size="large" :maxlength="120" show-count />
                </FormItem>

                <FormItem
                    v-if="isEdit"
                    :label="$t('workstations.is_active')"
                    :tooltip="$t('workstations.is_active_help')"
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
                        <Tag v-if="workstation.usage_count > 0" color="blue" :bordered="false">
                            {{ $t('workstations.usage_count') }}: {{ workstation.usage_count }}
                        </Tag>
                    </Space>
                </FormItem>

                <FormFooter
                    :cancel-href="route('business_management.workstations.index')"
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
