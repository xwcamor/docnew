<script setup>
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Switch, Button, Space, Alert, Avatar, Upload, Select,
} from 'ant-design-vue';
import {
    UserOutlined, MailOutlined, LockOutlined,
    EyeOutlined, EyeInvisibleOutlined, UploadOutlined, TeamOutlined,
    GlobalOutlined, ReloadOutlined,
} from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

const props = defineProps({
    user:           { type: Object, default: null },
    roleOptions:    { type: Array,  default: () => [] },
    tenantOptions:  { type: Array,  default: () => [] },
    countryOptions: { type: Array,  default: () => [] },
    localeOptions:  { type: Array,  default: () => [] },
    // Restricción por cliente (feature enterprise): multiselect de clientes
    // visibles. Vacío = el usuario ve todo su workspace.
    canScopeCustomers: { type: Boolean, default: false },
    customerOptions:   { type: Array,  default: () => [] },
});

const isEdit = computed(() => !!props.user);

// useForm — Inertia handles multipart/form-data automatically when File present.
const form = useForm({
    name:       props.user?.name       ?? '',
    email:      props.user?.email      ?? '',
    password:   '',
    is_active:  props.user?.is_active  ?? true,
    role_id:    props.user?.role_id    ?? null,
    tenant_id:  props.user?.tenant_id  ?? null,
    country_id: props.user?.country_id ?? null,
    locale_id:  props.user?.locale_id  ?? null,
    assigned_customer_ids: props.user?.assigned_customer_ids ?? [],
    // Flag escalar (sobrevive a FormData): marca que la lista de clientes vino en
    // el submit. Sin esto, vaciar la lista (array vacío) se pierde en FormData y
    // el backend no sincroniza → no se podían quitar todos los clientes.
    assigned_customers_touched: 1,
    photo:      null,
    _method:    isEdit.value ? 'put' : 'post',
});

// Photo preview (from upload OR existing photo_url).
const previewUrl = ref(props.user?.photo_url ?? null);
const onPhotoChange = (file) => {
    form.photo = file;
    previewUrl.value = file ? URL.createObjectURL(file) : (props.user?.photo_url ?? null);
};

// Descripción del perfil elegido — se muestra debajo del select (en el label
// del option no se alcanzaba a leer).
const selectedRoleDescription = computed(() => {
    const opt = props.roleOptions.find((o) => o.value === form.role_id);
    return opt?.description ?? '';
});

const showPassword = ref(false);

// Genera una contraseña segura (sin caracteres ambiguos) y la muestra para
// que el admin la copie. Usa crypto cuando está disponible.
const generatePassword = () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$%&*';
    const n = 14;
    let p = '';
    const cryptoObj = window.crypto || window.msCrypto;
    if (cryptoObj?.getRandomValues) {
        const arr = new Uint32Array(n);
        cryptoObj.getRandomValues(arr);
        for (let i = 0; i < n; i++) p += chars[arr[i] % chars.length];
    } else {
        for (let i = 0; i < n; i++) p += chars[Math.floor(Math.random() * chars.length)];
    }
    form.password = p;
    showPassword.value = true;
};

