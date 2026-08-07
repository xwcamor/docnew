<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, useForm, Link, usePage } from '@inertiajs/vue3';
import { Input, Checkbox, Button, Alert, Select, SelectOption } from 'ant-design-vue';
import {
    MailOutlined, LockOutlined, EyeOutlined, EyeInvisibleOutlined,
    SafetyOutlined, GoogleOutlined,
    FileDoneOutlined, ScanOutlined, TeamOutlined, SafetyCertificateOutlined,
} from '@ant-design/icons-vue';

import AuthLayout from '@/Layouts/AuthLayout.vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AuthLayout });

const props = defineProps({
    appName: { type: String, default: '' },
    locale:  { type: String, default: 'es' },
    locales: { type: Object, default: () => ({}) },
});

// Shared props del middleware:
//   appName / appLogoUrl   → branding global (setting `app.name`, `app.logo_url`)
//   googleLoginEnabled     → feature flag para el boton Google
const page = usePage();
const googleLoginEnabled = computed(() => !!page.props.googleLoginEnabled);
const effectiveAppName   = computed(() => props.appName || page.props.appName || 'TR Health');
const effectiveAppLogo   = computed(() => page.props.appLogoUrl || null);

// ─── Form ──────────────────────────────────────────────────────────────────
const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);
const togglePassword = () => { showPassword.value = !showPassword.value; };

const submit = () => {
    form.post(route('login.post'), {
        onFinish: () => form.reset('password'),
    });
};

// Sin parallax: el hero tiene efectos eléctricos animados (CSS/SVG).

// ─── Ilustracion: verificacion facial y firma ──────────────────────────────
// La animacion es puramente CSS (ver estilos abajo). Aqui solo se respeta la
// preferencia del sistema de reducir movimiento.
const sinMovimiento = ref(false);

onMounted(() => {
    sinMovimiento.value = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
});


// ─── Locale switch ─────────────────────────────────────────────────────────
const onLocaleChange = (newLocale) => {
    if (props.locales?.[newLocale]) {
        window.location.href = props.locales[newLocale];
    }
};

// ─── Disclosure with embedded links ────────────────────────────────────────
// Construimos el HTML reemplazando :terms y :privacy en la traducción por
// anchors al estilo Laravel. Vue lo renderiza con v-html para que los links
// no se escapen.
const disclosureHtml = computed(() => {
    const tpl = t('auth.disclosure');
    // Las rutas legal_management.terms y .privacy son blade-rendered (no
    // Inertia) — usamos los URLs directos para que abran en pestaña nueva.
    const termsUrl   = route('legal_management.terms');
    const privacyUrl = route('legal_management.privacy');
    return tpl
        .replace(':terms',   `<a href="${termsUrl}" target="_blank" rel="noopener" class="underline">${t('auth.terms_short')}</a>`)
        .replace(':privacy', `<a href="${privacyUrl}" target="_blank" rel="noopener" class="underline">${t('auth.privacy_short')}</a>`);
});
</script>

