<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Form, FormItem, Input, Switch, Space, Alert, Row, Col, Select, DatePicker, Tooltip, Button,
} from 'ant-design-vue';
import { IdcardOutlined, LockOutlined, SafetyCertificateOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    person:             { type: Object, default: null },
    countryOptions:     { type: Array,  default: () => [] },
    docTypeOptions:     { type: Array,  default: () => [] },
    // Tipos de documento por país: el país se elige en esta misma pantalla, así
    // que la lista de tipos tiene que seguirlo.
    docTypesByCountry:  { type: Object, default: () => ({}) },
    nationalityOptions: { type: Array,  default: () => [] },
    companyOptions:     { type: Array,  default: () => [] },
    positionOptions:    { type: Array,  default: () => [] },
    roleOptions:        { type: Array,  default: () => [] },
    defaultCountryId:   { type: Number, default: null },
    // Si es falso, el documento llega tapado: se enseña, no se toca.
    canViewPrivateInfo: { type: Boolean, default: true },
});

const isEdit = computed(() => !!props.person);

// El documento sólo se edita con `people.view_private_info`. Sin el permiso el
// número llega enmascarado (`******36`) y dejarlo editable significaba guardar
// los asteriscos encima del DNI real al tocar cualquier otro campo.
const docLocked = computed(() => isEdit.value && !props.canViewPrivateInfo);

const form = useForm({
    name:           props.person?.name ?? '',
    lastname:       props.person?.lastname ?? '',
    doc_type:       props.person?.doc_type ?? null,
    num_doc:        props.person?.num_doc ?? '',
    // Al crear, por defecto el país del usuario; al editar, el de la persona.
    country_id:     props.person?.country_id ?? props.defaultCountryId ?? null,
    nationality_id: props.person?.nationality_id ?? null,
    birthdate:      props.person?.birthdate ?? null,
    is_active:      props.person?.is_active ?? true,
    // Empresa y cargo: no son columnas de la persona, van al vinculo
    // persona-empresa. Al editar se carga el primero, que es el que se enseña.
    company_id:     props.person?.primary_link?.company_id ?? null,
    position_id:    props.person?.primary_link?.position_id ?? null,
    // Qué firma en obra. Una persona puede ser las dos cosas.
    roles:          props.person?.roles?.length ? [...props.person.roles] : ['worker'],
});

// Los tipos del país elegido. Si ese país no tiene catálogo se cae a los que
// mande el servidor, que a su vez se cae a DNI/CE/PASAPORTE — igual que la
// validación, para que la pantalla no ofrezca nada que el servidor rechace.
const docTypesForCountry = computed(() => {
    const delPais = props.docTypesByCountry?.[form.country_id];
    const lista = [...(delPais?.length ? delPais : props.docTypeOptions)];

    // El tipo que la persona ya tiene sigue en la lista aunque se haya dado de
    // baja del catálogo: corregir un apellido no puede cambiarle el documento.
    const actual = props.person?.doc_type;
    if (actual && form.country_id === props.person?.country_id
        && !lista.some((o) => o.value === actual)) {
        lista.unshift({ value: actual, label: actual });
    }

    return lista;
});

// Al cambiar de país, el tipo que ya no existe allí se descarta: elegir Chile y
// dejar «DNI» —que es peruano— reventaba el alta sin forma de arreglarlo.
//
// El que se propone es el DNI y no el primero de la lista. El catálogo va por
// código y «CE» va antes que «DNI» alfabéticamente, así que el formulario abría
// proponiendo carné de extranjería: cuatro personas de 228 lo llevan. Con el
// tipo equivocado delante el tope del número es otro y RENIEC ni se consulta.
watch(docTypesForCountry, (opciones) => {
    if (docLocked.value) return;
    if (!opciones.some((o) => o.value === form.doc_type)) {
        const porDefecto = opciones.find((o) => o.value === 'DNI') ?? opciones[0];
        form.doc_type = porDefecto?.value ?? null;
    }
}, { immediate: true });

