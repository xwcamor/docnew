<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Input, Button, Alert } from 'ant-design-vue';
import {
    MailOutlined, ArrowLeftOutlined, SafetyOutlined, CheckOutlined,
    CheckCircleFilled,
} from '@ant-design/icons-vue';
import AuthLayout from '@/Layouts/AuthLayout.vue';

defineOptions({ layout: AuthLayout });

defineProps({
    appName: { type: String, default: '' },
});

const page = usePage();

const form = useForm({ email: '' });

// Tras un envío exitoso mostramos un panel de confirmación en lugar del form.
// El email confirmado viene del flash del backend (sent_to) para sobrevivir
// si el componente se rehidrata, y como respaldo guardamos el último valor enviado.
const sentEmail = ref('');
const sentMessage = computed(() => page.props.flash?.success || '');
const showConfirmation = computed(() => !!sentEmail.value || !!page.props.flash?.sent_to);
const confirmedEmail = computed(() => page.props.flash?.sent_to || sentEmail.value);

const submit = () => {
    const attempted = form.email;
    form.post(route('password.email'), {
        onSuccess: () => {
            sentEmail.value = attempted;
            form.reset('email');
        },
    });
};

const sendAgain = () => {
    form.email = confirmedEmail.value;
    form.post(route('password.email'), {
        preserveScroll: true,
        onSuccess: () => { form.reset('email'); },
    });
};

const useDifferentEmail = () => {
    sentEmail.value = '';
    // Limpiamos también el flash en memoria para que vuelva el form.
    if (page.props.flash) {
        page.props.flash.sent_to = null;
        page.props.flash.success = null;
    }
    form.reset('email');
};
</script>

