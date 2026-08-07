<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, provide } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import {
    Layout, LayoutHeader, LayoutSider, LayoutContent,
    Menu, MenuItem, MenuDivider, MenuItemGroup, SubMenu,
    Avatar, Dropdown, Drawer, Badge, Tooltip, Alert, Tag,
    ConfigProvider, theme, Modal, Button, Checkbox,
    message, notification,
} from 'ant-design-vue';
// Locales nativos de Ant Design (tooltip de orden, paginación, date pickers,
// "vacío", etc.). Sin esto Ant cae a inglés en TODOS los módulos.
import esES from 'ant-design-vue/es/locale/es_ES';
import enUS from 'ant-design-vue/es/locale/en_US';
import { h } from 'vue';
import RotatePortraitOverlay from '@/Components/Common/RotatePortraitOverlay.vue';
import GlobalSearch from '@/Components/GlobalSearch.vue';
import TransformerIcon from '@/Components/Transformers/TransformerIcon.vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();
import {
    DashboardOutlined,
    GlobalOutlined,
    UserOutlined,
    SettingOutlined,
    BankOutlined, CrownOutlined,
    LogoutOutlined,
    HistoryOutlined,
    DownOutlined,
    MenuOutlined,
    PicLeftOutlined,
    PicCenterOutlined,
    BellOutlined,
    MailOutlined,
    DesktopOutlined,
    TranslationOutlined,
    FlagOutlined,
    ClockCircleOutlined,
    ReadOutlined,
    AuditOutlined,
    AppstoreOutlined,
    TeamOutlined, BgColorsOutlined, BlockOutlined, FileDoneOutlined,
    ControlOutlined,
    ExperimentOutlined,
    RadarChartOutlined,
    IdcardOutlined,
    SafetyCertificateOutlined,
    CheckOutlined,
    DownloadOutlined,
    DeleteOutlined,
    FileExcelOutlined,
    FilePdfOutlined,
    FileWordOutlined,
    FileOutlined,
    LoadingOutlined,
    CloseCircleFilled,
    ShoppingOutlined,
    ShopOutlined,
    TagsOutlined,
    ThunderboltOutlined, NotificationOutlined,
    InboxOutlined,
    MessageOutlined,
    SolutionOutlined,
    LineChartOutlined,
    BarChartOutlined,
    ShareAltOutlined,
} from '@ant-design/icons-vue';

import { usePlanFeatures } from '@/Composables/usePlanFeatures';
const { canUse: canUsePlanFeature } = usePlanFeatures();

// Inline SVG icons (Lucide-style) — Ant Design Vue no incluye Sun/Moon outlined.
// Reutilizamos el mismo set del AuthLayout para que el switcher se vea idéntico
// dentro y fuera de la sesión.
const sunSvg  = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>`;
const moonSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>`;

const page = usePage();

const user    = computed(() => page.props.auth?.user);
const appName = computed(() => page.props.app?.name ?? 'TR Health');
const appLogo = computed(() => page.props.appLogoUrl || null);
const locale  = computed(() => page.props.locale ?? 'es');
// Locale nativo de Ant Design según el idioma activo (orden, paginación, etc.).
const antLocale = computed(() => (locale.value === 'en' ? enUS : esES));

// ── Aceptación de Términos/Privacidad (LPDP) ──────────────────────────────
// Modal bloqueante cuando la versión vigente no fue aceptada por el usuario
// (primera sesión, o el super subió legal.terms_version). El POST registra
// versión + fecha + IP en BD y audit log — consentimiento demostrable.
const legalPending = computed(() => page.props.legal && !page.props.legal.accepted);
const legalChecked = ref(false);
const legalSaving = ref(false);
const acceptLegal = () => {
    if (!legalChecked.value || legalSaving.value) return;
    legalSaving.value = true;
    router.post(route('legal.accept'), {}, {
        preserveScroll: true, preserveState: true,
        onFinish: () => { legalSaving.value = false; },
    });
};

// Para mostrar el badge del tenant en notifs de automation cuando el receptor
// es super (necesita distinguir de que workspace viene). Admin no necesita
// el badge porque solo ve las de su propio tenant.
const isSuperUser = computed(() => (page.props.auth?.user?.roles ?? []).includes('super'));

// Flash messages → toast.
// 2 defensas combinadas:
// 1. Backend `pull()` consume el flash, garantiza que solo viene la primera vez.
// 2. Frontend compara contra oldValue para no mostrar el mismo toast dos veces
//    en caso de partial reload con el mismo flash (defensive).
// `immediate: true` necesario para mostrar el toast del request post-redirect
// donde AppLayout se monta con el flash ya populated.
// Disparamos el toast cuando llega un flash y lo CONSUMIMOS (lo seteamos a
// null en el state reactivo). Asi, si el usuario provoca el MISMO error dos
// veces seguidas, el watch vuelve a disparar porque el valor paso por null
// entre medio. Sin esto, el watch comparaba string === string y silenciaba
// el segundo toast.
watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) return;
        if (flash.success) {
            message.success(flash.success);
            flash.success = null;
        }
        if (flash.error) {
            message.error(flash.error);
            flash.error = null;
        }
    },
    { deep: true, immediate: true },
);

// ─── Notifications bell (inbox) ──────────────────────────────────────────
// El backend comparte `page.props.inbox` con { recent[], unread, processing }.
// Usamos `inbox` (no `notifications`) para evitar colisión con el page-prop
// `notifications` que el listado completo usa.
//
// Cada item del recent[] tiene un `kind` (download/task/alert/etc.) — hoy
// solo download. El badge muestra `unread`; el polling auto-refresca cada
// 8s mientras haya jobs en `processing`.
// Overrides optimistas: cuando el user marca una notif app como leida desde
// el bell, no esperamos el round-trip al server para bajar el badge — el
// numero cae al instante y los items aparecen como read. Cuando llega un
// `inbox` nuevo del backend (refreshInbox), los overrides se resetean porque
// el server ya tiene el estado autoritativo.
const locallyReadAppIds = ref(new Set());
const localUnreadOffset = ref(0);

const DEFAULT_INBOX = { recent: [], unread: 0, processing: 0, unread_messages: 0, messages: [] };
// Estado local del inbox: arranca con el SSR (page.props.inbox) y se actualiza
// por (a) navegación Inertia y (b) el poll a /inbox/poll (axios). NO usamos
// router.reload para esto — pollear datos no debe re-renderizar la página (eso
// reposicionaba el panel del bell). El server es autoritativo, así que al llegar
// datos frescos se descartan los overrides optimistas.
const inboxData = ref(page.props.inbox ?? { ...DEFAULT_INBOX });

const applyInbox = (data) => {
    inboxData.value = data ?? { ...DEFAULT_INBOX };
    locallyReadAppIds.value = new Set();
    localUnreadOffset.value = 0;
};

const inbox = computed(() => {
    const raw = inboxData.value ?? DEFAULT_INBOX;
    return {
        ...raw,
        unread: Math.max(0, (raw.unread ?? 0) - localUnreadOffset.value),
        recent: (raw.recent ?? []).map(item =>
            item.kind === 'app' && locallyReadAppIds.value.has(item.raw_id)
                ? { ...item, status: 'read' }
                : item
        ),
    };
});

// Una navegación Inertia trae un inbox fresco en page.props → lo sincronizamos.
watch(() => page.props.inbox, (val) => { if (val) applyInbox(val); }, { deep: false });

// Preview de los últimos 5 mensajes (leídos o no) para el dropdown del sobre.
// Separado del recent[] del bell — los dos iconos son distintos:
//   - Bell (BellOutlined): notificaciones de SISTEMA (downloads + automations)
//   - Sobre (MailOutlined): MENSAJES del módulo Communication
const messagesInBell = computed(() => inbox.value.messages ?? []);

const goToInboxMessage = (m) => {
    router.visit(route('communication.inbox.show', m.slug));
};

// Banner global de suscripción — solo se renderiza si el backend lo manda
// (días_restantes <= 7 OR trial). super nunca lo ve.
const subscriptionWarning = computed(() => page.props.subscription ?? null);

// Refresco del bell por fetch liviano (axios) — sin router.reload, sin
// re-render de la página. Esto es lo que evita que el panel se reposicione.
let inboxFetching = false;
const refreshInbox = async () => {
    if (inboxFetching) return;
    inboxFetching = true;
    try {
        const { data } = await window.axios.get(route('communication.inbox.poll'));
        applyInbox(data);
    } catch (_) { /* silencioso: es polling de fondo */ }
    finally { inboxFetching = false; }
};

// Recientes — los últimos N registros vistos por el usuario, vienen del
// shared prop `recentViews` que pobla HandleInertiaRequests. Cada item ya
// trae { id, name, module, url }.
const recentViews = computed(() => page.props.recentViews ?? []);
const goToRecent = (item) => {
    if (item?.url) router.visit(item.url);
};

const goToProfile = () => router.visit(route('profile.show'));
const goToProfilePrefs = () => router.visit(route('profile.show') + '#preferences');

// Mientras un panel (bell o mensajes) está abierto, se PAUSA el polling: el
// reload de cada N segundos re-renderiza el overlay y Ant lo reposiciona con su
// animación — eso era el "movimiento random / se abre y cierra" del panel,
// notorio en mobile. Con el panel abierto los datos quedan congelados (estaban
// frescos del último poll, ≤Ns) y no hay reload que lo mueva.
const inboxPanelOpen = ref(false);
const onInboxPanelOpen = (open) => { inboxPanelOpen.value = open; };
let inboxPollTimer = null;
const startInboxPolling = () => {
    if (inboxPollTimer) return;
    // El intervalo lo lee desde el setting `notifications.poll_interval_seconds`
    // que el backend comparte como prop. Default 4s. Configurable desde el
    // modulo Settings sin redeploy. Clamp a [1, 60] por seguridad.
    const fromSettings = Number(page.props.notificationsPollInterval ?? 4);
    const seconds = Math.min(60, Math.max(1, Number.isFinite(fromSettings) ? fromSettings : 4));
    inboxPollTimer = setInterval(() => {
        // No pollear con el panel abierto ni con la pestaña oculta (sin foco).
        if (inboxPanelOpen.value || document.hidden) return;
        refreshInbox();
    }, seconds * 1000);
};
const stopInboxPolling = () => {
    if (inboxPollTimer) {
        clearInterval(inboxPollTimer);
        inboxPollTimer = null;
    }
};

// Polling SIEMPRE activo mientras la pagina este montada. Antes solo arrancaba
// con downloads en `processing` — pero eso significa que si llegaba una notif
// app (automation ejecutada, alerta de seguridad), el bell no la veia hasta
// que el user abria el dropdown manualmente. Ahora el badge se actualiza solo
// cada N segundos (setting `notifications.poll_interval_seconds`, default 4s).
//
// El user puede subirlo a 60s desde Settings si le preocupa el trafico.
startInboxPolling();

// ── Toast notifications cuando un download cambia de estado ──────────────
// Track de los IDs ya conocidos en cada bucket. En la PRIMERA observación
// solo registramos (sin toast) para no inundar al usuario al cargar la
// página con cosas que ya sabía. De ahí en adelante, comparamos con la
// observación previa y disparamos toast por cada nueva transición.
const knownReadyIds      = new Set();
const knownFailedIds     = new Set();
let firstInboxObservation = true;

