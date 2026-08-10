<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Form, FormItem, Input, Switch, Space, Alert, Select, Tooltip, Button,
} from 'ant-design-vue';
import { BankOutlined, LoadingOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const { t } = useI18n();

const props = defineProps({
    company:           { type: Object, default: null },
    countryOptions:    { type: Array,  default: () => [] },
    docTypesByCountry: { type: Object, default: () => ({}) },
    defaultCountryId:  { type: Number, default: null },
    // Si el proveedor de la consulta peruana tiene token puesto. Lo dice el
    // servidor porque la pantalla no puede saberlo hasta haber preguntado una
    // vez, y hasta entonces prometia un relleno automatico que no iba a llegar.
    consultaConfigurada: { type: Boolean, default: false },
});

const isEdit = computed(() => !!props.company);


const form = useForm({
    // Al crear, por defecto el país del usuario; al editar, el de la empresa.
    country_id:    props.company?.country_id ?? props.defaultCountryId ?? null,
    doc_type:      props.company?.doc_type ?? '',
    num_doc:       props.company?.num_doc ?? '',
    complete_name: props.company?.complete_name ?? '',
    name:          props.company?.name ?? '',
    is_active:     props.company?.is_active ?? true,
});

// ── El documento de la empresa ─────────────────────────────────────────────
//
// No es «un código»: es un documento tributario y cambia de país en país — RUC
// en Perú, RUT en Chile, CUIT en Argentina, NIT en Colombia. Sale del mismo
// catálogo que el DNI de una persona, con scope de empresa, así que hereda lo
// mismo: cuántos caracteres lleva y cuáles admite.
const docTypesForCountry = computed(() => {
    const lista = [...(props.docTypesByCountry?.[form.country_id] ?? [])];

    // El tipo que la empresa ya tiene sigue en la lista aunque se haya dado de
    // baja del catálogo: corregirle el nombre no puede cambiarle el documento.
    const actual = props.company?.doc_type;
    if (actual && form.country_id === props.company?.country_id
        && !lista.some((o) => o.value === actual)) {
        lista.unshift({ value: actual, label: actual });
    }

    return lista;
});

// Al cambiar de país, el tipo que ya no existe allí se descarta.
watch(() => form.country_id, () => {
    const lista = docTypesForCountry.value;
    if (!lista.some((o) => o.value === form.doc_type)) {
        form.doc_type = lista[0]?.value ?? '';
    }
}, { immediate: true });

const tipoElegido = computed(() =>
    docTypesForCountry.value.find((o) => o.value === form.doc_type) ?? null);

// El pais elegido no tiene ningun documento de empresa en el catalogo.
//
// Antes esto era un desplegable vacio y nada mas: se elegia Chile, no salia
// nada, y al guardar la empresa se quedaba con «RUC» porque ese era el valor
// por defecto de la columna — un documento peruano en una contratista chilena,
// sin un solo error por ninguna parte. Ahora la columna admite vacio y la
// pantalla dice lo que pasa y donde se arregla.
const sinCatalogo = computed(() => docTypesForCountry.value.length === 0);

// Sin catálogo (país sin sembrar) se cae al tope de siempre.
const largoMaximo = computed(() => tipoElegido.value?.max ?? 20);

const filtroDelNumero = computed(() => ({
    digits:       /[^0-9]/g,
    alphanumeric: /[^A-Za-z0-9]/g,
}[tipoElegido.value?.allowed_chars] ?? /\s/g));

// Se limpia lo pegado en vez de rechazar la tecla, y el tope se aplica DESPUÉS
// de limpiar: con el `maxlength` del navegador, pegar «20-51234 5678» se
// cortaba a los once primeros caracteres —guiones y espacios incluidos— y
// perdía dígitos buenos.
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

// ─── Consulta del RUC en SUNAT ─────────────────────────────────────────────
// Lo que hacia la v1 y se perdio al portar el modulo: al teclear un RUC de 11
// digitos se pregunta a SUNAT y se rellena sola la razon social. Nunca estorba
// — si no hay token, si la API no contesta o si el RUC no existe, el campo
// sigue siendo editable a mano.
const rucEstado = ref(null);   // 'buscando' | 'encontrado' | 'no_encontrado' | 'error' | null
let temporizador = null;

// Si el proveedor no esta configurado no hay consulta que esperar, asi que la
// razon social no se puede quedar bloqueada esperandola. Arranca con lo que
// dice el servidor: antes arrancaba en `true` y el candado —con su «se rellena
// al escribir el documento» debajo— salia igual en una instalacion sin token,
// hasta que alguien tecleaba once digitos y la respuesta lo desmentia.
const consultaDisponible = ref(props.consultaConfigurada);

// La salida a mano: se pulsa cuando la consulta no encuentra la empresa, o
// cuando devuelve algo que no cuadra.
const razonAMano = ref(false);

// Lo EXACTO que puso la consulta en cada campo, para saber despues si lo que
// hay escrito lo trajo SUNAT o lo escribio una persona.
const puestoPorSunat = ref({ razon: null, corto: null });

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
        // puede arreglarlo. Lo que sí hace es soltar el campo, porque si no
        // habría que esperar a una consulta que nunca va a llegar.
        consultaDisponible.value = data.estado !== 'sin_configurar';
        rucEstado.value = data.estado === 'sin_configurar' ? null : data.estado;

        if (data.estado === 'encontrado' && data.razon_social) {
            form.complete_name = data.razon_social;
            puestoPorSunat.value.razon = data.razon_social;

            // Solo si esta vacio: lo que ya escribiste no se pisa nunca.
            if (!form.name?.trim()) {
                form.name = nombreCorto(data.razon_social);
                puestoPorSunat.value.corto = form.name;
            }
        }
    } catch {
        rucEstado.value = 'error';
    }
};

