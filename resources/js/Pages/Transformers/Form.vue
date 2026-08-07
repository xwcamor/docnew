<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, InputNumber, Space, Alert, Row, Col, Select, DatePicker,
    Modal, Button, Divider, message,
} from 'ant-design-vue';
import { PlusOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import TransformerIcon from '@/Components/Transformers/TransformerIcon.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';
import { useI18n } from '@/Plugins/i18n';
import { useAuth } from '@/Composables/useAuth';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const { can, isCustomerRestricted } = useAuth();

const props = defineProps({
    transformer:            { type: Object, default: null },
    customerOptions:        { type: Array,  default: () => [] },
    countryOptions:         { type: Array,  default: () => [] },
    oilTypeOptions:         { type: Array,  default: () => [] },
    transformerTypeOptions: { type: Array,  default: () => [] },
    brandOptions:           { type: Array,  default: () => [] },
    tapChangerTypeOptions:  { type: Array,  default: () => [] },
    tapChangerBrandOptions:      { type: Array,  default: () => [] },
    tapChangerModelOptions:      { type: Array,  default: () => [] },
    tapChangerTechnologyOptions: { type: Array,  default: () => [] },
    connectionTypeOptions:  { type: Array,  default: () => [] },
    preservationOptions:    { type: Array,  default: () => [] },
    substationOptions:      { type: Array,  default: () => [] },
    // Solo se llena para super; vacío = no se renderiza la sección Workspace.
    tenantOptions:          { type: Array,  default: () => [] },
});

const isEdit = computed(() => !!props.transformer);

const form = useForm({
    serial:              props.transformer?.serial ?? '',
    tag:                 props.transformer?.tag ?? '',
    customer_id:         props.transformer?.customer_id ?? null,
    customer_substation_id: props.transformer?.customer_substation_id ?? null,
    oil_type_id:         props.transformer?.oil_type_id ?? null,
    transformer_type_id: props.transformer?.transformer_type_id ?? null,
    brand_id:            props.transformer?.brand_id ?? null,
    tap_changer_type_id: props.transformer?.tap_changer_type_id ?? null,
    tap_changer_brand_id:      props.transformer?.tap_changer_brand_id ?? null,
    tap_changer_model_id:      props.transformer?.tap_changer_model_id ?? null,
    tap_changer_technology_id: props.transformer?.tap_changer_technology_id ?? null,
    connection_type_id:  props.transformer?.connection_type_id ?? null,
    transformer_preservation_id: props.transformer?.transformer_preservation_id ?? null,
    voltage_kv:          props.transformer?.voltage_kv ?? null,
    power_mva:           props.transformer?.power_mva ?? null,
    manufacture_year:    props.transformer?.manufacture_year ?? null,
    paper_type:          props.transformer?.paper_type ?? null,
    phases:              props.transformer?.phases ?? null,
    oil_treated_at:      props.transformer?.oil_treated_at ?? null,
    tenant_id:           props.transformer?.tenant_id ?? null,
});

// Árbol del conmutador: el tipo (DETC/OLTC/-) decide qué campos se muestran.
// DETC → marca + modelo; OLTC → marca + modelo + tecnología; "-" → nada.
// (Va DESPUÉS de `form`: el watch evalúa su source al crearse y necesita `form` ya definido.)
const tapType = computed(() => props.tapChangerTypeOptions.find((o) => o.value === form.tap_changer_type_id));
const tapCode = computed(() => String(tapType.value?.code || '').toLowerCase());
const showTapDetails = computed(() => tapCode.value === 'oltc' || tapCode.value === 'detc');
const showTapTechnology = computed(() => tapCode.value === 'oltc');
watch(() => form.tap_changer_type_id, () => {
    if (!showTapDetails.value) { form.tap_changer_brand_id = null; form.tap_changer_model_id = null; }
    if (!showTapTechnology.value) { form.tap_changer_technology_id = null; }
});

// Tipo de papel aislante. Enum fijo (no es catálogo de BD), se arma desde i18n.
const paperTypeOptions = computed(() => [
    { value: 'kraft',    label: t('transformers.paper_type_kraft') },
    { value: 'upgraded', label: t('transformers.paper_type_upgraded') },
]);

// Pasa las opciones a MAYÚSCULAS y las ordena A→Z por etiqueta. Conserva el
// resto de campos (ej. customer_id de las subestaciones). Se aplica a los selects
// de cliente, subestación, aceite, tipo de trafo, marca y fases.
const upSort = (opts) => [...opts]
    .map((o) => ({ ...o, label: (o.label ?? '').toLocaleUpperCase() }))
    .sort((a, b) => a.label.localeCompare(b.label));

const localCustomerOptions = ref([...props.customerOptions]);
const customerOpts        = computed(() => upSort(localCustomerOptions.value));
const oilTypeOpts         = computed(() => upSort(props.oilTypeOptions));
const transformerTypeOpts = computed(() => upSort(props.transformerTypeOptions));

// Lista local de marcas (copia de las del backend) para poder inyectar una
// recién creada inline sin recargar la página.
const localBrandOptions   = ref([...props.brandOptions]);
const brandOpts           = computed(() => upSort(localBrandOptions.value));

// Catálogos de conmutador con copia local (para el alta rápida sin salir del form).
const localTapBrandOptions = ref([...props.tapChangerBrandOptions]);
const localTapModelOptions = ref([...props.tapChangerModelOptions]);
const localTapTechOptions  = ref([...props.tapChangerTechnologyOptions]);
const tapBrandOpts = computed(() => upSort(localTapBrandOptions.value));
const tapModelOpts = computed(() => upSort(localTapModelOptions.value));
const tapTechOpts  = computed(() => upSort(localTapTechOptions.value));

// Alta rápida de marca/modelo/tecnología de conmutador (mismo patrón que marca).
const tapModal = ref({ open: false, saving: false, error: null, name: '', target: null });
const TAP_CFG = {
    brand:      { route: 'business_management.tap_changer_brands.quick_store',       field: 'tap_changer_brand_id',      opts: localTapBrandOptions },
    model:      { route: 'business_management.tap_changer_models.quick_store',       field: 'tap_changer_model_id',      opts: localTapModelOptions },
    technology: { route: 'business_management.tap_changer_technologies.quick_store', field: 'tap_changer_technology_id', opts: localTapTechOptions },
};
const openTapModal = (target) => { tapModal.value = { open: true, saving: false, error: null, name: '', target }; };
const createTap = async () => {
    const cfg = TAP_CFG[tapModal.value.target];
    if (!tapModal.value.name.trim()) { tapModal.value.error = t('transformers.add_tap_name_required'); return; }
    tapModal.value.saving = true; tapModal.value.error = null;
    try {
        const { data } = await window.axios.post(route(cfg.route), { name: tapModal.value.name.trim(), is_active: true });
        cfg.opts.value.push({ value: data.id, label: data.name });
        form[cfg.field] = data.id;
        tapModal.value.open = false;
        message.success(t('global.success'));
    } catch (e) {
        const errs = e?.response?.data?.errors;
        tapModal.value.error = errs?.name?.[0] || e?.response?.data?.message || t('global.error');
    } finally {
        tapModal.value.saving = false;
    }
};

// ── Alta rápida de marca (gated por brands.create; super/admin siempre) ──────
const brandModalOpen = ref(false);
const brandSaving    = ref(false);
const brandError     = ref(null);
const brandDraft     = ref({ name: '', code: '' });

// Aviso anti-duplicados: marcas existentes que se parecen a lo que escribe.
const similarBrands = computed(() => {
    const n = (brandDraft.value.name ?? '').trim().toLocaleLowerCase();
    if (n.length < 2) return [];
    return localBrandOptions.value
        .filter((b) => (b.label ?? '').toLocaleLowerCase().includes(n))
        .slice(0, 6);
});

const openBrandModal = () => {
    brandDraft.value = { name: '', code: '' };
    brandError.value = null;
    brandModalOpen.value = true;
};

const createBrand = async () => {
    if (!(brandDraft.value.name ?? '').trim()) {
        brandError.value = t('transformers.add_brand_name_required');
        return;
    }
    brandSaving.value = true;
    brandError.value = null;
    try {
        const { data } = await window.axios.post(
            route('business_management.brands.quick_store'),
            { name: brandDraft.value.name.trim(), code: (brandDraft.value.code ?? '').trim() || null, is_active: true },
        );
        localBrandOptions.value.push({ value: data.id, label: data.name });
        form.brand_id = data.id;
        brandModalOpen.value = false;
        message.success(t('brands.created'));
    } catch (e) {
        const errs = e?.response?.data?.errors;
        brandError.value = errs?.name?.[0] || errs?.code?.[0] || e?.response?.data?.message || t('global.error');
    } finally {
        brandSaving.value = false;
    }
};

// ── Alta rápida de cliente (gated por customers.create; super/admin siempre) ──
const customerModalOpen = ref(false);
const customerSaving    = ref(false);
const customerError     = ref(null);
const customerDraft     = ref({ name: '', cod: '', country_id: null, address: '' });

// Aviso anti-duplicados: clientes existentes que se parecen a lo que escribe.
const similarCustomers = computed(() => {
    const n = (customerDraft.value.name ?? '').trim().toLocaleLowerCase();
    if (n.length < 2) return [];
    return localCustomerOptions.value
        .filter((c) => (c.label ?? '').toLocaleLowerCase().includes(n))
        .slice(0, 6);
});

const openCustomerModal = () => {
    customerDraft.value = { name: '', cod: '', country_id: null, address: '' };
    customerError.value = null;
    customerModalOpen.value = true;
};

const createCustomer = async () => {
    if (!(customerDraft.value.name ?? '').trim()) {
        customerError.value = t('transformers.add_customer_name_required');
        return;
    }
    customerSaving.value = true;
    customerError.value = null;
    try {
        const { data } = await window.axios.post(
            route('business_management.customers.quick_store'),
            {
                name: customerDraft.value.name.trim(),
                cod: (customerDraft.value.cod ?? '').trim(),
                country_id: customerDraft.value.country_id,
                address: (customerDraft.value.address ?? '').trim() || null,
                is_active: true,
            },
        );
        localCustomerOptions.value.push({ value: data.id, label: data.name });
        form.customer_id = data.id;
        customerModalOpen.value = false;
        message.success(t('customers.created'));
    } catch (e) {
        const errs = e?.response?.data?.errors;
        customerError.value = errs?.name?.[0] || errs?.cod?.[0] || errs?.country_id?.[0]
            || e?.response?.data?.message || t('global.error');
    } finally {
        customerSaving.value = false;
    }
};

// Número de fases. Enum fijo.
const phasesOptions = computed(() => upSort([
    { value: 'single', label: t('transformers.phases_single') },
    { value: 'three',  label: t('transformers.phases_three') },
    { value: 'two',    label: t('transformers.phases_two') },
]));

// Subestaciones del cliente seleccionado (cascada en el cliente, sin AJAX).
const filteredSubstations = computed(
    () => upSort(props.substationOptions.filter((s) => s.customer_id === form.customer_id)),
);

// Si cambia el cliente y la subestación elegida ya no aplica, se limpia.
watch(() => form.customer_id, () => {
    if (!filteredSubstations.value.some((s) => s.value === form.customer_substation_id)) {
        form.customer_substation_id = null;
    }
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.transformers.update', props.transformer.slug));
    } else {
        form.post(route('business_management.transformers.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('transformers.singular') : $t('transformers.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.transformers.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('transformers.record') : $t('transformers.new')"
            :subtitle="isEdit ? (transformer.serial || transformer.tag) : $t('transformers.create_subtitle')"
        >
            <template #icon><TransformerIcon /></template>
        </SectionHeader>

        <Card class="form-card" :bodyStyle="{ padding: '24px 28px' }">
            <Form layout="horizontal" :label-col="{ xs: 24, sm: 8, md: 6 }" :wrapper-col="{ xs: 24, sm: 16, md: 13 }" label-align="right" :colon="true" @submit.prevent="submit">

                <Alert
                    v-if="form.hasErrors && Object.keys(form.errors).length > 0"
                    type="error"
                    show-icon
                    :message="$t('global.fix_marked_fields')"
                    class="mb-4"
                />

                <Row :gutter="[20, 0]">
                    <Col :xs="24">
                        <h2 class="form-section-title">{{ $t('transformers.sec_general') }}</h2>
                    </Col>
                    <Col :xs="24" :md="12">
                        <FormItem
                            :label="$t('transformers.serial')"
                            :tooltip="$t('transformers.serial_help')"
                            required
                            :validate-status="form.errors.serial ? 'error' : ''"
                            :help="form.errors.serial"
                        >
                            <Input
                                v-model:value="form.serial"
                                size="large"
                                :maxlength="100"
                                showCount
                                autofocus
                            />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="12">
                        <FormItem
                            :label="$t('transformers.tag')"
                            required
                            :validate-status="form.errors.tag ? 'error' : ''"
                            :help="form.errors.tag"
                        >
                            <Input v-model:value="form.tag" size="large" :maxlength="100" />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('transformers.customer')"
                            required
                            :validate-status="form.errors.customer_id ? 'error' : ''"
                            :help="form.errors.customer_id"
                        >
                            <Select
                                v-model:value="form.customer_id"
                                size="large"
                                show-search
                                option-filter-prop="label"
                                :options="customerOpts"
                                :placeholder="$t('transformers.customer')"
                            />
                            <a v-if="can('customers.create') && !isCustomerRestricted" class="add-brand-link" @click="openCustomerModal">
                                <PlusOutlined /> {{ $t('transformers.add_customer') }}
                            </a>
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('transformers.substation')"
                            :tooltip="$t('transformers.substation_help')"
                            required
                            :validate-status="form.errors.customer_substation_id ? 'error' : ''"
                            :help="form.errors.customer_substation_id"
                        >
                            <Select
                                v-model:value="form.customer_substation_id"
                                size="large"
                                show-search
                                option-filter-prop="label"
                                :options="filteredSubstations"
                                :disabled="!form.customer_id"
                                :placeholder="form.customer_id ? $t('transformers.substation') : $t('transformers.select_customer_first')"
                            />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('transformers.oil_type')"
                            required
                            :validate-status="form.errors.oil_type_id ? 'error' : ''"
                            :help="form.errors.oil_type_id"
                        >
                            <Select
                                v-model:value="form.oil_type_id"
                                size="large"
                                show-search
                                option-filter-prop="label"
                                :options="oilTypeOpts"
                                :placeholder="$t('transformers.oil_type')"
                            />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('transformers.transformer_type')"
                            required
                            :validate-status="form.errors.transformer_type_id ? 'error' : ''"
                            :help="form.errors.transformer_type_id"
                        >
                            <Select
                                v-model:value="form.transformer_type_id"
                                size="large"
                                show-search
                                option-filter-prop="label"
                                :options="transformerTypeOpts"
                                :placeholder="$t('transformers.transformer_type')"
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('transformers.brand')"
                            required
                            :validate-status="form.errors.brand_id ? 'error' : ''"
                            :help="form.errors.brand_id"
                        >
                            <Select
                                v-model:value="form.brand_id"
                                size="large"
                                show-search
                                option-filter-prop="label"
                                :options="brandOpts"
                                :placeholder="$t('transformers.brand')"
                            />
                            <a v-if="can('brands.create')" class="add-brand-link" @click="openBrandModal">
                                <PlusOutlined /> {{ $t('transformers.add_brand') }}
                            </a>
                        </FormItem>
                    </Col>

                    <Col :xs="24">
                        <h2 class="form-section-title form-section-title--spaced">{{ $t('transformers.sec_tap_changer') }}</h2>
                    </Col>
                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('transformers.tap_changer_type')"
                            required
                            :validate-status="form.errors.tap_changer_type_id ? 'error' : ''"
                            :help="form.errors.tap_changer_type_id"
                        >
                            <Select
                                v-model:value="form.tap_changer_type_id"
                                size="large"
                                show-search
                                option-filter-prop="label"
                                :options="tapChangerTypeOptions"
                                :placeholder="$t('transformers.tap_changer_type')"
                            />
                        </FormItem>
                    </Col>

                    <!-- Árbol del conmutador: marca + modelo (DETC y OLTC), tecnología (solo OLTC) -->
                    <Col v-if="showTapDetails" :xs="24" :md="8">
                        <FormItem
                            :label="$t('transformers.tap_changer_brand')"
                            :validate-status="form.errors.tap_changer_brand_id ? 'error' : ''"
                            :help="form.errors.tap_changer_brand_id"
                        >
                            <Select
                                v-model:value="form.tap_changer_brand_id"
                                size="large" show-search allow-clear option-filter-prop="label"
                                :options="tapBrandOpts"
                                :placeholder="$t('transformers.tap_changer_brand')"
                            />
                            <a v-if="can('tap_changer_brands.create')" class="add-brand-link" @click="openTapModal('brand')">
                                <PlusOutlined /> {{ $t('transformers.add_tap_brand') }}
                            </a>
                        </FormItem>
                    </Col>

                    <Col v-if="showTapDetails" :xs="24" :md="8">
                        <FormItem
                            :label="$t('transformers.tap_changer_model')"
                            :validate-status="form.errors.tap_changer_model_id ? 'error' : ''"
                            :help="form.errors.tap_changer_model_id"
                        >
                            <Select
                                v-model:value="form.tap_changer_model_id"
                                size="large" show-search allow-clear option-filter-prop="label"
                                :options="tapModelOpts"
                                :placeholder="$t('transformers.tap_changer_model')"
                            />
                            <a v-if="can('tap_changer_models.create')" class="add-brand-link" @click="openTapModal('model')">
                                <PlusOutlined /> {{ $t('transformers.add_tap_model') }}
                            </a>
                        </FormItem>
                    </Col>

                    <Col v-if="showTapTechnology" :xs="24" :md="8">
                        <FormItem
                            :label="$t('transformers.tap_changer_technology')"
                            :validate-status="form.errors.tap_changer_technology_id ? 'error' : ''"
                            :help="form.errors.tap_changer_technology_id"
                        >
                            <Select
                                v-model:value="form.tap_changer_technology_id"
                                size="large" show-search allow-clear option-filter-prop="label"
                                :options="tapTechOpts"
                                :placeholder="$t('transformers.tap_changer_technology')"
                            />
                            <a v-if="can('tap_changer_technologies.create')" class="add-brand-link" @click="openTapModal('technology')">
                                <PlusOutlined /> {{ $t('transformers.add_tap_technology') }}
                            </a>
                        </FormItem>
                    </Col>

                    <Col :xs="24">
                        <h2 class="form-section-title form-section-title--spaced">{{ $t('transformers.sec_specs') }}</h2>
                    </Col>
                    <Col :xs="24" :md="6">
                        <FormItem
                            :label="$t('transformers.voltage_kv')"
                            required
                            :validate-status="form.errors.voltage_kv ? 'error' : ''"
                            :help="form.errors.voltage_kv"
                        >
                            <InputNumber v-model:value="form.voltage_kv" size="large" :min="0" :step="0.01" style="width: 100%" />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="6">
                        <FormItem
                            :label="$t('transformers.power_mva')"
                            required
                            :validate-status="form.errors.power_mva ? 'error' : ''"
                            :help="form.errors.power_mva"
                        >
                            <InputNumber v-model:value="form.power_mva" size="large" :min="0" :step="0.01" style="width: 100%" />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="6">
                        <FormItem
                            :label="$t('transformers.manufacture_year')"
                            required
                            :validate-status="form.errors.manufacture_year ? 'error' : ''"
                            :help="form.errors.manufacture_year"
                        >
                            <InputNumber v-model:value="form.manufacture_year" size="large" :min="1900" :max="2100" style="width: 100%" />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :md="6">
                        <FormItem
                            :label="$t('transformers.phases')"
                            required
                            :validate-status="form.errors.phases ? 'error' : ''"
                            :help="form.errors.phases"
                        >
                            <Select
                                v-model:value="form.phases"
                                size="large"
                                :options="phasesOptions"
                                :placeholder="$t('transformers.phases_select')"
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24">
                        <h2 class="form-section-title form-section-title--spaced">{{ $t('transformers.sec_other') }}</h2>
                    </Col>
                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('transformers.connection_type')"
                            :tooltip="$t('transformers.connection_type_help')"
                            :validate-status="form.errors.connection_type_id ? 'error' : ''"
                            :help="form.errors.connection_type_id"
                        >
                            <Select
                                v-model:value="form.connection_type_id"
                                size="large"
                                show-search
                                allow-clear
                                option-filter-prop="label"
                                :options="connectionTypeOptions"
                                :placeholder="$t('transformers.connection_type_placeholder')"
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('transformers.preservation')"
                            :tooltip="$t('transformers.preservation_help')"
                            :validate-status="form.errors.transformer_preservation_id ? 'error' : ''"
                            :help="form.errors.transformer_preservation_id"
                        >
                            <Select
                                v-model:value="form.transformer_preservation_id"
                                size="large"
                                show-search
                                allow-clear
                                option-filter-prop="label"
                                :options="preservationOptions"
                                :placeholder="$t('transformers.preservation_placeholder')"
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('transformers.paper_type')"
                            :tooltip="$t('transformers.paper_type_help')"
                            :validate-status="form.errors.paper_type ? 'error' : ''"
                            :help="form.errors.paper_type"
                        >
                            <Select
                                v-model:value="form.paper_type"
                                size="large"
                                allow-clear
                                :options="paperTypeOptions"
                                :placeholder="$t('transformers.paper_type_placeholder')"
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('transformers.oil_treated_at')"
                            :tooltip="$t('transformers.oil_treated_at_help')"
                            :validate-status="form.errors.oil_treated_at ? 'error' : ''"
                            :help="form.errors.oil_treated_at"
                        >
                            <DatePicker
                                v-model:value="form.oil_treated_at"
                                value-format="YYYY-MM-DD"
                                size="large"
                                style="width: 100%"
                                :placeholder="$t('transformers.oil_treated_at_placeholder')"
                            />
                        </FormItem>
                    </Col>

                    <!-- ════ Sección: Workspace ════ (solo super; tenantOptions vacío para los demás) -->
                    <template v-if="tenantOptions.length">
                        <Col :xs="24">
                            <h2 class="form-section-title form-section-title--spaced">{{ $t('tenants.singular') }}</h2>
                        </Col>
                        <Col :xs="24" :md="8">
                            <FormItem
                                :label="$t('tenants.singular')"
                                :tooltip="$t('tenants.assign_required_hint')"
                                required
                                :validate-status="form.errors.tenant_id ? 'error' : ''"
                                :help="form.errors.tenant_id"
                            >
                                <Select
                                    v-model:value="form.tenant_id"
                                    :options="tenantOptions"
                                    :placeholder="$t('tenants.select_placeholder')"
                                    size="large"
                                    show-search
                                    option-filter-prop="label"
                                />
                            </FormItem>
                        </Col>
                    </template>
                </Row>

                <FormFooter
                    :cancel-href="route('business_management.transformers.index')"
                    :is-edit="isEdit"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </Card>

        <!-- Alta rápida de marca. La validación de unicidad (insensible a
             acentos/mayúsculas) vive en el backend; aquí solo avisamos de
             marcas parecidas para evitar duplicados por typo. -->
        <Modal
            v-model:open="brandModalOpen"
            :title="$t('transformers.add_brand_title')"
            :confirm-loading="brandSaving"
            :ok-text="$t('global.save')"
            :cancel-text="$t('global.cancel')"
            @ok="createBrand"
        >
            <FormItem :label="$t('brands.name')" required>
                <Input
                    v-model:value="brandDraft.name"
                    size="large"
                    :placeholder="$t('brands.name')"
                    @press-enter="createBrand"
                />
            </FormItem>
            <FormItem :label="$t('brands.code')">
                <Input v-model:value="brandDraft.code" :placeholder="$t('brands.code')" />
            </FormItem>

            <Alert
                v-if="similarBrands.length"
                type="warning"
                show-icon
                :message="$t('transformers.add_brand_similar')"
                style="margin-bottom: 8px"
            >
                <template #description>
                    <div class="similar-brands">
                        <span v-for="b in similarBrands" :key="b.value" class="similar-chip">{{ b.label }}</span>
                    </div>
                </template>
            </Alert>

            <Alert v-if="brandError" type="error" show-icon :message="brandError" />
        </Modal>

        <Modal
            v-model:open="customerModalOpen"
            :title="$t('transformers.add_customer_title')"
            :confirm-loading="customerSaving"
            :ok-text="$t('global.save')"
            :cancel-text="$t('global.cancel')"
            @ok="createCustomer"
        >
            <FormItem :label="$t('customers.name')" required>
                <Input
                    v-model:value="customerDraft.name"
                    size="large"
                    :placeholder="$t('customers.name')"
                    @press-enter="createCustomer"
                />
            </FormItem>
            <FormItem :label="$t('customers.cod')" required>
                <Input v-model:value="customerDraft.cod" :placeholder="$t('customers.cod')" />
            </FormItem>
            <FormItem :label="$t('customers.country')" required>
                <Select
                    v-model:value="customerDraft.country_id"
                    size="large"
                    show-search
                    option-filter-prop="label"
                    :options="countryOptions"
                    :placeholder="$t('customers.country')"
                />
            </FormItem>
            <FormItem :label="$t('customers.address')">
                <Input v-model:value="customerDraft.address" :placeholder="$t('customers.address')" />
            </FormItem>

            <Alert
                v-if="similarCustomers.length"
                type="warning"
                show-icon
                :message="$t('transformers.add_customer_similar')"
                style="margin-bottom: 8px"
            >
                <template #description>
                    <div class="similar-brands">
                        <span v-for="c in similarCustomers" :key="c.value" class="similar-chip">{{ c.label }}</span>
                    </div>
                </template>
            </Alert>

            <Alert v-if="customerError" type="error" show-icon :message="customerError" />
        </Modal>

        <!-- Alta rápida de marca/modelo/tecnología de conmutador (solo nombre). -->
        <Modal
            v-model:open="tapModal.open"
            :title="$t('transformers.add_tap_title')"
            :confirm-loading="tapModal.saving"
            :ok-text="$t('global.save')"
            :cancel-text="$t('global.cancel')"
            @ok="createTap"
        >
            <FormItem
                :label="$t('transformers.add_tap_name')"
                required
                :validate-status="tapModal.error ? 'error' : ''"
                :help="tapModal.error"
            >
                <Input v-model:value="tapModal.name" size="large" @pressEnter="createTap" />
            </FormItem>
        </Modal>
    </div>
</template>

<style scoped>
.form-card { border-radius: 6px; }
.add-brand-link {
    display: inline-flex; align-items: center; gap: 5px;
    margin-top: 6px; font-size: 0.8rem; color: #0A6ED1; cursor: pointer;
}
.add-brand-link:hover { text-decoration: underline; }
.similar-brands { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.similar-chip {
    background: rgba(10, 110, 209, 0.10); color: #0A6ED1;
    padding: 2px 9px; border-radius: 999px; font-size: 0.78rem; font-weight: 600;
}
.state-label {
    font-size: 0.875rem;
    color: var(--color-text);
    font-weight: 500;
}
.mb-4 { margin-bottom: 16px; }
</style>
