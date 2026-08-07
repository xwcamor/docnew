<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, useForm, Link, usePage } from '@inertiajs/vue3';
import { Input, Checkbox, Button, Alert, Select, SelectOption } from 'ant-design-vue';
import {
    MailOutlined, LockOutlined, EyeOutlined, EyeInvisibleOutlined,
    SafetyOutlined, GoogleOutlined,
    ExperimentOutlined, HeartOutlined, NodeIndexOutlined, SafetyCertificateOutlined,
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

// ─── Flota: 4 índices de salud animados en el hero ────────────────────────
// Cada anillo cuenta 0 → su valor (solo número, language-neutral) y su arco se
// llena en su color de semáforo: representa el estado de salud de la flota.
const FCIRC = 94.25; // 2π·15
const FLEET = [
    { cx: 222, cy: 48, color: '#3FBF6F', target: 92 }, // muy bueno
    { cx: 258, cy: 48, color: '#9BD64A', target: 76 }, // bueno
    { cx: 222, cy: 92, color: '#F0A23C', target: 54 }, // medio
    { cx: 258, cy: 92, color: '#F2554A', target: 28 }, // crítico
];
const fleetPct = ref(FLEET.map(() => 0));
const fleetDash = (i) => `${(fleetPct.value[i] / 100 * FCIRC).toFixed(1)} ${FCIRC}`;

onMounted(() => {
    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) { fleetPct.value = FLEET.map((f) => f.target); return; }
    FLEET.forEach((f, i) => {
        const t0 = performance.now(), dur = 1400, delay = i * 180;
        const step = (now) => {
            const p = Math.min(1, Math.max(0, (now - t0 - delay) / dur));
            fleetPct.value[i] = f.target * (1 - Math.pow(1 - p, 3)); // ease-out cúbico
            if (p < 1) requestAnimationFrame(step);
            else fleetPct.value[i] = f.target;
        };
        requestAnimationFrame(step);
    });
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
                    <svg viewBox="0 0 280 200" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <defs>
                            <linearGradient id="tr-tank" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="rgba(255,255,255,0.17)" />
                                <stop offset="100%" stop-color="rgba(255,255,255,0.05)" />
                            </linearGradient>
                            <linearGradient id="tr-arc" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="#7BD389" />
                                <stop offset="100%" stop-color="#4DB6E8" />
                            </linearGradient>
                            <filter id="tr-glow" x="-60%" y="-60%" width="220%" height="220%">
                                <feGaussianBlur stdDeviation="2.2" result="b" />
                                <feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge>
                            </filter>
                        </defs>

                        <!-- Radiadores (aletas de refrigeración) -->
                        <g stroke="rgba(255,255,255,0.22)" stroke-width="3" stroke-linecap="round">
                            <line x1="50" y1="104" x2="50" y2="150" />
                            <line x1="56" y1="104" x2="56" y2="150" />
                            <line x1="62" y1="104" x2="62" y2="150" />
                            <line x1="188" y1="104" x2="188" y2="150" />
                            <line x1="194" y1="104" x2="194" y2="150" />
                            <line x1="200" y1="104" x2="200" y2="150" />
                        </g>

                        <!-- Boquillas (bushings) -->
                        <rect x="83" y="62" width="10" height="28" rx="4" fill="rgba(255,255,255,0.18)" stroke="rgba(255,255,255,0.25)" />
                        <rect x="115" y="56" width="10" height="34" rx="4" fill="rgba(255,255,255,0.18)" stroke="rgba(255,255,255,0.25)" />
                        <rect x="147" y="62" width="10" height="28" rx="4" fill="rgba(255,255,255,0.18)" stroke="rgba(255,255,255,0.25)" />
                        <circle class="term" cx="88" cy="60" r="4.5" fill="#7fe0ff" filter="url(#tr-glow)" />
                        <circle class="term term-b" cx="120" cy="54" r="4.5" fill="#7fe0ff" filter="url(#tr-glow)" />
                        <circle class="term term-c" cx="152" cy="60" r="4.5" fill="#7fe0ff" filter="url(#tr-glow)" />
                        <g stroke="rgba(255,255,255,0.30)" stroke-width="1">
                            <line x1="82" y1="72" x2="94" y2="72" /><line x1="82" y1="80" x2="94" y2="80" />
                            <line x1="114" y1="66" x2="126" y2="66" /><line x1="114" y1="74" x2="126" y2="74" /><line x1="114" y1="82" x2="126" y2="82" />
                            <line x1="146" y1="72" x2="158" y2="72" /><line x1="146" y1="80" x2="158" y2="80" />
                        </g>

                        <!-- Arcos eléctricos entre boquillas (crepitan) -->
                        <g stroke="#cdeeff" stroke-width="1.6" fill="none" stroke-linejoin="round" stroke-linecap="round" filter="url(#tr-glow)">
                            <path class="arcA" d="M88,60 L96,52 L103,61 L112,50 L120,54" />
                            <path class="arcA2" d="M88,60 L95,56 L105,49 L113,58 L120,54" />
                            <path class="arcB" d="M120,54 L129,49 L135,61 L145,50 L152,60" />
                            <path class="arcB2" d="M120,54 L128,58 L138,49 L146,57 L152,60" />
                        </g>

                        <!-- Tanque -->
                        <rect x="58" y="88" width="134" height="78" rx="12" fill="url(#tr-tank)" stroke="rgba(255,255,255,0.20)" stroke-width="1.2" />
                        <!-- Placa de datos -->
                        <rect x="74" y="104" width="50" height="32" rx="5" fill="rgba(255,255,255,0.08)" stroke="rgba(255,255,255,0.16)" />
                        <rect x="80" y="110" width="32" height="3" rx="1.5" fill="rgba(255,255,255,0.40)" />
                        <rect x="80" y="118" width="38" height="2.5" rx="1.2" fill="rgba(255,255,255,0.22)" />
                        <rect x="80" y="126" width="24" height="2.5" rx="1.2" fill="rgba(255,255,255,0.18)" />
                        <circle cx="158" cy="150" r="6" fill="none" stroke="rgba(255,255,255,0.28)" stroke-width="2" />

                        <!-- Corriente entrando por las boquillas (flujo animado) -->
                        <g stroke="#9fe1ff" stroke-width="2" stroke-linecap="round" filter="url(#tr-glow)">
                            <line class="flow" x1="88" y1="64" x2="88" y2="100" />
                            <line class="flow flow-b" x1="120" y1="58" x2="120" y2="100" />
                            <line class="flow flow-c" x1="152" y1="64" x2="152" y2="100" />
                        </g>

                        <!-- Base + ruedas -->
                        <rect x="70" y="166" width="110" height="5" rx="2.5" fill="rgba(255,255,255,0.16)" />
                        <circle cx="88" cy="176" r="4" fill="rgba(255,255,255,0.20)" />
                        <circle cx="162" cy="176" r="4" fill="rgba(255,255,255,0.20)" />

                        <!-- Burbujas de gases disueltos -->
                        <g fill="#4DB6E8">
                            <circle class="bub" cx="150" cy="98" r="3" />
                            <circle class="bub bub-b" cx="160" cy="86" r="2.3" />
                            <circle class="bub bub-c" cx="168" cy="77" r="1.8" />
                        </g>

                        <!-- Flota: 4 índices de salud (semáforo), se llenan al cargar -->
                        <g v-for="(f, i) in FLEET" :key="i" :transform="`translate(${f.cx},${f.cy})`">
                            <circle r="15" fill="rgba(28,40,52,0.65)" />
                            <circle r="15" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="4" />
                            <circle class="gArc" r="15" fill="none" :stroke="f.color" stroke-width="4" stroke-linecap="round" :stroke-dasharray="fleetDash(i)" transform="rotate(-90)" />
                            <text x="0" y="3.5" text-anchor="middle" font-size="10" font-weight="700" fill="#fff" font-family="-apple-system,Segoe UI,Roboto,sans-serif">{{ Math.round(fleetPct[i]) }}</text>
                        </g>
                    </svg>
                </div>

                <ul class="login-brand__features">
                    <li><ExperimentOutlined /><span>{{ $t('auth.feature_tests') }}</span></li>
                    <li><HeartOutlined /><span>{{ $t('auth.feature_health') }}</span></li>
                    <li><NodeIndexOutlined /><span>{{ $t('auth.feature_methods') }}</span></li>
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
                        <a :href="route('report.verify')" target="_blank" rel="noopener" class="link-sm">{{ $t('auth.verify_report') }}</a>
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
@keyframes hero-flickA { 0%,18%,22%,100%{opacity:.12} 20%,54%,58%{opacity:1} 56%{opacity:.4} }
@keyframes hero-flickB { 0%,48%,52%,100%{opacity:.12} 50%,84%,88%{opacity:1} 86%{opacity:.4} }
@keyframes hero-pulseT { 0%,100%{opacity:.5} 50%{opacity:1} }
@keyframes hero-flow   { to{stroke-dashoffset:-24} }
@keyframes hero-rise   { 0%{transform:translateY(0);opacity:0} 15%{opacity:.9} 100%{transform:translateY(-18px);opacity:0} }
@keyframes hero-gfill  { from{stroke-dashoffset:137.2} to{stroke-dashoffset:0} }
@keyframes hero-gglow  { 0%,100%{opacity:.82} 50%{opacity:1} }
.login-brand__hero :deep(.arcA)  { animation: hero-flickA 1.7s infinite; }
.login-brand__hero :deep(.arcA2) { animation: hero-flickA 1.7s infinite .35s; }
.login-brand__hero :deep(.arcB)  { animation: hero-flickB 2s infinite; }
.login-brand__hero :deep(.arcB2) { animation: hero-flickB 2s infinite .45s; }
.login-brand__hero :deep(.term)   { animation: hero-pulseT 1.8s ease-in-out infinite; }
.login-brand__hero :deep(.term-b) { animation-delay: .6s; }
.login-brand__hero :deep(.term-c) { animation-delay: 1.2s; }
.login-brand__hero :deep(.flow)   { stroke-dasharray: 3 9; animation: hero-flow 1.1s linear infinite; }
.login-brand__hero :deep(.flow-b) { animation-delay: .3s; }
.login-brand__hero :deep(.flow-c) { animation-delay: .6s; }
.login-brand__hero :deep(.bub)    { animation: hero-rise 3.2s ease-in infinite; }
.login-brand__hero :deep(.bub-b)  { animation-delay: 1.1s; }
.login-brand__hero :deep(.bub-c)  { animation-delay: 2.1s; }
.login-brand__hero :deep(.gArc)   { animation: hero-gglow 2.6s ease-in-out 1.5s infinite; }
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
