<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Form, FormItem, Input, Textarea, Switch, Space, Alert, Row, Col, Select, DatePicker,
} from 'ant-design-vue';
import { ScheduleOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    workPlan:            { type: Object, default: null },
    companyOptions:      { type: Array,  default: () => [] },
    workTypeOptions:     { type: Array,  default: () => [] },
    workLocationOptions: { type: Array,  default: () => [] },
    workAreaOptions:     { type: Array,  default: () => [] },
    workstationOptions:  { type: Array,  default: () => [] },
});

const isEdit = computed(() => !!props.workPlan);

const form = useForm({
    code:             props.workPlan?.code ?? '',
    num_os:           props.workPlan?.num_os ?? '',
    description:      props.workPlan?.description ?? '',
    company_id:       props.workPlan?.company_id ?? null,
    work_type_id:     props.workPlan?.work_type_id ?? null,
    work_location_id: props.workPlan?.work_location_id ?? null,
    workstation_id:   props.workPlan?.workstation_id ?? null,
    work_area_id:     props.workPlan?.work_area_id ?? null,
    date_start:       props.workPlan?.date_start ?? null,
    date_end:         props.workPlan?.date_end ?? null,
    is_done:          props.workPlan?.is_done ?? false,
});

// El puesto pertenece a una sede: mostrar los 900 puestos de todas las sedes
// haría inusable el selector en tablet.
const workstationsForLocation = computed(() =>
    props.workstationOptions.filter((w) => w.work_location_id === form.work_location_id),
);
const onLocationChange = () => { form.workstation_id = null; };

// Los selectores buscan por texto: en obra se tipea el RUC o el nombre, no se
// scrollea una lista de 22 empresas.
const filterOption = (input, option) =>
    String(option.label ?? '').toLowerCase().includes(String(input).toLowerCase());

