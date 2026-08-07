<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Switch, Space, Alert, Row, Col, Select, Button,
} from 'ant-design-vue';
import { UserOutlined, UploadOutlined, TeamOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    customer:       { type: Object, default: null },
    countryOptions: { type: Array,  default: () => [] },
    defaultCountryId: { type: Number, default: null },
    // Solo se llena para super; vacío = no se renderiza la sección Workspace.
    tenantOptions:  { type: Array,  default: () => [] },
});

const isEdit = computed(() => !!props.customer);

const form = useForm({
    name:       props.customer?.name ?? '',
    cod:        props.customer?.cod ?? '',
    address:    props.customer?.address ?? '',
    // Al crear, por defecto el país del usuario; al editar, el del cliente.
    country_id: props.customer?.country_id ?? props.defaultCountryId ?? null,
    is_active:  props.customer?.is_active ?? true,
    tenant_id:  props.customer?.tenant_id ?? null,
    logo:       null,
    // Method spoofing: subir archivo requiere POST aunque editar sea PUT.
    _method:    isEdit.value ? 'put' : 'post',
});

// Preview del logo (usa logo_url del backend; blob local solo al elegir nuevo).
const previewUrl = ref(props.customer?.logo_url ?? null);
const onLogoChange = (file) => {
    form.logo = file ?? null;
    previewUrl.value = file ? URL.createObjectURL(file) : (props.customer?.logo_url ?? null);
};

const submit = () => {
    const url = isEdit.value
        ? route('business_management.customers.update', props.customer.slug)
        : route('business_management.customers.store');
    form.post(url, { forceFormData: true });
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('customers.singular') : $t('customers.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.customers.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('customers.record') : $t('customers.new')"
            :subtitle="isEdit ? customer.name : $t('customers.create_subtitle')"
        >
            <template #icon><TeamOutlined /></template>
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

                <h2 class="form-section-title">{{ $t('global.general_data') }}</h2>

                <Row :gutter="[20, 0]">
                    <Col :xs="24" :md="16">
                        <FormItem
                            :label="$t('customers.name')"
                            :tooltip="$t('customers.name_help')"
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
                                :placeholder="$t('customers.name_placeholder')"
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('customers.cod')"
                            :tooltip="$t('customers.cod_help')"
                            required
                            :validate-status="form.errors.cod ? 'error' : ''"
                            :help="form.errors.cod || $t('customers.cod_hint')"
                        >
                            <Input
                                v-model:value="form.cod"
                                size="large"
                                :maxlength="50"
                                :placeholder="$t('customers.cod_placeholder')"
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :md="16">
                        <FormItem
                            :label="$t('customers.country')"
                            :tooltip="$t('customers.country_help')"
                            required
                            :validate-status="form.errors.country_id ? 'error' : ''"
                            :help="form.errors.country_id"
                        >
                            <Select
                                v-model:value="form.country_id"
                                :options="countryOptions"
                                :placeholder="$t('customers.country_placeholder')"
                                size="large"
                                show-search
                                :filter-option="(input, opt) => (opt.label ?? '').toLowerCase().includes(input.toLowerCase())"
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24">
                        <FormItem
                            :label="$t('customers.address')"
                            :tooltip="$t('customers.address_help')"
                            :validate-status="form.errors.address ? 'error' : ''"
                            :help="form.errors.address"
                        >
                            <Input
                                v-model:value="form.address"
                                size="large"
                                :maxlength="255"
                                :placeholder="$t('customers.address_placeholder')"
                            />
                        </FormItem>
                    </Col>

                    <!-- Logo del cliente: debajo de los datos, alineado como el resto. -->
                    <Col :xs="24">
                        <FormItem :label="$t('customers.logo')" :tooltip="$t('customers.logo_hint')">
                            <div class="logo-row">
                                <div class="logo-preview">
                                    <img v-if="previewUrl" :src="previewUrl" alt="logo" />
                                    <UserOutlined v-else class="logo-preview__ph" />
                                </div>
                                <div class="logo-controls">
                                    <input
                                        ref="fileInput"
                                        type="file"
                                        accept="image/jpeg,image/png,image/gif,image/svg+xml,image/webp"
                                        style="display: none"
                                        @change="(e) => onLogoChange(e.target.files[0])"
                                    />
                                    <Button @click="$refs.fileInput.click()">
                                        <UploadOutlined /> {{ previewUrl ? $t('customers.change_logo') : $t('customers.upload_logo') }}
                                    </Button>
                                    <div class="logo-hint">{{ $t('customers.logo_hint') }}</div>
                                    <div v-if="form.errors.logo" class="logo-error">{{ form.errors.logo }}</div>
                                </div>
                            </div>
                        </FormItem>
                    </Col>

                    <Col v-if="isEdit" :xs="24" :md="8">
                        <FormItem
                            :label="$t('customers.is_active')"
                            :tooltip="$t('customers.is_active_help')"
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
                    </Col>
                </Row>

                <!-- ════ Sección: Workspace ════ (solo super; tenantOptions vacío para los demás) -->
                <template v-if="tenantOptions.length">
                    <h2 class="form-section-title form-section-title--spaced">{{ $t('tenants.singular') }}</h2>
                    <FormItem
                        :label="$t('tenants.singular')"
                        :tooltip="$t('tenants.assign_hint')"
                        :validate-status="form.errors.tenant_id ? 'error' : ''"
                        :help="form.errors.tenant_id ?? $t('tenants.assign_hint')"
                    >
                        <Select
                            v-model:value="form.tenant_id"
                            :options="tenantOptions"
                            :placeholder="$t('tenants.select_placeholder')"
                            size="large"
                            allow-clear
                            show-search
                            option-filter-prop="label"
                        />
                    </FormItem>
                </template>

                <FormFooter
                    :cancel-href="route('business_management.customers.index')"
                    :is-edit="isEdit"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </Card>
    </div>
</template>

<style scoped>
.form-card { border-radius: 6px; }
.state-label {
    font-size: 0.875rem;
    color: var(--color-text);
    font-weight: 500;
}
.mb-4 { margin-bottom: 16px; }

/* Logo */
.logo-row { display: flex; align-items: center; gap: 18px; margin-bottom: 20px; flex-wrap: wrap; }
.logo-controls { min-width: 0; }
.logo-preview {
    width: 76px; height: 76px; border-radius: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; overflow: hidden;
    background: var(--color-surface-alt, #f3f5f7); border: 1px solid var(--color-border, #e5e7eb);
}
.logo-preview img { width: 100%; height: 100%; object-fit: cover; }
.logo-preview__ph { font-size: 1.8rem; color: var(--color-text-muted, #9aa0a6); }
.logo-controls { display: flex; flex-direction: column; gap: 6px; }
.logo-hint { font-size: 0.78rem; color: var(--color-text-muted, #6A6D70); }
.logo-error { font-size: 0.8rem; color: #C8281D; }
</style>
