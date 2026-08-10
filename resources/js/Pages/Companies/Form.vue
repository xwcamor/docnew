<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Form, FormItem, Input, Switch, Space, Alert, Select,
} from 'ant-design-vue';
import { BankOutlined, LoadingOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    company:          { type: Object, default: null },
    countryOptions:   { type: Array,  default: () => [] },
    defaultCountryId: { type: Number, default: null },
});

const isEdit = computed(() => !!props.company);


const form = useForm({
    name:          props.company?.name ?? '',
    complete_name: props.company?.complete_name ?? '',
    num_doc:       props.company?.num_doc ?? '',
    // Al crear, por defecto el país del usuario; al editar, el de la empresa.
    country_id:    props.company?.country_id ?? props.defaultCountryId ?? null,
    is_active:     props.company?.is_active ?? true,
});

// ─── Consulta del RUC en SUNAT ─────────────────────────────────────────────
// Lo que hacia la v1 y se perdio al portar el modulo: al teclear un RUC de 11
// digitos se pregunta a SUNAT y se rellena sola la razon social. Nunca estorba
// — si no hay token, si la API no contesta o si el RUC no existe, el campo
// sigue siendo editable a mano.
const rucEstado = ref(null);   // 'buscando' | 'encontrado' | 'no_encontrado' | 'error' | null
let temporizador = null;

const paisEsPeru = computed(() => {
    const p = props.countryOptions.find((o) => o.value === form.country_id);
    return /\(PE\)\s*$/.test(p?.label ?? '');
});

/**
 * Las formas societarias peruanas, al final del nombre.
 *
 * El separador de delante es obligatorio y no es un detalle: sin el, «EMPRESA»
 * acabaria en «EMPRE» y «MASA» en «MA», porque las dos terminan en «SA».
 */
const FORMA_SOCIETARIA = new RegExp(
    '[\\s,]+(?:' + [
        'S\\.?\\s?A\\.?\\s?C\\.?',
        'S\\.?\\s?A\\.?\\s?A\\.?',
        'S\\.?\\s?C\\.?\\s?R\\.?\\s?L\\.?(?:\\s?TDA\\.?)?',
        'S\\.?\\s?R\\.?\\s?L\\.?(?:\\s?TDA\\.?)?',
        'E\\.?\\s?I\\.?\\s?R\\.?\\s?L\\.?',
        'S\\.?\\s?A\\.?',
        'SOCIEDAD\\s+AN[OÓ]NIMA(?:\\s+(?:CERRADA|ABIERTA))?',
        'EMPRESA\\s+INDIVIDUAL\\s+DE\\s+RESPONSABILIDAD\\s+LIMITADA',
        'SUCURSAL\\s+DEL\\s+PERU',
    ].join('|') + ')\\.?\\s*$', 'i');

/**
 * El nombre corto propuesto a partir de la razon social.
 *
 * Son dos campos distintos y los dos hacen falta, como en el sistema anterior:
 * la razon social es el nombre legal y sale en la cabecera del PDF; el corto es
 * el que cabe en un listado, en la tarjeta de un plan y en un selector con
 * guantes. SUNAT solo da el primero.
 *
 * Esto propone el segundo quitandole la forma societaria — «HITACHI ENERGY PERU
 * S.A.C.» → «HITACHI ENERGY PERU» — para no teclearlo entero. Recortar de ahi a
 * «HITACHI» es un segundo; escribirlo desde cero, no.
 */
const nombreCorto = (razon) => (razon ?? '').trim().replace(FORMA_SOCIETARIA, '').trim();

const consultarRuc = async (ruc) => {
    rucEstado.value = 'buscando';
    try {
        const { data } = await window.axios.get(route('business_management.companies.lookup_ruc'), {
            params: { ruc },
        });
        // `sin_configurar` no se le enseña al usuario: no ha hecho nada mal y no
        // puede arreglarlo. La pantalla se queda como estaba.
        rucEstado.value = data.estado === 'sin_configurar' ? null : data.estado;
        if (data.estado === 'encontrado' && data.razon_social) {
            form.complete_name = data.razon_social;

            // Solo si esta vacio: lo que ya escribiste no se pisa nunca.
            if (!form.name?.trim()) {
                form.name = nombreCorto(data.razon_social);
            }
        }
    } catch {
        rucEstado.value = 'error';
    }
};

watch(() => form.num_doc, (valor) => {
    clearTimeout(temporizador);
    const ruc = (valor ?? '').replace(/\D/g, '');
    if (ruc.length !== 11 || !paisEsPeru.value) {
        rucEstado.value = null;
        return;
    }
    temporizador = setTimeout(() => consultarRuc(ruc), 500);
});


// El selector de país se escribe, no se scrollea.
const filterOption = (input, option) =>
    String(option.label ?? '').toLowerCase().includes(String(input).toLowerCase());

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
            <template #icon><BankOutlined /></template>
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
                    :label="$t('companies.complete_name')"
                    :tooltip="$t('companies.complete_name_help')"
                    required
                    :validate-status="form.errors.complete_name ? 'error' : ''"
                    :help="form.errors.complete_name"
                >
                    <Input
                        v-model:value="form.complete_name"
                        size="large"
                        :maxlength="255"
                        showCount
                        :placeholder="$t('companies.complete_name_placeholder')"
                    />
                </FormItem>

                <FormItem
                    :label="$t('companies.num_doc')"
                    :tooltip="$t('companies.num_doc_help')"
                    required
                    :validate-status="form.errors.num_doc ? 'error' : ''"
                    :help="form.errors.num_doc || (rucEstado ? $t(`companies.ruc_${rucEstado}`) : '')"
                >
                    <Input
                        v-model:value="form.num_doc"
                        size="large"
                        :maxlength="20"
                        :placeholder="$t('companies.num_doc_placeholder')"
                    >
                        <!-- SUNAT puede tardar varios segundos. Sin algo que se
                             mueva, el campo parece muerto y se teclea la razon
                             social encima de lo que iba a llegar. -->
                        <template v-if="rucEstado === 'buscando'" #suffix>
                            <LoadingOutlined />
                        </template>
                    </Input>
                </FormItem>

                <FormItem
                    :label="$t('companies.country')"
                    :tooltip="$t('companies.country_help')"
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