<template>
    <Head :title="$t('auth.login')" />

    <div class="login-grid">
        <!-- LEFT: brand panel (desktop only) -->
        <aside class="login-brand">
            <div class="login-brand__bg" />
            <div class="login-brand__inner">
                <div class="login-brand__logo">
                    <img v-if="effectiveAppLogo" :src="effectiveAppLogo" :alt="effectiveAppName" class="login-brand__logo-img" />
                    <SafetyOutlined v-else />
                </div>
                <h2 class="login-brand__title">{{ effectiveAppName }}</h2>
                <p class="login-brand__tagline">{{ $t('auth.tagline') }}</p>

                <!-- Hero on-brand: transformador con efectos eléctricos animados. -->
                <div class="login-brand__hero">
                    <svg viewBox="0 0 280 200" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"
                         :class="{ 'sin-movimiento': sinMovimiento }">
                        <defs>
                            <linearGradient id="df-card" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0" stop-color="rgba(255,255,255,0.14)" />
                                <stop offset="1" stop-color="rgba(255,255,255,0.04)" />
                            </linearGradient>
                            <filter id="df-glow" x="-50%" y="-50%" width="200%" height="200%">
                                <feGaussianBlur stdDeviation="2.5" result="b" />
                                <feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge>
                            </filter>
                            <clipPath id="df-marco">
                                <rect x="86" y="24" width="108" height="104" rx="10" />
                            </clipPath>
                        </defs>

                        <!-- Tablet de obra -->
                        <rect x="70" y="10" width="140" height="180" rx="16"
                              fill="url(#df-card)" stroke="rgba(255,255,255,0.22)" stroke-width="1.2" />

                        <!-- Encuadre de reconocimiento -->
                        <g clip-path="url(#df-marco)">
                            <rect x="86" y="24" width="108" height="104" fill="rgba(10,110,209,0.10)" />

                            <!-- Rostro -->
                            <g fill="none" stroke="#cdeeff" stroke-width="2" stroke-linecap="round">
                                <circle cx="140" cy="66" r="22" />
                                <path d="M112,124 C114,102 126,94 140,94 C154,94 166,102 168,124" />
                                <path class="df-ojo" d="M131,62 h6" />
                                <path class="df-ojo df-ojo-b" d="M149,62 h6" />
                                <path d="M134,76 c4,3 8,3 12,0" stroke-opacity=".7" />
                            </g>

                            <!-- Malla de puntos: los 128 valores que se comparan -->
                            <g class="df-puntos" fill="#7fe0ff">
                                <circle cx="128" cy="52" r="1.6" /><circle cx="140" cy="48" r="1.6" />
                                <circle cx="152" cy="52" r="1.6" /><circle cx="124" cy="66" r="1.6" />
                                <circle cx="156" cy="66" r="1.6" /><circle cx="132" cy="78" r="1.6" />
                                <circle cx="148" cy="78" r="1.6" /><circle cx="140" cy="86" r="1.6" />
                                <circle cx="140" cy="66" r="1.6" />
                            </g>

                            <!-- Barrido -->
                            <rect class="df-barrido" x="86" y="24" width="108" height="3" fill="#7fe0ff"
                                  filter="url(#df-glow)" opacity=".85" />
                        </g>

                        <!-- Esquinas del encuadre -->
                        <g stroke="#7fe0ff" stroke-width="2.4" fill="none" stroke-linecap="round">
                            <path d="M92,42 v-10 h10" /><path d="M188,42 v-10 h-10" />
                            <path d="M92,110 v10 h10" /><path d="M188,110 v10 h-10" />
                        </g>

                        <!-- Firma trazandose sobre la linea -->
                        <line x1="94" y1="164" x2="186" y2="164" stroke="rgba(255,255,255,0.28)" stroke-width="1.5" />
                        <path class="df-firma" d="M100,160 c6,-14 12,-14 16,-2 c3,9 7,10 11,1 c4,-10 9,-9 13,2
                                                  c3,8 8,7 12,-3 c4,-9 10,-6 14,4 c3,6 8,6 12,-2"
                              fill="none" stroke="#ffffff" stroke-width="2.4"
                              stroke-linecap="round" stroke-linejoin="round" filter="url(#df-glow)" />

                        <!-- Sello de verificado -->
                        <g class="df-sello" transform="translate(196,138)">
                            <circle r="17" fill="rgba(29,112,68,0.92)" />
                            <path d="M-7,0 l5,5 l9,-10" fill="none" stroke="#fff" stroke-width="2.8"
                                  stroke-linecap="round" stroke-linejoin="round" />
                        </g>
                    </svg>
                </div>

                <ul class="login-brand__features">
                    <li><FileDoneOutlined /><span>{{ $t('auth.feature_tests') }}</span></li>
                    <li><ScanOutlined /><span>{{ $t('auth.feature_health') }}</span></li>
                    <li><TeamOutlined /><span>{{ $t('auth.feature_methods') }}</span></li>
                    <li><SafetyCertificateOutlined /><span>{{ $t('auth.feature_reports') }}</span></li>
                </ul>
            </div>
        </aside>

        <!-- RIGHT: form panel (full screen on mobile) -->
        <main class="login-main">
            <!-- Mobile header (hidden on desktop) -->
            <header class="login-mobile-header">
                <div class="login-mobile-header__logo">
                    <img v-if="effectiveAppLogo" :src="effectiveAppLogo" :alt="effectiveAppName" class="login-mobile-header__logo-img" />
                    <SafetyOutlined v-else />
                </div>
                <h2>{{ effectiveAppName }}</h2>
                <p>{{ $t('auth.tagline') }}</p>
            </header>

            <div class="login-form-wrap">
                <div class="login-form">
                    <div class="login-form__header">
                        <h1>{{ $t('auth.login') }}</h1>
                        <p>{{ $t('auth.signin_subtitle') }}</p>
                    </div>

                    <Alert
                        v-if="form.errors.email && !form.errors.password"
                        type="error"
                        :message="form.errors.email"
                        show-icon
                        class="mb-3"
                    />

                    <form @submit.prevent="submit" autocomplete="off">
                        <!-- Email -->
                        <label for="auth-email" class="field-label">{{ $t('auth.email') }}</label>
                        <Input
                            id="auth-email"
                            v-model:value="form.email"
                            size="large"
                            :placeholder="$t('auth.email_placeholder')"
                            type="email"
                            autocomplete="username"
                            :status="form.errors.email ? 'error' : ''"
                        >
                            <template #prefix><MailOutlined /></template>
                        </Input>
                        <div v-if="form.errors.email" class="field-error">{{ form.errors.email }}</div>

                        <!-- Password -->
                        <label for="auth-password" class="field-label" style="margin-top: 14px">{{ $t('auth.password') }}</label>
                        <Input
                            id="auth-password"
                            v-model:value="form.password"
                            size="large"
                            placeholder="••••••••"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="current-password"
                            :status="form.errors.password ? 'error' : ''"
                        >
                            <template #prefix><LockOutlined /></template>
                            <template #suffix>
                                <button
                                    type="button"
                                    class="pass-toggle"
                                    @click="togglePassword"
                                    :aria-label="showPassword ? $t('auth.hide_password') : $t('auth.show_password')"
                                >
                                    <EyeOutlined v-if="!showPassword" />
                                    <EyeInvisibleOutlined v-else />
                                </button>
                            </template>
                        </Input>
                        <div v-if="form.errors.password" class="field-error">{{ form.errors.password }}</div>

                        <!-- Remember + Forgot -->
                        <div class="row-between" style="margin-top: 14px">
                            <Checkbox v-model:checked="form.remember">{{ $t('auth.rememberme') }}</Checkbox>
                            <Link :href="route('password.request')" class="link-sm">
                                {{ $t('auth.forgot_password') }}
                            </Link>
                        </div>

                        <!-- Submit -->
                        <Button
                            type="primary"
                            html-type="submit"
                            size="large"
                            block
                            :loading="form.processing"
                            class="submit-btn"
                        >
                            {{ $t('auth.login') }}
                        </Button>
                    </form>

                    <!-- Divider + Google login. Gateado por el setting
                         `features.google_login_enabled` (shared prop). Si
                         esta off no se muestra ni el divider. -->
                    <template v-if="googleLoginEnabled">
                        <div class="divider"><span>{{ $t('auth.or_continue_with') }}</span></div>
                        <a :href="route('auth_management.google.redirect')" class="google-btn">
                            <GoogleOutlined /> <span>{{ $t('auth.continue_with_google') }}</span>
                        </a>
                    </template>

                    <!-- Locale -->
                    <div class="locale-row">
                        <Select
                            :value="locale"
                            size="small"
                            style="min-width: 120px"
                            @change="onLocaleChange"
                        >
                            <SelectOption v-for="(url, code) in locales" :key="code" :value="code">
                                {{ code === 'es' ? 'Español' : code === 'en' ? 'English' : code }}
                            </SelectOption>
                        </Select>
                    </div>

                    <!-- Disclosure -->
                    <p class="disclosure">
                        <span v-html="disclosureHtml" />
                    </p>
                </div>

                <footer class="login-footer">
                    <p>© {{ new Date().getFullYear() }} {{ effectiveAppName }} · {{ $t('auth.all_rights_reserved') }}</p>
                    <p class="login-footer__links">
                        <span class="login-footer__sep">·</span>
                        <a :href="route('manual')" target="_blank" rel="noopener" class="link-sm">{{ $t('global.user_manual') }}</a>
                    </p>
                </footer>
            </div>
        </main>
    </div>
