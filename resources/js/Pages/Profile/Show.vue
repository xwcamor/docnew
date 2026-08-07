<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import {
    Card, Tabs, TabPane, Input, Button, Avatar, Tag, Alert, Descriptions, DescriptionsItem,
    Form, FormItem, Select, SelectOption, notification, Modal, Switch,
    RadioGroup, RadioButton,
} from 'ant-design-vue';
import {
    UserOutlined, LockOutlined, SettingOutlined, MailOutlined,
    GlobalOutlined, BankOutlined, SafetyOutlined, EditOutlined, CameraOutlined, DownloadOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import axios from 'axios';

import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/Plugins/i18n';
import { useAuth } from '@/Composables/useAuth';

const { t } = useI18n();
const { isSuper } = useAuth();

defineOptions({ layout: AppLayout });

const props = defineProps({
    profile: { type: Object, required: true },
});

const page = usePage();

// ─── Tab activo (persistido en URL hash) ───────────────────────────────────
const activeTab = ref(window.location.hash?.replace('#', '') || 'info');
const onTabChange = (key) => {
    activeTab.value = key;
    window.history.replaceState(null, '', `#${key}`);
};

// ─── Info form ────────────────────────────────────────────────────────────
// El TZ propio del user puede ser null (heredar del workspace). El form
// arranca con el valor actual o '' (representa la opción "heredar").
const infoForm = useForm({
    name:     props.profile.name,
    timezone: props.profile.timezone ?? '',
});

const submitInfo = () => {
    infoForm.put(route('profile.update'), { preserveScroll: true });
};

// ─── Foto de perfil ────────────────────────────────────────────────────────
// Clic en el avatar → file picker → sube y refresca con cache-bust.
const photoInput = ref(null);
const photoVersion = ref(0);
const photoUrl = computed(() => {
    const base = page.props.auth?.user?.photo_url ?? props.profile.photo_url;
    if (!base) return null;
    return photoVersion.value ? base.split('?')[0] + '?v=' + photoVersion.value : base;
});
const onPhotoPicked = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    router.post(route('profile.photo.update'), { photo: file }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { photoVersion.value = Date.now(); },
        onFinish: () => { if (e.target) e.target.value = ''; },
    });
};

// ─── Firma manuscrita ──────────────────────────────────────────────────────
// Imagen privada (disk local, ruta autenticada): se estampa como "Preparado
// por" en los informes que este usuario genera. El ?v= invalida el cache del
// browser al subir una nueva.
const sigInput = ref(null);
const hasSignature = ref(!!props.profile.has_signature);
const sigVersion = ref(Date.now());
const sigUploading = ref(false);
const sigUrl = computed(() => (hasSignature.value ? route('profile.signature.show') + '?v=' + sigVersion.value : null));
const onSignaturePicked = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    sigUploading.value = true;
    router.post(route('profile.signature.update'), { signature: file }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { hasSignature.value = true; sigVersion.value = Date.now(); },
        onFinish: () => { sigUploading.value = false; if (e.target) e.target.value = ''; },
    });
};
const removeSignature = () => {
    router.delete(route('profile.signature.remove'), {
        preserveScroll: true,
        onSuccess: () => { hasSignature.value = false; },
    });
};

// ─── Auto-firma como aprobador (consentimiento propio, auditado) ──────────
const autoSign = ref(!!props.profile.auto_sign_reports);
const autoSignSaving = ref(false);
const onAutoSignChange = (checked) => {
    autoSignSaving.value = true;
    router.put(route('profile.autosign.update'), { auto_sign_reports: checked }, {
        preserveScroll: true,
        onSuccess: () => { autoSign.value = checked; },
        onError: () => { autoSign.value = !checked; },
        onFinish: () => { autoSignSaving.value = false; },
    });
};

// ─── Privacidad y datos (ARCO) ─────────────────────────────────────────────
const downloadMyData = () => { window.location.href = route('profile.data_export'); };
const confirmDeletion = () => {
    Modal.confirm({
        title: t('profile.request_deletion'),
        content: t('profile.request_deletion_confirm'),
        okText: t('profile.request_deletion_ok'),
        okType: 'danger',
        cancelText: t('global.cancel'),
        onOk: () => router.post(route('profile.deletion_request'), {}, { preserveScroll: true }),
    });
};