watch(
    () => inbox.value.recent,
    (items) => {
        if (!Array.isArray(items)) return;

        const currentReadyIds  = new Set(items.filter(n => n.status === 'ready'  && !n.downloaded_at).map(n => n.id));
        const currentFailedIds = new Set(items.filter(n => n.status === 'failed').map(n => n.id));

        if (!firstInboxObservation) {
            // Recién listos
            for (const id of currentReadyIds) {
                if (knownReadyIds.has(id)) continue;
                const n = items.find(x => x.id === id);
                if (!n) continue;
                notification.success({
                    message: t('global.download_ready'),
                    description: n.filename,
                    placement: 'topRight',
                    duration: 8,
                    btn: () => h('button', {
                        onClick: () => triggerDownload(n),
                        style: 'background:#0A6ED1;color:#fff;border:0;padding:6px 14px;border-radius:4px;cursor:pointer;font-weight:500;font-size:0.8rem;',
                    }, t('notifications.download')),
                });
            }
            // Recién fallados
            for (const id of currentFailedIds) {
                if (knownFailedIds.has(id)) continue;
                const n = items.find(x => x.id === id);
                if (!n) continue;
                notification.error({
                    message: t('global.download_failed'),
                    description: (n.error_message || n.filename || t('global.unknown_error')),
                    placement: 'topRight',
                    duration: 10,
                });
            }
        }

        // Actualizamos los conocidos para la próxima comparación.
        knownReadyIds.clear();
        currentReadyIds.forEach(id => knownReadyIds.add(id));
        knownFailedIds.clear();
        currentFailedIds.forEach(id => knownFailedIds.add(id));

        firstInboxObservation = false;
    },
    { deep: true, immediate: true },
);

// Helpers de UI por tipo de archivo (solo aplica al kind 'download')
const downloadFileIcon = (type) => {
    switch (type) {
        case 'excel': return { icon: FileExcelOutlined, color: '#1D7044' };
        case 'pdf':   return { icon: FilePdfOutlined,   color: '#C8281D' };
        case 'word':  return { icon: FileWordOutlined,  color: '#185ABD' };
        default:      return { icon: FileOutlined,      color: '#6A6D70' };
    }
};

const downloadStatusLabel = (status) => {
    switch (status) {
        case 'processing': return t('notifications.status_processing');
        case 'ready':      return t('notifications.status_ready');
        case 'failed':     return t('notifications.status_failed');
        case 'expired':    return t('notifications.status_expired');
        default:           return status;
    }
};

const triggerDownload = (n) => {
    if (n.kind !== 'download' || n.status !== 'ready') return;
    window.location.href = route('notifications.download', n.id);
    // Optimistic refresh para que el badge baje al instante.
    setTimeout(refreshInbox, 800);
};

const dismissNotification = (n) => {
    router.delete(
        route('notifications.delete', n.id),
        { preserveScroll: true, preserveState: true, onFinish: refreshInbox },
    );
};

// Marca una notificacion `kind:'app'` como leida sin navegar a otra pagina.
// Optimistic update: actualiza el estado local AL INSTANTE (badge baja, item
// pasa a read) y dispara el POST en background. Cuando refreshInbox trae el
// payload nuevo, los overrides locales se descartan en el watcher.
const markAppNotificationRead = (n) => {
    if (n.kind !== 'app' || n.status !== 'unread') return;
    if (locallyReadAppIds.value.has(n.raw_id)) return;

    locallyReadAppIds.value.add(n.raw_id);
    localUnreadOffset.value++;

    router.post(
        route('notifications.app.read', n.raw_id),
        {},
        { preserveScroll: true, preserveState: true, onFinish: refreshInbox },
    );
};

// Icono y color por categoria de notif app — fallback a BellOutlined.
// Para automations distinguimos channel: si es 'email' usamos icono de
// sobre (confirmacion de envio); si es 'in_app' usamos megafono
// (notificacion interna del sistema — semanticamente correcto).
// El rayo queda reservado para el item del sidebar "Automatizaciones".
const appNotifIcon = (n) => {
    if (n.type === 'automation') {
        return n.channel === 'email' ? MailOutlined : NotificationOutlined;
    }
    const map = {
        security:    BellOutlined,
        plan_change: BellOutlined,
    };
    return map[n.type] ?? BellOutlined;
};
const appNotifColor = (n) => {
    if (n.type === 'automation') {
        return n.channel === 'email' ? '#1677ff' : '#fa8c16';
    }
    const map = {
        security:    '#cf1322',
        plan_change: '#1677ff',
    };
    return map[n.type] ?? '#0A6ED1';
};

const goToNotificationsPage = () => {
    router.visit(route('notifications.index'));
};

// Responsive
const isMobile = ref(false);
const checkMobile = () => { isMobile.value = window.innerWidth < 992; };
// Al volver a la pestaña (estuvo oculta), refrescamos el bell una vez para
// ponerlo al día sin esperar al próximo tick del poll.
const onVisibility = () => { if (!document.hidden) refreshInbox(); };
onMounted(() => {
    checkMobile();
    window.addEventListener('resize', checkMobile);
    document.addEventListener('visibilitychange', onVisibility);
});
onBeforeUnmount(() => {
    window.removeEventListener('resize', checkMobile);
    document.removeEventListener('visibilitychange', onVisibility);
    stopInboxPolling();
});

const collapsed   = ref(false);
const drawerOpen  = ref(false);
const toggleSidebar = () => {
    // Móvil o modo 'arriba' (GitHub): abre/cierra el Drawer con overlay.
    // Modo 'side' en desktop: colapsa/expande el sidebar fijo.
    if (isMobile.value || navMode.value === 'top') drawerOpen.value = !drawerOpen.value;
    else                                           collapsed.value  = !collapsed.value;
};
// Expuesto para que páginas con mucho contenido (ej. detalle de trafo) puedan
// colapsar el sidebar al entrar y restaurarlo al salir.
provide('sidebarCollapsed', collapsed);

// Active menu key — uses Inertia's reactive page.url so the highlight follows
// SPA navigation (window.location.pathname is NOT reactive in Vue/Inertia).
// Order matters: more specific patterns first (e.g. system_modules before users).
const selectedKey = computed(() => {
    const url = page.url ?? '';
    // Comparación: 2 páginas independientes con paths distintos.
    if (url.includes('/comparison/patrones')) return 'cmp_patrones';
    if (url.includes('/comparison/gases')) return 'cmp_gases';
    const matchers = [
        ['audit_logs',     '/audit_logs'],
        ['system_modules', '/system_modules'],
        ['automations',    '/automations'],
        ['messages',       '/communication/messages'],
        ['inbox',          '/communication/inbox'],
        ['tenants',        '/tenants'],
        ['plans',          '/plans'],
        ['regions',        '/regions'],
        ['languages',      '/languages'],
        ['countries',      '/countries'],
        ['locales',        '/locales'],
        ['settings',       '/settings'],
        ['workspace',      '/workspace'],
        ['my_requests',    '/my-requests'],
        ['approvals',      '/approvals'],
        ['diagnostic_rules', '/diagnostic-rules'],
        ['tools-duval',    '/tools/duval'],
        ['roles',          '/roles'],
        ['users',          '/users'],
        ['customers',          '/customers'],
        ['transformers',       '/transformers'],
        ['oil_types',          '/oil_types'],
        ['brands',             '/brands'],
        ['transformer_types',  '/transformer_types'],
        ['tap_changer_types',  '/tap_changer_types'],
        ['tap_changer_brands',       '/tap_changer_brands'],
        ['tap_changer_models',       '/tap_changer_models'],
        ['tap_changer_technologies', '/tap_changer_technologies'],
        ['laboratories',       '/laboratories'],
        ['dashboard',      '/dashboard_management/dashboards'],
        ['dashboard',      '/dashboard'],  // legacy fallback
    ];
    for (const [key, pattern] of matchers) {
        if (url.includes(pattern)) return key;
    }
    return '';
});

// Theme switcher
const themeMode = ref('auto');
let mql = null;

const effectiveTheme = computed(() => {
    if (themeMode.value === 'auto') return mql && mql.matches ? 'dark' : 'light';
    return themeMode.value;
});

const applyTheme = () => {
    document.documentElement.setAttribute('data-theme', effectiveTheme.value);
    document.body.classList.toggle('dark-mode', effectiveTheme.value === 'dark');
};

const setTheme = (mode) => {
    themeMode.value = mode;
    try { localStorage.setItem('theme-mode', mode); } catch (e) {}
    applyTheme();
};

// El ícono visible depende del MODO seleccionado (no del tema efectivo):
// auto → monitor, light → sol, dark → luna. Así el botón comunica la elección.
const themeIconSvg = computed(() => {
    if (themeMode.value === 'light') return sunSvg;
    if (themeMode.value === 'dark')  return moonSvg;
    return null;
});

// Ant Design Vue theme config — lee los tokens de app.css en runtime para
// mantener UNA sola fuente de verdad de colores de marca. Si cambias
// `--color-primary` en app.css, los botones de Ant Design (Crear, Guardar,
// Restaurar, etc.) reflejan el cambio sin tocar este archivo.
//
// `effectiveTheme.value` está como dependencia para que el computed se
// re-evalúe al cambiar tema y getComputedStyle lea los nuevos valores del
// bloque `html[data-theme="dark"]`.
const readCssToken = (name, fallback) => {
    if (typeof document === 'undefined') return fallback;
    const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
};

// ─── Apariencia (esquema de color + posición de menú) ──────────────────────
// Fuente de verdad: la BD (auth.user), compartida por HandleInertiaRequests.
// Se aplican al cargar y se re-sincronizan si cambian (ej. al guardar en el
// perfil). El esquema cambia --color-primary vía data-scheme; Ant lo re-lee.
const NAV_MODES = ['top', 'side', 'bottom'];
const uiScheme = ref(user.value?.ui_scheme || 'sap');
const navMode  = ref(NAV_MODES.includes(user.value?.nav_position) ? user.value.nav_position : 'top');

const applyScheme = () => {
    if (typeof document === 'undefined') return;
    if (uiScheme.value && uiScheme.value !== 'sap') {
        document.documentElement.setAttribute('data-scheme', uiScheme.value);
    } else {
        document.documentElement.removeAttribute('data-scheme');
    }
};
watch(uiScheme, applyScheme, { immediate: true });
// Re-sync desde la BD cuando cambian las props (tras guardar en el perfil).
watch(() => user.value?.ui_scheme, (v) => { if (v) uiScheme.value = v; });
watch(() => user.value?.nav_position, (v) => { if (NAV_MODES.includes(v)) navMode.value = v; });

const antdTheme = computed(() => {
    const isDark = effectiveTheme.value === 'dark';
    uiScheme.value; // dependencia: al cambiar de esquema, re-lee los tokens CSS.
    return {
        algorithm: isDark ? theme.darkAlgorithm : theme.defaultAlgorithm,
        token: {
            colorPrimary:   readCssToken('--color-primary',   isDark ? '#4db6e8' : '#0A6ED1'),
            colorError:     readCssToken('--color-danger',    '#BB0000'),
            colorWarning:   readCssToken('--color-warning',   '#f59e0b'),
            colorTextBase:  readCssToken('--color-text',      isDark ? '#e5e6e7' : '#32363A'),
            borderRadius:   4,
            fontFamily:     "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
        },
    };
});

// Provide effective theme to descendants (AG Grid, custom components)
provide('theme', effectiveTheme);

onMounted(() => {
    try { themeMode.value = localStorage.getItem('theme-mode') || 'auto'; } catch (e) {}
    mql = window.matchMedia('(prefers-color-scheme: dark)');
    applyTheme();
    const handler = () => { if (themeMode.value === 'auto') applyTheme(); };
    mql.addEventListener ? mql.addEventListener('change', handler) : mql.addListener(handler);
});

// Languages — viene del shared prop `availableLocales` (intersección de
// laravellocalization.supportedLocales ∩ Language::active). Single source of
// truth: lo que active super desde el módulo Languages.
const languages = computed(() => page.props.availableLocales ?? []);