const submit = () => {
    if (isEdit.value) {
        // Inertia uses POST + _method=put for multipart edits with files.
        form.post(route('user_management.users.update', props.user.slug), {
            forceFormData: true,
        });
    } else {
        form.post(route('user_management.users.store'), {
            forceFormData: true,
        });
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('users.singular') : $t('users.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('user_management.users.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('users.record') : $t('users.new')"
            :subtitle="isEdit ? user.name : $t('users.create_subtitle')"
        >
            <template #icon><UserOutlined /></template>
        </SectionHeader>

        <Card class="form-card" :bodyStyle="{ padding: '24px 28px' }">
            <Form layout="horizontal" :label-col="{ xs: 24, sm: 8, md: 6 }" :wrapper-col="{ xs: 24, sm: 16, md: 13 }" label-align="right" :colon="true" @submit.prevent="submit">

                <Alert
                    v-if="form.hasErrors && Object.keys(form.errors).length > 0"
                    type="error"
                    show-icon
                    :message="$t('global.form_has_errors')"
                    class="mb-4"
                />

                <!-- ════ Sección: Datos de la cuenta ════ -->
                <section class="form-section">
                    <h2 class="form-section-title">{{ $t('users.section_account') }}</h2>
                    <!-- Nombre -->
                    <FormItem
                        :label="$t('users.name')"
                        :tooltip="$t('users.name_help')"
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
                            :placeholder="$t('users.name_placeholder')"
                        >
                            <template #prefix><UserOutlined /></template>
                        </Input>
                    </FormItem>

                    <!-- Email -->
                    <FormItem
                        :label="$t('users.email')"
                        :tooltip="$t('users.email_help')"
                        required
                        :validate-status="form.errors.email ? 'error' : ''"
                        :help="form.errors.email"
                    >
                        <Input
                            v-model:value="form.email"
                            placeholder="user@example.com"
                            size="large"
                            type="email"
                        >
                            <template #prefix><MailOutlined /></template>
                        </Input>
                    </FormItem>

                    <!-- Password + generar -->
                    <FormItem
                        :label="isEdit ? $t('users.password') + ' (opcional)' : $t('users.password')"
                        :tooltip="$t('users.password_help')"
                        :required="!isEdit"
                        :validate-status="form.errors.password ? 'error' : ''"
                        :help="form.errors.password || (isEdit ? $t('global.leave_blank_to_keep') : $t('global.min_chars', { n: 6 }))"
                    >
                        <div class="pass-row">
                            <Input
                                v-model:value="form.password"
                                :placeholder="isEdit ? '••••••••' : $t('global.min_chars', { n: 6 })"
                                size="large"
                                :type="showPassword ? 'text' : 'password'"
                            >
                                <template #prefix><LockOutlined /></template>
                                <template #suffix>
                                    <button
                                        type="button"
                                        class="pass-toggle"
                                        @click="showPassword = !showPassword"
                                    >
                                        <EyeOutlined v-if="!showPassword" />
                                        <EyeInvisibleOutlined v-else />
                                    </button>
                                </template>
                            </Input>
                            <Button block size="large" @click="generatePassword" :title="$t('users.generate_password_hint')">
                                <ReloadOutlined /> {{ $t('users.generate_password') }}
                            </Button>
                        </div>
                    </FormItem>

                    <!-- Foto: debajo de la contraseña, alineada como el resto. -->
                    <FormItem :label="$t('users.photo')" :tooltip="$t('global.photo_hint')">
                        <div class="photo-section">
                            <div class="photo-preview">
                                <img v-if="previewUrl" :src="previewUrl" alt="foto" />
                                <UserOutlined v-else class="photo-preview__ph" />
                            </div>
                            <div class="photo-controls">
                                <input
                                    ref="fileInput"
                                    type="file"
                                    accept="image/jpeg,image/png,image/gif,image/jpg"
                                    style="display: none"
                                    @change="(e) => onPhotoChange(e.target.files[0])"
                                />
                                <Button @click="$refs.fileInput.click()">
                                    <UploadOutlined /> {{ previewUrl ? $t('global.change_photo') : $t('global.upload_photo') }}
                                </Button>
                                <Button v-if="form.photo" @click="onPhotoChange(null)" type="text" danger>
                                    {{ $t('global.remove') }}
                                </Button>
                                <p class="photo-hint">{{ $t('global.photo_hint') }}</p>
                            </div>
                        </div>
                        <div v-if="form.errors.photo" class="field-error">{{ form.errors.photo }}</div>
                    </FormItem>
                </section>

                <!-- ════ Sección: Acceso y permisos ════ -->
                <section class="form-section">
                    <h2 class="form-section-title">{{ $t('users.section_access') }}</h2>
                    <!-- Perfil (Rol) -->
                    <FormItem
                        v-if="roleOptions.length"
                        :label="$t('users.role')"
                        :tooltip="$t('users.role_help')"
                        required
                        :validate-status="form.errors.role_id ? 'error' : ''"
                        :help="form.errors.role_id"
                    >
                        <Select
                            v-model:value="form.role_id"
                            :options="roleOptions"
                            :placeholder="$t('users.role')"
                            size="large"
                            allow-clear
                            option-filter-prop="label"
                            show-search
                        />
                        <p v-if="selectedRoleDescription" class="role-desc">
                            {{ selectedRoleDescription }}
                        </p>
                    </FormItem>

                </section>

                <!-- ════ Sección: Workspace ════ (solo super; tenantOptions vacío para los demás) -->
                <section v-if="tenantOptions.length" class="form-section">
                    <h2 class="form-section-title">{{ $t('tenants.singular') }}</h2>
                    <!-- No hay usuarios globales: el workspace es obligatorio. -->
                    <FormItem
                        :label="$t('users.tenant')"
                        :tooltip="$t('users.tenant_help')"
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
                </section>

                <!-- ════ Sección: Restricción de clientes ════ -->
                <!-- Restricción por cliente (enterprise): vacío = ve todo. -->
                <section v-if="canScopeCustomers && customerOptions.length" class="form-section">
                    <h2 class="form-section-title">{{ $t('users.section_customer_scope') }}</h2>
                    <FormItem
                        :label="$t('users.assigned_customers')"
                        :tooltip="$t('users.assigned_customers_help')"
                        :validate-status="form.errors.assigned_customer_ids ? 'error' : ''"
                        :help="form.errors.assigned_customer_ids"
                    >
                        <Select
                            v-model:value="form.assigned_customer_ids"
                            mode="multiple"
                            :options="customerOptions"
                            :placeholder="$t('users.assigned_customers_placeholder')"
                            size="large"
                            allow-clear
                            show-search
                            option-filter-prop="label"
                            :max-tag-count="3"
                        />
                    </FormItem>
                </section>

                <!-- ════ Sección: Regionalización ════ -->
                <section v-if="countryOptions.length || localeOptions.length" class="form-section">
                    <h2 class="form-section-title">{{ $t('users.section_region') }}</h2>
                    <!-- País -->
                    <FormItem
                        v-if="countryOptions.length"
                        :label="$t('countries.singular')"
                        :tooltip="$t('users.country_help')"
                        required
                        :validate-status="form.errors.country_id ? 'error' : ''"
                        :help="form.errors.country_id"
                    >
                        <Select
                            v-model:value="form.country_id"
                            :options="countryOptions"
                            :placeholder="$t('countries.singular')"
                            size="large"
                            allow-clear
                            show-search
                            :filter-option="(input, opt) => (opt.label ?? '').toLowerCase().includes(input.toLowerCase())"
                        />
                    </FormItem>

                    <!-- Idioma -->
                    <FormItem
                        v-if="localeOptions.length"
                        :label="$t('locales.singular')"
                        :tooltip="$t('users.locale_help')"
                        required
                        :validate-status="form.errors.locale_id ? 'error' : ''"
                        :help="form.errors.locale_id"
                    >
                        <Select
                            v-model:value="form.locale_id"
                            :options="localeOptions"
                            :placeholder="$t('locales.singular')"
                            size="large"
                            allow-clear
                        />
                    </FormItem>
                </section>

                <!-- Estado (solo edición) — siempre al final del formulario. -->
                <section v-if="isEdit" class="form-section">
                    <FormItem
                        :label="$t('users.is_active')"
                        :tooltip="$t('users.is_active_help')"
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
                </section>

                <!-- Footer -->
                <FormFooter
                    :cancel-href="route('user_management.users.index')"
                    :is-edit="isEdit"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </Card>
    </div>
</template>

<style scoped>
.form-page { /* fullscreen — sin max-width, ocupa todo el ancho del content */ }

.form-card { border-radius: 6px; }

/* Secciones agrupadas */
.form-section + .form-section { margin-top: 4px; }
.form-section__title {
    font-size: 0.95rem; font-weight: 700; color: #354A5F;
    margin: 0 0 16px; display: flex; align-items: center; gap: 8px;
}
.form-section__title :deep(.anticon) { color: #0A6ED1; }

/* Dos columnas para campos relacionados; una sola en mobile. minmax(0,1fr)
   permite que la columna se achique bajo el contenido (sin esto, un option
   largo del select estira la columna y se sale de la pantalla en mobile). */
.grid-2 { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 0 22px; }
@media (max-width: 768px) { .grid-2 { grid-template-columns: minmax(0, 1fr); } }

/* Selects e inputs ocupan el 100% y nunca desbordan; el valor largo se recorta. */
.form-page :deep(.ant-select) { width: 100%; }
.form-page :deep(.ant-select),
.form-page :deep(.ant-input),
.form-page :deep(.ant-input-affix-wrapper) { max-width: 100%; }
.form-page :deep(.ant-form-item-control-input-content) { min-width: 0; }
.form-page :deep(.ant-select-selection-item) { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Password + botón generar: en web van en la misma fila (input crece,
   botón al lado); en mobile el botón cae debajo a todo el ancho. */
.pass-row { display: flex; gap: 10px; align-items: stretch; }
.pass-row :deep(.ant-input-affix-wrapper) { flex: 1; min-width: 0; }
.pass-row :deep(.ant-btn) { flex: none; width: auto; }
@media (max-width: 768px) {
    .pass-row { flex-direction: column; }
    .pass-row :deep(.ant-btn) { width: 100%; }
}

/* Foto al mismo estilo que el logo del form de Clientes: cuadro redondeado
   con borde + botón/hint a la derecha. */
.photo-section { display: flex; align-items: center; gap: 18px; margin-bottom: 20px; }
.photo-preview {
    width: 76px; height: 76px; border-radius: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; overflow: hidden;
    background: var(--color-surface-alt, #f3f5f7); border: 1px solid var(--color-border, #e5e7eb);
}
.photo-preview img { width: 100%; height: 100%; object-fit: cover; }
.photo-preview__ph { font-size: 1.8rem; color: var(--color-text-muted, #9aa0a6); }
.photo-controls { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
.photo-hint { font-size: 0.75rem; color: #6A6D70; margin: 0; }
.field-error { color: #dc2626; font-size: 0.8rem; font-weight: 500; margin-bottom: 12px; }

.state-label { font-size: 0.875rem; color: #32363A; font-weight: 500; }

/* Descripción del perfil elegido, debajo del select. */
.role-desc {
    margin: 6px 0 0;
    font-size: 0.8rem;
    line-height: 1.35;
    color: var(--color-text-muted, #6A6D70);
}

.pass-toggle {
    background: transparent; border: 0; cursor: pointer; padding: 0;
    color: #6A6D70; display: flex; align-items: center;
}
.pass-toggle:hover { color: #0A6ED1; }

.mb-4 { margin-bottom: 16px; }

@media (max-width: 768px) {
    .photo-section { gap: 14px; }
    .photo-controls { min-width: 0; }
}
</style>

<style>
html[data-theme="dark"] .photo-section  { border-bottom-color: #3f4448; }
html[data-theme="dark"] .state-label    { color: #e5e6e7; }
html[data-theme="dark"] .form-section + .form-section { border-top-color: #3f4448; }
html[data-theme="dark"] .form-section__title { color: #cdd6df; }
</style>