</template>

<style scoped>
/* ─── Layout grid ──────────────────────────────────────────────────────── */
.login-grid {
    display: grid;
    grid-template-columns: 1fr;
    min-height: 100vh;
    min-height: 100dvh;
    background: #fff;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}
@media (min-width: 768px) {
    .login-grid { grid-template-columns: 1fr 1fr; }
}

/* ─── LEFT: brand panel (desktop only) ─────────────────────────────────── */
.login-brand {
    display: none;
    position: relative;
    overflow: hidden;
    color: #fff;
    background: linear-gradient(160deg, #354A5F 0%, #2C3E51 100%);
    padding: clamp(2.5rem, 5vw, 5rem) clamp(2rem, 4vw, 4rem);
}
@media (min-width: 768px) {
    .login-brand { display: flex; align-items: center; }
}
.login-brand__bg::before,
.login-brand__bg::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.04);
    pointer-events: none;
}
.login-brand__bg::before { width: 320px; height: 320px; top: -100px; right: -100px; }
.login-brand__bg::after  { width: 220px; height: 220px; bottom: -80px; left: -80px; }

.login-brand__inner {
    position: relative;
    z-index: 1;
    max-width: 480px;
    margin: 0 auto;
    text-align: center;
    width: 100%;
}
.login-brand__logo {
    width: 64px;
    height: 64px;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.12);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
    margin-bottom: 1.25rem;
    color: #cbd5e1;
    overflow: hidden;
}
.login-brand__logo-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 6px;
}
.login-brand__title {
    font-weight: 700;
    font-size: 1.6rem;
    margin: 0 0 0.25rem 0;
    letter-spacing: -0.01em;
}
.login-brand__tagline {
    font-size: 0.9rem;
    opacity: 0.85;
    margin-bottom: 1.5rem;
}
.login-brand__hero {
    width: 100%;
    max-width: 320px;
    margin: 0 auto 0.5rem;
}
.login-brand__hero svg {
    display: block;
    width: 100%;
    height: auto;
    filter: drop-shadow(0 12px 30px rgba(0, 0, 0, 0.35));
}

