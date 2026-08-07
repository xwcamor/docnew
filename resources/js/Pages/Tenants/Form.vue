<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Textarea, InputPassword, Switch, Button, Space, Alert, Avatar,
    Select, SelectOption, Tag, Divider, Row, Col,
} from 'ant-design-vue';
import {
    BankOutlined, UploadOutlined,
    UserOutlined, MailOutlined, LockOutlined, TeamOutlined,
} from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

const props = defineProps({
    tenant: { type: Object, default: null },
    // Planes disponibles — viene del controller (Plan::publicOptions()), 100%
    // DB-driven. Cualquier plan que cree el super aparece automaticamente.
    planOptions: { type: Array, default: () => [] },
});

const isEdit = computed(() => !!props.tenant);

const page = usePage();

const form = useForm({
    // Workspace
    name:      props.tenant?.name      ?? '',
    is_active: props.tenant?.is_active ?? true,
    plan:      props.tenant?.plan      ?? (props.planOptions[0]?.value ?? 'free'),
    timezone:  props.tenant?.timezone  ?? '',
    logo:      null,
    // Membrete de los informes PDF (dirección + disclaimer legal).
    address:           props.tenant?.address           ?? '',
    report_disclaimer: props.tenant?.report_disclaimer ?? '',
    report_approver:   props.tenant?.report_approver   ?? '',
    // Admin del workspace (solo en create)
    admin_name:     '',
    admin_email:    '',
    admin_password: '',
    _method:   isEdit.value ? 'put' : 'post',
});

// Lista completa de timezones para el selector (compartida por Inertia).
const availableTimezones = computed(() => page.props.tz?.available ?? []);

// Planes — derivados del prop. value/label/color/tagline vienen de DB.
const PLANS = computed(() => props.planOptions);

const currentPlanHint = computed(() =>
    PLANS.value.find(p => p.value === form.plan)?.tagline ?? ''
);

// Uso `logo_url` del backend (incluye cache-busting). El blob URL local solo
// para preview antes de submit; en edicion del registro existente, el server
// devuelve un URL nuevo cada vez que `updated_at` cambia.
const previewUrl = ref(props.tenant?.logo_url ?? null);
const onLogoChange = (file) => {
    form.logo = file;
    previewUrl.value = file
        ? URL.createObjectURL(file)
        : (props.tenant?.logo_url ?? null);
};

