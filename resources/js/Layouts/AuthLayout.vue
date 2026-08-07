<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { ConfigProvider, theme, Dropdown, Menu, MenuItem } from 'ant-design-vue';
import { DesktopOutlined, CheckOutlined } from '@ant-design/icons-vue';
import RotatePortraitOverlay from '@/Components/Common/RotatePortraitOverlay.vue';

/**
 * AuthLayout — minimal layout for unauthenticated pages (login, forgot, reset).
 *
 * Includes a theme switcher (auto / light / dark) in the bottom-right corner.
 * Choice persists in localStorage `authTheme` so login → reset → forgot
 * keeps the same theme across the auth flow.
 */

// ─── Inline SVG icons (Lucide style — no extra dependency) ────────────────
const sunSvg  = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>`;
const moonSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>`;

// ─── Theme state ───────────────────────────────────────────────────────────
const THEME_STORAGE = 'authTheme';

const themeMode = ref('auto');                  // 'auto' | 'light' | 'dark'
let mql = null;
const systemPrefersDark = ref(false);
const mqlHandler = (e) => { systemPrefersDark.value = e.matches; };

const effectiveTheme = computed(() => {
    if (themeMode.value === 'auto') return systemPrefersDark.value ? 'dark' : 'light';
    return themeMode.value;
});

const isDark = computed(() => effectiveTheme.value === 'dark');

// Lee tokens de app.css en runtime — única fuente de verdad de colores de marca.
const readCssToken = (name, fallback) => {
    if (typeof document === 'undefined') return fallback;
    const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
};

// Reactive Ant Design config — switches algorithm when isDark changes.
const antdTheme = computed(() => {
    isDark.value; // dependency para re-leer CSS al cambiar tema
    return {
        algorithm: isDark.value ? theme.darkAlgorithm : theme.defaultAlgorithm,
        token: {
            colorPrimary: readCssToken('--color-primary', '#0A6ED1'),
            colorLink:    readCssToken('--color-primary', '#0A6ED1'),
            borderRadius: 6,
            fontFamily:   '"Inter", "Source Sans Pro", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
        },
    };
});

const applyTheme = () => {
    document.documentElement.setAttribute('data-theme', effectiveTheme.value);
    // Update <meta name="theme-color"> for mobile browsers/PWAs.
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', isDark.value ? '#1a1f24' : '#354A5F');
};

watch(effectiveTheme, applyTheme);

const setTheme = (mode) => {
    themeMode.value = mode;
    try { localStorage.setItem(THEME_STORAGE, mode); } catch (e) {}
};

onMounted(() => {
    // Restore saved preference
    try {
        const saved = localStorage.getItem(THEME_STORAGE);
        if (saved === 'auto' || saved === 'light' || saved === 'dark') {
            themeMode.value = saved;
        }
    } catch (e) {}

    // Watch system preference for "auto" mode
    if (window.matchMedia) {
        mql = window.matchMedia('(prefers-color-scheme: dark)');
        systemPrefersDark.value = mql.matches;
        mql.addEventListener('change', mqlHandler);
    }

    applyTheme();
});

onBeforeUnmount(() => {
    if (mql) mql.removeEventListener('change', mqlHandler);
});
</script>

<template>
    <ConfigProvider :theme="antdTheme">
        <!-- Overlay para "rotá el celu" — solo aparece en celulares en landscape. -->
        <RotatePortraitOverlay />
        <div class="auth-shell">
            <slot />

            <!-- Floating theme switcher — dropdown with 3 explicit options.
                 Sun & Moon are inline Lucide-style SVGs since Ant Design Vue
                 doesn't ship SunOutlined/MoonOutlined. -->
            <Dropdown :trigger="['click']" placement="topRight" overlayClassName="auth-theme-overlay">
                <button
                    type="button"
                    class="theme-toggle"
                    :class="{ 'theme-toggle--dark': isDark }"
                    aria-label="Cambiar tema"
                >
                    <DesktopOutlined v-if="themeMode === 'auto'" />
                    <span v-else class="theme-toggle__svg" v-html="themeMode === 'light' ? sunSvg : moonSvg" />
                </button>
                <template #overlay>
                    <Menu @click="({ key }) => setTheme(key)" :selectedKeys="[themeMode]" class="theme-menu">
                        <MenuItem key="auto">
                            <DesktopOutlined /> <span>Automático</span>
                            <CheckOutlined v-if="themeMode === 'auto'" class="check" />
                        </MenuItem>
                        <MenuItem key="light">
                            <span class="menu-svg" v-html="sunSvg" /> <span>Claro</span>
                            <CheckOutlined v-if="themeMode === 'light'" class="check" />
                        </MenuItem>
                        <MenuItem key="dark">
                            <span class="menu-svg" v-html="moonSvg" /> <span>Oscuro</span>
                            <CheckOutlined v-if="themeMode === 'dark'" class="check" />
                        </MenuItem>
                    </Menu>
                </template>
            </Dropdown>
        </div>
    </ConfigProvider>