/* ── Efectos eléctricos del hero (transformador animado) ── */
@keyframes df-barrido { 0%{transform:translateY(0);opacity:0} 8%{opacity:.9} 92%{opacity:.9} 100%{transform:translateY(101px);opacity:0} }
@keyframes df-parpadeo { 0%,92%,100%{opacity:1} 95%{opacity:.15} }
@keyframes df-punto    { 0%,100%{opacity:.35} 50%{opacity:1} }
@keyframes df-trazo    { from{stroke-dashoffset:220} to{stroke-dashoffset:0} }
@keyframes df-sello    { 0%{opacity:0;transform:translate(196px,138px) scale(.6)} 100%{opacity:1;transform:translate(196px,138px) scale(1)} }

.login-brand__hero :deep(.df-barrido) { animation: df-barrido 3.4s ease-in-out infinite; }
.login-brand__hero :deep(.df-ojo)     { animation: df-parpadeo 4.2s ease-in-out infinite; }
.login-brand__hero :deep(.df-ojo-b)   { animation-delay: .06s; }
.login-brand__hero :deep(.df-puntos) circle { animation: df-punto 2.4s ease-in-out infinite; }
.login-brand__hero :deep(.df-puntos) circle:nth-child(2n) { animation-delay: .4s; }
.login-brand__hero :deep(.df-puntos) circle:nth-child(3n) { animation-delay: .8s; }
.login-brand__hero :deep(.df-firma)   { stroke-dasharray: 220; animation: df-trazo 2.6s ease-out 1s forwards; stroke-dashoffset: 220; }
.login-brand__hero :deep(.df-sello)   { opacity: 0; animation: df-sello .5s ease-out 3.4s forwards; }