// El tipo elegido, con sus longitudes. El campo del número tenía un tope fijo
// de 20 para todos, así que cabía un celular de nueve dígitos en un DNI y no se
// notaba hasta darle a Guardar — y en la base maestra hay uno.
const tipoElegido = computed(() =>
    docTypesForCountry.value.find((o) => o.value === form.doc_type) ?? null);

// Sin catálogo (país sin sembrar) se cae al tope de siempre: cortar en un largo
// inventado sería peor que no cortar.
const largoMaximo = computed(() => tipoElegido.value?.max ?? 20);

// Qué caracteres admite el tipo, además de cuántos. El largo puede cuadrar y el
// contenido no: «1111111A» son ocho caracteres y no es un DNI. Los espacios no
// entran en ningún caso — uno al final es invisible y convierte a la misma
// persona en dos.
const filtroDelNumero = computed(() => ({
    digits:       /[^0-9]/g,
    alphanumeric: /[^A-Za-z0-9]/g,
}[tipoElegido.value?.allowed_chars] ?? /\s/g));

// Se limpia lo que se escribe en vez de rechazar la tecla: así el pegar desde
// un Excel —que es como llega la mitad de la cuadrilla— también se limpia, y no
// se pierde lo que sí valía de lo pegado.
//
// El tope también se aplica aquí y no con el `maxlength` del navegador. Con el
// atributo puesto, pegar «  436 735-35  » se cortaba a los ocho PRIMEROS
// caracteres —espacios incluidos— y quedaba «43673»: se perdían tres dígitos
// buenos. Primero se limpia y después se cuenta.
watch(() => form.num_doc, (valor) => {
    if (typeof valor !== 'string') return;

    let limpio = valor.replace(filtroDelNumero.value, '');
    if (limpio.length > largoMaximo.value) {
        limpio = limpio.slice(0, largoMaximo.value);
    }

    if (limpio !== valor) form.num_doc = limpio;
});

// Cuántos faltan para llegar al mínimo, para avisar mientras se teclea.
const faltanDigitos = computed(() => {
    const min = tipoElegido.value?.min ?? 0;
    const largo = (form.num_doc ?? '').trim().length;

    return min && largo > 0 && largo < min ? min - largo : 0;
});

// ── RENIEC ───────────────────────────────────────────────────────────────────
//
// Solo Perú y solo DNI: un carné de extranjería, un PTP o un pasaporte no están
// en RENIEC. La v1 sí lo tenía y al portar el módulo se perdió, así que el
// nombre se teclea — y de ahí salieron el mismo DNI escrito de dos formas, un
// celular en el campo del documento y un «11111111» inventado.
const paisEsPeru = computed(() => {
    const p = props.countryOptions.find((o) => o.value === form.country_id);
    return /\(PE\)\s*$/.test(p?.label ?? '');
});

const dniEstado = ref(null);
// Con el nombre traído de RENIEC los campos se bloquean: es el nombre oficial,
// y es el que tiene que cuadrar con el documento que piden en la puerta. Queda
// una salida a mano por si RENIEC devuelve algo raro.
const nombreVerificado = ref(false);
let temporizador = null;

const consultarDni = async (dni) => {
    dniEstado.value = 'buscando';
    try {
        const { data } = await window.axios.get(route('business_management.people.lookup_dni'), {
            params: { dni },
        });

        // `sin_configurar` no se le enseña al usuario: no ha hecho nada mal y no
        // puede arreglarlo. La pantalla se queda como estaba.
        dniEstado.value = data.estado === 'sin_configurar' ? null : data.estado;

        if (data.estado === 'encontrado') {
            form.name = data.nombres;
            form.lastname = data.apellidos;
            nombreVerificado.value = true;

            // El DNI solo lo lleva un peruano, así que la nacionalidad se
            // deduce. Se rellena únicamente si está vacía: es de lo que depende
            // que el tipo de documento salga bien, y en la base maestra hay
            // gente sin ella.
            if (!form.nationality_id) {
                const peru = props.nationalityOptions.find((o) => /^per/i.test(o.label ?? ''));
                if (peru) form.nationality_id = peru.value;
            }
        }
    } catch {
        dniEstado.value = 'error';
    }
};