// DatePicker trabaja con dayjs; el backend espera y devuelve 'YYYY-MM-DD'.
const dateModel = (field) => computed({
    get: () => (form[field] ? dayjs(form[field]) : null),
    set: (v) => { form[field] = v ? v.format('YYYY-MM-DD') : null; },
});
const dateStart = dateModel('date_start');
const dateEnd   = dateModel('date_end');

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.work_plans.update', props.workPlan.slug));
    } else {
        form.post(route('business_management.work_plans.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('work_plans.singular') : $t('work_plans.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.work_plans.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('work_plans.record') : $t('work_plans.new')"
            :subtitle="isEdit ? workPlan.code : $t('work_plans.create_subtitle')"
        >
            <template #icon><ScheduleOutlined /></template>
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

                <!-- El código lo pone el sistema: es el correlativo del día y
                     tiene que ser único. Antes se escribía a mano, que es como
                     acababan repetidos. Al editar se muestra, no se toca. -->
                <FormItem
                    :label="$t('work_plans.code')"
                    :tooltip="$t('work_plans.code_help')"
                    :validate-status="form.errors.code ? 'error' : ''"
                    :help="form.errors.code"
                >
                    <Input
                        v-if="isEdit"
                        :value="form.code"
                        size="large"
                        disabled
                    />
                    <p v-else class="code-auto">{{ $t('work_plans.code_auto') }}</p>
                </FormItem>

                <FormItem
                    :label="$t('work_plans.num_os')"
                    :tooltip="$t('work_plans.num_os_help')"
                    :validate-status="form.errors.num_os ? 'error' : ''"
                    :help="form.errors.num_os"
                >
                    <Input
                        v-model:value="form.num_os"
                        size="large"
                        :maxlength="255"
                        :placeholder="$t('work_plans.num_os_placeholder')"
                    />
                </FormItem>

                <FormItem
                    :label="$t('work_plans.description')"
                    :tooltip="$t('work_plans.description_help')"
                    required
                    :validate-status="form.errors.description ? 'error' : ''"
                    :help="form.errors.description"
                >
                    <Textarea
                        v-model:value="form.description"
                        :rows="3"
                        :maxlength="5000"
                        show-count
                        :placeholder="$t('work_plans.description_placeholder')"
                    />
                </FormItem>

                <h2 class="form-section-title">{{ $t('work_plans.section_work') }}</h2>

                <FormItem
                    :label="$t('work_plans.company')"
                    :tooltip="$t('work_plans.company_help')"
                    required
                    :validate-status="form.errors.company_id ? 'error' : ''"
                    :help="form.errors.company_id"
                >
                    <Select
                        v-model:value="form.company_id"
                        size="large"
                        show-search
                        :options="companyOptions"
                        :filter-option="filterOption"
                        :placeholder="$t('global.select')"
                    />
                </FormItem>

                <FormItem
                    :label="$t('work_plans.work_type')"
                    :tooltip="$t('work_plans.work_type_help')"
                    required
                    :validate-status="form.errors.work_type_id ? 'error' : ''"
                    :help="form.errors.work_type_id"
                >
                    <Select
                        v-model:value="form.work_type_id"
                        size="large"
                        show-search
                        :options="workTypeOptions"
                        :filter-option="filterOption"
                        :placeholder="$t('global.select')"
                    />
                </FormItem>

                <FormItem
                    :label="$t('work_plans.work_location')"
                    :tooltip="$t('work_plans.work_location_help')"
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
                        @change="onLocationChange"
                    />
                </FormItem>

                <FormItem
                    :label="$t('work_plans.workstation')"
                    :tooltip="$t('work_plans.workstation_help')"
                    :validate-status="form.errors.workstation_id ? 'error' : ''"
                    :help="form.errors.workstation_id"
                >
                    <Select
                        v-model:value="form.workstation_id"
                        size="large"
                        show-search
                        allow-clear
                        :options="workstationsForLocation"
                        :filter-option="filterOption"
                        :disabled="!form.work_location_id"
                        :placeholder="form.work_location_id ? $t('global.select') : $t('work_plans.workstation_needs_location')"
                    />
                </FormItem>

                <FormItem
                    :label="$t('work_plans.work_area')"
                    :tooltip="$t('work_plans.work_area_help')"
                    :validate-status="form.errors.work_area_id ? 'error' : ''"
                    :help="form.errors.work_area_id"
                >
                    <Select
                        v-model:value="form.work_area_id"
                        size="large"
                        show-search
                        allow-clear
                        :options="workAreaOptions"
                        :filter-option="filterOption"
                        :placeholder="$t('global.select')"
                    />
                </FormItem>

                <h2 class="form-section-title">{{ $t('work_plans.section_schedule') }}</h2>

                <Row :gutter="[20, 0]">
                    <Col :xs="24" :md="12">
                        <FormItem
                            :label="$t('work_plans.date_start')"
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :validate-status="form.errors.date_start ? 'error' : ''"
                            :help="form.errors.date_start"
                        >
                            <DatePicker v-model:value="dateStart" size="large" style="width: 100%" value-format="YYYY-MM-DD" />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="12">
                        <FormItem
                            :label="$t('work_plans.date_end')"
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :validate-status="form.errors.date_end ? 'error' : ''"
                            :help="form.errors.date_end"
                        >
                            <DatePicker v-model:value="dateEnd" size="large" style="width: 100%" value-format="YYYY-MM-DD" />
                        </FormItem>
                    </Col>
                </Row>

                <FormItem
                    v-if="isEdit"
                    :label="$t('work_plans.is_done')"
                    :tooltip="$t('work_plans.is_done_help')"
                    :validate-status="form.errors.is_done ? 'error' : ''"
                    :help="form.errors.is_done"
                >
                    <Space>
                        <Switch v-model:checked="form.is_done" />
                        <span class="state-label">
                            {{ form.is_done ? $t('work_plans.state_done') : $t('work_plans.state_pending') }}
                        </span>
                    </Space>
                </FormItem>

                <FormFooter
                    :cancel-href="route('business_management.work_plans.index')"
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