// ─── Dibujar firma (canvas) ────────────────────────────────────────────────
// Alternativa a subir imagen: dibujar con mouse/dedo. Pointer events cubren
// ambos. El canvas se dibuja a 2x (nítido en el PDF) y se sube como PNG por
// el MISMO endpoint que la imagen.
const drawOpen = ref(false);
const sigCanvas = ref(null);
const drew = ref(false);
let drawing = false;
let drawCtx = null;

const initCanvas = () => {
    const c = sigCanvas.value;
    if (!c) return;
    const rect = c.getBoundingClientRect();
    c.width = rect.width * 2;
    c.height = rect.height * 2;
    drawCtx = c.getContext('2d');
    drawCtx.scale(2, 2);
    drawCtx.lineWidth = 2.4;
    drawCtx.lineCap = 'round';
    drawCtx.lineJoin = 'round';
    drawCtx.strokeStyle = '#1f2937';
    drew.value = false;
};
const openDraw = () => {
    drawOpen.value = true;
    requestAnimationFrame(() => requestAnimationFrame(initCanvas));
};
const drawPos = (e) => {
    const rect = sigCanvas.value.getBoundingClientRect();
    return { x: e.clientX - rect.left, y: e.clientY - rect.top };
};
const drawStart = (e) => {
    drawing = true;
    const p = drawPos(e);
    drawCtx?.beginPath();
    drawCtx?.moveTo(p.x, p.y);
    e.target.setPointerCapture?.(e.pointerId);
};
const drawMove = (e) => {
    if (!drawing || !drawCtx) return;
    const p = drawPos(e);
    drawCtx.lineTo(p.x, p.y);
    drawCtx.stroke();
    drew.value = true;
};
const drawEnd = () => { drawing = false; };
const clearDraw = () => initCanvas();
const saveDraw = () => {
    if (!drew.value || !sigCanvas.value) return;
    sigUploading.value = true;
    sigCanvas.value.toBlob((blob) => {
        const file = new File([blob], 'firma.png', { type: 'image/png' });
        router.post(route('profile.signature.update'), { signature: file }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { hasSignature.value = true; sigVersion.value = Date.now(); drawOpen.value = false; },
            onFinish: () => { sigUploading.value = false; },
        });
    }, 'image/png');
};

// ─── Timezone options ─────────────────────────────────────────────────────
// Lista compartida por Inertia (page.props.tz.available). El componente
// agrega arriba la opción especial '' = "heredar del workspace" para que
// el user pueda volver atrás sin tener que mantener un toggle aparte.
const availableTimezones = computed(() => page.props.tz?.available ?? []);
const inheritedTzLabel = computed(() => {
    const workspaceTz = props.profile.tenant?.timezone;
    return workspaceTz
        ? `${t('global.tz_inherit_from_workspace')} (${workspaceTz})`
        : t('global.tz_inherit_from_workspace');
});