const switchLang = (code) => {
    // Strippeamos cualquier prefijo de locale soportado.
    const validCodes = languages.value.map(l => l.code).join('|');
    const re = new RegExp('^/(' + validCodes + ')(/|$)');
    const path = window.location.pathname.replace(re, '/');
    window.location.href = `/${code}${path === '/' ? '/' : path}${window.location.search}`;
};

// Logout
const logout = () => router.post(route('logout'));

// Manual de uso (HTML estático servido en /manual) — se abre en pestaña nueva
// para no perder el contexto de trabajo.
const openManual = () => window.open(route('manual'), '_blank', 'noopener');

// ─── Permission/role helpers ───────────────────────────────────────────────
// Mirrors backend Gate::before — super always passes.
const can = (perm) => {
    const u = page.props.auth?.user;
    if (!u) return false;
    if (u.roles?.includes('super')) return true;
    return u.permissions?.includes(perm) ?? false;
};
const hasRole = (...names) => {
    const userRoles = page.props.auth?.user?.roles ?? [];
    return names.some(n => userRoles.includes(n));
};

// Sidebar structure — sections (groups) with items inside.
// Each item declares a `visible` predicate. Disabled items render greyed out
// with a "coming soon" tooltip (used for routes not built yet).
const menuStructure = computed(() => [
    // ── Dashboard (primer item del sidebar, arriba de los grupos) ─────────
    {
        kind: 'item',
        key: 'dashboard', label: t('sidebar.dashboard'), icon: DashboardOutlined,
        href: route('dashboard_management.dashboards.index'), inertia: true,
        visible: () => true,
    },

    // ── Aprobaciones (etapa 2 de firmas) — solo para firmantes ────────────
    {
        kind: 'item',
        key: 'approvals', label: t('approvals.menu'), icon: FileDoneOutlined,
        href: route('approvals.index'), inertia: true,
        badge: () => page.props.approvals?.pending ?? 0,
        visible: () => !!page.props.approvals?.is_signer,
    },

    // ── Mis solicitudes — lo que YO envié a aprobación (seguimiento) ───────
    // Visible para quien haya enviado al menos una solicitud. El badge cuenta
    // las que siguen en revisión (pendientes). El aviso de "ya se aprobó/
    // rechazó" llega por la campana (notificación al solicitante).
    {
        kind: 'item',
        key: 'my_requests', label: t('approvals.my_requests_menu'), icon: SolutionOutlined,
        href: route('report_requests.index'), inertia: true,
        badge: () => page.props.approvals?.my_requests_pending ?? 0,
        visible: () => (page.props.approvals?.my_requests_total ?? 0) > 0,
    },

    // ── Grupo: Accesos ────────────────────────────────────────────────────
    {
        kind: 'group',
        key: 'group-accesos', title: t('sidebar.group_access'),
        items: [
            // Users + Roles = "Equipos de trabajo" — gated por plan_feature.
            // free/basic son operacion de 1 persona y no ven estos modulos.
            // super bypassa el gate de plan (usePlanFeatures lo maneja).
            {
                key: 'users', label: t('sidebar.users'), icon: UserOutlined,
                href: route('user_management.users.index'), inertia: true,
                visible: () => can('users.view') && canUsePlanFeature('team_management'),
            },
            {
                key: 'roles', label: t('sidebar.roles'), icon: IdcardOutlined,
                href: route('user_management.roles.index'), inertia: true,
                visible: () => hasRole('super', 'admin') && canUsePlanFeature('team_management'),
            },
        ],
    },

    // ── Grupo: Negocio (operación del día a día) ─────────────────────────
    {
        kind: 'group',
        key: 'group-business', title: t('sidebar.group_business'),
        items: [
            {
                key: 'customers', label: t('sidebar.customers'), icon: TeamOutlined,
                href: route('business_management.customers.index'), inertia: true,
                visible: () => can('customers.view'),
            },
            {
                key: 'transformers', label: t('sidebar.transformers'), icon: TransformerIcon,
                href: route('business_management.transformers.index'), inertia: true,
                visible: () => can('transformers.view'),
            },
            {
                key: 'cmp_gases', label: t('sidebar.comparison_gases'), icon: LineChartOutlined,
                href: route('business_management.comparison.gases'), inertia: true,
                visible: () => can('transformers.view'),
            },
            {
                key: 'cmp_patrones', label: t('sidebar.comparison_patrones'), icon: BarChartOutlined,
                href: route('business_management.comparison.patrones'), inertia: true,
                visible: () => can('transformers.view'),
            },
            {
                // Historial de enlaces compartidos, cruzando clientes. Premium
                // (misma feature de plan que el botón de compartir).
                key: 'report_shares', label: t('sidebar.report_shares'), icon: ShareAltOutlined,
                href: route('business_management.report_shares_log.index'), inertia: true,
                visible: () => can('transformers.view') && canUsePlanFeature('report_sharing'),
            },
        ],
    },

    // ── Grupo: Condiciones de diagnóstico (catálogos editables del motor) ──
    // Lo que un ingeniero ajusta sin reprogramar: tipos de aceite, y más
    // adelante tipos de trafo, normas, variables, reglas y escalas/semáforos.
    // Separado de "Negocio" (operación) a propósito: aquí se configura CÓMO
    // diagnostica el sistema, no se opera con transformadores.
    {
        kind: 'group',
        key: 'group-diagnostics', title: t('sidebar.group_diagnostics'),
        items: [
            // Editor del semáforo + pesos del HI (reglas en datos). SOLO super.
            {
                key: 'diagnostic_rules', label: t('sidebar.diagnostic_rules'), icon: ExperimentOutlined,
                href: route('system_management.diagnostic_rules.index'), inertia: true,
                // Híbrido: super edita el estándar global; el admin del workspace
                // edita SU override (aislado). Por eso ahora también lo ve el admin.
                visible: () => hasRole('super') || hasRole('admin'),
            },
            // Tipo de aceite / tipo de trafo / conmutador: catálogos internos del
            // motor de diagnóstico. SOLO super los ve y edita; el admin del workspace
            // no los necesita (las reglas viven en datos, no se tocan por tenant).
            {
                key: 'oil_types', label: t('sidebar.oil_types'), icon: BgColorsOutlined,
                href: route('business_management.oil_types.index'), inertia: true,
                visible: () => hasRole('super'),
            },
            {
                key: 'transformer_types', label: t('sidebar.transformer_types'), icon: AppstoreOutlined,
                href: route('business_management.transformer_types.index'), inertia: true,
                visible: () => hasRole('super'),
            },
            {
                key: 'brands', label: t('sidebar.brands'), icon: TagsOutlined,
                href: route('business_management.brands.index'), inertia: true,
                visible: () => can('brands.view'),
            },
            {
                key: 'laboratories', label: t('sidebar.laboratories'), icon: ExperimentOutlined,
                href: route('business_management.laboratories.index'), inertia: true,
                visible: () => can('laboratories.view'),
            },
            {
                key: 'tap_changer_types', label: t('sidebar.tap_changer_types'), icon: ControlOutlined,
                href: route('business_management.tap_changer_types.index'), inertia: true,
                visible: () => hasRole('super'),
            },
            {
                key: 'tap_changer_brands', label: t('sidebar.tap_changer_brands'), icon: TagsOutlined,
                href: route('business_management.tap_changer_brands.index'), inertia: true,
                visible: () => can('tap_changer_brands.view'),
            },
            {
                key: 'tap_changer_models', label: t('sidebar.tap_changer_models'), icon: BlockOutlined,
                href: route('business_management.tap_changer_models.index'), inertia: true,
                visible: () => can('tap_changer_models.view'),
            },
            {
                key: 'tap_changer_technologies', label: t('sidebar.tap_changer_technologies'), icon: ThunderboltOutlined,
                href: route('business_management.tap_changer_technologies.index'), inertia: true,
                visible: () => can('tap_changer_technologies.view'),
            },
        ],
    },


    // ── Grupo: Comunicacion (Mensajes + Bandeja) ─────────────────────────
    // Mensajes: solo super (envia anuncios/avisos/debates a la audiencia)
    // Bandeja: todos los users autenticados (lee los mensajes recibidos)
    // ── Grupo: Automatizaciones (solo planes con la feature activa) ───────
    {
        kind: 'group',
        key: 'group-automation', title: t('sidebar.group_automation'),
        // Doble gate: rol (super/admin) + feature de plan. Los workers (roles
        // custom como "Customer Editor") NO ven automations aunque su tenant
        // tenga el plan — automations es admin-only. super siempre.
        visible: () => hasRole('super', 'admin') && canUsePlanFeature('automations'),
        items: [
            {
                key: 'automations', label: t('sidebar.automations'), icon: ThunderboltOutlined,
                href: route('automation_management.automations.index'), inertia: true,
                visible: () => hasRole('super', 'admin') && canUsePlanFeature('automations'),
            },
        ],
    },

    // ── Grupo: Comunicación ───────────────────────────────────────────────
    {
        kind: 'group',
        key: 'group-communication', title: t('sidebar.group_communication'),
        items: [
            {
                key: 'messages', label: t('sidebar.messages'), icon: MessageOutlined,
                href: route('communication.messages.index'), inertia: true,
                visible: () => hasRole('super'),
            },
            {
                key: 'inbox', label: t('sidebar.inbox'), icon: InboxOutlined,
                href: route('communication.inbox.index'), inertia: true,
                visible: () => true,
            },
        ],
    },

    // ── Grupo: Auditoría ──────────────────────────────────────────────────
    {
        kind: 'group',
        key: 'group-audit', title: t('sidebar.group_audit'),
        items: [
            {
                key: 'audit_logs', label: t('sidebar.audit_logs'), icon: AuditOutlined,
                href: route('system_management.audit_logs.index'), inertia: true,
                // Solo por rol: todo super/admin ve SU propia auditoría, sin
                // depender del plan (es un derecho básico del tenant).
                visible: () => hasRole('super', 'admin'),
            },
        ],
    },

    // ── Mi workspace — autoservicio del tenant (admin), SEPARADO del core ──
    // El super NO lo ve: no tiene workspace propio (rompía la lógica). Va aparte
    // del grupo "Configuración del sistema" (que es super-only) para diferenciar
    // lo del tenant de lo del sistema.
    {
        kind: 'item',
        key: 'workspace', label: t('sidebar.workspace'), icon: ShopOutlined,
        href: route('workspace.edit'), inertia: true,
        visible: () => hasRole('admin') && !hasRole('super'),
    },

    // ── Grupo: Configuración del sistema (super only) ───────────────
    {
        kind: 'group',
        key: 'group-system', title: t('sidebar.group_system'),
        items: [
            {
                key: 'tenants', label: t('sidebar.tenants'), icon: BankOutlined,
                href: route('system_management.tenants.index'), inertia: true,
                visible: () => hasRole('super'),
            },
            {
                key: 'plans', label: t('sidebar.plans'), icon: CrownOutlined,
                href: route('system_management.plans.index'), inertia: true,
                visible: () => hasRole('super'),
            },
            {
                key: 'system_modules', label: t('sidebar.system_modules'), icon: BlockOutlined,
                href: route('system_management.system_modules.index'), inertia: true,
                visible: () => hasRole('super'),
            },
            {
                key: 'regions', label: t('sidebar.regions'), icon: GlobalOutlined,
                href: route('system_management.regions.index'), inertia: true,
                visible: () => hasRole('super'),
            },
            {
                key: 'languages', label: t('sidebar.languages'), icon: TranslationOutlined,
                href: route('system_management.languages.index'), inertia: true,
                visible: () => hasRole('super'),
            },
            {
                key: 'countries', label: t('sidebar.countries'), icon: FlagOutlined,
                href: route('system_management.countries.index'), inertia: true,
                visible: () => hasRole('super'),
            },
            {
                key: 'locales', label: t('sidebar.locales'), icon: ReadOutlined,
                href: route('system_management.locales.index'), inertia: true,
                visible: () => hasRole('super'),
            },
            {
                key: 'settings', label: t('sidebar.settings'), icon: SettingOutlined,
                href: route('system_management.settings.index'), inertia: true,
                visible: () => hasRole('super'),
            },
        ],
    },
    {
        kind: 'group',
        key: 'group-tools', title: t('sidebar.group_tools'),
        items: [
            {
                key: 'tools-duval', label: t('sidebar.tools_duval'), icon: RadarChartOutlined,
                href: route('tools.duval.index'), inertia: true,
                visible: () => hasRole('super'),
            },
        ],
    },
]);

