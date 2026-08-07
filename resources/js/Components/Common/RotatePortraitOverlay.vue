<script setup>
/**
 * RotatePortraitOverlay — overlay fullscreen que aparece cuando el dispositivo
 * está en landscape Y es pequeño (celular). En desktop (ancho ≥ 933px o alto
 * > 500px) NUNCA aparece, así PC siempre ve la app normal.
 *
 * 100% CSS via media queries — sin estado reactivo, sin listeners de resize.
 * El navegador re-evalúa la media query automáticamente al rotar y la oculta.
 *
 * Usage: incluir UNA vez en el layout root (AppLayout, AuthLayout).
 */
</script>

<template>
    <div class="rotate-overlay" aria-hidden="true">
        <div class="rotate-overlay__inner">
            <div class="rotate-overlay__icon">
                <svg viewBox="0 0 64 96" xmlns="http://www.w3.org/2000/svg" width="80" height="120" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="6" y="6" width="52" height="84" rx="8" />
                    <line x1="28" y1="78" x2="36" y2="78" />
                    <path d="M52 32 L52 24 L60 24 M58 28 a20 20 0 0 0 -38 6" stroke="#4db6e8" />
                </svg>
            </div>
            <h2 class="rotate-overlay__title">{{ $t('global.rotate_phone') }}</h2>
            <p class="rotate-overlay__msg">{{ $t('global.rotate_phone_hint') }}</p>
        </div>
    </div>
</template>

<style>
/* Por defecto oculto. Solo visible en celulares en landscape. */
.rotate-overlay { display: none; }

/* Detección de "celular en landscape":
 *   - orientation: landscape  → ancho > alto
 *   - max-height: 500px       → excluye tablets/laptops chicos
 *   - max-width: 932px        → cubre todos los iPhone modernos en landscape
 *                              (iPhone 14 Pro Max: 932×430)
 *   - hover: none + pointer: coarse → solo dispositivos táctiles
 *                              (descarta desktop con ventana redimensionada
 *                              y laptops convertibles con mouse conectado)
 *
 * En PC (1920×1080) NUNCA aparece (no es touch + tiene mucho alto).
 * En tablet (iPad landscape 1024×768) NUNCA aparece (alto > 500).
 * En iPhone landscape SIEMPRE aparece. */
@media (orientation: landscape) and (max-height: 500px) and (max-width: 932px) and (hover: none) and (pointer: coarse) {
    .rotate-overlay {
        display: flex;
        position: fixed;
        inset: 0;
        z-index: 99999;
        background: linear-gradient(160deg, #354A5F 0%, #1c2228 100%);
        color: #ffffff;
        align-items: center;
        justify-content: center;
        padding: 24px;
        animation: rotate-fade-in 0.18s ease-out;
    }
}

@keyframes rotate-fade-in {
    from { opacity: 0; }
    to   { opacity: 1; }
}

.rotate-overlay__inner {
    text-align: center;
    max-width: 320px;
}
.rotate-overlay__icon {
    color: #cbd5e1;
    margin-bottom: 16px;
    display: inline-flex;
    animation: rotate-tilt 1.6s ease-in-out infinite;
    transform-origin: center;
}
@keyframes rotate-tilt {
    0%, 100% { transform: rotate(-90deg); }
    50%      { transform: rotate(0deg); }
}

.rotate-overlay__title {
    font-size: 1.4rem;
    font-weight: 700;
    margin: 0 0 8px 0;
    color: #ffffff;
    letter-spacing: -0.01em;
}
.rotate-overlay__msg {
    font-size: 0.95rem;
    color: #cbd5e1;
    margin: 0;
    line-height: 1.5;
}
</style>