/* Respeta a quien pidio menos movimiento: se ve el resultado final, quieto. */
.login-brand__hero :deep(.sin-movimiento) * { animation: none !important; }
.login-brand__hero :deep(.sin-movimiento) .df-firma { stroke-dashoffset: 0; }
.login-brand__hero :deep(.sin-movimiento) .df-sello { opacity: 1; }
.login-brand__hero :deep(.sin-movimiento) .df-barrido { opacity: 0; }
@media (prefers-reduced-motion: reduce) {
    .login-brand__hero :deep(*) { animation: none !important; }
}
.login-brand__features {
    list-style: none;
    padding: 0;
    margin: 1.75rem 0 0 0;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    text-align: left;
}
.login-brand__features li {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    font-size: 0.9rem;
    line-height: 1.5;
    color: rgba(255, 255, 255, 0.92);
}
.login-brand__features li :deep(.anticon) {
    flex-shrink: 0;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.15);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    margin-top: 2px;
}

/* ─── RIGHT: form panel ────────────────────────────────────────────────── */
.login-main {
    display: flex;
    flex-direction: column;
    background: #fff;
    min-height: 100vh;
    min-height: 100dvh;
}
@media (min-width: 768px) {
    .login-main {
        align-items: center;
        justify-content: center;
        padding: 2rem;
    }
}

/* Mobile-only header (app-like sheet style) */
.login-mobile-header {
    background: linear-gradient(160deg, #354A5F 0%, #2C3E51 100%);
    color: #fff;
    text-align: center;
    padding: calc(env(safe-area-inset-top, 0px) + 2.25rem) 1.5rem 2.5rem;
    border-bottom-left-radius: 28px;
    border-bottom-right-radius: 28px;
    margin-bottom: -1.25rem;
    position: relative;
    overflow: hidden;
}
.login-mobile-header::before,
.login-mobile-header::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.05);
}
.login-mobile-header::before { width: 180px; height: 180px; top: -60px; right: -60px; }
.login-mobile-header::after  { width: 120px; height: 120px; bottom: -40px; left: -30px; }
.login-mobile-header__logo {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.12);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-bottom: 0.85rem;
    color: #cbd5e1;
    position: relative;
    z-index: 1;
    overflow: hidden;
}
.login-mobile-header__logo-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 4px;
}
.login-mobile-header h2 {
    font-weight: 700;
    font-size: 1.35rem;
    margin: 0 0 0.15rem 0;
    color: #fff;
    letter-spacing: -0.01em;
    position: relative;
    z-index: 1;
}
.login-mobile-header p {
    font-size: 0.8rem;
    opacity: 0.85;
    margin: 0;
    color: #fff;
    position: relative;
    z-index: 1;
}
@media (min-width: 768px) {
    .login-mobile-header { display: none; }
}

/* Form wrap — sheet on mobile, naturally centered on desktop (login-main centers it) */
.login-form-wrap {
    width: 100%;
    max-width: 460px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
}
@media (max-width: 767.98px) {
    .login-form-wrap {
        flex: 1;
        background: #fff;
        border-top-left-radius: 24px;
        border-top-right-radius: 24px;
        padding: 2rem 1.5rem 1.25rem;  /* más respiro lateral en mobile (1.5rem en lugar de 1.25rem) */
        position: relative;
        z-index: 2;
        box-shadow: 0 -8px 24px rgba(2, 32, 71, 0.06);
    }
}

.login-form {
    padding: 1.75rem 0.5rem 1rem;
}

@media (min-width: 768px) {
    .login-form { padding: 0.5rem 0.5rem; }
}

.login-form__header { margin-bottom: 1.75rem; }
.login-form__header h1 {
    font-weight: 700;
    font-size: 1.75rem;
    color: #1f2937;
    margin: 0 0 0.4rem 0;
    letter-spacing: -0.02em;
}
.login-form__header p {
    color: #6b7280;
    font-size: 0.95rem;
    margin: 0;
}

.field-label {
    display: block;
    font-size: 0.78rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 7px;
    letter-spacing: 0.01em;
}

.field-error {
    color: #dc2626;
    font-size: 0.8rem;
    font-weight: 500;
    margin: 6px 0 0 0;
}

/* Inputs — definidos, no fantasmagóricos.
   Bordes claros + fondo blanco + focus ring fuerte. */
