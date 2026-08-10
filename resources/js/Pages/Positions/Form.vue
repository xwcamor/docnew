<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Form, FormItem, Input, Select, Switch, Space, Alert, Tag } from 'ant-design-vue';
import { SolutionOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    position: { type: Object, default: null },
    countryOptions:   { type: Array,  default: () => [] },
    defaultCountryId: { type: [Number, String], default: null },
});

const isEdit = computed(() => !!props.position);

const form = useForm({
    country_id: props.position?.country_id ?? props.defaultCountryId ?? null,
    code: props.position?.code ?? '',
    is_active: props.position?.is_active ?? true,
});

// Los selectores buscan por texto: en obra se teclea el nombre, no se scrollea
// una lista larga con guantes.
const filterOption = (input, option) =>
    String(option.label ?? '').toLowerCase().includes(String(input).toLowerCase());

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.positions.update', props.position.slug));
    } else {
        form.post(route('business_management.positions.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('positions.edit_title') : $t('positions.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.positions.index')"
            :title="isEdit ? $t('positions.edit_title') : $t('positions.new')"
            :subtitle="isEdit ? position.code : $t('positions.create_subtitle')"
        >
            <template #icon><SolutionOutlined /></template>
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
                    :label="$t('positions.country')"
                    :tooltip="$t('positions.country_help')"
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
                    :label="$t('positions.code')"
                    :tooltip="$t('positions.code_help')"
                    required
                    :validate-status="form.errors.code ? 'error' : ''"
                    :help="form.errors.code"
                >
                    <Input v-model:value="form.code" size="large" :maxlength="60" show-count />
                </FormItem>

                <!-- Aquí estuvo «puede firmar aprobaciones», y se quitó: un
                     cargo dice qué hace alguien en obra, no si puede aprobar un
                     plan. Eso lo dicen los roles de la persona, en otra tabla.
                     El interruptor no lo leía nadie, así que prometía una regla
                     que el servidor nunca aplicó. -->
                <FormItem
                    v-if="isEdit"
                    :label="$t('positions.is_active')"
                    :tooltip="$t('positions.is_active_help')"
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
                        <Tag v-if="position.usage_count > 0" color="blue" :bordered="false">
                            {{ $t('positions.usage_count') }}: {{ position.usage_count }}
                        </Tag>
                    </Space>
                </FormItem>

                <FormFooter
                    :cancel-href="route('business_management.positions.index')"
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
