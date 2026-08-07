import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { ZiggyVue } from 'ziggy-js';
import Antd from 'ant-design-vue';
import { ModuleRegistry, AllCommunityModule } from 'ag-grid-community';
import { autoAnimatePlugin } from '@formkit/auto-animate/vue';
import I18nPlugin from '@/Plugins/i18n';
import { setDiagnosticColors } from '@/utils/severity';

// Register AG Grid Community modules once for the whole app
ModuleRegistry.registerModules([AllCommunityModule]);

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        import.meta.glob('./Pages/**/*.vue', { eager: false })[`./Pages/${name}.vue`](),
    setup({ el, App, props, plugin }) {
        // Colores del diagnóstico (editables por super/tenant): aplica el override
        // antes de montar y en cada navegación (la prop compartida se revalúa).
        setDiagnosticColors(props.initialPage?.props?.diagnosticColors);
        router.on('navigate', (e) => setDiagnosticColors(e.detail?.page?.props?.diagnosticColors));
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(Antd)
            .use(autoAnimatePlugin)
            .use(I18nPlugin)
            .mount(el);
    },
    progress: {
        color: '#0A6ED1',
        showSpinner: true,
        // Sin delay: la barra aparece al instante para saber que el click registró.
        delay: 0,
    },
});

// ─── Feedback de navegación ─────────────────────────────────────────────────
// Mientras dura un visit de Inertia: el <body> entra en estado "navegando"
// (cursor de carga) y el elemento que se clickeó (link/botón) se atenúa y se
// bloquea para que NO se pueda re-clickear. Esto da feedback inmediato y evita
// el doble-click. (Inertia ya cancela el visit anterior si haces otro, así que
// clickear varias veces no encola ni hace más lento.)
let navEl = null;
document.addEventListener('mousedown', (e) => {
    navEl = (e.target instanceof HTMLElement) ? e.target.closest('a, button') : null;
}, true);
router.on('start', () => {
    document.body.classList.add('is-navigating');
    if (navEl) navEl.classList.add('is-navigating');
});
const endNavigating = () => {
    document.body.classList.remove('is-navigating');
    if (navEl) { navEl.classList.remove('is-navigating'); navEl = null; }
};
router.on('finish', endNavigating);

// Bloqueo global del menú contextual (click derecho).
// El usuario pidió desactivar el right-click en toda la app. Los inputs
// editables (Input/Textarea) NO se bloquean: el usuario debe poder usar
// "pegar/cortar/seleccionar todo" del menú nativo dentro de campos de
// texto. Para todo lo demás (botones, links, action buttons, tablas)
// se previene el contextmenu.
window.addEventListener('contextmenu', (e) => {
    const t = e.target;
    if (!t || !(t instanceof HTMLElement)) return;
    const editable = t.closest('input, textarea, [contenteditable="true"], [contenteditable=""]');
    if (editable) return;
    e.preventDefault();
});