</template>

<style>
/* Global font + dark mode background for auth pages.
   overflow-x: hidden defends against any element accidentally pushing past
   the viewport width (which would create a horizontal scroll on mobile and
   make the layout look off-center). */
.auth-shell {
    font-family: "Inter", "Source Sans Pro", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: #1f2937;
    min-height: 100vh;
    background: #fff;
    overflow-x: hidden;
    width: 100%;
    max-width: 100vw;
}
* { box-sizing: border-box; }
html[data-theme="dark"] .auth-shell {
    color: #e5e6e7;
    background: #1a1f24;
}

/* ── Floating theme toggle ───────────────────────────────────────────────── */
.theme-toggle {
    position: fixed;
    bottom: calc(env(safe-area-inset-bottom, 0px) + 16px);
    right: 16px;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, 0.18);
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: transform 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
    z-index: 1050;
    font-size: 1rem;
}

/* Mobile: move to top-right inside the navy header (no clash with form) */
@media (max-width: 767.98px) {
    .theme-toggle {
        top: calc(env(safe-area-inset-top, 0px) + 16px);
        right: 16px;
        bottom: auto;
    }
}

/* Desktop: solid white button on the white form panel */
@media (min-width: 768px) {
    .theme-toggle {
        background: #ffffff;
        border: 1px solid rgba(15, 23, 42, 0.08);
        color: #475569;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.15);
        backdrop-filter: none;
    }
}
.theme-toggle:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.22);
}
.theme-toggle__svg {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.theme-toggle__svg svg {
    width: 18px;
    height: 18px;
}
.menu-svg {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 14px;
    height: 14px;
}
.menu-svg svg { width: 14px; height: 14px; }
.theme-toggle--dark {
    background: #2c3034;
    color: #e5e6e7;
    border-color: rgba(255, 255, 255, 0.08);
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4);
}
.theme-toggle--dark:hover {
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.5);
}

/* Theme menu (Dropdown overlay) */
.auth-theme-overlay .ant-dropdown-menu {
    border-radius: 10px !important;
    padding: 6px !important;
    min-width: 180px;
    box-shadow: 0 8px 28px rgba(15, 23, 42, 0.18) !important;
}
.theme-menu .ant-dropdown-menu-item {
    border-radius: 6px !important;
    padding: 8px 12px !important;
    font-size: 0.875rem;
}
/* Ant Design envuelve el contenido del MenuItem en .ant-dropdown-menu-title-content;
   ahí va el flex para que margin-left:auto del check funcione. */
.theme-menu .ant-dropdown-menu-item .ant-dropdown-menu-title-content {
    display: flex !important;
    align-items: center;
    width: 100%;
}
.theme-menu .ant-dropdown-menu-title-content > .anticon,
.theme-menu .ant-dropdown-menu-title-content > .menu-svg {
    margin-right: 10px;
    flex-shrink: 0;
}
.theme-menu .ant-dropdown-menu-title-content > .check {
    margin-left: auto;
    margin-right: 0;
    color: #0A6ED1;
    font-size: 0.8rem;
}
.theme-menu .ant-dropdown-menu-item-selected {
    background: rgba(10, 110, 209, 0.08) !important;
    color: #0A6ED1 !important;
    font-weight: 500;
}
</style>