// Computed: filter items inside groups; drop empty groups; keep ungrouped items.
const visibleStructure = computed(() => {
    return menuStructure.value
        .map(section => {
            if (section.kind === 'item') {
                return section.visible() ? section : null;
            }
            // Group — keep only visible items
            const items = section.items.filter(i => i.visible());
            return items.length > 0 ? { ...section, items } : null;
        })
        .filter(Boolean);
});

// Flat list of clickable items (used by mobile drawer + key resolution).
const flatItems = computed(() =>
    visibleStructure.value.flatMap(s => s.kind === 'item' ? [s] : s.items)
);

// Items de navegación para el buscador global (módulos con href, sin íconos).
const searchNavItems = computed(() =>
    flatItems.value.filter(i => i.href).map(i => ({ key: i.key, label: i.label, href: i.href }))
);

// ─── Grupos colapsables del sidebar ────────────────────────────────────────
// Cada grupo (Accesos, Negocio, Configuración del sistema, etc.) se puede
// plegar/expandir. El estado se persiste por usuario en localStorage para que
// la próxima sesión recuerde qué grupos dejó cerrados. Default: todo abierto.
const SIDEBAR_GROUPS_KEY = 'sidebar:collapsed-groups';
const collapsedGroups = ref({});
try {
    const raw = localStorage.getItem(SIDEBAR_GROUPS_KEY);
    if (raw) collapsedGroups.value = JSON.parse(raw) || {};
} catch (e) { /* localStorage no disponible — default todo abierto */ }

const isGroupCollapsed = (key) => !!collapsedGroups.value[key];

const toggleGroup = (key) => {
    collapsedGroups.value = {
        ...collapsedGroups.value,
        [key]: !collapsedGroups.value[key],
    };
    try {
        localStorage.setItem(SIDEBAR_GROUPS_KEY, JSON.stringify(collapsedGroups.value));
    } catch (e) { /* no-op */ }
};

const navigateTo = (item) => {
    drawerOpen.value = false;
    if (item.inertia) router.visit(item.href);
    else              window.location.href = item.href;
};

// ─── Modo de navegación: 'top' (giantbar + mega-menú) | 'side' (sidebar clásico)
// Reversible: el usuario alterna desde el botón del shell-bar o desde el perfil.
// Se persiste por usuario en BD (cross-device). Default 'top'. En mobile el modo
// no aplica: siempre se usa el Drawer. (navMode se define arriba, junto a uiScheme.)
const setNavMode = (m) => {
    if (!NAV_MODES.includes(m)) return;
    navMode.value = m;
    openCat.value = null;
    // Persistir a BD sin recargar la página (mantiene scroll y estado).
    router.put(route('profile.preferences.update'),
        { ui_scheme: uiScheme.value, nav_position: m },
        { preserveScroll: true, preserveState: true });
};
// El botón del shell-bar alterna entre arriba y lateral (los dos modos de escritorio).
const toggleNavMode = () => setNavMode(navMode.value === 'top' ? 'side' : 'top');

// Altura del header sticky (shell-bar 44px + topnav ~44px si está en modo 'top').
// Se expone como var CSS en .content para que el contenido ancle sus elementos
// sticky (ej. el rail de diagnóstico del trafo) justo debajo del header.
const stickyHeaderH = computed(() => (navMode.value === 'bottom' && !isMobile.value) ? '88px' : '44px');

// Mega-menú del giantbar: qué categoría (grupo) está abierta. Se abre al pasar
// el mouse por la categoría y se cierra al salir de la barra.
const openCat = ref(null);
const openMega = computed(() =>
    visibleStructure.value.find(s => s.kind === 'group' && s.key === openCat.value) || null
);
const isCatActive = (section) =>
    section.kind === 'group' && section.items.some(i => i.key === selectedKey.value);

// Empuje SUAVE del mega-menú: animamos la altura (0 ↔ contenido) para que el
// contenido se deslice hacia abajo/arriba en vez de saltar. (Altura automática.)
const megaEnter = (el) => {
    el.style.height = '0';
    void el.offsetHeight;            // fuerza reflow
    el.style.height = el.scrollHeight + 'px';
};
const megaAfterEnter = (el) => { el.style.height = 'auto'; };
const megaLeave = (el) => {
    el.style.height = el.scrollHeight + 'px';
    void el.offsetHeight;
    el.style.height = '0';
};

</script>

<template>
  <ConfigProvider :theme="antdTheme" :locale="antLocale">
    <!-- Overlay para "rotá el celu" — solo aparece en celulares en landscape. -->
    <RotatePortraitOverlay />
    <Layout class="app-shell">
        <!-- Shell Bar (full width, oscuro tipo SAP Fiori) -->
        <LayoutHeader class="shell-bar">
            <div class="shell-bar__left">
                <button
                    v-show="isMobile || navMode === 'side' || navMode === 'top'"
                    class="icon-btn" @click="toggleSidebar" aria-label="menu" :title="$t('global.menu')"
                >
                    <MenuOutlined />
                </button>
                <div class="brand">
                    <div class="brand-logo">
                        <img v-if="appLogo" :src="appLogo" :alt="appName" class="brand-logo-img" />
                        <template v-else>{{ appName.charAt(0) }}</template>
                    </div>
                    <span class="brand-text">{{ appName }}</span>
                </div>
            </div>

            <!-- Buscador global: ir a cualquier trafo (serie/TAG), cliente o módulo. -->
            <div class="shell-bar__search">
                <GlobalSearch :nav-items="searchNavItems" :recent-views="recentViews" />
            </div>

            <div class="shell-bar__right">
                <!-- Toggle de navegación: giantbar (top) ⇄ sidebar clásico.
                     Solo en desktop; mobile siempre usa el Drawer. -->
                <Tooltip
                    v-if="!isMobile"
                    :title="navMode === 'top' ? $t('global.nav_use_sidebar') : $t('global.nav_use_topbar')"
                >
                    <button class="icon-btn" @click="toggleNavMode" :aria-label="$t('global.nav_toggle')">
                        <PicLeftOutlined v-if="navMode === 'top'" />
                        <PicCenterOutlined v-else />
                    </button>
                </Tooltip>

                <!-- Plan info vive ahora SOLO en el dropdown del avatar (debajo
                     del timezone). El topbar quedaba demasiado ruidoso con el
                     badge al lado de las notificaciones. -->