const submit = () => {
    if (isEdit.value) {
        form.post(route('system_management.tenants.update', props.tenant.slug), {
            forceFormData: true,
        });
    } else {
        form.post(route('system_management.tenants.store'), {
            forceFormData: true,
        });
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('tenants.singular') : $t('tenants.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('system_management.tenants.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('tenants.record') : $t('tenants.new')"
            :subtitle="isEdit ? tenant.name : $t('tenants.form_create_hint')"
        >
            <template #icon><BankOutlined /></template>
        </SectionHeader>

        <Form layout="horizontal" :label-col="{ xs: 24, sm: 8, md: 6 }" :wrapper-col="{ xs: 24, sm: 16, md: 13 }" label-align="right" :colon="true" @submit.prevent="submit">

            <Alert
                v-if="form.hasErrors && Object.keys(form.errors).length > 0"
                type="error"
                show-icon
                :message="$t('global.fix_marked_fields')"
                class="mb-4"
            />
            <Row :gutter="[20, 20]">
                <!-- COL IZQ: datos del workspace -->
                <Col :xs="24" :lg="!isEdit ? 14 : 24">
                    <Card class="form-card" :bodyStyle="{ padding: '24px 28px' }">
                        <h3 class="section-title">
                            <BankOutlined /> {{ $t('tenants.singular') }}
                        </h3>

                        <!-- Logo -->
                        <div class="logo-section">
                            <Avatar :src="previewUrl" :size="96" shape="square">
                                <template v-if="!previewUrl" #icon><BankOutlined /></template>
                            </Avatar>
                            <div class="logo-controls">
                                <input
                                    ref="fileInput"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,image/jpg"
                                    style="display: none"
                                    @change="(e) => onLogoChange(e.target.files[0])"
                                />
                                <Button @click="$refs.fileInput.click()">
                                    <UploadOutlined /> {{ previewUrl ? $t('tenants.form_change_logo') : $t('tenants.form_upload_logo') }}
                                </Button>
                                <Button v-if="form.logo" @click="onLogoChange(null)" type="text" danger>
                                    {{ $t('global.remove') }}
                                </Button>
                                <p class="logo-hint">{{ $t('tenants.form_logo_hint') }}</p>
                            </div>
                        </div>
                        <div v-if="form.errors.logo" class="field-error">{{ form.errors.logo }}</div>

                        <FormItem
                            :label="$t('tenants.form_name_label')"
                            :tooltip="$t('tenants.form_name_help')"
                            required
                            :validate-status="form.errors.name ? 'error' : ''"
                            :help="form.errors.name"
                        >
                            <Input
                                v-model:value="form.name"
                                :placeholder="$t('tenants.form_name_placeholder')"
                                size="large"
                                :maxlength="255"
                                showCount
                                autofocus
                            />
                        </FormItem>

                        <!-- Membrete de los informes PDF: dirección + disclaimer legal. -->
                        <FormItem
                            :label="$t('tenants.form_address_label')"
                            :tooltip="$t('tenants.form_address_help')"
                            :validate-status="form.errors.address ? 'error' : ''"
                            :help="form.errors.address"
                        >
                            <Input v-model:value="form.address" :maxlength="255" showCount />
                        </FormItem>
                        <FormItem
                            :label="$t('tenants.form_disclaimer_label')"
                            :tooltip="$t('tenants.form_disclaimer_help')"
                            :validate-status="form.errors.report_disclaimer ? 'error' : ''"
                            :help="form.errors.report_disclaimer"
                        >
                            <Textarea v-model:value="form.report_disclaimer" :rows="4" :maxlength="2000" showCount />
                        </FormItem>
                        <FormItem
                            :label="$t('tenants.form_approver_label')"
                            :tooltip="$t('tenants.form_approver_help')"
                            :validate-status="form.errors.report_approver ? 'error' : ''"
                            :help="form.errors.report_approver"
                        >
                            <Input v-model:value="form.report_approver" :maxlength="120" showCount />
                        </FormItem>

                        <Row :gutter="[20, 0]">
                            <!-- Plan: SOLO en create. Al crear con un plan pago se
                                 arranca un trial automático. En edición el plan NO
                                 se toca aquí — se gestiona en el tab Suscripción. -->
                            <Col v-if="!isEdit" :xs="24" :md="24">
                                <FormItem
                                    :label="$t('tenants.plan')"
                                    :tooltip="$t('tenants.plan_help')"
                                    :validate-status="form.errors.plan ? 'error' : ''"
                                    :help="form.errors.plan || currentPlanHint"
                                >
                                    <Select v-model:value="form.plan" size="large" :placeholder="$t('tenants.plan_placeholder')">
                                        <SelectOption v-for="p in PLANS" :key="p.value" :value="p.value">
                                            <Tag :color="p.color" :bordered="false" style="margin-right: 6px;">{{ p.label }}</Tag>
                                            <span class="plan-hint">{{ p.tagline }}</span>
                                        </SelectOption>
                                    </Select>
                                </FormItem>
                            </Col>

                            <Col v-if="isEdit" :xs="24" :md="24">
                                <FormItem
                                    :label="$t('tenants.form_status_label')"
                                    :tooltip="$t('tenants.form_status_help')"
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

                            <!-- Zona horaria del workspace — todos los users del
                                 tenant que no tengan TZ propio heredan de aquí. -->
                            <Col :xs="24" :md="24">
                                <FormItem
                                    :label="$t('tenants.timezone')"
                                    :tooltip="$t('tenants.timezone_help')"
                                    :validate-status="form.errors.timezone ? 'error' : ''"
                                    :help="form.errors.timezone || $t('tenants.timezone_hint')"
                                >
                                    <Select
                                        v-model:value="form.timezone"
                                        size="large"
                                        show-search
                                        option-filter-prop="children"
                                        :placeholder="$t('tenants.timezone')"
                                        allow-clear
                                    >
                                        <SelectOption v-for="tz in availableTimezones" :key="tz" :value="tz">
                                            {{ tz }}
                                        </SelectOption>
                                    </Select>
                                </FormItem>
                            </Col>
                        </Row>
                    </Card>
                </Col>

                <!-- COL DER: Admin del workspace (solo en create) -->
                <Col v-if="!isEdit" :xs="24" :lg="10">
                    <Card class="form-card" :bodyStyle="{ padding: '24px 28px' }">
                        <h3 class="section-title">
                            <TeamOutlined /> {{ $t('tenants.admin_section_title') }}
                        </h3>
                        <p class="admin-hint">{{ $t('tenants.admin_section_hint') }}</p>

                        <FormItem
                            :label="$t('tenants.admin_name')"
                            :tooltip="$t('tenants.admin_name_help')"
                            required
                            :validate-status="form.errors.admin_name ? 'error' : ''"
                            :help="form.errors.admin_name"
                        >
                            <Input v-model:value="form.admin_name" :placeholder="$t('tenants.admin_name_placeholder')" size="large" :maxlength="255">
                                <template #prefix><UserOutlined /></template>
                            </Input>
                        </FormItem>

                        <FormItem
                            :label="$t('tenants.admin_email')"
                            :tooltip="$t('tenants.admin_email_help')"
                            required
                            :validate-status="form.errors.admin_email ? 'error' : ''"
                            :help="form.errors.admin_email"
                        >
                            <Input v-model:value="form.admin_email" :placeholder="$t('tenants.admin_email_placeholder')" type="email" size="large">
                                <template #prefix><MailOutlined /></template>
                            </Input>
                        </FormItem>

                        <FormItem
                            :label="$t('tenants.admin_password')"
                            :tooltip="$t('tenants.admin_password_help')"
                            required
                            :validate-status="form.errors.admin_password ? 'error' : ''"
                            :help="form.errors.admin_password || $t('tenants.admin_password_hint')"
                        >
                            <InputPassword v-model:value="form.admin_password" size="large" :maxlength="255" :placeholder="$t('tenants.admin_password_placeholder')">
                                <template #prefix><LockOutlined /></template>
                            </InputPassword>
                        </FormItem>
                    </Card>
                </Col>
            </Row>

            <FormFooter
                :cancel-href="route('system_management.tenants.index')"
                :is-edit="isEdit"
                :processing="form.processing"
                    floating
            />
        </Form>
    </div>
</template>

<style scoped>
.form-page { width: 100%; max-width: none; }
.section-title {
    display: flex; align-items: center; gap: 8px;
    font-size: 0.9375rem; font-weight: 600; color: var(--color-text-strong);
    margin: 0 0 16px 0; padding-bottom: 12px;
    border-bottom: 1px solid var(--color-border-soft);
}
.form-card { border-radius: 6px; height: 100%; }

.logo-section {
    display: flex; align-items: center; gap: 18px;
    margin-bottom: 24px; padding-bottom: 18px;
    border-bottom: 1px solid #E5E5E5;
}
.logo-controls { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
.logo-hint { font-size: 0.75rem; color: #6A6D70; margin: 0; }
.field-error { color: #dc2626; font-size: 0.8rem; font-weight: 500; margin-bottom: 12px; }

.state-label { font-size: 0.875rem; color: #32363A; font-weight: 500; }

.mb-4 { margin-bottom: 16px; }

.admin-section { margin-top: 8px; }
.admin-hint { font-size: 0.8125rem; color: #6A6D70; margin: -8px 0 16px 0; line-height: 1.4; }
html[data-theme="dark"] .admin-hint { color: #a8aaae; }

@media (max-width: 768px) {
    .logo-section { flex-direction: column; align-items: flex-start; gap: 12px; }
}
</style>

<style>
html[data-theme="dark"] .logo-section { border-bottom-color: #3f4448; }
html[data-theme="dark"] .state-label  { color: #e5e6e7; }
</style>