/**
 * Cambiar el documento borra lo que habia traido la consulta.
 *
 * Sin esto, teclear un RUC, dejar que rellene «HITACHI ENERGY PERU S.A.C.» y
 * cambiarlo despues por otro numero dejaba el nombre legal de Hitachi pegado a
 * un RUC que no es el suyo, y se guardaba tal cual. Solo se limpia lo que puso
 * SUNAT y sigue intacto: si alguien lo corrigio a mano, ese texto es suyo y se
 * respeta.
 */
const olvidarLoQueTrajoSunat = () => {
    if (puestoPorSunat.value.razon !== null && form.complete_name === puestoPorSunat.value.razon) {
        form.complete_name = '';
    }
    if (puestoPorSunat.value.corto !== null && form.name === puestoPorSunat.value.corto) {
        form.name = '';
    }

    puestoPorSunat.value = { razon: null, corto: null };
};

watch(() => form.num_doc, (valor) => {
    clearTimeout(temporizador);
    // Cambiar el RUC vuelve a poner el candado: la razon social de antes ya no
    // es la de este documento.
    razonAMano.value = false;
    olvidarLoQueTrajoSunat();

    const ruc = (valor ?? '').replace(/\D/g, '');
    if (ruc.length !== 11 || !paisEsPeru.value) {
        rucEstado.value = null;
        return;
    }
    temporizador = setTimeout(() => consultarRuc(ruc), 500);
});

/**
 * La razon social no se teclea: la dice SUNAT.
 *
 * Es el nombre legal de la empresa y sale en la cabecera de cada PDF firmado.
 * Escribirla a mano es como acaban en la base «Hitachi Energy Peru SAC»,
 * «HITACHI ENERGY PERÚ S.A.C.» y «Hitachi energy peru» siendo la misma
 * empresa — que es justo lo que hubo que limpiar de la v1.
 *
 * Por eso nace bloqueada y solo se suelta cuando de verdad no hay de donde
 * sacarla: si la consulta no la encuentra, si falla, si el pais no es Peru
 * —fuera de ahi no hay a quien preguntar—, si el proveedor no esta
 * configurado, o si se pide expresamente editarla a mano.
 *
 * Al editar una empresa que ya existe tambien se suelta: el dato ya esta
 * guardado y se vino justamente a corregirlo.
 */