// ─── Password form ────────────────────────────────────────────────────────
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submitPassword = () => {
    passwordForm.put(route('profile.update_password'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
};

// ─── Apariencia (esquema de color + posición de menú, guardado en BD) ──────
// Al cambiar se guarda y se recargan los shared props → AppLayout aplica el
// esquema/menú nuevos (tiene watchers sobre auth.user). Cross-device.
const SCHEMES = [
    { value: 'sap',      label: 'SAP',             color: '#0A6ED1' },
    { value: 'slate',    label: 'Slate',           color: '#475569' },
    { value: 'emerald',  label: 'Emerald',         color: '#059669' },
    { value: 'indigo',   label: 'Indigo',          color: '#4f46e5' },
    { value: 'red',      label: 'Rojo',            color: '#B23A48' },
    { value: 'amber',    label: 'Ámbar',           color: '#B45309' },
    { value: 'teal',     label: 'Teal',            color: '#0E7490' },
    { value: 'contrast', label: t('profile.scheme_contrast'), color: '#1d4ed8' },
];
const uiScheme    = ref(page.props.auth?.user?.ui_scheme ?? 'sap');
const navPosition = ref(page.props.auth?.user?.nav_position ?? 'top');
const saveAppearance = () => {
    router.put(route('profile.preferences.update'),
        { ui_scheme: uiScheme.value, nav_position: navPosition.value },
        { preserveScroll: true, preserveState: true });
};

// ─── Preferencias: tours completados ───────────────────────────────────────
const tours = computed(() => page.props.auth?.user?.module_tours ?? {});
const tourCount = computed(() => Object.keys(tours.value).length);

const resetTours = async () => {
    // Borramos por entero las marcas de tour. Lo más rápido: pegarle al
    // mismo endpoint con una bandera, o exponer un DELETE. Como no tenemos
    // ese endpoint, usamos updateOrCreate desde el cliente vía
    // tour-complete con un flag — pero más limpio: hagamos un endpoint
    // dedicado. Por ahora, posteamos a un endpoint helper:
    try {
        await axios.delete(route('user_prefs.module_tours.complete'));
        notification.success({
            message: t('profile.reset_tours_done'),
            placement: 'topRight',
        });
        // Forzar recarga del shared prop auth para actualizar tourCount.
        router.reload({ only: ['auth'], preserveScroll: true });
    } catch (e) {
        notification.error({
            message: t('global.error'),
            placement: 'topRight',
        });
    }
};

const formatDate = (d) => d ? dayjs(d).format('YYYY-MM-DD') : '—';
</script>

<template>
    <Head :title="$t('profile.title')" />

    <div class="profile-page sap-show">
        <!-- Header con avatar + datos básicos -->
        <div class="profile-hero">
            <div class="profile-hero__avatar" :title="$t('profile.photo_change')" @click="photoInput?.click()">
                <Avatar
                    :src="photoUrl || undefined"
                    :size="72"
                    :style="{ background: '#0A6ED1', fontSize: '1.6rem' }"
                >
                    {{ profile.name?.charAt(0)?.toUpperCase() }}
                </Avatar>
                <span class="profile-hero__cam"><CameraOutlined /></span>
                <input ref="photoInput" type="file" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none" @change="onPhotoPicked">
            </div>
            <div class="profile-hero__info">
                <h1>{{ profile.name }}</h1>
                <p class="profile-hero__email">
                    <MailOutlined /> {{ profile.email }}
                </p>
                <div class="profile-hero__tags">
                    <Tag v-for="role in profile.roles" :key="role" color="blue" :bordered="false">
                        {{ role }}
                    </Tag>
                    <Tag :color="profile.is_active ? 'success' : 'default'" :bordered="false">
                        {{ profile.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <Card :bodyStyle="{ padding: '20px 24px' }" class="profile-card">
            <Tabs :activeKey="activeTab" @change="onTabChange">
                <!-- ── Tab 1: Información ─────────────────────────────── -->
                <TabPane key="info">
                    <template #tab>
                        <span><UserOutlined /> {{ $t('profile.tab_info') }}</span>
                    </template>

                    <Form layout="vertical" @submit.prevent="submitInfo" class="profile-form">
                        <FormItem
                            :label="$t('profile.name')"
                            :validate-status="infoForm.errors.name ? 'error' : ''"
                            :help="infoForm.errors.name"
                        >
                            <Input
                                v-model:value="infoForm.name"
                                size="large"
                                :prefix="undefined"
                            >
                                <template #prefix><UserOutlined /></template>
                            </Input>
                        </FormItem>

                        <FormItem :label="$t('profile.email')">
                            <Input :value="profile.email" disabled size="large">
                                <template #prefix><MailOutlined /></template>
                            </Input>
                            <small class="form-hint">{{ $t('profile.email_readonly_hint') }}</small>
                        </FormItem>

                        <FormItem
                            :label="$t('profile.timezone')"
                            :validate-status="infoForm.errors.timezone ? 'error' : ''"
                            :help="infoForm.errors.timezone || $t('profile.timezone_hint')"
                        >
                            <!-- Selector con búsqueda — la lista tiene ~400 timezones.
                                 La primera opción ('') deja al user heredar del workspace. -->
                            <Select
                                v-model:value="infoForm.timezone"
                                size="large"
                                show-search
                                option-filter-prop="children"
                                :placeholder="$t('profile.timezone')"
                            >
                                <SelectOption value="">{{ inheritedTzLabel }}</SelectOption>
                                <SelectOption v-for="tz in availableTimezones" :key="tz" :value="tz">
                                    {{ tz }}
                                </SelectOption>
                            </Select>
                        </FormItem>

                        <Descriptions :column="1" bordered size="small" class="profile-desc">
                            <DescriptionsItem v-if="profile.tenant && isSuper" :label="$t('profile.tenant')">
                                <BankOutlined /> {{ profile.tenant.name }}
                            </DescriptionsItem>
                            <DescriptionsItem v-if="profile.country" :label="$t('profile.country')">
                                <GlobalOutlined /> {{ profile.country.name }}
                            </DescriptionsItem>
                            <DescriptionsItem :label="$t('profile.member_since')">
                                {{ formatDate(profile.created_at) }}
                            </DescriptionsItem>
                        </Descriptions>

                        <div class="form-footer">
                            <Button
                                type="primary"
                                size="large"
                                html-type="submit"
                                :loading="infoForm.processing"
                                :disabled="!infoForm.isDirty"
                            >
                                {{ $t('profile.save_info') }}
                            </Button>
                        </div>
                    </Form>

                    <!-- ── Firma manuscrita (imagen privada, se estampa en los
                         informes que ESTE usuario genera como "Preparado por") ── -->
                    <div class="sig-block">
                        <h4 class="sig-title"><EditOutlined /> {{ $t('profile.signature_title') }}</h4>
                        <p class="form-hint">{{ $t('profile.signature_hint') }}</p>
                        <div v-if="sigUrl" class="sig-preview"><img :src="sigUrl" alt=""></div>
                        <input ref="sigInput" type="file" accept="image/png,image/jpeg,image/webp" style="display:none" @change="onSignaturePicked">
                        <Button type="primary" ghost @click="openDraw">
                            <EditOutlined /> {{ $t('profile.signature_draw') }}
                        </Button>
                        <Button :loading="sigUploading" style="margin-left:8px" @click="sigInput?.click()">
                            {{ hasSignature ? $t('profile.signature_change') : $t('profile.signature_upload') }}
                        </Button>
                        <Button v-if="hasSignature" danger style="margin-left:8px" @click="removeSignature">
                            {{ $t('profile.signature_remove') }}
                        </Button>

                        <!-- Consentimiento de auto-firma como aprobador: solo lo
                             activa el PROPIO usuario (acto auditado, revocable). -->
                        <div class="sig-autosign">
                            <Switch :checked="autoSign" :loading="autoSignSaving" :disabled="!hasSignature"
                                    @change="onAutoSignChange" />
                            <div>
                                <b>{{ $t('profile.autosign_label') }}</b>
                                <p class="form-hint" style="margin:2px 0 0">
                                    {{ hasSignature ? $t('profile.autosign_hint') : $t('profile.autosign_needs_signature') }}
                                </p>
                            </div>
                        </div>

                        <!-- Modal de dibujo: mouse o dedo (pointer events) -->
                        <Modal v-model:open="drawOpen" :title="$t('profile.signature_draw')" :footer="null" width="560px">
                            <p class="form-hint">{{ $t('profile.signature_draw_hint') }}</p>
                            <canvas
                                ref="sigCanvas"
                                class="sig-canvas"
                                @pointerdown.prevent="drawStart"
                                @pointermove.prevent="drawMove"
                                @pointerup="drawEnd"
                                @pointerleave="drawEnd"
                            ></canvas>
                            <div class="sig-canvas__actions">
                                <Button @click="clearDraw">{{ $t('profile.signature_draw_clear') }}</Button>
                                <Button type="primary" :disabled="!drew" :loading="sigUploading" @click="saveDraw">
                                    {{ $t('profile.signature_draw_save') }}
                                </Button>
                            </div>
                        </Modal>
                    </div>
                </TabPane>

                <!-- ── Tab 2: Contraseña ──────────────────────────────── -->
                <TabPane key="password">
                    <template #tab>
                        <span><LockOutlined /> {{ $t('profile.tab_password') }}</span>
                    </template>

                    <div class="password-section">
                        <h3 class="section-title">{{ $t('profile.password_title') }}</h3>
                        <p class="section-subtitle">{{ $t('profile.password_subtitle') }}</p>

                        <Alert
                            v-if="!profile.has_password"
                            type="info"
                            show-icon
                            :message="$t('profile.no_password_hint')"
                            class="mb-3"
                        />

                        <Form layout="vertical" @submit.prevent="submitPassword" class="profile-form">
                            <FormItem
                                v-if="profile.has_password"
                                :label="$t('profile.current_password')"
                                :validate-status="passwordForm.errors.current_password ? 'error' : ''"
                                :help="passwordForm.errors.current_password"
                            >
                                <Input.Password
                                    v-model:value="passwordForm.current_password"
                                    size="large"
                                    autocomplete="current-password"
                                >
                                    <template #prefix><LockOutlined /></template>
                                </Input.Password>
                            </FormItem>

                            <FormItem
                                :label="$t('profile.new_password')"
                                :validate-status="passwordForm.errors.password ? 'error' : ''"
                                :help="passwordForm.errors.password"
                            >
                                <Input.Password
                                    v-model:value="passwordForm.password"
                                    size="large"
                                    autocomplete="new-password"
                                >
                                    <template #prefix><LockOutlined /></template>
                                </Input.Password>
                            </FormItem>

                            <FormItem
                                :label="$t('profile.confirm_password')"
                            >
                                <Input.Password
                                    v-model:value="passwordForm.password_confirmation"
                                    size="large"
                                    autocomplete="new-password"
                                >
                                    <template #prefix><LockOutlined /></template>
                                </Input.Password>
                            </FormItem>

                            <div class="form-footer">
                                <Button
                                    type="primary"
                                    size="large"
                                    html-type="submit"
                                    :loading="passwordForm.processing"
                                    :disabled="!passwordForm.password || !passwordForm.password_confirmation"
                                >
                                    <SafetyOutlined /> {{ $t('profile.change_password') }}
                                </Button>
                            </div>
                        </Form>
                    </div>
                </TabPane>

                <!-- ── Tab 3: Preferencias ────────────────────────────── -->
                <TabPane key="preferences">
                    <template #tab>
                        <span><SettingOutlined /> {{ $t('profile.tab_preferences') }}</span>
                    </template>

                    <!-- ── Apariencia (esquema de color + posición de menú) ── -->
                    <div class="prefs-section">
                        <h3 class="section-title">{{ $t('profile.appearance_title') }}</h3>
                        <p class="section-subtitle">{{ $t('profile.appearance_hint') }}</p>

                        <div class="appearance-row">
                            <label class="appearance-label">{{ $t('profile.color_scheme') }}</label>
                            <RadioGroup v-model:value="uiScheme" button-style="solid" @change="saveAppearance">
                                <RadioButton v-for="s in SCHEMES" :key="s.value" :value="s.value">
                                    <span class="scheme-dot" :style="{ background: s.color }"></span>{{ s.label }}
                                </RadioButton>
                            </RadioGroup>
                        </div>

                        <div class="appearance-row">
                            <label class="appearance-label">{{ $t('profile.menu_position') }}</label>
                            <RadioGroup v-model:value="navPosition" button-style="solid" @change="saveAppearance">
                                <RadioButton value="side">{{ $t('profile.menu_side') }}</RadioButton>
                                <RadioButton value="top">{{ $t('profile.menu_top') }}</RadioButton>
                            </RadioGroup>
                            <p class="form-hint" style="margin-top:6px">{{ $t('profile.menu_position_hint') }}</p>
                        </div>
                    </div>

                    <div class="prefs-section" style="margin-top: 26px;">
                        <h3 class="section-title">{{ $t('profile.preferences_title') }}</h3>
                        <p class="section-subtitle">{{ $t('profile.preferences_hint') }}</p>

                        <Descriptions :column="1" bordered size="small" class="profile-desc">
                            <DescriptionsItem :label="$t('profile.tour_status')">
                                {{ tourCount }} {{ tourCount === 1 ? $t('global.tour_show_again').toLowerCase() : 'tours' }}
                                <Button
                                    v-if="tourCount > 0"
                                    type="link"
                                    size="small"
                                    @click="resetTours"
                                >
                                    {{ $t('profile.reset_tours') }}
                                </Button>
                            </DescriptionsItem>
                        </Descriptions>
                    </div>

                    <!-- ── Privacidad y datos (derechos ARCO — LPDP) ── -->
                    <div class="prefs-section" style="margin-top: 26px;">
                        <h3 class="section-title">{{ $t('profile.privacy_title') }}</h3>
                        <p class="section-subtitle">{{ $t('profile.privacy_hint') }}</p>

                        <div class="privacy-actions">
                            <Button @click="downloadMyData">
                                <DownloadOutlined /> {{ $t('profile.download_my_data') }}
                            </Button>
                            <Button danger @click="confirmDeletion">
                                {{ $t('profile.request_deletion') }}
                            </Button>
                        </div>
                        <p class="form-hint" style="margin-top: 10px;">{{ $t('profile.privacy_rights_note') }}</p>
                    </div>
                </TabPane>
            </Tabs>
        </Card>
    </div>
</template>

<style scoped>
.profile-page { /* fullscreen — sin max-width, ocupa todo el ancho disponible del content */ }

/* Hero a BORDE COMPLETO (franja cuadrada, como el resto de fichas): bleed
   sobre el padding de .sap-show y sin esquinas redondeadas. */
.profile-hero {
    display: flex;
    align-items: center;
    gap: 18px;
    margin: -24px -24px 20px;
    padding: 18px 24px;
    background: linear-gradient(135deg, #0A6ED1 0%, #064C92 100%);
    color: #fff;
    border-radius: 0;
    box-shadow: none;
}
.profile-hero__info { flex: 1; min-width: 0; }
.profile-hero h1 {
    color: #fff;
    font-size: 1.4rem;
    font-weight: 600;
    margin: 0 0 4px 0;
    letter-spacing: -0.01em;
}
.profile-hero__email {
    color: rgba(255, 255, 255, 0.85);
    font-size: 0.875rem;
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    gap: 6px;
}
.profile-hero__tags {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}
.profile-hero__tags :deep(.ant-tag) {
    background: rgba(255, 255, 255, 0.15);
    border: 0;
    color: #fff;
}

.profile-card { border-radius: 8px; }

.profile-form { /* sin max-width — los FormItem usan Row/Col internos si necesitan limitar */ }

.form-hint {
    color: #94a3b8;
    font-size: 0.75rem;
    margin-top: 4px;
    display: block;
}

.form-footer {
    margin-top: 24px;
    padding-top: 16px;
    border-top: 1px solid #f0f0f0;
}

.profile-desc { margin-top: 16px; }

.password-section .section-title,
.prefs-section .section-title {
    font-size: 1rem;
    font-weight: 600;
    color: #1f2937;
    margin: 0 0 4px 0;
}
.password-section .section-subtitle,
.prefs-section .section-subtitle {
    color: #6A6D70;
    font-size: 0.8125rem;
    margin: 0 0 16px 0;
}

.mb-3 { margin-bottom: 12px; }

/* Avatar editable */
.profile-hero__avatar { position: relative; cursor: pointer; flex-shrink: 0; }
.profile-hero__cam { position: absolute; right: -2px; bottom: -2px; width: 26px; height: 26px; border-radius: 50%; background: #0A6ED1; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; border: 2px solid #fff; }
.profile-hero__avatar:hover .profile-hero__cam { background: #085CAF; }

/* Firma manuscrita */
.sig-block { margin-top: 28px; padding-top: 18px; border-top: 1px solid var(--color-border, #e5e7eb); }
.sig-title { font-size: 0.95rem; font-weight: 700; margin: 0 0 4px; display: flex; align-items: center; gap: 7px; }
.sig-preview { border: 1px dashed var(--color-border, #d0d5da); border-radius: 8px; padding: 10px 16px; display: inline-block; margin: 6px 0 12px; background: #fff; }
.sig-preview img { max-height: 64px; max-width: 240px; display: block; }
.sig-canvas { width: 100%; height: 180px; border: 1px dashed var(--color-border, #d0d5da); border-radius: 8px; background: #fff; touch-action: none; cursor: crosshair; display: block; }
.sig-canvas__actions { display: flex; justify-content: space-between; margin-top: 12px; }
.privacy-actions { display: flex; flex-wrap: wrap; gap: 10px; }
.sig-autosign { display: flex; align-items: flex-start; gap: 12px; margin-top: 18px; padding: 12px 14px; border: 1px solid var(--color-border, #e5e7eb); border-radius: 8px; background: var(--color-surface-alt, #fafbfc); }

@media (max-width: 768px) {
    /* En móvil el padding de .sap-show es 16 → el bleed también. */
    .profile-hero {
        margin: -16px -16px 16px;
        flex-direction: column;
        text-align: center;
    }
    .profile-hero__tags { justify-content: center; }
}

/* Apariencia */
.appearance-row { margin: 14px 0; }
.appearance-label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--color-text-strong, #334155);
    margin-bottom: 8px;
}
.scheme-dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-right: 7px;
    vertical-align: middle;
    border: 1px solid rgba(0, 0, 0, 0.12);
}
.appearance-row :deep(.ant-radio-button-wrapper) { margin-bottom: 6px; }
</style>

<style>
html[data-theme="dark"] /* Hero a BORDE COMPLETO (franja cuadrada, como el resto de fichas): bleed
   sobre el padding de .sap-show y sin esquinas redondeadas. */
.profile-hero {
    margin: -24px -24px 20px;
    border-radius: 0;
    background: linear-gradient(135deg, #354A5F 0%, #1a2530 100%);
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3);
}
html[data-theme="dark"] .form-footer { border-top-color: #3f4448; }
html[data-theme="dark"] .password-section .section-title,
html[data-theme="dark"] .prefs-section .section-title { color: #e5e6e7; }
html[data-theme="dark"] .password-section .section-subtitle,
html[data-theme="dark"] .prefs-section .section-subtitle { color: #a8aaae; }
</style>