.login-form :deep(.ant-input-affix-wrapper),
.login-form :deep(.ant-input) {
    height: 50px;
    border-radius: 10px;
    background: #fff;
    border: 1.5px solid #d4d8dd;
    font-size: 0.95rem;
    color: #1f2937;
    padding: 0 14px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.login-form :deep(.ant-input-affix-wrapper) {
    padding: 0 14px;
}
.login-form :deep(.ant-input-affix-wrapper input.ant-input) {
    background: transparent;
    border: 0;
    box-shadow: none !important;
    height: 100%;
    padding: 0;
}
.login-form :deep(.ant-input-affix-wrapper:hover),
.login-form :deep(.ant-input:hover) {
    border-color: #94a3b8;
}
.login-form :deep(.ant-input-affix-wrapper-focused),
.login-form :deep(.ant-input-affix-wrapper:focus-within),
.login-form :deep(.ant-input:focus) {
    border-color: #0A6ED1 !important;
    box-shadow: 0 0 0 3px rgba(10, 110, 209, 0.18) !important;
}
.login-form :deep(.ant-input-affix-wrapper-status-error) {
    border-color: #ef4444 !important;
}
.login-form :deep(.ant-input-affix-wrapper-status-error:focus-within) {
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15) !important;
}
.login-form :deep(.ant-input-prefix) {
    color: #64748b;
    margin-right: 10px;
    font-size: 1.05rem;
}
.login-form :deep(.ant-input::placeholder) {
    color: #94a3b8;
}