<template>
    <Head title="Recuperar contraseña" />

    <div class="auth-grid">
        <!-- LEFT brand (desktop only) -->
        <aside class="auth-brand">
            <div class="auth-brand__bg" />
            <div class="auth-brand__inner">
                <div class="auth-brand__logo"><SafetyOutlined /></div>
                <h2 class="auth-brand__title">{{ appName || 'TR Health' }}</h2>
                <p class="auth-brand__tagline">¿Olvidaste tu contraseña?</p>

                <!-- Abstract SVG hero (mismo estilo que Login) -->
                <div class="auth-brand__hero">
                    <svg viewBox="0 0 280 200" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <defs>
                            <linearGradient id="fp-grad-a" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="rgba(255,255,255,0.18)" />
                                <stop offset="100%" stop-color="rgba(255,255,255,0.04)" />
                            </linearGradient>
                            <linearGradient id="fp-grad-b" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="rgba(77,182,232,0.30)" />
                                <stop offset="100%" stop-color="rgba(10,110,209,0.10)" />
                            </linearGradient>
                        </defs>

                        <!-- Sobre / mensaje -->
                        <rect x="50" y="60" width="180" height="110" rx="10" fill="url(#fp-grad-a)" stroke="rgba(255,255,255,0.14)" stroke-width="1" />
                        <path d="M50 70 L140 130 L230 70" fill="none" stroke="rgba(255,255,255,0.35)" stroke-width="2" />
                        <rect x="70" y="140" width="80" height="4" rx="2" fill="rgba(255,255,255,0.30)" />
                        <rect x="70" y="150" width="50" height="3" rx="1.5" fill="rgba(255,255,255,0.18)" />

                        <!-- Candado flotante -->
                        <rect x="180" y="110" width="60" height="60" rx="10" fill="url(#fp-grad-b)" stroke="rgba(255,255,255,0.18)" stroke-width="1" />
                        <rect x="200" y="130" width="20" height="22" rx="3" fill="rgba(255,255,255,0.55)" />
                        <path d="M204 130 v-6 a6 6 0 0 1 12 0 v6" fill="none" stroke="rgba(255,255,255,0.55)" stroke-width="2" />

                        <!-- Acentos -->
                        <circle cx="60" cy="50" r="8" fill="rgba(77,182,232,0.55)" />
                        <circle cx="240" cy="55" r="14" fill="rgba(77,182,232,0.20)" />
                    </svg>
                </div>

                <ul class="auth-brand__features">
                    <li><CheckOutlined /><span>Enlace seguro enviado a tu correo</span></li>
                    <li><CheckOutlined /><span>Restablecé en pocos minutos</span></li>
                    <li><CheckOutlined /><span>Sin necesidad de contactar soporte</span></li>
                </ul>
            </div>
        </aside>

        <!-- RIGHT form -->
        <main class="auth-main">
            <header class="auth-mobile-header">
                <div class="auth-mobile-header__logo"><SafetyOutlined /></div>
                <h2>{{ appName || 'TR Health' }}</h2>
                <p>Recuperar contraseña</p>
            </header>

            <div class="auth-form-wrap">
                <div class="auth-form">
                    <Link :href="route('login')" class="back-link">
                        <ArrowLeftOutlined /> Volver al login
                    </Link>

                    <!-- ── ESTADO 1: panel de confirmación tras envío ─────── -->
                    <template v-if="showConfirmation">
                        <div class="confirm-icon">
                            <CheckCircleFilled />
                        </div>

                        <div class="auth-form__header confirm-header">
                            <h1>Revisa tu correo</h1>
                            <p>
                                Si <strong>{{ confirmedEmail }}</strong> está registrado,
                                te enviamos un enlace para restablecer tu contraseña.
                            </p>
                        </div>

                        <div class="confirm-tips">
                            <p><strong>¿No lo encuentras?</strong></p>
                            <ul>
                                <li>El enlace puede tardar hasta 1–2 minutos en llegar.</li>
                                <li>Revisa tu carpeta de <em>spam</em> o <em>promociones</em>.</li>
                                <li>Verifica que escribiste bien el correo.</li>
                            </ul>
                        </div>

                        <Button
                            type="primary"
                            size="large"
                            block
                            :loading="form.processing"
                            class="submit-btn"
                            @click="sendAgain"
                        >
                            Reenviar enlace
                        </Button>

                        <button
                            type="button"
                            class="text-btn"
                            @click="useDifferentEmail"
                        >
                            Usar otro correo
                        </button>
                    </template>

                    <!-- ── ESTADO 2: formulario inicial ───────────────────── -->
                    <template v-else>
                        <div class="auth-form__header">
                            <h1>Recuperar contraseña</h1>
                            <p>Ingrese su correo y le enviaremos un enlace para restablecerla.</p>
                        </div>

                        <Alert
                            v-if="sentMessage"
                            type="success"
                            :message="sentMessage"
                            show-icon
                            class="mb-3"
                        />

                        <form @submit.prevent="submit" autocomplete="off">
                            <label for="auth-email" class="field-label">Correo electrónico</label>
                            <Input
                                id="auth-email"
                                v-model:value="form.email"
                                size="large"
                                type="email"
                                placeholder="tu@empresa.com"
                                autocomplete="username"
                                :status="form.errors.email ? 'error' : ''"
                            >
                                <template #prefix><MailOutlined /></template>
                            </Input>
                            <div v-if="form.errors.email" class="field-error">{{ form.errors.email }}</div>

                            <Button
                                type="primary"
                                html-type="submit"
                                size="large"
                                block
                                :loading="form.processing"
                                class="submit-btn"
                            >
                                Enviar enlace
                            </Button>
                        </form>

                        <p class="hint">
                            ¿Recordaste tu contraseña?
                            <Link :href="route('login')" class="link-sm">Volver al login</Link>
                        </p>
                    </template>
                </div>

                <footer class="auth-footer">
                    <p>© {{ new Date().getFullYear() }} {{ appName }} · Todos los derechos reservados</p>
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
    background: #fff;
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
    color: #fff;
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
    color: #cbd5e1;
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
    background: #fff;
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
    color: #fff;
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
    color: #cbd5e1;
    position: relative;
    z-index: 1;
}
.auth-mobile-header h2 {
    font-weight: 700;
    font-size: 1.35rem;
    margin: 0 0 0.15rem 0;
    color: #fff;
    letter-spacing: -0.01em;
    position: relative;
    z-index: 1;
}
.auth-mobile-header p {
    font-size: 0.8rem;
    opacity: 0.85;
    margin: 0;
    color: #fff;
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
        background: #fff;
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
    color: #6b7280;
    font-size: 0.875rem;
    text-decoration: none;
    margin-bottom: 1.25rem;
}
.back-link:hover { color: #0A6ED1; }

.auth-form__header { margin-bottom: 1.75rem; }
.auth-form__header h1 {
    font-weight: 700;
    font-size: 1.75rem;
    color: #1f2937;
    margin: 0 0 0.4rem 0;
    letter-spacing: -0.02em;
}
.auth-form__header p {
    color: #6b7280;
    font-size: 0.95rem;
    margin: 0;
    line-height: 1.5;
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

/* Inputs */
.auth-form :deep(.ant-input-affix-wrapper),
.auth-form :deep(.ant-input) {
    height: 50px;
    border-radius: 10px;
    background: #fff;
    border: 1.5px solid #d4d8dd;
    font-size: 0.95rem;
    color: #1f2937;
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
.auth-form :deep(.ant-input-affix-wrapper:hover),
.auth-form :deep(.ant-input:hover) {
    border-color: #94a3b8;
}
.auth-form :deep(.ant-input-affix-wrapper-focused),
.auth-form :deep(.ant-input-affix-wrapper:focus-within),
.auth-form :deep(.ant-input:focus) {
    border-color: #0A6ED1 !important;
    box-shadow: 0 0 0 3px rgba(10, 110, 209, 0.18) !important;
}
.auth-form :deep(.ant-input-affix-wrapper-status-error) {
    border-color: #ef4444 !important;
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

.hint {
    text-align: center;
    margin-top: 1.5rem;
    font-size: 0.875rem;
    color: #6b7280;
}
.link-sm {
    font-size: 0.875rem;
    color: #0A6ED1;
    font-weight: 500;
    text-decoration: none;
}
.link-sm:hover { color: #085CAF; text-decoration: underline; }

.auth-footer {
    text-align: center;
    padding: 1rem 1rem calc(env(safe-area-inset-bottom, 0px) + 1.25rem);
    color: #9ca3af;
    font-size: 0.7rem;
}
.auth-footer p { margin: 0; font-weight: 500; }

.mb-3 { margin-bottom: 12px; }

/* ─── Panel de confirmación (post-envío) ───────────────────────────────── */
.confirm-icon {
    display: flex;
    justify-content: center;
    margin: 0.5rem 0 1.25rem;
    font-size: 3.25rem;
    color: #22c55e;
    line-height: 1;
}
.confirm-header { text-align: center; }
.confirm-header p { font-size: 0.95rem; line-height: 1.55; }
.confirm-header strong { color: #1f2937; font-weight: 600; }

.confirm-tips {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    margin: 1.25rem 0 0.5rem;
    color: #475569;
    font-size: 0.85rem;
    line-height: 1.55;
}
.confirm-tips p { margin: 0 0 6px 0; color: #334155; font-size: 0.85rem; }
.confirm-tips ul { margin: 0; padding-left: 18px; }
.confirm-tips li { margin-bottom: 2px; }
.confirm-tips em { color: #334155; font-style: normal; font-weight: 500; }

.text-btn {
    display: block;
    width: 100%;
    margin-top: 12px;
    padding: 10px;
    background: transparent;
    border: 0;
    color: #0A6ED1;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    border-radius: 8px;
    transition: background 0.12s ease, color 0.12s ease;
}
.text-btn:hover { background: #f1f5f9; color: #085CAF; }

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
    .hint { margin-top: 24px; font-size: 0.875rem; }
}
</style>

<!-- Dark mode overrides (NOT scoped) -->
<style>
html[data-theme="dark"] .auth-grid { background: #1a1f24; }
html[data-theme="dark"] .auth-main { background: #1a1f24; }

@media (max-width: 767.98px) {
    html[data-theme="dark"] .auth-form-wrap {
        background: #1a1f24 !important;
        box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.4) !important;
    }
}

html[data-theme="dark"] .auth-form__header h1 { color: #e5e6e7; }
html[data-theme="dark"] .auth-form__header p  { color: #a8aaae; }
html[data-theme="dark"] .field-label          { color: #cbd5e1; }
html[data-theme="dark"] .back-link            { color: #a8aaae; }
html[data-theme="dark"] .back-link:hover      { color: #4db6e8; }
html[data-theme="dark"] .hint                 { color: #a8aaae; }

html[data-theme="dark"] .auth-form .ant-input-affix-wrapper,
html[data-theme="dark"] .auth-form .ant-input {
    background: #2c3034 !important;
    border-color: #3f4448 !important;
    color: #e5e6e7 !important;
}
html[data-theme="dark"] .auth-form .ant-input-affix-wrapper:hover,
html[data-theme="dark"] .auth-form .ant-input:hover {
    border-color: #4db6e8 !important;
}
html[data-theme="dark"] .auth-form .ant-input-affix-wrapper-focused,
html[data-theme="dark"] .auth-form .ant-input-affix-wrapper:focus-within {
    border-color: #4db6e8 !important;
    box-shadow: 0 0 0 3px rgba(77, 182, 232, 0.18) !important;
}
html[data-theme="dark"] .auth-form .ant-input-prefix       { color: #7c8390; }
html[data-theme="dark"] .auth-form .ant-input::placeholder { color: #6b7785; }

html[data-theme="dark"] .auth-footer { color: #6b7785; }

/* Panel de confirmación */
html[data-theme="dark"] .confirm-header strong { color: #e5e6e7; }
html[data-theme="dark"] .confirm-tips {
    background: #2c3034;
    border-color: #3f4448;
    color: #a8aaae;
}
html[data-theme="dark"] .confirm-tips p,
html[data-theme="dark"] .confirm-tips em { color: #cbd5e1; }
html[data-theme="dark"] .text-btn { color: #4db6e8; }
html[data-theme="dark"] .text-btn:hover { background: #313a44; color: #6cc7f0; }
</style>