watch([() => form.num_doc, () => form.doc_type, () => form.country_id], () => {
    clearTimeout(temporizador);

    const dni = (form.num_doc ?? '').replace(/\D/g, '');
    const consultable = paisEsPeru.value && form.doc_type === 'DNI' && dni.length === 8 && !docLocked.value;

    if (!consultable) {
        // Al romper la condición se suelta el nombre: si se cambia el documento
        // el nombre de antes ya no está verificado y hay que poder corregirlo.
        dniEstado.value = null;
        nombreVerificado.value = false;
        return;
    }

    temporizador = setTimeout(() => consultarDni(dni), 500);
});

/** Suelta los campos para escribir el nombre a mano. */
const editarNombreAMano = () => {
    nombreVerificado.value = false;
};

const filterOption = (input, option) =>
    String(option.label ?? '').toLowerCase().includes(String(input).toLowerCase());

// Con `value-format` el DatePicker habla en cadenas, igual que el backend: se
// enlaza el campo directo. El computed que habia en medio convertia a dayjs y
// llamaba a `.format()` sobre una cadena, asi que la fecha se borraba al
// elegirla.

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
            :subtitle="isEdit ? person.full_name : $t('people.create_subtitle')"
        >
            <template #icon><IdcardOutlined /></template>
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

                <!-- La identidad va primero: se teclea el documento y RENIEC
                     rellena el nombre. Al revés se escribiría un nombre que la
                     consulta pisa acto seguido. -->
                <h2 class="form-section-title">{{ $t('people.section_identity') }}</h2>

                <Alert
                    v-if="docLocked"
                    type="info"
                    show-icon
                    class="mb-4"
                    :message="$t('people.doc_masked_notice')"
                >
                    <template #icon><LockOutlined /></template>
                </Alert>

                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="10">
                        <FormItem
                            :label="$t('people.doc_type')"
                            :label-col="{ xs: 24, sm: 10 }"
                            :wrapper-col="{ xs: 24, sm: 14 }"
                            required
                            :validate-status="form.errors.doc_type ? 'error' : ''"
                            :help="form.errors.doc_type"
                        >
                            <Select
                                v-model:value="form.doc_type"
                                size="large"
                                :disabled="docLocked"
                                :options="docTypesForCountry"
                                :placeholder="$t('global.select')"
                            />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :lg="14">
                        <FormItem
                            :label="$t('people.num_doc')"
                            :tooltip="docLocked ? $t('people.doc_masked_notice') : $t('people.num_doc_help')"
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            required
                            :validate-status="form.errors.num_doc ? 'error' : ''"
                            :help="form.errors.num_doc
                                || (dniEstado ? $t(`people.dni_${dniEstado}`) : '')
                                || (faltanDigitos === 1 ? $t('people.num_doc_falta_uno') : '')
                                || (faltanDigitos ? $t('people.num_doc_faltan', { n: faltanDigitos }) : '')"
                        >
                            <Tooltip :title="docLocked ? $t('people.doc_masked_notice') : ''">
                                <Input
                                    v-model:value="form.num_doc"
                                    size="large"
                                    autofocus
                                    :disabled="docLocked"
                                    :placeholder="$t('people.num_doc_placeholder')"
                                />
                            </Tooltip>
                        </FormItem>
                    </Col>
                </Row>

                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label="$t('people.country')"
                            :tooltip="$t('people.country_help')"
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            required
                            :validate-status="form.errors.country_id ? 'error' : ''"
                            :help="form.errors.country_id"
                        >
                            <Select
                                v-model:value="form.country_id"
                                size="large"
                                show-search
                                :disabled="docLocked"
                                :options="countryOptions"
                                :filter-option="filterOption"
                                :placeholder="$t('global.select')"
                            />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label="$t('people.nationality')"
                            :tooltip="$t('people.nationality_help')"
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :validate-status="form.errors.nationality_id ? 'error' : ''"
                            :help="form.errors.nationality_id"
                        >
                            <Select
                                v-model:value="form.nationality_id"
                                size="large"
                                show-search
                                allow-clear
                                :options="nationalityOptions"
                                :filter-option="filterOption"
                                :placeholder="$t('global.select')"
                            />
                        </FormItem>
                    </Col>
                </Row>

                <FormItem
                    :label="$t('people.birthdate')"
                    :tooltip="$t('people.birthdate_help')"
                    :validate-status="form.errors.birthdate ? 'error' : ''"
                    :help="form.errors.birthdate"
                >
                    <DatePicker v-model:value="form.birthdate" size="large" style="width: 100%" value-format="YYYY-MM-DD" />
                </FormItem>

                <h2 class="form-section-title">{{ $t('global.general_data') }}</h2>

                <!-- Con el nombre traído de RENIEC los dos campos se bloquean:
                     es el nombre oficial, el que tiene que cuadrar con el
                     documento que piden en la puerta. Queda la salida a mano
                     por si la consulta devolviera algo raro. -->
                <Alert
                    v-if="nombreVerificado"
                    type="success"
                    show-icon
                    class="mb-4"
                    :message="$t('people.dni_verificado')"
                >
                    <template #icon><SafetyCertificateOutlined /></template>
                    <template #action>
                        <Button size="small" type="link" @click="editarNombreAMano">
                            {{ $t('people.dni_editar_a_mano') }}
                        </Button>
                    </template>
                </Alert>

                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label="$t('people.name')"
                            :tooltip="$t('people.name_help')"
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            required
                            :validate-status="form.errors.name ? 'error' : ''"
                            :help="form.errors.name"
                        >
                            <Input
                                v-model:value="form.name"
                                size="large"
                                :maxlength="255"
                                :disabled="nombreVerificado"
                                :placeholder="$t('people.name_placeholder')"
                            />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label="$t('people.lastname')"
                            :tooltip="$t('people.lastname_help')"
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            required
                            :validate-status="form.errors.lastname ? 'error' : ''"
                            :help="form.errors.lastname"
                        >
                            <Input
                                v-model:value="form.lastname"
                                size="large"
                                :maxlength="255"
                                :disabled="nombreVerificado"
                                :placeholder="$t('people.lastname_placeholder')"
                            />
                        </FormItem>
                    </Col>
                </Row>

                <h2 class="form-section-title">{{ $t('people.section_work') }}</h2>

                <!-- Empresa y cargo. En el sistema anterior son parte de la
                     ficha del trabajador y los dos son obligatorios; aquí
                     faltaban los dos, así que no había forma de decir que
                     alguien es técnico de tal contratista. -->
                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label="$t('people.company')"
                            :tooltip="$t('people.company_help')"
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
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
                            :label="$t('people.position')"
                            :tooltip="$t('people.position_help')"
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            required
                            :validate-status="form.errors.position_id ? 'error' : ''"
                            :help="form.errors.position_id"
                        >
                            <Select
                                v-model:value="form.position_id"
                                size="large"
                                show-search
                                :options="positionOptions"
                                :filter-option="filterOption"
                                :placeholder="$t('global.select')"
                            />
                        </FormItem>
                    </Col>
                </Row>

                <!-- Qué firma en obra. No estaba en ninguna pantalla: sin rol
                     de supervisor la persona no aparece nunca en el selector de
                     aprobadores del plan, así que un supervisor nuevo no se
                     podía dar de alta. -->
                <FormItem
                    :label="$t('people.roles')"
                    :tooltip="$t('people.roles_help')"
                    :validate-status="form.errors.roles ? 'error' : ''"
                    :help="form.errors.roles"
                >
                    <Select
                        v-model:value="form.roles"
                        mode="multiple"
                        size="large"
                        :options="roleOptions"
                        :placeholder="$t('people.roles_placeholder')"
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