<!-- ICONO 1: Bell de notificaciones del sistema — SOLO descargas + alertas
                     de automatizaciones. NO incluye mensajes (eso vive en el sobre). -->
                <Dropdown
                    :trigger="['click']"
                    overlayClassName="shell-menu-overlay shell-notifications-overlay"
                    placement="bottomRight"
                    @open-change="onInboxPanelOpen"
                >
                    <Badge
                        :count="inbox.unread"
                        :offset="[-6, 6]"
                        size="small"
                        :overflow-count="9"
                    >
                        <button
                            class="icon-btn notif-bell-btn"
                            :class="{ 'notif-bell-btn--alert': inbox.unread > 0 }"
                            :title="$t('global.notifications')"
                            :aria-label="$t('global.notifications')"
                        >
                            <BellOutlined />
                        </button>
                    </Badge>
                    <template #overlay>
                        <div class="notifications-menu">
                            <div class="notifications-menu__header">
                                <span class="notifications-menu__title">{{ $t('global.notifications') }}</span>
                                <span v-if="inbox.processing > 0" class="notifications-menu__pulse">
                                    <LoadingOutlined /> {{ $t('global.generating') }} {{ inbox.processing }}
                                </span>
                            </div>

                            <div v-if="inbox.recent.length === 0" class="notifications-menu__empty">
                                <BellOutlined style="font-size: 1.6rem; color: #cbd5e1;" />
                                <p>{{ $t('global.no_notifications') }}</p>
                                <small>{{ $t('global.no_notifications_hint') }}</small>
                            </div>

                            <ul v-else class="notifications-menu__list">
                                <li
                                    v-for="n in inbox.recent"
                                    :key="n.id"
                                    class="notifications-item"
                                    :class="{
                                        'notifications-item--unread':
                                            (n.kind === 'download' && n.status === 'ready' && !n.downloaded_at)
                                            || (n.kind === 'app' && n.status === 'unread'),
                                    }"
                                    @click="n.kind === 'download' ? triggerDownload(n) : markAppNotificationRead(n)"
                                >
                                    <template v-if="n.kind === 'download'">
                                        <component
                                            :is="downloadFileIcon(n.type).icon"
                                            class="notifications-item__icon"
                                            :style="{ color: downloadFileIcon(n.type).color }"
                                        />
                                        <div class="notifications-item__body">
                                            <div class="notifications-item__name">{{ n.filename }}</div>
                                            <div class="notifications-item__status" :class="`is-${n.status}`">
                                                <LoadingOutlined v-if="n.status === 'processing'" />
                                                <CloseCircleFilled v-else-if="n.status === 'failed'" />
                                                {{ downloadStatusLabel(n.status) }}
                                            </div>
                                        </div>
                                        <div class="notifications-item__actions" @click.stop>
                                            <Tooltip v-if="n.status === 'ready'" :title="$t('global.download')">
                                                <button class="notifications-item__btn" @click="triggerDownload(n)">
                                                    <DownloadOutlined />
                                                </button>
                                            </Tooltip>
                                            <Tooltip :title="$t('global.remove')">
                                                <button class="notifications-item__btn notifications-item__btn--danger" @click="dismissNotification(n)">
                                                    <DeleteOutlined />
                                                </button>
                                            </Tooltip>
                                        </div>
                                    </template>

                                    <template v-else-if="n.kind === 'app'">
                                        <component
                                            :is="appNotifIcon(n)"
                                            class="notifications-item__icon"
                                            :style="{ color: appNotifColor(n) }"
                                        />
                                        <div class="notifications-item__body">
                                            <div class="notifications-item__name">
                                                <span>{{ n.title || $t('global.notification') }}</span>
                                                <Tag
                                                    v-if="isSuperUser && n.tenant_name && n.type === 'automation'"
                                                    color="blue" :bordered="false"
                                                    class="notifications-item__tenant-badge"
                                                >
                                                    {{ n.tenant_name }}
                                                </Tag>
                                            </div>
                                            <div class="notifications-item__app-body">{{ (n.body || '').replace(/\s+/g, ' ').trim() }}</div>
                                        </div>
                                        <!-- Solo notifs que requieren ack (security, plan_change, etc.)
                                             muestran botón eliminar. Las de automation se autoborran
                                             a las 12h (PurgeAutomationNotifications) — sin clutter. -->
                                        <div v-if="n.type !== 'automation'" class="notifications-item__actions" @click.stop>
                                            <Tooltip :title="$t('global.remove')">
                                                <button class="notifications-item__btn notifications-item__btn--danger" @click="dismissNotification(n)">
                                                    <DeleteOutlined />
                                                </button>
                                            </Tooltip>
                                        </div>
                                    </template>
                                </li>
                            </ul>

                            <div class="notifications-menu__footer">
                                <button class="notifications-menu__view-all" @click="goToNotificationsPage">
                                    {{ $t('global.all_notifications') }}
                                </button>
                            </div>
                        </div>
                    </template>
                </Dropdown>

                <!-- ICONO 2: Sobre de mensajes — SOLO Communication module.
                     Dropdown propio con preview de últimos 5 mensajes del user.
                     Click en uno → /inbox/{slug} (auto-marca como leído). -->
                <Dropdown
                    :trigger="['click']"
                    overlayClassName="shell-menu-overlay shell-notifications-overlay"
                    placement="bottomRight"
                    @open-change="onInboxPanelOpen"
                >
                    <Badge
                        :count="inbox.unread_messages"
                        :offset="[-6, 6]"
                        size="small"
                        :overflow-count="9"
                    >
                        <button
                            class="icon-btn notif-bell-btn"
                            :class="{ 'notif-bell-btn--alert': inbox.unread_messages > 0 }"
                            :title="$t('sidebar.messages')"
                            :aria-label="$t('sidebar.messages')"
                        >
                            <MailOutlined />
                        </button>
                    </Badge>
                    <template #overlay>
                        <div class="notifications-menu">
                            <div class="notifications-menu__header">
                                <span class="notifications-menu__title">{{ $t('sidebar.messages') }}</span>
                                <span v-if="inbox.unread_messages > 0" class="notifications-menu__pulse">
                                    {{ inbox.unread_messages }} {{ $t('messages.unread').toLowerCase() }}
                                </span>
                            </div>

                            <div v-if="messagesInBell.length === 0" class="notifications-menu__empty">
                                <MailOutlined style="font-size: 1.6rem; color: #cbd5e1;" />
                                <p>{{ $t('messages.empty_bell') }}</p>
                                <small>{{ $t('messages.empty_bell_hint') }}</small>
                            </div>

                            <ul v-else class="notifications-menu__list">
                                <li
                                    v-for="m in messagesInBell"
                                    :key="m.id"
                                    class="notifications-item"
                                    :class="{ 'notifications-item--unread': m.status === 'unread' }"
                                    @click="goToInboxMessage(m)"
                                >
                                    <MailOutlined
                                        class="notifications-item__icon"
                                        :style="{ color: m.status === 'unread' ? '#0A6ED1' : '#8c8c8c' }"
                                    />
                                    <div class="notifications-item__body">
                                        <div class="notifications-item__name">{{ m.subject }}</div>
                                        <div class="notifications-item__status" :class="m.status === 'unread' ? 'is-ready' : 'is-read'">
                                            {{ m.status === 'unread' ? $t('messages.unread') : $t('messages.read') }}
                                        </div>
                                    </div>
                                </li>
                            </ul>

                            <div class="notifications-menu__footer">
                                <button class="notifications-menu__view-all" @click="router.visit(route('communication.inbox.index'))">
                                    {{ $t('messages.view_inbox') }}
                                </button>
                            </div>
                        </div>
                    </template>
                </Dropdown>

                <div class="divider" />

                <!-- User -->
                <Dropdown :trigger="['click']" overlayClassName="shell-menu-overlay" placement="bottomRight">
                    <button class="user-trigger">
                        <Avatar
                            :src="user?.photo_url || undefined"
                            :style="{ background: '#0A6ED1' }"
                            :size="28"
                        >
                            {{ user?.name?.charAt(0)?.toUpperCase() }}
                        </Avatar>
                        <span class="user-name">{{ user?.name }}</span>
                        <DownOutlined style="font-size: 0.7rem; opacity: 0.7;" />
                    </button>
                    <template #overlay>
                        <Menu class="shell-menu shell-menu--user">
                            <div class="shell-menu__header">
                                <Avatar
                                    :src="user?.photo_url || undefined"
                                    :style="{ background: '#0A6ED1' }"
                                    :size="36"
                                >
                                    {{ user?.name?.charAt(0)?.toUpperCase() }}
                                </Avatar>
                                <div class="shell-menu__user">
                                    <span class="shell-menu__user-name">{{ user?.name }}</span>
                                    <span class="shell-menu__user-email">{{ user?.email }}</span>
                                </div>
                            </div>

                            <!-- Timezone effective — muestra al user en qué TZ está
                                 viendo todas las fechas (resuelto por backend en
                                 Tz::for($user)). Reduce la confusión "¿por qué
                                 este timestamp dice 14:00 si son las 09:00?". -->
                            <div v-if="user?.timezone" class="shell-menu__plan">
                                <div class="shell-menu__plan-row">
                                    <span class="shell-menu__plan-label">
                                        <ClockCircleOutlined /> {{ $t('global.timezone') }}
                                    </span>
                                    <Tooltip :title="$t('global.timezone_hint')">
                                        <span class="shell-menu__plan-value">{{ user.timezone }}</span>
                                    </Tooltip>
                                </div>
                            </div>

                            <!-- Plan info — ubicado debajo del timezone (lugar
                                 secundario, no compite con notificaciones). Solo
                                 visible si el user tiene tenant. -->
                            <div v-if="user?.plan_info" class="shell-menu__plan">
                                <div class="shell-menu__plan-row">
                                    <span class="shell-menu__plan-label">{{ $t('plans.singular') }}</span>
                                    <Tag :color="user.plan_info.color || 'default'" :bordered="false">
                                        {{ user.plan_info.name }}
                                    </Tag>
                                </div>
                                <div v-if="user.plan_info.days_remaining !== null" class="shell-menu__plan-row">
                                    <span class="shell-menu__plan-label">{{ $t('subscriptions.days_remaining') }}</span>
                                    <span class="shell-menu__plan-value" :class="{ 'is-urgent': user.plan_info.days_remaining <= 7 }">
                                        {{ user.plan_info.days_remaining }}
                                    </span>
                                </div>
                            </div>

                            <MenuDivider />
                            <MenuItem key="profile" @click="goToProfile">
                                <UserOutlined /> <span>{{ $t('global.my_profile') }}</span>
                            </MenuItem>
                            <MenuItem key="settings" @click="goToProfilePrefs">
                                <SettingOutlined /> <span>{{ $t('global.settings') }}</span>
                            </MenuItem>

                            <!-- Apariencia (tema) e idioma — ajustes personales,
                                 movidos del topbar para dejarlo limpio. -->
                            <SubMenu key="appearance">
                                <template #icon>
                                    <DesktopOutlined v-if="themeMode === 'auto'" />
                                    <span v-else class="shell-menu__svg" v-html="themeIconSvg" />
                                </template>
                                <template #title>{{ $t('global.change_theme') }}</template>
                                <MenuItem key="theme-auto" @click="setTheme('auto')">
                                    <DesktopOutlined /> <span>{{ $t('global.theme_auto') }}</span>
                                    <CheckOutlined v-if="themeMode === 'auto'" class="shell-menu__check" />
                                </MenuItem>
                                <MenuItem key="theme-light" @click="setTheme('light')">
                                    <span class="shell-menu__svg" v-html="sunSvg" /> <span>{{ $t('global.theme_light') }}</span>
                                    <CheckOutlined v-if="themeMode === 'light'" class="shell-menu__check" />
                                </MenuItem>
                                <MenuItem key="theme-dark" @click="setTheme('dark')">
                                    <span class="shell-menu__svg" v-html="moonSvg" /> <span>{{ $t('global.theme_dark') }}</span>
                                    <CheckOutlined v-if="themeMode === 'dark'" class="shell-menu__check" />
                                </MenuItem>
                            </SubMenu>
                            <SubMenu key="language">
                                <template #icon><GlobalOutlined /></template>
                                <template #title>{{ $t('global.change_language') }}</template>
                                <MenuItem v-for="l in languages" :key="`lang-${l.code}`" @click="switchLang(l.code)">
                                    <span>{{ l.label }}</span>
                                    <CheckOutlined v-if="locale === l.code" class="shell-menu__check" />
                                </MenuItem>
                            </SubMenu>

                            <!-- Recientes (últimos vistos) se movieron al buscador
                                 global (⌘K / click en la barra de arriba). -->

                            <MenuDivider />
                            <MenuItem key="manual" @click="openManual">
                                <ReadOutlined /> <span>{{ $t('global.user_manual') }}</span>
                            </MenuItem>
                            <MenuItem key="logout" @click="logout" class="shell-menu__logout">
                                <LogoutOutlined /> <span>{{ $t('global.logout') }}</span>
                            </MenuItem>
                        </Menu>
                    </template>
                </Dropdown>
            </div>
        </LayoutHeader>

        <!-- ── Giantbar: navegación superior con mega-menú (desktop · modo 'top') ──
             Reemplaza al sidebar para ganar ancho. Items sueltos = enlaces directos;
             grupos = categorías que despliegan un panel ancho con sus módulos. -->
        <nav
            v-if="!isMobile && navMode === 'bottom'"
            class="topnav"
            :class="{ 'topnav--bottom': navMode === 'bottom' }"
            @mouseleave="openCat = null"
        >
            <div class="topnav__row">
                <template v-for="section in visibleStructure" :key="section.key">
                    <button
                        v-if="section.kind === 'item'"
                        class="topnav__btn"
                        :class="{ 'is-active': selectedKey === section.key }"
                        @click="navigateTo(section)"
                    >
                        <component :is="section.icon" class="topnav__ico" />
                        <span>{{ section.label }}</span>
                        <span v-if="section.badge && section.badge() > 0" class="topnav__badge">{{ section.badge() }}</span>
                    </button>

                    <div
                        v-else
                        class="topnav__cat-wrap"
                        @mouseenter="openCat = section.key"
                    >
                        <button
                            class="topnav__btn topnav__btn--cat"
                            :class="{ 'is-active': isCatActive(section), 'is-open': openCat === section.key }"
                            @click="openCat = openCat === section.key ? null : section.key"
                        >
                            <span>{{ section.title }}</span>
                            <DownOutlined class="topnav__chev" />
                        </button>
                    </div>
                </template>
            </div>

            <!-- Panel mega de la categoría abierta (empuja el contenido, animado) -->
            <transition @enter="megaEnter" @after-enter="megaAfterEnter" @leave="megaLeave">
                <div v-if="openMega" class="topnav__mega">
                    <div class="topnav__mega-inner">
                        <span class="topnav__mega-title">{{ openMega.title }}</span>
                        <div class="topnav__mega-grid">
                            <button
                                v-for="item in openMega.items"
                                :key="item.key"
                                class="mega-link"
                                :class="{ 'is-active': selectedKey === item.key }"
                                :disabled="item.disabled"
                                @click="(!item.disabled) && (navigateTo(item), openCat = null)"
                            >
                                <span class="mega-link__ico"><component :is="item.icon" /></span>
                                <span class="mega-link__txt">{{ item.label }}</span>
                                <span v-if="item.badge && item.badge() > 0" class="topnav__badge">{{ item.badge() }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </transition>
        </nav>

        <!-- Below the shell: sidebar + content -->
        <Layout class="below-shell">
            <!-- Sidebar desktop (solo en modo 'side') -->
            <LayoutSider
                v-if="!isMobile && navMode === 'side'"
                v-model:collapsed="collapsed"
                :width="240"
                :collapsed-width="64"
                :trigger="null"
                class="app-sider"
            >
                <Menu
                    mode="inline"
                    :selectedKeys="[selectedKey]"
                    :style="{ background: 'transparent', borderRight: 0 }"
                >
                    <template v-for="section in visibleStructure" :key="section.key">
                        <!-- Standalone item -->
                        <MenuItem
                            v-if="section.kind === 'item'"
                            :key="section.key"
                            @click="navigateTo(section)"
                        >
                            <component :is="section.icon" />
                            <span>{{ section.label }}</span>
                            <span v-if="section.badge && section.badge() > 0" class="nav-badge">{{ section.badge() }}</span>
                        </MenuItem>

                        <!-- Grouped items — header clickeable que pliega/expande -->
                        <MenuItemGroup v-else>
                            <template #title>
                                <button
                                    type="button"
                                    class="group-header"
                                    :class="{ 'group-header--collapsed': isGroupCollapsed(section.key) }"
                                    @click="toggleGroup(section.key)"
                                >
                                    <span class="group-header__label">{{ section.title }}</span>
                                    <DownOutlined class="group-header__chevron" />
                                </button>
                            </template>
                            <!-- En modo iconos (sidebar colapsado) ignoramos el
                                 plegado de grupos: mostramos todos los iconos.
                                 El plegado solo aplica en modo expandido. -->
                            <template v-if="collapsed || !isGroupCollapsed(section.key)">
                                <MenuItem
                                    v-for="item in section.items"
                                    :key="item.key"
                                    :disabled="item.disabled"
                                    @click="!item.disabled && navigateTo(item)"
                                >
                                    <Tooltip v-if="item.tooltip" :title="item.tooltip" placement="right">
                                        <span class="menu-row">
                                            <component :is="item.icon" />
                                            <span>{{ item.label }}</span>
                                        </span>
                                    </Tooltip>
                                    <template v-else>
                                        <component :is="item.icon" />
                                        <span>{{ item.label }}</span>
                                    </template>
                                </MenuItem>
                            </template>
                        </MenuItemGroup>
                    </template>
                </Menu>
            </LayoutSider>

            <!-- Sidebar mobile (Drawer) — fullscreen con header (título + close).
                 Patrón Yape/iOS Mail: el menú toma toda la pantalla, con título
                 claro y X grande para cerrar (touch target 44px). -->
            <Drawer
                v-model:open="drawerOpen"
                placement="left"
                :width="isMobile ? '100%' : 300"
                :title="$t('global.menu')"
                :closable="true"
                :body-style="{ padding: 0, background: 'var(--color-surface)' }"
                wrap-class-name="sidebar-mobile-drawer"
            >
                <Menu
                    mode="inline"
                    :selectedKeys="[selectedKey]"
                    :style="{ background: 'transparent', borderRight: 0 }"
                >
                    <template v-for="section in visibleStructure" :key="section.key">
                        <MenuItem
                            v-if="section.kind === 'item'"
                            :key="section.key"
                            @click="navigateTo(section)"
                        >
                            <component :is="section.icon" />
                            <span>{{ section.label }}</span>
                            <span v-if="section.badge && section.badge() > 0" class="nav-badge">{{ section.badge() }}</span>
                        </MenuItem>

                        <MenuItemGroup v-else>
                            <template #title>
                                <button
                                    type="button"
                                    class="group-header"
                                    :class="{ 'group-header--collapsed': isGroupCollapsed(section.key) }"
                                    @click="toggleGroup(section.key)"
                                >
                                    <span class="group-header__label">{{ section.title }}</span>
                                    <DownOutlined class="group-header__chevron" />
                                </button>
                            </template>
                            <template v-if="!isGroupCollapsed(section.key)">
                                <MenuItem
                                    v-for="item in section.items"
                                    :key="item.key"
                                    :disabled="item.disabled"
                                    @click="!item.disabled && navigateTo(item)"
                                >
                                    <component :is="item.icon" />
                                    <span>{{ item.label }}</span>
                                </MenuItem>
                            </template>
                        </MenuItemGroup>
                    </template>
                </Menu>
            </Drawer>

            <Layout class="content-layout">
                <!-- Banner global de suscripción. Solo si backend mandó props.subscription
                     (trial activo OR <= 7 días para expirar). super no lo ve. -->
                <Alert
                    v-if="subscriptionWarning"
                    :type="subscriptionWarning.days_remaining <= 0 ? 'error' : 'warning'"
                    show-icon
                    banner
                    closable
                    class="subscription-banner"
                >
                    <template #message>
                        <template v-if="subscriptionWarning.is_trial">
                            {{ $t('subscriptions.status_trial') }} ·
                            {{ $t('subscriptions.days_remaining_n', { count: subscriptionWarning.days_remaining }, subscriptionWarning.days_remaining) }}
                        </template>
                        <template v-else-if="subscriptionWarning.days_remaining > 0">
                            {{ $t('subscriptions.expires_in_warning', { days: subscriptionWarning.days_remaining }) }}
                        </template>
                        <template v-else>
                            {{ $t('subscriptions.expired_warning') }}
                        </template>
                    </template>
                </Alert>

                <LayoutContent
                    class="content"
                    :class="{ 'content--bottomnav': !isMobile && navMode === 'bottom' }"
                    :style="{ '--tr-sticky-h': stickyHeaderH }"
                >
                    <slot />
                </LayoutContent>
            </Layout>
        </Layout>
    </Layout>

    <!-- ── Aceptación de Términos/Privacidad (LPDP) — bloqueante ── -->
    <Modal
        :open="legalPending"
        :closable="false"
        :maskClosable="false"
        :keyboard="false"
        :footer="null"
        :title="$t('auth.legal_modal_title')"
        width="480px"
    >
        <p class="legal-modal__text">{{ $t('auth.legal_modal_body') }}</p>
        <p class="legal-modal__links">
            <a :href="route('legal_management.terms')" target="_blank" rel="noopener">{{ $t('auth.terms') }}</a>
            ·
            <a :href="route('legal_management.privacy')" target="_blank" rel="noopener">{{ $t('auth.privacy') }}</a>
        </p>
        <Checkbox v-model:checked="legalChecked" class="legal-modal__check">
            {{ $t('auth.legal_modal_check') }}
        </Checkbox>
        <div class="legal-modal__actions">
            <Button type="primary" block :disabled="!legalChecked" :loading="legalSaving" @click="acceptLegal">
                {{ $t('auth.legal_modal_accept') }}
            </Button>
        </div>
    </Modal>
  </ConfigProvider>
</template>

<style scoped>
/* =========================================================================
   SAP Fiori palette (Quartz Light)
   --shell:    #354A5F (top bar)
   --brand:    #0A6ED1 (SAP Blue)
   --brand-soft: #E6F1FB (selección)
   --page-bg:  #F7F7F7
   --text:     #32363A
   --text-soft:#6A6D70
   --border:   #D9D9D9
   --border-soft: #E5E5E5
   ========================================================================= */

.app-shell { min-height: 100vh; }

/* ─── Giantbar: navegación superior con mega-menú (modo 'top') ───────────── */
.topnav {
    position: sticky;
    top: 44px;            /* debajo del shell-bar (44px) */
    z-index: 90;
    background: var(--color-surface, #fff);
    border-bottom: 1px solid var(--color-border, #e6eaf2);
    box-shadow: 0 1px 3px rgba(15, 27, 45, 0.04);
}
/* Modo 'bottom': misma barra anclada al fondo; el mega flota HACIA ARRIBA. */
.topnav--bottom {
    position: fixed;
    top: auto;
    bottom: 0;
    left: 0;
    right: 0;
    border-bottom: 0;
    border-top: 1px solid var(--color-border, #e6eaf2);
    box-shadow: 0 -1px 4px rgba(15, 27, 45, 0.06);
}
.topnav--bottom .topnav__mega {
    position: absolute;
    bottom: 100%;        /* despliega por encima de la barra */
    left: 0;
    right: 0;
    border-top: 1px solid var(--color-border, #e6eaf2);
    border-bottom: 0;
    box-shadow: 0 -6px 16px rgba(15, 27, 45, 0.10);
}
/* Espacio inferior para que el contenido no quede tapado por la barra fija. */
.content--bottomnav { padding-bottom: 52px; }
.topnav__row {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 6px 14px;
    overflow-x: auto;
    scrollbar-width: none;
}
.topnav__row::-webkit-scrollbar { display: none; }
.topnav__cat-wrap { display: inline-flex; }
.topnav__btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 0;
    background: transparent;
    font: inherit;
    font-size: 0.86rem;
    font-weight: 600;
    color: var(--color-text, #42506a);
    padding: 8px 12px;
    border-radius: 9px;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.14s, color 0.14s;
}
.topnav__btn:hover { background: var(--color-surface-alt, #eef2fb); color: var(--color-text-strong, #0f1b2d); }
.topnav__btn.is-active { background: var(--brand-soft, #E6F1FB); color: var(--brand, #0A6ED1); }
.topnav__btn.is-open { background: var(--color-surface-alt, #eef2fb); }
.topnav__ico { font-size: 0.95rem; opacity: 0.85; }
.topnav__chev { font-size: 0.6rem; opacity: 0.55; transition: transform 0.16s; }
.topnav__btn.is-open .topnav__chev { transform: rotate(180deg); }
.topnav__badge {
    min-width: 16px; height: 16px; padding: 0 5px;
    border-radius: 999px; background: #C8281D; color: #fff;
    font-size: 0.64rem; font-weight: 700; line-height: 16px; text-align: center;
}

/* Panel mega: ancho completo bajo la fila, con grilla de módulos. */
.topnav__mega {
    /* In-flow (NO absolute): al pasar el mouse, el panel ocupa lugar y EMPUJA el
       contenido hacia abajo en la misma página, en vez de flotar encima. Como
       solo se renderiza con openCat (hover), el contenido vuelve arriba al salir. */
    background: var(--color-surface, #fff);
    border-top: 1px solid var(--color-border, #eef0f3);
    border-bottom: 1px solid var(--color-border, #e6eaf2);
    box-shadow: inset 0 8px 12px -12px rgba(15, 27, 45, 0.12);
    overflow: hidden;                 /* recorta durante la animación de altura */
    transition: height 0.22s ease;
}
@media (prefers-reduced-motion: reduce) {
    .topnav__mega { transition: none; }
}
.topnav__mega-inner { max-width: 1500px; margin: 0 auto; padding: 16px 18px 20px; }
.topnav__mega-title {
    display: block; font-size: 0.66rem; letter-spacing: 0.06em; text-transform: uppercase;
    color: var(--color-text-muted, #8a93a6); margin: 0 8px 8px;
}
.topnav__mega-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 4px 10px;
}
.mega-link {
    display: flex; align-items: center; gap: 11px;
    border: 0; background: transparent; font: inherit; text-align: left;
    padding: 9px 11px; border-radius: 10px; cursor: pointer;
    color: var(--color-text, #33405a); font-size: 0.86rem; font-weight: 500;
    transition: background 0.13s, color 0.13s;
}
.mega-link:hover { background: var(--brand-soft, #f1f5ff); color: var(--brand, #0A6ED1); }
.mega-link.is-active { background: var(--brand-soft, #E6F1FB); color: var(--brand, #0A6ED1); }
.mega-link:disabled { opacity: 0.45; cursor: not-allowed; }
.mega-link__ico {
    width: 32px; height: 32px; flex: none; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    background: var(--color-surface-alt, #eef2fb); color: var(--brand, #0A6ED1); font-size: 0.95rem;
}
.mega-link:hover .mega-link__ico { background: #fff; }
.mega-fade-enter-active, .mega-fade-leave-active { transition: opacity 0.16s, transform 0.16s; }
.mega-fade-enter-from, .mega-fade-leave-to { opacity: 0; transform: translateY(-4px); }
@media (prefers-reduced-motion: reduce) {
    .mega-fade-enter-active, .mega-fade-leave-active { transition: none; }
}

/* ─── Shell Bar (full width arriba) ─────────────────────────────────────── */
.shell-bar {
    background: var(--color-shell-bar, #354A5F);
    padding: 0 8px;
    height: 44px;
    line-height: 44px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 0;
    position: sticky;
    top: 0;
    z-index: 100;
}
.shell-bar__left, .shell-bar__right { display: flex; align-items: center; gap: 2px; }
.shell-bar__left { flex-shrink: 0; }
.shell-bar__right { flex-shrink: 0; }
.shell-bar__search { flex: 1; display: flex; justify-content: center; padding: 0 16px; min-width: 0; }
@media (max-width: 680px) { .shell-bar__search { padding: 0 6px; } .brand-text { display: none; } }

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 12px;
    color: #ffffff;
}
.brand-logo {
    width: 26px;
    height: 26px;
    border-radius: 4px;
    background: #0A6ED1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.8rem;
    flex-shrink: 0;
    color: #ffffff;
    overflow: hidden;
}
.brand-logo:has(.brand-logo-img) { background: transparent; }
.brand-logo-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.brand-text {
    font-size: 0.95rem;
    font-weight: 400;
    letter-spacing: 0.01em;
    white-space: nowrap;
    color: #ffffff;
}

/* Botones del shell bar — densidad SAP Fiori "default" (36px) */
.icon-btn {
    background: transparent;
    border: 0;
    width: 36px;
    height: 36px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: rgba(255, 255, 255, 0.92);
    font-size: 1rem;
    transition: background 0.15s ease, color 0.15s ease;
    padding: 0;
}
.icon-btn :deep(.anticon) { font-size: 18px; }
.icon-btn:hover {
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
}
.icon-btn:active {
    background: rgba(255, 255, 255, 0.18);
}

/* SVG inline (sun/moon) dentro de .icon-btn — hereda el color del botón */
.icon-btn__svg {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.icon-btn__svg :deep(svg),
.icon-btn__svg svg {
    width: 18px;
    height: 18px;
    display: block;
}

.divider {
    width: 1px;
    height: 20px;
    background: rgba(255, 255, 255, 0.20);
    margin: 0 8px;
}

.user-trigger {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    padding: 0 10px 0 4px;
    border: 0;
    background: transparent;
    border-radius: 2px;
    transition: background 0.12s ease;
    height: 36px;
    color: #ffffff;
}
.user-trigger:hover { background: rgba(255, 255, 255, 0.10); }
.user-name { font-weight: 500; color: #ffffff; font-size: 0.85rem; }

.shell-bar :deep(.ant-badge-count) {
    box-shadow: 0 0 0 1px #354A5F;
}

/* ─── Below shell: sidebar + content ──────────────────────────────────── */
.subscription-banner {
    border-radius: 0;
    margin: 0;
}
.below-shell {
    flex-direction: row;
    min-height: calc(100vh - 44px);
}

.app-sider {
    background: var(--color-sidebar-bg, #ffffff) !important;
    border-right: 1px solid rgba(0, 0, 0, 0.14);
    box-shadow: none;
    /* Se ancla bajo el header (shell-bar 44px) y ocupa la altura del viewport;
       el menú scrollea DENTRO en vez de estirar la página (footer largo). */
    position: sticky;
    top: 44px;
    align-self: flex-start;
    height: calc(100vh - 44px);
    overflow: hidden;
}
/* El contenedor interno del Sider es el que scrollea (scrollbar fino). */
.app-sider :deep(.ant-layout-sider-children) {
    height: 100%;
    overflow-y: auto;
    overflow-x: hidden;
    scrollbar-width: thin;
}
.app-sider :deep(.ant-layout-sider-children)::-webkit-scrollbar { width: 6px; }
.app-sider :deep(.ant-layout-sider-children)::-webkit-scrollbar-thumb {
    background: rgba(0, 0, 0, 0.18); border-radius: 3px;
}
/* Badge de pendientes en un ítem del sidebar (ej. Aprobaciones) */
.nav-badge {
    margin-left: auto;
    background: #C8281D;
    color: #fff;
    font-size: 0.68rem;
    font-weight: 700;
    border-radius: 999px;
    padding: 0 7px;
    line-height: 18px;
    min-width: 18px;
    text-align: center;
}

/* Sidebar menu items — Fiori Launchpad */
.app-sider :deep(.ant-menu-light) {
    padding-top: 8px;
}
.app-sider :deep(.ant-menu-light .ant-menu-item) {
    color: rgba(255, 255, 255, 0.82);
    margin: 2px 0 !important;
    border-radius: 0 !important;
    width: 100% !important;
    padding-left: 16px !important;
    height: 40px;
    line-height: 40px;
    font-size: 0.875rem;
}
.app-sider :deep(.ant-menu-light .ant-menu-item .anticon) {
    color: rgba(255, 255, 255, 0.6);
    font-size: 16px;
    margin-right: 12px;
    vertical-align: -2px;
}
.app-sider :deep(.ant-menu-light .ant-menu-item:hover) {
    background-color: rgba(255, 255, 255, 0.1) !important;
    color: #ffffff;
}
.app-sider :deep(.ant-menu-light .ant-menu-item:hover .anticon) {
    color: #ffffff;
}
.app-sider :deep(.ant-menu-light .ant-menu-item-selected) {
    background-color: rgba(255, 255, 255, 0.16) !important;
    color: #ffffff !important;
    border-left: 3px solid var(--color-primary-accent, #ffffff);
    padding-left: 13px !important;
    font-weight: 600;
}
.app-sider :deep(.ant-menu-light .ant-menu-item-selected .anticon) {
    color: #ffffff !important;
}
.app-sider :deep(.ant-menu-light .ant-menu-item-selected::after) {
    display: none;
}

/* ─── Group titles (Accesos / Auditoría / Configuración del sistema) ─── */
/* El título del grupo es un botón clickeable que pliega/expande sus ítems.
   Reseteamos el padding del wrapper de Ant para que el botón lo controle. */
.app-sider :deep(.ant-menu-item-group-title),
.sidebar-mobile-drawer :deep(.ant-menu-item-group-title) {
    padding: 0 !important;
}
.group-header {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    background: transparent;
    border: 0;
    cursor: pointer;
    padding: 14px 16px 6px;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #94a3b8;
    font-weight: 600;
    line-height: 1.2;
    transition: color 0.12s ease;
}
.group-header:hover { color: #0A6ED1; }
/* En el sidebar fijo (fondo oscuro), los headers de grupo van claros. El Drawer
   ("Pantalla completa"/móvil) queda blanco y conserva el gris de arriba. */
.app-sider .group-header { color: rgba(255, 255, 255, 0.62); }
.app-sider .group-header:hover { color: #ffffff; }
.group-header__label { flex: 1; text-align: left; }
.group-header__chevron {
    font-size: 0.6rem !important;
    opacity: 0.6;
    transition: transform 0.18s ease;
}
/* Colapsado → chevron apunta a la derecha (rota -90deg). Expandido → abajo. */
.group-header--collapsed .group-header__chevron {
    transform: rotate(-90deg);
}
/* Modo iconos (sidebar a 64px): el texto del header de grupo no entra y se ve
   apretado. Lo ocultamos — en modo iconos solo se ven los iconos de los items.
   Dejamos un separador fino para mantener la division visual entre grupos. */
.app-sider :deep(.ant-menu-inline-collapsed .ant-menu-item-group-title) {
    padding: 0 !important;
    height: 1px;
    overflow: hidden;
    margin: 6px 12px;
    background: rgba(255, 255, 255, 0.16);
}
.app-sider :deep(.ant-menu-inline-collapsed) .group-header {
    display: none;
}

/* Disabled items (Profiles / Permissions — coming soon) */
.app-sider :deep(.ant-menu-item-disabled) {
    color: rgba(255, 255, 255, 0.32) !important;
    cursor: not-allowed !important;
}
.app-sider :deep(.ant-menu-item-disabled .anticon) {
    color: rgba(255, 255, 255, 0.32) !important;
}

/* ─── Content ─────────────────────────────────────────────────────────── */
/* min-width: 0 → el área de contenido es un flex item junto al sidebar;
   sin esto su min-width = auto (min-content) y contenido ancho (canvas de
   echarts, tablas) impide que se encoja al colapsar el sidebar → la página
   se desborda a la derecha y se deforma. */
.content-layout { min-width: 0; }
.content {
    padding: 24px;
    background: var(--color-page-bg, #F7F7F7);
    min-width: 0;
    /* `clip` (no `hidden`): recorta el overflow horizontal igual, pero NO crea
       un contexto de scroll — así `position: sticky` de los hijos (ej. el sidebar
       flotante de diagnóstico del trafo) se ancla al viewport y no se va al
       hacer scroll. `hidden` rompía el sticky. */
    overflow-x: clip;
}

/* ─── Responsive ──────────────────────────────────────────────────────── */
@media (max-width: 991px) {
    .shell-bar { padding: 0 4px; }
    .brand-text { display: none; }
    .user-name { display: none; }
    .content { padding: 16px; }
}

/* ─── Badge sobre el icon-btn (alineado a la esquina) ─────────────────── */
.shell-bar :deep(.ant-badge) { display: inline-flex; }
.shell-bar :deep(.ant-badge-count) {
    box-shadow: 0 0 0 1.5px #354A5F !important;
    font-size: 0.65rem !important;
    height: 16px !important;
    min-width: 16px !important;
    line-height: 16px !important;
    padding: 0 4px !important;
}
</style>

<!-- =========================================================================
     GLOBAL styles (NOT scoped) — aplica al document.documentElement
     ========================================================================= -->
<style>
/* Dark mode (SAP Quartz Dark) — aplicado vía data-theme="dark" en <html> */
html[data-theme="dark"] body { background: #1d2126; color: #e5e6e7; }

html[data-theme="dark"] .shell-bar { background: #1c2228 !important; }
.legal-modal__text { font-size: 0.9rem; line-height: 1.55; color: #475569; margin: 0 0 10px; }
.legal-modal__links { font-size: 0.88rem; margin: 0 0 14px; }
.legal-modal__check { margin-bottom: 16px; }
.legal-modal__actions { margin-top: 4px; }

html[data-theme="dark"] .app-sider { background: #29313a !important; }
html[data-theme="dark"] .app-sider .ant-menu-light .ant-menu-item { color: #e5e6e7; }
html[data-theme="dark"] .app-sider .ant-menu-light .ant-menu-item .anticon { color: #a8aaae; }
html[data-theme="dark"] .app-sider .ant-menu-light .ant-menu-item:hover {
    background-color: #313a44 !important;
    color: var(--color-primary) !important;
}
html[data-theme="dark"] .app-sider .ant-menu-light .ant-menu-item:hover .anticon { color: var(--color-primary) !important; }
html[data-theme="dark"] .app-sider .ant-menu-light .ant-menu-item-selected {
    background-color: var(--color-surface-selected) !important;
    color: var(--color-primary) !important;
    border-left-color: var(--color-primary) !important;
}
html[data-theme="dark"] .app-sider .ant-menu-light .ant-menu-item-selected .anticon { color: var(--color-primary) !important; }
html[data-theme="dark"] .app-sider .ant-menu-item-group-title { color: #6b7785 !important; }
html[data-theme="dark"] .app-sider .ant-menu-item-disabled { color: #4b5563 !important; }
html[data-theme="dark"] .app-sider .ant-menu-item-disabled .anticon { color: #4b5563 !important; }

html[data-theme="dark"] .content { color: #e5e6e7; }

/* Giantbar en dark mode */
html[data-theme="dark"] .topnav { background: #232a31 !important; border-bottom-color: #313942 !important; }
html[data-theme="dark"] .topnav__btn { color: #c3cad6 !important; }
html[data-theme="dark"] .topnav__btn:hover, html[data-theme="dark"] .topnav__btn.is-open { background: #2d353e !important; color: #fff !important; }
html[data-theme="dark"] .topnav__mega { background: #232a31 !important; border-bottom-color: #313942 !important; }
html[data-theme="dark"] .mega-link { color: #c3cad6 !important; }
html[data-theme="dark"] .mega-link:hover { background: #2d353e !important; color: #fff !important; }
html[data-theme="dark"] .mega-link__ico { background: #2d353e !important; }

/* Adjust shell-bar's badge ring color in dark mode */
html[data-theme="dark"] .shell-bar .ant-badge-count { box-shadow: 0 0 0 1.5px #1c2228 !important; }

/* ─── Shell dropdowns (tema / idioma) — Fiori-style menu ─────────────────
   Vive en un portal fuera de .app-shell, por eso NO va scoped. */
.shell-menu-overlay .ant-dropdown-menu {
    border-radius: 6px !important;
    padding: 6px !important;
    min-width: 180px;
    box-shadow: 0 8px 28px rgba(15, 23, 42, 0.18) !important;
}
.shell-menu .ant-dropdown-menu-item {
    border-radius: 4px !important;
    padding: 8px 12px !important;
    font-size: 0.875rem;
}
/* Ant envuelve el contenido en .ant-dropdown-menu-title-content; ahí va el flex
   para que margin-left:auto del check funcione. */
.shell-menu .ant-dropdown-menu-item .ant-dropdown-menu-title-content {
    display: flex !important;
    align-items: center;
    width: 100%;
}
.shell-menu .ant-dropdown-menu-title-content > .anticon,
.shell-menu .ant-dropdown-menu-title-content > .shell-menu__svg {
    margin-right: 10px;
    flex-shrink: 0;
}
.shell-menu__svg {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 14px;
    height: 14px;
}
.shell-menu__svg svg { width: 14px; height: 14px; }
.shell-menu .ant-dropdown-menu-title-content > .shell-menu__check {
    margin-left: auto;
    margin-right: 0;
    color: #0A6ED1;
    font-size: 0.8rem;
}
.shell-menu .ant-dropdown-menu-item-selected {
    background: rgba(10, 110, 209, 0.08) !important;
    color: #0A6ED1 !important;
    font-weight: 500;
}

/* User dropdown — header con avatar grande + email */
.shell-menu--user { min-width: 240px !important; }
.shell-menu__header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 12px 10px;
}
.shell-menu__user {
    display: flex;
    flex-direction: column;
    line-height: 1.25;
    min-width: 0;
}
.shell-menu__user-name {
    font-size: 0.875rem;
    font-weight: 600;
    color: #1f2937;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.shell-menu__user-email {
    font-size: 0.75rem;
    color: #6A6D70;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.shell-menu__plan {
    padding: 8px 12px;
    background: #F8FAFC;
    border-top: 1px solid #E5E7EB;
    border-bottom: 1px solid #E5E7EB;
}
.shell-menu__plan-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.8125rem;
    padding: 2px 0;
}
.shell-menu__plan-label { color: #6A6D70; }
.shell-menu__plan-value { font-weight: 600; color: #1f2937; }
.shell-menu__plan-value.is-urgent { color: #ea580c; }
html[data-theme="dark"] .shell-menu__plan { background: #2c3034; border-color: #3f4448; }
html[data-theme="dark"] .shell-menu__plan-label { color: #a8aaae; }
html[data-theme="dark"] .shell-menu__plan-value { color: #e5e6e7; }
.shell-menu__logout .ant-dropdown-menu-title-content {
    color: #BB0000;  /* SAP Fiori semantic red */
}
.shell-menu__logout:hover .ant-dropdown-menu-title-content,
.shell-menu__logout:hover .ant-dropdown-menu-title-content > .anticon {
    color: #BB0000 !important;
}
.shell-menu__logout:hover {
    background: rgba(187, 0, 0, 0.06) !important;
}

/* Recent items en el dropdown del avatar — nombre principal en negro,
   módulo en gris pequeño abajo a modo subtítulo. Ant Design no permite
   styling rico en MenuItem por default, así que nuestro <span class="recent-item"
   /> aplica flex column. */
.recent-item {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
    overflow: hidden;
    max-width: 220px;
}
.recent-item__name {
    font-size: 0.875rem;
    color: #1f2937;
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.recent-item__module {
    font-size: 0.7rem;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-top: 2px;
}
html[data-theme="dark"] .recent-item__name { color: #e5e6e7; }
html[data-theme="dark"] .recent-item__module { color: #6b7785; }

/* Dark mode del dropdown — portal vive fuera de .app-shell */
html[data-theme="dark"] .shell-menu-overlay .ant-dropdown-menu {
    background: #2c3034 !important;
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.5) !important;
}
html[data-theme="dark"] .shell-menu .ant-dropdown-menu-item { color: #e5e6e7 !important; }
html[data-theme="dark"] .shell-menu .ant-dropdown-menu-item:hover {
    background: #313a44 !important;
    color: #4db6e8 !important;
}
html[data-theme="dark"] .shell-menu .ant-dropdown-menu-item-selected {
    background: rgba(77, 182, 232, 0.12) !important;
    color: #4db6e8 !important;
}
html[data-theme="dark"] .shell-menu .ant-dropdown-menu-title-content > .shell-menu__check {
    color: #4db6e8;
}
html[data-theme="dark"] .shell-menu__user-name { color: #e5e6e7; }
html[data-theme="dark"] .shell-menu__user-email { color: #a8aaae; }
html[data-theme="dark"] .shell-menu .ant-dropdown-menu-item-divider {
    background-color: #3f4448 !important;
}
html[data-theme="dark"] .shell-menu__logout .ant-dropdown-menu-title-content,
html[data-theme="dark"] .shell-menu__logout:hover .ant-dropdown-menu-title-content,
html[data-theme="dark"] .shell-menu__logout:hover .ant-dropdown-menu-title-content > .anticon {
    color: #ff6b6b !important;
}
html[data-theme="dark"] .shell-menu__logout:hover {
    background: rgba(255, 107, 107, 0.10) !important;
}

/* ── Bell de descargas — dropdown con la lista de archivos generados ──── */
.shell-notifications-overlay .ant-dropdown-menu {
    padding: 0 !important;
    min-width: 320px !important;
    max-width: 360px;
}
/* Mobile: el panel de notificaciones/mensajes ocupa el ancho de la pantalla
   (menos un margen) para que Ant NO tenga que reposicionarlo al abrir ni al
   refrescar cada 4s — eso causaba el "movimiento random" en el celular. Scoped
   a pantallas chicas: el desktop queda exactamente igual. */
@media (max-width: 640px) {
    .shell-notifications-overlay .ant-dropdown-menu {
        min-width: calc(100vw - 16px) !important;
        max-width: calc(100vw - 16px) !important;
    }
    .shell-notifications-overlay .notifications-menu__list { max-height: 60vh; }
    /* Sin animación de apertura/reposición en mobile: si Ant llega a realinear
       (scroll, barra del navegador), que sea instantáneo y no "cámara lenta". */
    .shell-notifications-overlay { animation: none !important; transition: none !important; }
}
.notifications-menu {
    background: #ffffff;
    border-radius: 6px;
    overflow: hidden;
}
.notifications-menu__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 14px;
    border-bottom: 1px solid #E5E5E5;
}
.notifications-menu__title {
    font-size: 0.875rem;
    font-weight: 600;
    color: #32363A;
}
.notifications-menu__pulse {
    font-size: 0.72rem;
    color: #0A6ED1;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.notifications-menu__empty {
    padding: 28px 16px;
    text-align: center;
    color: #6A6D70;
}
.notifications-menu__empty p { margin: 8px 0 4px; font-size: 0.875rem; color: #32363A; }
.notifications-menu__empty small { font-size: 0.78rem; color: #6A6D70; }

.notifications-menu__list {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 380px;
    overflow-y: auto;
}
.notifications-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    border-bottom: 1px solid #F0F0F0;
    cursor: pointer;
    transition: background 0.12s ease;
    position: relative;
}
.notifications-item:last-child { border-bottom: 0; }
.notifications-item:hover { background: #F0F6FB; }
.notifications-item--ready::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: #0A6ED1;
}
.notifications-item__icon {
    font-size: 1.6rem;
    flex-shrink: 0;
}
.notifications-item__body { flex: 1; min-width: 0; }
.notifications-item__name {
    font-size: 0.8125rem;
    font-weight: 500;
    color: #32363A;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.notifications-item__status {
    font-size: 0.72rem;
    color: #6A6D70;
    margin-top: 2px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.notifications-item__status.is-processing { color: #0A6ED1; }
.notifications-item__status.is-ready      { color: #1D7044; font-weight: 600; }
.notifications-item__status.is-failed     { color: #C8281D; }
.notifications-item__app-body {
    font-size: 0.72rem;
    color: #6A6D70;
    margin-top: 2px;
    line-height: 1.35;
    /* 1 sola linea con elipsis — el detalle completo se ve en /notifications.
       El bell debe ser un "preview" rapido. */
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
html[data-theme="dark"] .notifications-item__app-body { color: #a0a3a6; }
/* Tag con el nombre del workspace al lado del titulo — solo visible para
   super con notifs de automation, para distinguir cross-tenant. */
.notifications-item__tenant-badge {
    margin-left: 6px !important;
    font-size: 0.65rem !important;
    line-height: 1.4 !important;
    padding: 0 6px !important;
    vertical-align: middle;
}

.notifications-item__actions { display: inline-flex; gap: 2px; flex-shrink: 0; }
.notifications-item__btn {
    background: transparent;
    border: 0;
    width: 28px;
    height: 28px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #6A6D70;
    transition: background 0.12s ease, color 0.12s ease;
}
.notifications-item__btn:hover { background: #E6F1FB; color: #0A6ED1; }
.notifications-item__btn--danger:hover { background: rgba(200, 40, 29, 0.08); color: #C8281D; }

.notifications-menu__footer {
    border-top: 1px solid #E5E5E5;
    padding: 8px;
    background: #F8FAFC;
}
.notifications-menu__view-all {
    width: 100%;
    background: transparent;
    border: 0;
    padding: 8px 12px;
    font-size: 0.8125rem;
    font-weight: 500;
    color: #0A6ED1;
    cursor: pointer;
    border-radius: 4px;
    transition: background 0.12s ease;
}
.notifications-menu__view-all:hover { background: #E6F1FB; }

/* Dark mode */
html[data-theme="dark"] .notifications-menu { background: #2c3034; }
html[data-theme="dark"] .notifications-menu__header { border-bottom-color: #3f4448; }
html[data-theme="dark"] .notifications-menu__title { color: #e5e6e7; }
html[data-theme="dark"] .notifications-menu__pulse { color: #4db6e8; }
html[data-theme="dark"] .notifications-menu__empty p { color: #e5e6e7; }
html[data-theme="dark"] .notifications-menu__empty small { color: #a8aaae; }
html[data-theme="dark"] .notifications-item { border-bottom-color: #3f4448; }
html[data-theme="dark"] .notifications-item:hover { background: #313a44; }
html[data-theme="dark"] .notifications-item--ready::before { background: #4db6e8; }
html[data-theme="dark"] .notifications-item__name { color: #e5e6e7; }
html[data-theme="dark"] .notifications-item__btn { color: #a8aaae; }
html[data-theme="dark"] .notifications-item__btn:hover { background: rgba(77, 182, 232, 0.12); color: #4db6e8; }
html[data-theme="dark"] .notifications-menu__footer { background: #29313a; border-top-color: #3f4448; }
html[data-theme="dark"] .notifications-menu__view-all { color: #4db6e8; }
html[data-theme="dark"] .notifications-menu__view-all:hover { background: rgba(77, 182, 232, 0.12); }
</style>
