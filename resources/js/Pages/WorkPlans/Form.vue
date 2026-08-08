<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Form, FormItem, Input, Textarea, Alert, Row, Col, Select, DatePicker,
} from 'ant-design-vue';
import { ScheduleOutlined } from '@ant-design/icons-vue';

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

/*
 * Por qué la rejilla parte en `lg` (≥992) y no en `md` (≥768).
 *
 * Esto se llena en tablets de 10". En horizontal (1024) caben dos columnas de
 * sobra; en vertical (768) cada columna se queda en 350 px y, con la etiqueta a
 * la izquierda comiendo un tercio, al selector de empresa le sobrarían 230 px
 * para nombres como «CONTRATISTA GENERAL DEL NORTE SAC». Ahí es mejor apilado.
 */


const form = useForm({
    // No se pinta en ningún sitio: lo genera el sistema al guardar (correlativo
    // del día, ver WorkPlanCodeGenerator) y al editar ya sale en la cabecera.
    // Sigue aquí porque el PUT lo exige, y viaja sin que nadie lo toque.
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
});

/**
 * En el selector de fin, todo lo anterior al inicio queda deshabilitado.
 *
 * Se validaba en el servidor («la fecha de fin no puede ser anterior…»), o sea
 * que se dejaba elegir una fecha imposible y se explicaba el error después de
 * guardar. Mejor no poder equivocarse.
 *
 * Se compara por día para poder desactivar el día entero, y las horas del
 * propio día de inicio se limitan aparte con `disabledTime`.
 */
const dia = (v) => String(v ?? '').slice(0, 10);

const finDeshabilitado = (fecha) => {
    if (!form.date_start || !fecha) return false;

    return fecha.format('YYYY-MM-DD') < dia(form.date_start);
};

const horasDeshabilitadas = (fecha) => {
    // Sólo el día que coincide con el de inicio tiene horas prohibidas; los
    // días posteriores admiten cualquiera.
    if (!form.date_start || !fecha || fecha.format('YYYY-MM-DD') !== dia(form.date_start)) {
        return {};
    }

    const [h, m] = String(form.date_start).slice(11, 16).split(':').map(Number);
    const rango = (desde, hasta) => Array.from({ length: hasta - desde }, (_, i) => desde + i);

    return {
        disabledHours: () => rango(0, h),
        disabledMinutes: (horaElegida) => (horaElegida === h ? rango(0, m) : []),
    };
};

// Cambiar el inicio a después del fin dejaría un fin imposible: se limpia en
// vez de guardarlo y dejar que el servidor lo rechace.
const onStartChange = () => {
    if (form.date_end && String(form.date_end) < String(form.date_start)) {
        form.date_end = null;
    }
};

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

// Con `value-format` el DatePicker emite y recibe CADENAS, no dayjs, que es
// justo lo que el backend espera. Se enlaza el campo directamente.
//
// Antes habia en medio un computed que devolvia un objeto dayjs y llamaba a
// `.format()` sobre lo que recibia. Como lo que recibia era una cadena, al
// elegir una fecha reventaba y el campo se quedaba vacio: se seleccionaba el
// dia y se borraba solo.

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

                <!-- Media fila: es un numero de orden de seis digitos, no una frase. -->
                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
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
                    </Col>
                </Row>

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

                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
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
                    </Col>
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
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
                    </Col>
                </Row>
                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
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
                    </Col>
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('work_plans.workstation')"
                            :tooltip="$t('work_plans.workstation_help')"
                            required
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
                    </Col>
                </Row>
                <!-- Media fila, alineada con el par de arriba. -->
                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('work_plans.work_area')"
                            :tooltip="$t('work_plans.work_area_help')"
                            required
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
                    </Col>
                </Row>

                <h2 class="form-section-title">{{ $t('work_plans.section_schedule') }}</h2>

                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label="$t('work_plans.date_start')"
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            required
                            :validate-status="form.errors.date_start ? 'error' : ''"
                            :help="form.errors.date_start"
                        >
                            <!-- Con hora, y en minutos de 5 en 5: nadie apunta que
                                 la maniobra empezó a las 12:26:47. El `value-format`
                                 hace que el selector hable en cadenas — no se le
                                 puede pasar un computed que llame a .format(). -->
                            <DatePicker
                                v-model:value="form.date_start"
                                size="large"
                                style="width: 100%"
                                show-time
                                :minute-step="5"
                                format="DD-MM-YYYY HH:mm"
                                value-format="YYYY-MM-DD HH:mm"
                                @change="onStartChange"
                            />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label="$t('work_plans.date_end')"
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :validate-status="form.errors.date_end ? 'error' : ''"
                            :help="form.errors.date_end"
                        >
                            <!-- Todo lo anterior al inicio queda deshabilitado:
                                 no se puede elegir un fin imposible y enterarse
                                 al guardar. -->
                            <DatePicker
                                v-model:value="form.date_end"
                                size="large"
                                style="width: 100%"
                                show-time
                                :minute-step="5"
                                format="DD-MM-YYYY HH:mm"
                                value-format="YYYY-MM-DD HH:mm"
                                :disabled-date="finDeshabilitado"
                                :disabled-time="horasDeshabilitadas"
                            />
                        </FormItem>
                    </Col>
                </Row>

                <!-- El estado NO se elige aquí. Lo calcula el sistema: un plan
                     queda terminado cuando firman todos los trabajadores, se
                     confirman todos los formatos y se dan las aprobaciones
                     obligatorias (WorkPlanCompletionService). Un interruptor
                     que dijera «Terminado» sin que eso fuera cierto convertiría
                     el estado en una opinión. -->

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
.mb-4 { margin-bottom: 16px; }
</style>