const razonBloqueada = computed(() => {
    if (isEdit.value || razonAMano.value || !consultaDisponible.value) return false;
    if (!paisEsPeru.value) return false;

    return rucEstado.value === null || rucEstado.value === 'buscando' || rucEstado.value === 'encontrado';
});

/**
 * Por que esta bloqueada, para que el campo gris no parezca roto.
 *
 * La sigla del documento sale del catalogo y no del texto: aqui el tipo ya esta
 * elegido, asi que la pista puede decirlo con su nombre —«al escribir el RUC»,
 * «al escribir el RUT»— en vez de escribir el peruano para todo el mundo.
 */
const razonPista = computed(() => {
    if (!razonBloqueada.value) return '';

    if (rucEstado.value === 'encontrado') return t('companies.razon_de_sunat');

    return t('companies.razon_esperando_doc', {
        type: tipoElegido.value?.value || t('companies.document').toLowerCase(),
    });
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

                <!-- El país va primero porque decide todo lo que viene detrás:
                     qué documento lleva la empresa y con qué forma. Detrás va
                     el documento, que es lo que la identifica, y solo después
                     los nombres — que además se rellenan solos cuando el RUC
                     contesta. -->
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
                    :label="$t('companies.doc_type')"
                    :tooltip="$t('companies.doc_type_help')"
                    :required="!sinCatalogo"
                    :validate-status="form.errors.doc_type ? 'error' : ''"
                    :help="form.errors.doc_type || (sinCatalogo ? $t('companies.doc_type_sin_catalogo') : '')"
                >
                    <Select
                        v-model:value="form.doc_type"
                        size="large"
                        :disabled="sinCatalogo"
                        :options="docTypesForCountry"
                        :placeholder="sinCatalogo ? $t('companies.doc_type_ninguno') : $t('global.select')"
                    />
                </FormItem>

                <FormItem
                    :label="$t('companies.num_doc')"
                    :tooltip="$t('companies.num_doc_help')"
                    :required="!sinCatalogo"
                    :validate-status="form.errors.num_doc ? 'error' : ''"
                    :help="form.errors.num_doc
                        || (rucEstado ? $t(`companies.ruc_${rucEstado}`) : '')
                        || (faltanDigitos === 1 ? $t('companies.num_doc_falta_uno') : '')
                        || (faltanDigitos > 1 ? $t('companies.num_doc_faltan', { n: faltanDigitos }) : '')"
                >
                    <!-- Sin tipo no hay número: la longitud y los caracteres que
                         se admiten los dice el tipo. -->
                    <Input
                        v-model:value="form.num_doc"
                        size="large"
                        autofocus
                        :disabled="sinCatalogo"
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
                    :label="$t('companies.complete_name')"
                    :tooltip="$t('companies.complete_name_help')"
                    required
                    :validate-status="form.errors.complete_name ? 'error' : ''"
                    :help="form.errors.complete_name || razonPista"
                >
                    <!-- Bloqueada mientras haya de donde sacarla. El nombre
                         legal sale en la cabecera de cada PDF firmado: tecleado
                         a mano es como la misma empresa acaba tres veces en la
                         base, escrita de tres formas. -->
                    <Tooltip :title="razonPista">
                        <Input
                            v-model:value="form.complete_name"
                            size="large"
                            :maxlength="255"
                            showCount
                            :disabled="razonBloqueada"
                            :placeholder="$t('companies.complete_name_placeholder')"
                        />
                    </Tooltip>
                    <Button
                        v-if="razonBloqueada && rucEstado === 'encontrado'"
                        size="small"
                        type="link"
                        class="a-mano"
                        @click="razonAMano = true"
                    >
                        {{ $t('companies.razon_editar_a_mano') }}
                    </Button>
                </FormItem>

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
                        :placeholder="$t('companies.name_placeholder')"
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
.a-mano { padding-left: 0; }
</style>
