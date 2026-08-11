<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Form, FormItem, Input, Textarea, Alert, Row, Col, Select, AutoComplete,
} from 'ant-design-vue';
import { ScheduleOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';
import FechaHora from '@/Components/Common/FechaHora.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    workPlan:            { type: Object, default: null },
    companyOptions:      { type: Array,  default: () => [] },
    workTypeOptions:     { type: Array,  default: () => [] },
    workLocationOptions: { type: Array,  default: () => [] },
    workAreaOptions:     { type: Array,  default: () => [] },
    workstationOptions:  { type: Array,  default: () => [] },
    /** Descripciones ya usadas en otros planes, las más repetidas primero. */
    descriptionSuggestions: { type: Array, default: () => [] },
});

const isEdit = computed(() => !!props.workPlan);

/**
 * Con una sola sede (o una sola área) no hay nada que elegir: sale puesta.
 * Solo al crear — al editar el valor guardado manda, aunque sea nulo.
 */
const unicaOpcion = (opciones) => (opciones.length === 1 ? opciones[0].value : null);

/** La hora de ahora a los 5 minutos, como propone FechaHora: nadie apunta las 12:26. */
const ahora = () => {
    const d = dayjs();

    return d.minute(Math.floor(d.minute() / 5) * 5).format('YYYY-MM-DD HH:mm');
};

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
    // Única sede/área → ya seleccionada (petición del dueño): elegir entre
    // una sola opción es un clic que no decide nada. SOLO al crear: al editar
    // manda lo guardado, aunque sea un área vacía de un plan viejo.
    work_location_id: props.workPlan ? (props.workPlan.work_location_id ?? null) : unicaOpcion(props.workLocationOptions),
    workstation_id:   props.workPlan?.workstation_id ?? null,
    work_area_id:     props.workPlan ? (props.workPlan.work_area_id ?? null) : unicaOpcion(props.workAreaOptions),
    // El plan se crea cuando el trabajo empieza: la fecha y hora de AHORA van
    // puestas de fábrica (petición del dueño). Se ven y se cambian.
    date_start:       props.workPlan ? (props.workPlan.date_start ?? null) : ahora(),
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

                <!-- Orden de servicio y Descripción comparten Row y rejilla de
                     etiquetas: la OS es media fila (es un número, no una
                     frase) y la descripción va a lo ancho, pero sus etiquetas
                     terminan en la MISMA vertical en todos los anchos — la OS
                     usa 8 de las 24 columnas de su media fila (un tercio de la
                     mitad = 1/6) y la descripción 4 de sus 24 enteras (el
                     mismo 1/6). Antes cada una usaba una rejilla distinta y
                     los campos arrancaban descuadrados (queja del dueño). -->
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
                    <Col :xs="24">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8, lg: 4 }"
                            :wrapper-col="{ xs: 24, sm: 16, lg: 20 }"
                            :label="$t('work_plans.description')"
                            :tooltip="$t('work_plans.description_help')"
                            required
                            :validate-status="form.errors.description ? 'error' : ''"
                            :help="form.errors.description"
                        >
                            <!-- Texto libre con memoria, como los peligros del
                                 AST: sugiere las descripciones ya usadas en
                                 otros planes (las más repetidas primero) y lo
                                 elegido se puede seguir editando. En la v1 era
                                 el autocompletado del catálogo `jobs`; aquí el
                                 catálogo es lo que la gente escribe. -->
                            <AutoComplete
                                class="descripcion-libre"
                                :value="form.description"
                                :options="descriptionSuggestions.map((d) => ({ value: d }))"
                                :filter-option="(texto, opcion) => String(opcion.value).toLowerCase().includes(String(texto).toLowerCase())"
                                @update:value="form.description = $event"
                            >
                                <Textarea
                                    :rows="3"
                                    :maxlength="5000"
                                    show-count
                                    :placeholder="$t('work_plans.description_placeholder')"
                                />
                            </AutoComplete>
                        </FormItem>
                    </Col>
                </Row>

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
                            <!-- El calendario y la hora van por separado a
                                 propósito: el selector con hora de Ant Design
                                 obliga a pulsar «Aceptar» y son cuatro clics
                                 para poner una fecha. Habla en cadenas
                                 `YYYY-MM-DD HH:mm`, que es lo que espera el
                                 servidor. -->
                            <FechaHora
                                v-model:value="form.date_start"
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
                            <!-- Todo lo anterior al inicio queda fuera: los
                                 días en gris y la hora corregida si se elige
                                 una anterior. No se puede dejar un fin
                                 imposible y enterarse al guardar.

                                 El calendario ENSEÑA hoy de fábrica (petición
                                 del dueño): el caso normal es que el trabajo
                                 termina el mismo día y solo hay que poner la
                                 hora. Teclear la hora compone «hoy + hora»;
                                 sin tocar nada, no se guarda fecha. -->
                            <FechaHora
                                v-model:value="form.date_end"
                                :disabled-date="finDeshabilitado"
                                :min-time="form.date_start"
                                :default-day="isEdit ? null : dia(form.date_start)"
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
