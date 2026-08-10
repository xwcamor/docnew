<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Input, Button, Alert } from 'ant-design-vue';
import {
    MailOutlined, LockOutlined, EyeOutlined, EyeInvisibleOutlined,
    ArrowLeftOutlined, SafetyOutlined, CheckOutlined,
} from '@ant-design/icons-vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineOptions({ layout: AuthLayout });

const props = defineProps({
    token:   { type: String, default: '' },
    email:   { type: String, default: '' },
    appName: { type: String, default: '' },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const page = usePage();
// El nombre del producto sale del ajuste `app.name`. Antes caia a un
// 'TR Health' escrito a mano — la marca del producto anterior.
const effectiveAppName = computed(() => props.appName || page.props.appName || '');

const showPassword = ref(false);
const showConfirm  = ref(false);

const submit = () => {
    form.post(route('password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head :title="$t('auth.reset_title')" />

    <div class="auth-grid">
        <!-- LEFT brand (desktop only) -->
        <aside class="auth-brand">
            <div class="auth-brand__bg" />
            <div class="auth-brand__inner">
                <div class="auth-brand__logo"><SafetyOutlined /></div>
                <h2 class="auth-brand__title">{{ effectiveAppName }}</h2>
                <p class="auth-brand__tagline">{{ $t('auth.reset_tagline') }}</p>

                <!-- Abstract SVG hero -->
                <div class="auth-brand__hero">
                    <svg viewBox="0 0 280 200" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <defs>
                            <linearGradient id="rp-grad-a" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="rgba(255,255,255,0.18)" />
                                <stop offset="100%" stop-color="rgba(255,255,255,0.04)" />
                            </linearGradient>
                            <linearGradient id="rp-grad-b" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="rgba(77,182,232,0.30)" />
                                <stop offset="100%" stop-color="rgba(10,110,209,0.10)" />
                            </linearGradient>
                        </defs>

                        <!-- Tarjeta back -->
                        <rect x="40" y="50" width="180" height="110" rx="10" fill="url(#rp-grad-a)" stroke="rgba(255,255,255,0.14)" stroke-width="1" />
                        <rect x="56" y="68" width="60" height="6" rx="3" fill="rgba(255,255,255,0.35)" />
                        <rect x="56" y="84" width="148" height="3" rx="1.5" fill="rgba(255,255,255,0.18)" />
                        <rect x="56" y="94" width="120" height="3" rx="1.5" fill="rgba(255,255,255,0.14)" />

                        <!-- Tarjeta candado -->
                        <rect x="60" y="90" width="160" height="90" rx="10" fill="url(#rp-grad-b)" stroke="rgba(255,255,255,0.18)" stroke-width="1" />
                        <rect x="125" y="125" width="30" height="34" rx="4" fill="rgba(255,255,255,0.55)" />
                        <path d="M131 125 v-9 a9 9 0 0 1 18 0 v9" fill="none" stroke="rgba(255,255,255,0.55)" stroke-width="2.5" />
                        <circle cx="140" cy="142" r="3" fill="rgba(10,110,209,0.8)" />

                        <!-- Check de éxito -->
                        <circle cx="220" cy="60" r="18" fill="rgba(34,197,94,0.30)" />
                        <path d="M213 60 l5 5 l9 -10" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />

                        <circle cx="50" cy="170" r="10" fill="rgba(255,255,255,0.12)" />
                    </svg>
                </div>

                <ul class="auth-brand__features">
                    <li><CheckOutlined /><span>{{ $t('auth.reset_rule_length') }}</span></li>
                    <li><CheckOutlined /><span>{{ $t('auth.reset_rule_mix') }}</span></li>
                    <li><CheckOutlined /><span>{{ $t('auth.reset_rule_instant') }}</span></li>
                </ul>
            </div>
        </aside>

        <!-- RIGHT form -->
        <main class="auth-main">
            <header class="auth-mobile-header">
                <div class="auth-mobile-header__logo"><SafetyOutlined /></div>
                <h2>{{ effectiveAppName }}</h2>
                <p>{{ $t('auth.reset_title') }}</p>
            </header>

            <div class="auth-form-wrap">
                <div class="auth-form">
                    <Link :href="route('login')" class="back-link">
                        <ArrowLeftOutlined /> {{ $t('auth.back_to_login') }}
                    </Link>

                    <div class="auth-form__header">
                        <h1>{{ $t('auth.reset_title') }}</h1>
                        <p>{{ $t('auth.reset_message') }}</p>
                    </div>

                    <Alert
                        v-if="form.errors.email && !form.errors.password"
                        type="error"
                        :message="form.errors.email"
                        show-icon
                        class="mb-3"
                    />

                    <form @submit.prevent="submit" autocomplete="off">
                        <label for="auth-email" class="field-label">{{ $t('auth.email') }}</label>
                        <Input
                            id="auth-email"
                            v-model:value="form.email"
                            size="large"
                            type="email"
                            autocomplete="username"
                            :status="form.errors.email ? 'error' : ''"
                        >
                            <template #prefix><MailOutlined /></template>
                        </Input>

                        <label for="auth-password" class="field-label" style="margin-top: 14px">{{ $t('auth.reset_new_password') }}</label>
                        <Input
                            id="auth-password"
                            v-model:value="form.password"
                            size="large"
                            :type="showPassword ? 'text' : 'password'"
                            :placeholder="$t('auth.reset_min_placeholder')"
                            autocomplete="new-password"
                            :status="form.errors.password ? 'error' : ''"
                        >
                            <template #prefix><LockOutlined /></template>
                            <template #suffix>
                                <button
                                    type="button"
                                    class="pass-toggle"
                                    @click="showPassword = !showPassword"
                                    :aria-label="showPassword ? $t('auth.hide_password') : $t('auth.show_password')"
                                >
                                    <EyeOutlined v-if="!showPassword" />
                                    <EyeInvisibleOutlined v-else />
                                </button>
                            </template>
                        </Input>
                        <div v-if="form.errors.password" class="field-error">{{ form.errors.password }}</div>

                        <label for="auth-password-confirm" class="field-label" style="margin-top: 14px">{{ $t('auth.reset_repeat_password') }}</label>
                        <Input
                            id="auth-password-confirm"
                            v-model:value="form.password_confirmation"
                            size="large"
                            :type="showConfirm ? 'text' : 'password'"
                            :placeholder="$t('auth.reset_repeat_placeholder')"
                            autocomplete="new-password"
                        >
                            <template #prefix><LockOutlined /></template>
                            <template #suffix>
                                <button
                                    type="button"
                                    class="pass-toggle"
                                    @click="showConfirm = !showConfirm"
                                    :aria-label="showConfirm ? $t('auth.hide_password') : $t('auth.show_password')"
                                >
                                    <EyeOutlined v-if="!showConfirm" />
                                    <EyeInvisibleOutlined v-else />
                                </button>
                            </template>
                        </Input>

                        <Button
                            type="primary"
                            html-type="submit"
                            size="large"
                            block
                            :loading="form.processing"
                            class="submit-btn"
                        >
                            {{ $t('auth.reset_password_button') }}
                        </Button>
                    </form>
                </div>

                <footer class="auth-footer">
                    <p>© {{ new Date().getFullYear() }} {{ effectiveAppName }} · {{ $t('auth.all_rights_reserved') }}</p>
                </footer>
            </div>
        </main>
    </div>
</template>

<style scoped>
/* ─── Layout grid ──────────────────────────────────────────────────────── */
.auth-grid {
    display: grid;
    grid-template-columns: 1fr;
    min-height: 100vh;
    min-height: 100dvh;
    background: var(--color-surface);
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
}
@media (min-width: 768px) {
    .auth-grid { grid-template-columns: 1fr 1fr; }
}

/* ─── LEFT: brand panel (desktop only) ─────────────────────────────────── */
.auth-brand {
    display: none;
    position: relative;
    overflow: hidden;
    color: var(--color-text-on-dark);
    /* El degradado se queda a mano. Tokenizar solo el primer tramo lo partiria:
       arrancaria con el color del esquema del usuario y terminaria en un navy
       fijo, y el salto se ve. Un degradado necesita sus dos extremos del mismo
       sitio, y de eso no hay token. */
    background: linear-gradient(160deg, #354A5F 0%, #2C3E51 100%);
    padding: clamp(2.5rem, 5vw, 5rem) clamp(2rem, 4vw, 4rem);
}
@media (min-width: 768px) {
    .auth-brand { display: flex; align-items: center; }
}
.auth-brand__bg::before,
.auth-brand__bg::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.04);
    pointer-events: none;
}
.auth-brand__bg::before { width: 320px; height: 320px; top: -100px; right: -100px; }
.auth-brand__bg::after  { width: 220px; height: 220px; bottom: -80px; left: -80px; }

.auth-brand__inner {
    position: relative;
    z-index: 1;
    max-width: 480px;
    margin: 0 auto;
    text-align: center;
    width: 100%;
}
.auth-brand__logo {
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
    color: var(--color-icon-mute);
}
.auth-brand__title {
    font-weight: 700;
    font-size: 1.6rem;
    margin: 0 0 0.25rem 0;
    letter-spacing: -0.01em;
}
.auth-brand__tagline {
    font-size: 0.9rem;
    opacity: 0.85;
    margin-bottom: 1.5rem;
}
.auth-brand__hero {
    width: 100%;
    max-width: 320px;
    margin: 0 auto 0.5rem;
}
.auth-brand__hero svg {
    display: block;
    width: 100%;
    height: auto;
    filter: drop-shadow(0 12px 30px rgba(0, 0, 0, 0.35));
}
.auth-brand__features {
    list-style: none;
    padding: 0;
    margin: 1.75rem 0 0 0;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    text-align: left;
}
.auth-brand__features li {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    font-size: 0.9rem;
    line-height: 1.5;
    color: rgba(255, 255, 255, 0.92);
}
.auth-brand__features li :deep(.anticon) {
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
.auth-main {
    display: flex;
    flex-direction: column;
    background: var(--color-surface);
    min-height: 100vh;
    min-height: 100dvh;
}
@media (min-width: 768px) {
    .auth-main {
        align-items: center;
        justify-content: center;
        padding: 2rem;
    }
}

/* Mobile-only header */
.auth-mobile-header {
    background: linear-gradient(160deg, #354A5F 0%, #2C3E51 100%);
    color: var(--color-text-on-dark);
    text-align: center;
    padding: calc(env(safe-area-inset-top, 0px) + 2.25rem) 1.5rem 2.5rem;
    border-bottom-left-radius: 28px;
    border-bottom-right-radius: 28px;
    margin-bottom: -1.25rem;
    position: relative;
    overflow: hidden;
}
.auth-mobile-header::before,
.auth-mobile-header::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.05);
}
.auth-mobile-header::before { width: 180px; height: 180px; top: -60px; right: -60px; }
.auth-mobile-header::after  { width: 120px; height: 120px; bottom: -40px; left: -30px; }
.auth-mobile-header__logo {
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
    color: var(--color-icon-mute);
    position: relative;
    z-index: 1;
}
.auth-mobile-header h2 {
    font-weight: 700;
    font-size: 1.35rem;
    margin: 0 0 0.15rem 0;
    color: var(--color-text-on-dark);
    letter-spacing: -0.01em;
    position: relative;
    z-index: 1;
}
.auth-mobile-header p {
    font-size: 0.8rem;
    opacity: 0.85;
    margin: 0;
    color: var(--color-text-on-dark);
    position: relative;
    z-index: 1;
}
@media (min-width: 768px) {
    .auth-mobile-header { display: none; }
}

/* Form wrap */
.auth-form-wrap {
    width: 100%;
    max-width: 460px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
}
@media (max-width: 767.98px) {
    .auth-form-wrap {
        flex: 1;
        background: var(--color-surface);
        border-top-left-radius: 24px;
        border-top-right-radius: 24px;
        padding: 2rem 1.5rem 1.25rem;
        position: relative;
        z-index: 2;
        box-shadow: 0 -8px 24px rgba(2, 32, 71, 0.06);
    }
}

.auth-form {
    padding: 1.75rem 0.5rem 1rem;
}
@media (min-width: 768px) {
    .auth-form { padding: 0.5rem 0.5rem; }
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--color-text-muted);
    font-size: 0.875rem;
    text-decoration: none;
    margin-bottom: 1.25rem;
}
.back-link:hover { color: var(--color-primary); }

.auth-form__header { margin-bottom: 1.75rem; }
.auth-form__header h1 {
    font-weight: 700;
    font-size: 1.75rem;
    color: var(--color-text);
    margin: 0 0 0.4rem 0;
    letter-spacing: -0.02em;
}
.auth-form__header p {
    color: var(--color-text-muted);
    font-size: 0.95rem;
    margin: 0;
    line-height: 1.5;
}

.field-label {
    display: block;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--color-text-strong);
    margin-bottom: 7px;
    letter-spacing: 0.01em;
}
.field-error {
    color: var(--state-bad-text);
    font-size: 0.8rem;
    font-weight: 500;
    margin: 6px 0 0 0;
}

/* Inputs */
.auth-form :deep(.ant-input-affix-wrapper),
.auth-form :deep(.ant-input) {
    height: 50px;
    border-radius: 10px;
    background: var(--color-surface);
    /* El gris del borde no tiene token: es mas oscuro que --color-border a
       proposito, para que el campo se vea sin tener que enfocarlo. */
    border: 1.5px solid #d4d8dd;
    font-size: 0.95rem;
    color: var(--color-text);
    padding: 0 14px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.auth-form :deep(.ant-input-affix-wrapper) {
    padding: 0 14px;
}
.auth-form :deep(.ant-input-affix-wrapper input.ant-input) {
    background: transparent;
    border: 0;
    box-shadow: none !important;
    height: 100%;
    padding: 0;
}
/* Los grises del campo —borde en reposo, borde al pasar, icono de prefijo,
   placeholder y el ojo de la contraseña— son una escala propia del formulario:
   cada uno tiene que quedar por debajo del anterior. Los tokens de texto no
   dan esa gradacion, asi que se quedan en hex hasta que exista. */
.auth-form :deep(.ant-input-affix-wrapper:hover),
.auth-form :deep(.ant-input:hover) {
    border-color: #94a3b8;
}
.auth-form :deep(.ant-input-affix-wrapper-focused),
.auth-form :deep(.ant-input-affix-wrapper:focus-within),
.auth-form :deep(.ant-input:focus) {
    border-color: var(--color-primary) !important;
    box-shadow: 0 0 0 3px rgba(10, 110, 209, 0.18) !important;
}
.auth-form :deep(.ant-input-affix-wrapper-status-error) {
    border-color: var(--color-input-error) !important;
}
.auth-form :deep(.ant-input-affix-wrapper-status-error:focus-within) {
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15) !important;
}
.auth-form :deep(.ant-input-prefix) {
    color: #64748b;
    margin-right: 10px;
    font-size: 1.05rem;
}
.auth-form :deep(.ant-input::placeholder) {
    color: #94a3b8;
}

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
.pass-toggle:hover { background: var(--color-surface-hover); color: var(--color-primary); }

.submit-btn {
    margin-top: 20px;
    height: 52px !important;
    font-weight: 600 !important;
    font-size: 1rem !important;
    border-radius: 10px !important;
    /* Mismo caso que el panel de marca: degradado de dos tramos, y el segundo
       tramo oscuro no tiene token. Se deja entero para no partirlo. */
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

.auth-footer {
    text-align: center;
    padding: 1rem 1rem calc(env(safe-area-inset-bottom, 0px) + 1.25rem);
    color: var(--color-text-dim);
    font-size: 0.7rem;
}
.auth-footer p { margin: 0; font-weight: 500; }

.mb-3 { margin-bottom: 12px; }

/* ─── Mobile-specific tweaks ───────────────────────────────────────────── */
@media (max-width: 767.98px) {
    .auth-form__header { margin-bottom: 1.5rem; }
    .auth-form__header h1 { font-size: 1.5rem; letter-spacing: -0.01em; }
    .auth-form__header p  { font-size: 0.875rem; }

    .auth-form :deep(.ant-input-affix-wrapper),
    .auth-form :deep(.ant-input) {
        height: 54px;
        font-size: 1rem;
        border-width: 1px;
    }

    .submit-btn { height: 56px !important; font-size: 1rem !important; margin-top: 24px; }
}
</style>

<!-- Ajustes de tema oscuro (sin scope).
     Solo queda lo que el token no resuelve solo: la sombra de la hoja movil y
     los tonos de los campos, que en oscuro van MAS claros que la tarjeta. El
     resto se ha borrado porque arriba ya son tokens y cambiaban dos veces. -->
<style>
@media (max-width: 767.98px) {
    html[data-theme="dark"] .auth-form-wrap {
        box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.4) !important;
    }
}

/* El campo va sobre la superficie alterna: con la de la tarjeta desaparecia. */
html[data-theme="dark"] .auth-form .ant-input-affix-wrapper,
html[data-theme="dark"] .auth-form .ant-input {
    background: var(--color-surface-alt) !important;
    border-color: var(--color-border) !important;
}
/* El azul principal no se aclara en oscuro, asi que el foco tira del acento. */
html[data-theme="dark"] .auth-form .ant-input-affix-wrapper:hover,
html[data-theme="dark"] .auth-form .ant-input:hover {
    border-color: var(--color-primary-accent) !important;
}
html[data-theme="dark"] .auth-form .ant-input-affix-wrapper-focused,
html[data-theme="dark"] .auth-form .ant-input-affix-wrapper:focus-within {
    border-color: var(--color-primary-accent) !important;
    box-shadow: 0 0 0 3px rgba(77, 182, 232, 0.18) !important;
}
/* Grises de dentro del campo: escala propia del formulario, sin token. */
html[data-theme="dark"] .auth-form .ant-input-prefix       { color: #7c8390; }
html[data-theme="dark"] .auth-form .ant-input::placeholder { color: #6b7785; }

html[data-theme="dark"] .back-link:hover   { color: var(--color-primary-accent); }
html[data-theme="dark"] .pass-toggle:hover { color: var(--color-primary-accent); }
</style>