.row-between {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.link-sm {
    font-size: 0.875rem;
    color: #0A6ED1;
    font-weight: 500;
    text-decoration: none;
}
.link-sm:hover { color: #085CAF; text-decoration: underline; }

.submit-btn {
    margin-top: 20px;
    height: 52px !important;
    font-weight: 600 !important;
    font-size: 1rem !important;
    border-radius: 10px !important;
    background: linear-gradient(135deg, #0A6ED1 0%, #064C92 100%) !important;
    border: 0 !important;
    box-shadow: 0 6px 18px rgba(10, 110, 209, 0.28) !important;
    letter-spacing: 0.01em;
    transition: transform 0.12s ease, box-shadow 0.15s ease !important;
}
.submit-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 22px rgba(10, 110, 209, 0.35) !important;
}
.submit-btn:active { transform: translateY(0) !important; }

.pass-toggle {
    background: transparent;
    border: 0;
    cursor: pointer;
    padding: 6px;
    color: #94a3b8;
    display: flex;
    align-items: center;
    border-radius: 6px;
    transition: background 0.12s ease, color 0.12s ease;
}
.pass-toggle:hover { background: #f1f5f9; color: #0A6ED1; }

/* Divider */
.divider {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    color: #6A6D70;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 500;
    margin: 1.75rem 0 1.25rem;
}
.divider::before, .divider::after {
    content: "";
    flex: 1;
    height: 1px;
    background: #e5e7eb;
}

/* Google button — matches original Blade: white bg, soft border, hover lift */
.google-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.65rem;
    height: 50px;
    border-radius: 10px;
    background: #fff;
    color: #1f2937;
    border: 1px solid #e5e7eb;
    font-weight: 500;
    text-decoration: none;
    transition: background 0.15s ease, border-color 0.15s ease, transform 0.12s ease;
    width: 100%;
}
.google-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    transform: translateY(-1px);
}
.google-btn :deep(.anticon) { color: #ea4335; font-size: 1.15rem; }

/* Locale — chip pill style, like the original */
.locale-row {
    display: flex;
    justify-content: center;
    margin-top: 1.25rem;
}
.locale-row :deep(.ant-select) {
    min-width: 130px;
}
.locale-row :deep(.ant-select-selector) {
    background: #f1f5f9 !important;
    border: 0 !important;
    border-radius: 999px !important;
    padding: 4px 14px !important;
    height: 32px !important;
    font-size: 0.8rem !important;
    color: #475569 !important;
}

/* Disclosure */
.disclosure {
    font-size: 0.72rem;
    color: #94a3b8;
    margin-top: 1rem;
    line-height: 1.5;
    text-align: center;
}
.disclosure a { color: #0A6ED1; text-decoration: none; font-weight: 500; }
.disclosure a:hover { text-decoration: underline; }

/* Footer */
.login-footer {
    text-align: center;
    padding: 1rem 1rem calc(env(safe-area-inset-bottom, 0px) + 1.25rem);
    color: #9ca3af;
    font-size: 0.7rem;
}
.login-footer p { margin: 0; font-weight: 500; }
.login-footer__links { margin-top: 4px !important; display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap; }
.login-footer__sep { opacity: 0.5; }

/* ─── Mobile-specific tweaks — app-like polish ─────────────────────────── */
@media (max-width: 767.98px) {
    .login-form__header { margin-bottom: 1.5rem; }
    .login-form__header h1 { font-size: 1.5rem; letter-spacing: -0.01em; }
    .login-form__header p  { font-size: 0.875rem; }

    /* Inputs: thinner border + slightly bigger touch target */
    .login-form :deep(.ant-input-affix-wrapper),
    .login-form :deep(.ant-input) {
        height: 54px;
        font-size: 1rem;
        border-width: 1px;  /* más sutil en pantalla chica */
    }

    /* Buttons: matching heights for visual consistency */
    .submit-btn { height: 56px !important; font-size: 1rem !important; margin-top: 24px; }
    .google-btn { height: 56px; font-size: 1rem; }

    /* Vertical rhythm — gaps consistentes (8/16/24/32px scale) */
    .row-between { margin-top: 16px !important; }
    .divider     { margin: 24px 0 16px !important; }
    .locale-row  { margin-top: 24px !important; }
    .disclosure  { margin-top: 12px; font-size: 0.75rem; line-height: 1.55; }
}

.mb-3 { margin-bottom: 12px; }
</style>

<!-- Dark mode overrides (NOT scoped) -->
<style>
html[data-theme="dark"] .login-grid { background: #1a1f24; }
html[data-theme="dark"] .login-main { background: #1a1f24; }

/* Mobile sheet — needs darker bg + adjusted shadow */
@media (max-width: 767.98px) {
    html[data-theme="dark"] .login-form-wrap {
        background: #1a1f24 !important;
        box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.4) !important;
    }
}

html[data-theme="dark"] .login-form__header h1 { color: #e5e6e7; }
html[data-theme="dark"] .login-form__header p  { color: #a8aaae; }
html[data-theme="dark"] .field-label           { color: #cbd5e1; }

html[data-theme="dark"] .login-form .ant-input-affix-wrapper,
html[data-theme="dark"] .login-form .ant-input {
    background: #2c3034 !important;
    border-color: #3f4448 !important;
    color: #e5e6e7 !important;
}
html[data-theme="dark"] .login-form .ant-input-affix-wrapper:hover,
html[data-theme="dark"] .login-form .ant-input:hover {
    border-color: #4db6e8 !important;
}
html[data-theme="dark"] .login-form .ant-input-affix-wrapper-focused,
html[data-theme="dark"] .login-form .ant-input-affix-wrapper:focus-within {
    border-color: #4db6e8 !important;
    box-shadow: 0 0 0 3px rgba(77, 182, 232, 0.18) !important;
}
html[data-theme="dark"] .login-form .ant-input-prefix       { color: #7c8390; }
html[data-theme="dark"] .login-form .ant-input::placeholder { color: #6b7785; }

html[data-theme="dark"] .pass-toggle:hover { background: #313a44; color: #4db6e8; }

html[data-theme="dark"] .divider          { color: #6b7785; }
html[data-theme="dark"] .divider::before,
html[data-theme="dark"] .divider::after   { background: #3f4448; }

html[data-theme="dark"] .google-btn {
    background: #2c3034;
    color: #e5e6e7;
    border-color: #3f4448;
}
html[data-theme="dark"] .google-btn:hover {
    background: #313a44;
    border-color: #4db6e8;
}

html[data-theme="dark"] .locale-row .ant-select-selector {
    background: #313a44 !important;
    color: #cbd5e1 !important;
}

html[data-theme="dark"] .disclosure   { color: #7c8390; }
html[data-theme="dark"] .login-footer { color: #6b7785; }
</style>
