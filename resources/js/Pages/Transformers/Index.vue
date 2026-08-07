<script setup>
import { computed, ref, watch, inject, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Card, Tag, Button, Tooltip, Space, Badge, Dropdown, Menu, MenuItem, Input, Drawer, Pagination, Spin, Checkbox, message } from 'ant-design-vue';
import {
    PlusOutlined, EditOutlined, InboxOutlined,
    DownloadOutlined, UploadOutlined, QuestionCircleOutlined,
    FilterOutlined, CloseCircleFilled, DashboardOutlined, EllipsisOutlined, SearchOutlined,
    AppstoreOutlined, SortAscendingOutlined, SortDescendingOutlined,
    StarOutlined, StarFilled, AudioOutlined, CloseOutlined,
    SettingOutlined, TableOutlined, BarsOutlined,
    DeploymentUnitOutlined, TagOutlined, ThunderboltOutlined, FireOutlined,
    ExperimentOutlined, NodeIndexOutlined, ControlOutlined, ContainerOutlined,
    FormOutlined, ClearOutlined, SaveOutlined,
    BranchesOutlined, CalendarOutlined,
} from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import ColumnSelector from '@/Components/Common/ColumnSelector.vue';
import InlineFilterBuilder from '@/Components/Common/InlineFilterBuilder.vue';
import ExportDialog from '@/Components/Common/ExportDialog.vue';
import ImportDialog from '@/Components/Common/ImportDialog.vue';
import SavedViews from '@/Components/Common/SavedViews.vue';

import TransformersBulkBar from '@/Components/Transformers/TransformersBulkBar.vue';
import ShareModal from '@/Components/Sharing/ShareModal.vue';
import TransformersBulkDeleteModal from '@/Components/Transformers/TransformersBulkDeleteModal.vue';
import TransformersFavoriteCell from '@/Components/Transformers/TransformersFavoriteCell.vue';
import TransformersPageHeader from '@/Components/Transformers/TransformersPageHeader.vue';
import TransformersActionsCell from '@/Components/Transformers/TransformersActionsCell.vue';
import TransformersEmptyState from '@/Components/Transformers/TransformersEmptyState.vue';
import TransformerGlyph from '@/Components/Transformers/TransformerGlyph.vue';

import { useAuth } from '@/Composables/useAuth';
import { useColumnPreferences } from '@/Composables/useColumnPreferences';
import { useModuleFilters } from '@/Composables/useModuleFilters';
import { useModuleBulkActions } from '@/Composables/useModuleBulkActions';
import { useModuleUndoToast } from '@/Composables/useModuleUndoToast';
import { useModuleFavorites } from '@/Composables/useModuleFavorites';
import { useModuleSavedViews } from '@/Composables/useModuleSavedViews';
import { useModuleListMeta } from '@/Composables/useModuleListMeta';
import { useModuleTour } from '@/Composables/useModuleTour';
import { useKeyboardShortcuts } from '@/Composables/useKeyboardShortcuts';
import { useViewport } from '@/Composables/useViewport';
import { useDateFormat } from '@/Composables/useDateFormat';
import { usePlanFeatures } from '@/Composables/usePlanFeatures';
import { useI18n } from '@/Plugins/i18n';

// Gate per plan: saved_views requiere basic+, imports/edit_all requieren pro+.
// El toolbar inline de Transformers (no usa ModuleToolbar) repite manualmente
// estos gates para no mostrar botones que no funcionan al user free/basic.
const { canUse: canUsePlanFeature } = usePlanFeatures();

import {
    transformersEmptyFilters, hydrateTransformersFilters,
    transformersFiltersToQuery, transformersFiltersSummary,
    serializeSavedFilters, deserializeSavedFilters,
} from './config/filters';
import { transformersTableColumns } from './config/columns';
import { transformersExportableColumns, transformersExportEndpoints } from './config/exports';
import { moduleTourSteps } from '@/Composables/moduleTourSteps';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const { can, isSuper } = useAuth();
const { formatDateTime } = useDateFormat();

const props = defineProps({
    transformers:      { type: Object, required: true },
    filters:        { type: Object, default: () => ({}) },
    filterSchema:   { type: Array,  default: () => [] },
    customerOptions:        { type: Array, default: () => [] },
    transformerTypeOptions: { type: Array, default: () => [] },
    oilTypeOptions:         { type: Array, default: () => [] },
    substationOptions:      { type: Array, default: () => [] },
    tapChangerTypeOptions:  { type: Array, default: () => [] },
    connectionTypeOptions:  { type: Array, default: () => [] },
    preservationOptions:    { type: Array, default: () => [] },
    brandOptions:           { type: Array, default: () => [] },
    exportLimits:           { type: Object, default: () => ({}) },
});

// Chips rápidos de condición (ninguno por defecto). Sin pruebas = sin HI.
const healthBandChips = computed(() => [
    { value: 'good',    label: t('transformers.band_good'),    color: '#1D7044' },
    { value: 'regular', label: t('transformers.band_regular'), color: '#E9A23B' },
    { value: 'bad',     label: t('transformers.band_bad'),     color: '#C8281D' },
    { value: 'none',    label: t('transformers.band_none'),    color: '#9aa0a6' },
]);

// Color del Índice de Salud por banda (Hitachi): >85 verde … ≤30 rojo.
const healthColor = (hi) => {
    if (hi == null) return '#9aa0a6';
    if (hi > 85) return '#1D7044';
    if (hi > 70) return '#5AA82E';
    if (hi > 50) return '#E9A23B';
    if (hi > 30) return '#E2661E';
    return '#C8281D';
};
const phaseLabel = (p) => (p ? t('transformers.phases_' + p) : '—');

// Badges de la tarjeta: data-driven para que TODOS salgan iguales (misma fuente,
// mismo estilo) y solo cambien por su icono + tooltip. Orden fijo; se omite el
// que no tiene dato. Incluye conexión (faltaba), conmutador, preservación,
// fases y año.
const cardBadges = (r) => {
    const b = [];
    // Algunos catálogos legacy tienen el name literal "-" (placeholder de "ninguno").
    // No los mostramos: un badge "-" es ruido visual.
    const nm = (v) => (v && v !== '-' ? v : null);
    if (nm(r.transformer_type?.name)) b.push({ icon: DeploymentUnitOutlined, text: r.transformer_type.name, tip: t('transformers.transformer_type') });
    if (nm(r.brand?.name))            b.push({ icon: TagOutlined,            text: r.brand.name,            tip: t('transformers.brand') });
    if (nm(r.connection_type?.name))  b.push({ icon: NodeIndexOutlined,      text: r.connection_type.name,  tip: t('transformers.connection_type') });
    if (nm(r.oil_type?.name))         b.push({ icon: ExperimentOutlined,     text: r.oil_type.name,         tip: t('transformers.oil_type') });
    if (nm(r.tap_changer_type?.name)) b.push({ icon: ControlOutlined,        text: r.tap_changer_type.name, tip: t('transformers.tap_changer_type') });
    if (nm(r.preservation?.name))     b.push({ icon: ContainerOutlined,      text: r.preservation.name,     tip: t('transformers.preservation') });
    if (r.phases)                 b.push({ icon: BranchesOutlined,       text: phaseLabel(r.phases),    tip: t('transformers.phases') });
    if (r.voltage_kv != null)     b.push({ icon: ThunderboltOutlined,    text: r.voltage_kv, unit: 'kV',  tip: t('transformers.voltage_kv') });
    if (r.power_mva != null)      b.push({ icon: FireOutlined,           text: r.power_mva,  unit: 'MVA', tip: t('transformers.power_mva') });
    if (r.manufacture_year)       b.push({ icon: CalendarOutlined,       text: r.manufacture_year,      tip: t('transformers.manufacture_year') });
    return b;
};

// Anillo del HI (vista de tarjetas): circunferencia para r=30.
const HI_CIRC = 2 * Math.PI * 30; // ≈ 188.5
const healthDash = (hi) => {
    const v = hi == null ? 0 : Math.max(0, Math.min(100, hi));
    return `${((v / 100) * HI_CIRC).toFixed(1)} ${HI_CIRC.toFixed(1)}`;
};
// Tendencia REAL del motor de cromas (health_trend = dirección del DGAF entre las
// 2 últimas muestras). null = <2 muestras → no se muestra chip.
const trendMeta = (trend) => {
    if (trend === 'worsening') return { cls: 'tcard__trend--up',   glyph: '▲', label: t('transformers.fleet.trend_worsening') };
    if (trend === 'improving') return { cls: 'tcard__trend--down', glyph: '▼', label: t('transformers.fleet.trend_improving') };
    if (trend === 'stable')    return { cls: 'tcard__trend--flat', glyph: '■', label: t('transformers.fleet.trend_stable') };
    return null;
};

// ─── Modo de vista: tabla | tarjetas (persistido por usuario, desktop only) ──
const VIEW_KEY = 'transformers_view_mode';
const viewMode = ref('table');
onMounted(() => {
    const saved = localStorage.getItem(VIEW_KEY);
    if (saved === 'cards' || saved === 'table' || saved === 'list') viewMode.value = saved;
});
watch(viewMode, (v) => localStorage.setItem(VIEW_KEY, v));
const viewOptions = computed(() => [
    { value: 'table', label: t('global.view_table'),      icon: TableOutlined },
    { value: 'list',  label: t('global.view_list_short'), icon: BarsOutlined },
    { value: 'cards', label: t('global.view_cards'),      icon: AppstoreOutlined },
]);
const currentView = computed(() => viewOptions.value.find((o) => o.value === viewMode.value) ?? viewOptions.value[0]);
const setView = ({ key }) => { viewMode.value = key; };
// Paginación de la vista de tarjetas (reusa el reload de filtros).
const onCardsPageChange = (page, pageSize) => reload({ page, per_page: pageSize });

const {
    filters, reload, isFetching, suspendReload, hasActiveFilters, clearFilters, filtersSummary, buildQueryData,
} = useModuleFilters({
    serverFilters: props.filters,
    hydrate:       hydrateTransformersFilters,
    toQuery:       transformersFiltersToQuery,
    summary:       transformersFiltersSummary,
    empty:         transformersEmptyFilters,
    only:          ['transformers', 'filters'],
    t,
});

// Buscador global tipo Google: un término que busca (OR) en serie, tag, cliente,
// marca, tipo y aceite (lo resuelve el backend en scopeFilter, param `q`). El
// builder de condiciones sigue para filtros precisos.
const quickSearch = computed({
    get: () => filters.value.q ?? '',
    set: (v) => { filters.value.q = v ?? ''; },
});

// ─── Búsqueda por voz (Web Speech API nativa — sin librerías) ───────────────
// Solo Chrome/Edge la soportan y requiere HTTPS o localhost. Si no existe, el
// micrófono ni se muestra (degradación elegante).
const SpeechRec = typeof window !== 'undefined' ? (window.SpeechRecognition || window.webkitSpeechRecognition) : null;
const micSupported = !!SpeechRec;
const listening = ref(false);
let recognition = null;
const startVoiceSearch = () => {
    if (!SpeechRec) return;
    if (listening.value) { recognition?.stop(); return; }
    if (!recognition) {
        recognition = new SpeechRec();
        recognition.lang = (document.documentElement.lang || 'es').startsWith('en') ? 'en-US' : 'es-PE';
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;
        recognition.onresult = (e) => { quickSearch.value = (e.results[0][0].transcript || '').trim(); };
        recognition.onend = () => { listening.value = false; };
        recognition.onerror = () => { listening.value = false; };
    }
    listening.value = true;
    try { recognition.start(); } catch { listening.value = false; }
};

// ─── Filtros avanzados (drawer con query builder dinámico) ──────────────────
// Estos NO viven en useModuleFilters porque son un array de clausulas
// estructuradas {field, op, value}, distinto al shape plano del FilterBar.
// Se persisten via Inertia (filters.advanced_where) para que sobreviva al
// paginate/sort sin perder el filtro aplicado.
const advancedWhere = ref(Array.isArray(props.filters?.advanced_where) ? props.filters.advanced_where : []);
const advancedCount = computed(() => advancedWhere.value.length);

// ── Filtros Fiori: panel toggle + Guardar Filtro + contador ─────────────────
const savedViewsRef = ref(null);
const builderRef = ref(null);
const showFilters = ref(advancedWhere.value.length > 0 || hasActiveFilters.value);
const isFilterComplete = (c) => {
    if (!c || !c.field || !c.op) return false;
    if (Array.isArray(c.value)) return c.value.length > 0;
    return c.value !== '' && c.value !== null && c.value !== undefined;
};
const activeFilterCount = computed(() => advancedWhere.value.filter(isFilterComplete).length);
const toggleFilters = async () => {
    if (showFilters.value) {
        advancedWhere.value = advancedWhere.value.filter(isFilterComplete);
        showFilters.value = false;
        return;
    }
    showFilters.value = true;
    await nextTick();
    if (advancedWhere.value.length === 0) builderRef.value?.addRow();
};
watch(() => advancedWhere.value.length, (n) => {
    if (n === 0 && showFilters.value) showFilters.value = false;
});

// Builder inline (Airtable-style): aplica SOLO las cláusulas completas, sin
// reescribir advancedWhere (así la fila a medio editar no desaparece). Debounce
// para no recargar en cada tecla de un valor de texto.
let inlineTimer = null;
const applyInlineFilters = (cleaned) => {
    clearTimeout(inlineTimer);
    inlineTimer = setTimeout(() => {
        const data = {
            ...buildQueryData(),
            sort: props.filters.sort,
            direction: props.filters.direction,
            per_page: props.filters.per_page,
        };
        if (cleaned.length > 0) data.advanced_where = JSON.stringify(cleaned);
        router.get(route('business_management.transformers.index'), data, {
            preserveScroll: true,
            preserveState: true,
            onStart:  () => { isFetching.value = true; },
            onFinish: () => { isFetching.value = false; },
        });
    }, 350);
};

// Aplica un estado de vista guardada que incluye condiciones del builder:
// navega en UNA request con los simples (buildQueryData) + advanced_where + sort.
const applySavedViewState = (clauses, meta) => {
    const data = {
        ...buildQueryData(),
        sort: meta.sort,
        direction: meta.direction,
        per_page: meta.perPage,
    };
    if (clauses.length > 0) data.advanced_where = JSON.stringify(clauses);
    router.get(route('business_management.transformers.index'), data, {
        preserveScroll: true,
        // preserveState evita re-montar la página: así SavedViews conserva su
        // vista activa (sin esto el indicador volvía siempre a "Por defecto").
        preserveState: true,
        onStart:  () => { isFetching.value = true; },
        onFinish: () => { isFetching.value = false; },
    });
};

// "Limpiar todo": resetea buscador (serie) + condiciones del builder, URL limpia.
const clearAll = () => {
    advancedWhere.value = [];
    filters.value = transformersEmptyFilters();
    router.get(
        route('business_management.transformers.index'),
        {
            sort: props.filters.sort,
            direction: props.filters.direction,
            per_page: props.filters.per_page,
        },
        { preserveScroll: true },
    );
};

// ─── Contador adaptativo "X registros" / "X de Y registros" ────────────────
const { counterLabel } = useModuleListMeta({
    pagination: computed(() => props.transformers),
    hasActiveFilters,
    t,
});

// ─── Columnas (schema en config/columns.js) ─────────────────────────────────
// Viewport: el ancho de la columna de acciones depende de si es pantalla chica
// (kebab vs botones), así que se declara ANTES de allColumns (lo consume el
// computed) para evitar pantalla en blanco por TDZ.
const { isMobile: isMobileScreen } = useViewport(768);

const allColumns = computed(() =>
    transformersTableColumns(t, { isSuper: isSuper.value, isMobile: isMobileScreen.value }),
);
const { visibleColumnKeys, columns } = useColumnPreferences(allColumns);

// ─── Columnas activas (orden/filtro) → cards mobile ─────────────────────────
// La card mobile muestra serie + tag + estado por defecto (config: mobile.primary),
// y suma la columna que el usuario esté consultando (ordena o filtra). Esto mapea
// cada filtro/orden a su columna; ResponsiveTable hace el resto.
const FILTER_TO_COLUMN = {
    customer_id: 'customer', customer_substation_id: 'customer',
    transformer_type_id: 'transformer_type', oil_type_id: 'oil_type',
    brand_id: 'brand', tap_changer_type_id: 'tap_changer_type',
    connection_type_id: 'connection_type', transformer_preservation_id: 'preservation',
    voltage: 'voltage_kv', power: 'power_mva', year: 'manufacture_year',
    phases: 'phases', health_rating: 'health', tag: 'tag', serial: 'serial',
    created_at: 'created_at',
};
const colKeyForField = (field) => {
    if (!field) return null;
    const join = (di) => (Array.isArray(di) ? di.join('.') : di);
    const col = allColumns.value.find((c) => c.key === field || join(c.dataIndex) === field);
    return col?.key ?? null;
};
const isFilterActive = (v) => (Array.isArray(v) ? v.length > 0 : (v !== null && v !== undefined && v !== '' && v !== false));
const activeColumnKeys = computed(() => {
    const keys = new Set();
    const sortCol = colKeyForField(props.filters?.sort);
    if (sortCol) keys.add(sortCol);
    const f = filters.value ?? {};
    for (const [fk, col] of Object.entries(FILTER_TO_COLUMN)) {
        if (isFilterActive(f[fk])) keys.add(col);
    }
    for (const c of (advancedWhere.value ?? [])) {
        const col = colKeyForField(c?.field);
        if (col) keys.add(col);
    }
    return [...keys];
});

// ─── Paginacion + sort ──────────────────────────────────────────────────────
const tablePagination = computed(() => ({
    current:  props.transformers.current_page,
    pageSize: props.transformers.per_page,
    total:    props.transformers.total,
    showSizeChanger: true,
    pageSizeOptions: ['10', '25', '50', '100'],
}));

const onTableChange = (pag, _f, sorter) => {
    // Columnas de relación tienen dataIndex anidado (['customer','name']); el
    // backend espera la clave corta ('customer'). Normaliza array y 'a.b'.
    let sort = sorter?.field || props.filters.sort;
    if (Array.isArray(sort)) sort = sort[0];
    else if (typeof sort === 'string' && sort.includes('.')) sort = sort.split('.')[0];

    const direction = sorter?.order === 'ascend' ? 'asc'
                    : sorter?.order === 'descend' ? 'desc'
                    : props.filters.direction;
    reload({ page: pag.current, per_page: pag.pageSize, sort, direction });
};

// ─── Orden global (dropdown — funciona en tabla Y tarjetas) ─────────────────
const normField = (di) => Array.isArray(di) ? di[0] : (typeof di === 'string' && di.includes('.') ? di.split('.')[0] : di);
const sortOptions = computed(() =>
    allColumns.value
        .filter((c) => c.sorter)
        .map((c) => ({ value: normField(c.dataIndex), label: typeof c.title === 'string' ? c.title : c.key })),
);
const currentSort = computed(() => props.filters?.sort ?? 'id');
const currentDir  = computed(() => props.filters?.direction ?? 'desc');
const currentSortLabel = computed(() =>
    sortOptions.value.find((o) => o.value === currentSort.value)?.label ?? t('global.created_at'),
);
const setSort = ({ key }) => {
    const dir = key === currentSort.value && currentDir.value === 'asc' ? 'desc' : 'asc';
    reload({ sort: key, direction: dir, page: 1 });
};
const toggleSortDir = () =>
    reload({ sort: currentSort.value, direction: currentDir.value === 'asc' ? 'desc' : 'asc', page: 1 });

// ─── Solo favoritos (toggle) ────────────────────────────────────────────────
const onlyFavorites = computed({
    get: () => !!filters.value.only_favorites,
    set: (v) => { filters.value.only_favorites = v; },
});
const toggleOnlyFavorites = () => {
    const next = !onlyFavorites.value;
    suspendReload(() => {
        clearFilters();
        filters.value.only_favorites = next;
    });
    advancedWhere.value = [];
    applySavedViewState([], {
        sort:      props.filters.sort,
        direction: props.filters.direction,
        perPage:   props.filters.per_page,
    });
};

// Chip de condición: exclusivo como "Todos"/"Favoritos". Limpia el resto y deja
// solo el preset.
const onTogglePreset = (val) => {
    suspendReload(() => {
        clearFilters();
        filters.value.health_band = val;
    });
    advancedWhere.value = [];
    applySavedViewState([], {
        sort:      props.filters.sort,
        direction: props.filters.direction,
        perPage:   props.filters.per_page,
    });
};

// ─── Undo toast (60s window) ────────────────────────────────────────────────
useModuleUndoToast('business_management.transformers.undo_last_delete');

// ─── Favoritos polimorficos ────────────────────────────────────────────────
const { submitting: favoriteSubmitting, toggle: toggleFavorite } = useModuleFavorites('transformers', 'transformers');

// ─── Bulk ───────────────────────────────────────────────────────────────────
const {
    selectedRowKeys, rowSelection, clearSelection,
    bulkOpen, bulkReason, bulkSubmitting, bulkError,
    openBulkDelete, confirmBulkDelete,
} = useModuleBulkActions({
    bulkDeleteRoute:    'business_management.transformers.bulk_delete',
    resourceLabel:      t('transformers.records'),
    // Deshabilita el checkbox de filas BLOQUEADAS (Lockable): el bulk igual las
    // saltea en el server, pero así ni se pueden tildar.
    rowDisabled:        (r) => !!(r.is_locked ?? r.locked_at),
});

// ─── Compartir la selección ─────────────────────────────────────────────────
// El portal del cliente muestra la flota DE UN cliente, así que la selección
// tiene que ser de uno solo. Si mezcla clientes se avisa en vez de mandar algo
// a medias (se compartirían trafos ajenos al destinatario).
const shareOpen = ref(false);
const shareCustomerId = ref(null);
const shareOptions = ref([]);   // [{id,label}] de lo elegido, para el modal

// Clientes distintos que abarca la selección. Se calcula EN VIVO para poder
// deshabilitar el botón con el motivo a la vista, en vez de avisar recién
// después del clic.
const clientesElegidos = computed(() => {
    const elegidos = (props.transformers.data ?? []).filter((r) => selectedRowKeys.value.includes(r.id));
    return [...new Map(elegidos.filter((r) => r.customer?.id).map((r) => [r.customer.id, r.customer.name]))];
});

// Texto del impedimento (vacío = se puede compartir). El enlace del portal es
// de UN cliente: mezclarlos le mostraría al destinatario trafos de otra empresa.
const shareBlocked = computed(() => {
    const c = clientesElegidos.value;
    if (c.length === 0) return t('sharing.bulk_no_customer');
    if (c.length === 1) return '';
    const nombres = c.slice(0, 3).map(([, name]) => name).join(', ');
    const resto = c.length > 3 ? t('sharing.bulk_and_more', { n: c.length - 3 }) : '';
    return t('sharing.bulk_mixed_customers', { n: c.length, names: nombres + resto });
});

const openBulkShare = () => {
    if (shareBlocked.value) return;   // el botón ya está deshabilitado
    const elegidos = (props.transformers.data ?? []).filter((r) => selectedRowKeys.value.includes(r.id));
    // Las etiquetas salen de las filas ya cargadas: el modal no tiene que pedir
    // la flota entera del cliente solo para mostrar los nombres de lo elegido.
    shareOptions.value = elegidos.map((r) => ({ id: r.id, label: r.serial || r.tag || String(r.id) }));
    shareCustomerId.value = clientesElegidos.value[0][0];
    shareOpen.value = true;
};

// Excel de la selección (mismo libro multi-pestaña del reporte de flota:
// resumen + una hoja por prueba + diagnósticos). Va por cola como el resto de
// los exports pesados; llega al centro de descargas.
const exportBulkExcel = () => {
    router.post(route('business_management.transformers.fleet_report_excel'), {
        transformer_ids: selectedRowKeys.value,
    }, { preserveScroll: true, preserveState: true });
};

// Selección en la vista de tarjetas/lista (reusa selectedRowKeys del bulk).
// Un registro BLOQUEADO (Lockable) no se puede tildar (igual que en la tabla,
// donde el checkbox sale deshabilitado vía rowSelection).
const isRowLocked = (r) => !!(r?.is_locked ?? r?.locked_at);
const isCardSelected = (record) => selectedRowKeys.value.includes(record.id);
const toggleCardSelect = (record) => {
    if (isRowLocked(record)) return;
    selectedRowKeys.value = isCardSelected(record)
        ? selectedRowKeys.value.filter((k) => k !== record.id)
        : [...selectedRowKeys.value, record.id];
};

// ─── Duplicate ──────────────────────────────────────────────────────────────
const duplicating = ref(null);
const duplicate = (record) => {
    duplicating.value = record.id;
    router.post(route('business_management.transformers.duplicate', record.slug), {}, {
        preserveScroll: true,
        onFinish: () => { duplicating.value = null; },
    });
};

// ─── Export / Import (columnas + endpoints en config/exports.js) ────────────
const exportOpen = ref(false);
const importOpen = ref(false);
// Ref al ColumnSelector (montado oculto) para abrirlo desde el engranaje.
const colSel = ref(null);
const exportableColumns = computed(() => transformersExportableColumns(t, { isSuper: isSuper.value }));
const exportEndpoints   = computed(() => transformersExportEndpoints());

// ─── Saved Views (filtros + columnas + sort persistidos por usuario) ──────
const { currentViewState, applySavedState } = useModuleSavedViews({
    filters,
    visibleColumnKeys,
    allColumns,
    serverFilters: props.filters,
    serialize:     serializeSavedFilters,
    deserialize:   deserializeSavedFilters,
    clearFilters,
    reload,
    advancedWhere,
    applyWithAdvanced: applySavedViewState,
    suspendReload,
});

// ─── Onboarding tour (pasos en config/tour.js) ──────────────────────────────
const tour = useModuleTour({ module: 'transformers', steps: () => moduleTourSteps(t, { hasFleet: true, moduleName: t('transformers.plural') }) });

// ─── Keyboard shortcuts ────────────────────────────────────────────────────
useKeyboardShortcuts({
    'ctrl+n': () => can('transformers.create') && router.visit(route('business_management.transformers.create')),
    'esc': () => {
        if (exportOpen.value)             exportOpen.value = false;
        else if (importOpen.value)        importOpen.value = false;
        else if (bulkOpen.value)          bulkOpen.value = false;
    },
    'ctrl+f': () => {
        // Abre el panel de filtros inline y enfoca el buscador de la toolbar.
        showFilters.value = true;
        document.querySelector('.mi-bar--toolbar input')?.focus();
    },
});

// ─── Acciones ───────────────────────────────────────────────────────────────
const goEdit   = (record) => router.visit(route('business_management.transformers.edit',   record.slug));
const goDelete = (record) => router.visit(route('business_management.transformers.delete', record.slug));
</script>

<template>
    <Head :title="$t('transformers.plural')" />

    <div class="sap-index">
        <!-- Título + tabs; las acciones viven en el toolbar de la tabla. -->
        <div class="tx-title" data-tour="module">
            <TransformersPageHeader
                :title="$t('transformers.plural')"
            />
        </div>

        <!-- Consola de filtros: un solo panel que agrupa busqueda + acciones +
             controles + builder, para que se lea como UNA unidad (no filas sueltas). -->
        <div class="tx-console">
            <!-- Vistas guardadas + chips de condición (misma fila, junto a favoritos). -->
            <div v-if="canUsePlanFeature('saved_views')" class="mi-viewsbar" data-tour="saved-views">
                <SavedViews
                    ref="savedViewsRef"
                    layout="bar"
                    variant="tabs"
                    module="transformers"
                    :show-add="false"
                    :current-state="currentViewState"
                    :show-favorites="true"
                    :favorites-active="onlyFavorites"
                    @apply="applySavedState"
                    @default-loaded="applySavedState"
                    @toggle-favorites="toggleOnlyFavorites"
                    :presets="healthBandChips"
                    :preset-active="filters.health_band"
                    @toggle-preset="onTogglePreset"
                />
            </div>

            <!-- ColumnSelector oculto: expone open() al engranaje/Columnas. -->
            <span class="tx-colsel-host" aria-hidden="true">
                <ColumnSelector
                    ref="colSel"
                    :columns="allColumns"
                    v-model="visibleColumnKeys"
                    storage-key="transformers_v3"
                />
            </span>
        </div>

        <!-- Resultados: toolbar pegada arriba + tabla/cards (no flota suelta). -->
        <div class="mi-resultpanel">
            <div class="mi-tabletoolbar">
                <div class="mi-tabletoolbar__left">
                    <span class="mi-toolbar-count">{{ counterLabel }}</span>
                </div>
                <div class="mi-tabletoolbar__right">
                    <label class="mi-bar mi-bar--toolbar" :class="{ 'is-active': quickSearch }">
                        <SearchOutlined class="mi-bar__icon" />
                        <input v-model="quickSearch" class="mi-bar__input" :placeholder="$t('transformers.search_global')" autocomplete="off" spellcheck="false" type="text" />
                        <button v-if="quickSearch" type="button" class="mi-bar__act" :title="$t('global.clear')" @click="quickSearch = ''"><CloseOutlined /></button>
                        <Tooltip v-if="micSupported" :title="$t('global.voice_search')">
                            <button type="button" class="mi-bar__act mi-bar__mic" :class="{ 'mi-bar__mic--on': listening }" @click="startVoiceSearch"><AudioOutlined /></button>
                        </Tooltip>
                    </label>
                    <Tooltip :title="$t('global.filters')">
                        <Button class="mi-iconbtn" :class="{ 'mi-iconbtn--active': showFilters || activeFilterCount > 0 }" @click="toggleFilters" data-tour="advanced-filters">
                            <FilterOutlined />
                            <span v-if="activeFilterCount > 0" class="mi-iconbtn__count">{{ activeFilterCount }}</span>
                        </Button>
                    </Tooltip>
                    <span v-if="viewMode !== 'table' || isMobileScreen" class="mi-sortgroup" data-tour="sort">
                        <Dropdown :trigger="['click']" placement="bottomRight">
                            <Tooltip :title="$t('global.sort_by_hint')">
                                <Button class="sort-btn">
                                    <SortAscendingOutlined v-if="currentDir === 'asc'" />
                                    <SortDescendingOutlined v-else />
                                    <span class="sort-btn__label">{{ $t('global.sort_by') }}: {{ currentSortLabel }}</span>
                                </Button>
                            </Tooltip>
                            <template #overlay>
                                <Menu :selected-keys="[currentSort]" @click="setSort">
                                    <MenuItem v-for="o in sortOptions" :key="o.value">{{ o.label }}</MenuItem>
                                </Menu>
                            </template>
                        </Dropdown>
                    </span>
                    <Tooltip v-if="viewMode === 'table'" :title="$t('global.columns')">
                        <Button class="mi-iconbtn" @click="colSel?.open()"><ControlOutlined /></Button>
                    </Tooltip>
                    <Tooltip v-if="can('transformers.export')" :title="$t('global.export_hint')" data-tour="export-import">
                        <Button class="mi-iconbtn" @click="exportOpen = true"><DownloadOutlined /></Button>
                    </Tooltip>
                    <Tooltip :title="$t('transformers.fleet.title')" data-tour="fleet-panel">
                        <Link :href="route('business_management.transformers.fleet')">
                            <Button class="mi-iconbtn"><DashboardOutlined /></Button>
                        </Link>
                    </Tooltip>
                    <Dropdown :trigger="['click']" placement="bottomRight">
                        <Tooltip :title="$t('global.tools')" data-tour="tools">
                            <Button class="mi-iconbtn"><SettingOutlined /></Button>
                        </Tooltip>
                        <template #overlay>
                            <Menu>
                                <MenuItem v-if="can('transformers.create') && can('transformers.import') && canUsePlanFeature('imports')" key="import" @click="importOpen = true"><UploadOutlined /> {{ $t('global.import') }}</MenuItem>
                                <MenuItem v-if="can('transformers.edit') && canUsePlanFeature('edit_all')" key="editall" @click="router.visit(route('business_management.transformers.edit_all'))"><FormOutlined /> {{ $t('global.edit_all') }}</MenuItem>
                                <MenuItem v-if="isSuper" key="trash" @click="router.visit(route('business_management.transformers.trash'))"><InboxOutlined /> {{ $t('global.view_deleted') }}</MenuItem>
                                <MenuItem key="help" @click="tour.restart()"><QuestionCircleOutlined /> {{ $t('global.tour_show_again') }}</MenuItem>
                            </Menu>
                        </template>
                    </Dropdown>
                    <Dropdown :trigger="['click']" placement="bottomRight">
                        <Tooltip :title="$t('global.view_mode_hint')">
                            <Button class="sort-btn">
                                <component :is="currentView.icon" />
                                <span class="sort-btn__label">{{ $t('global.view_mode') }}: {{ currentView.label }}</span>
                            </Button>
                        </Tooltip>
                        <template #overlay>
                            <Menu :selected-keys="[viewMode]" @click="setView">
                                <MenuItem v-for="o in viewOptions" :key="o.value">
                                    <component :is="o.icon" /> {{ o.label }}
                                </MenuItem>
                            </Menu>
                        </template>
                    </Dropdown>
                    <Tooltip v-if="can('transformers.create')" :title="$t('transformers.new')" data-tour="new">
                        <Link :href="route('business_management.transformers.create')">
                            <Button type="primary" class="mi-iconbtn mi-create-btn" :aria-label="$t('transformers.new')"><PlusOutlined /></Button>
                        </Link>
                    </Tooltip>
                </div>
            </div>

            <div v-if="showFilters" class="mi-builder mi-builder--table">
                <InlineFilterBuilder ref="builderRef" v-model="advancedWhere" :schema="props.filterSchema" show-conjunction @change="applyInlineFilters">
                    <template #actions>
                        <Button v-if="quickSearch || advancedCount > 0 || onlyFavorites" type="link" class="bidx-clear" @click="clearAll"><ClearOutlined /> {{ $t('global.clear_filters') }}</Button>
                        <Button v-if="canUsePlanFeature('saved_views')" type="link" class="bidx-savefilter" @click="savedViewsRef?.openSave()"><SaveOutlined /> {{ $t('global.save_filter') }}</Button>
                    </template>
                </InlineFilterBuilder>
            </div>

        <Card v-if="viewMode === 'table'" :bodyStyle="{ padding: 0 }" class="grid-card">
            <ResponsiveTable
                :loading="isFetching"
                :dataSource="transformers.data"
                :columns="columns"
                :active-column-keys="activeColumnKeys"
                :pagination="tablePagination"
                :row-selection="(can('transformers.delete') || can('transformers.edit')) ? rowSelection : null"
                :scroll="{ x: 'max-content' }"
                :view="viewMode"
                rowKey="id"
                @change="onTableChange"
                data-tour="bulk"
            >
                <template #empty>
                    <TransformersEmptyState
                        :has-filters="hasActiveFilters || advancedCount > 0"
                        :can-create="can('transformers.create')"
                        @clear-filters="clearAll"
                        @open-import="importOpen = true"
                    />
                </template>
                <template #bodyCell="{ column, record, text, isMobile, compact }">
                    <TransformersFavoriteCell
                        v-if="column.key === 'favorite'"
                        :record="record"
                        :submitting="favoriteSubmitting"
                        :data-tour="record === transformers.data[0] ? 'favorites' : null"
                        @toggle="toggleFavorite"
                    />

                    <template v-else-if="column.key === 'serial'">
                        <strong v-if="isMobile">{{ record.serial || record.tag || '—' }}</strong>
                        <div v-else class="lead">
                            <span class="lead__glyph">
                                <TransformerGlyph
                                    :shape="record.transformer_type?.shape || 'tank'"
                                    :phases="record.phases || 'three'"
                                    :color="healthColor(record.health_index)"
                                    :animated="false"
                                />
                            </span>
                            <div class="lead__txt">
                                <Link :href="route('business_management.transformers.show', record.slug)"
                                    class="lead__name lead__link">{{ record.serial || record.tag || '—' }}</Link>
                                <span v-if="record.serial && record.tag" class="lead__sub">{{ record.tag }}</span>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="column.key === 'tenant'">
                        <Tag v-if="record.tenant" color="blue" :bordered="false">
                            {{ record.tenant.name }}
                        </Tag>
                        <Tag v-else color="purple" :bordered="false">{{ $t('global.platform') }}</Tag>
                    </template>

                    <template v-else-if="column.key === 'created_at'">
                        {{ formatDateTime(record.created_at) }}
                    </template>

                    <template v-else-if="column.key === 'customer'">
                        {{ record.customer?.name || '—' }}
                    </template>

                    <template v-else-if="column.key === 'transformer_type'">
                        {{ record.transformer_type?.name || '—' }}
                    </template>

                    <template v-else-if="column.key === 'oil_type'">
                        {{ record.oil_type?.name || '—' }}
                    </template>

                    <template v-else-if="column.key === 'voltage_kv'">
                        {{ record.voltage_kv != null ? record.voltage_kv + ' kV' : '—' }}
                    </template>

                    <template v-else-if="column.key === 'power_mva'">
                        {{ record.power_mva != null ? record.power_mva + ' MVA' : '—' }}
                    </template>

                    <template v-else-if="column.key === 'phases'">
                        {{ phaseLabel(record.phases) }}
                    </template>

                    <template v-else-if="column.key === 'health'">
                        <span v-if="record.health_index != null" class="health-pill" :style="{ background: healthColor(record.health_index) }">
                            {{ Math.round(record.health_index) }}
                        </span>
                        <Tooltip v-else :title="$t('global.no_data')">
                            <span class="muted" style="cursor: help;">—</span>
                        </Tooltip>
                    </template>

                    <TransformersActionsCell
                        v-else-if="column.key === 'actions'"
                        :record="record"
                        :is-mobile="isMobile"
                        :compact="compact"
                        :can-edit="can('transformers.edit')"
                        :can-create="can('transformers.create')"
                        :can-delete="can('transformers.delete')"
                        :duplicating-id="duplicating"
                        @edit="goEdit"
                        @duplicate="duplicate"
                        @delete="goDelete"
                    />

                    <template v-else>{{ text ?? record[column.dataIndex] ?? '' }}</template>
                </template>
            </ResponsiveTable>
        </Card>

        <!-- ── Vista de tarjetas horizontales (desktop) ───────────────────── -->
        <div v-else class="tcards-wrap">
            <Spin :spinning="isFetching">
                <TransformersEmptyState
                    v-if="!transformers.data.length"
                    :has-filters="hasActiveFilters || advancedCount > 0"
                    :can-create="can('transformers.create')"
                    @clear-filters="clearAll"
                    @open-import="importOpen = true"
                />

                <!-- ── Vista de LISTA: filas horizontales (layout previo, restaurado) ── -->
                <div v-else-if="viewMode === 'list'" class="tlist" v-auto-animate>
                    <article
                        v-for="record in transformers.data"
                        :key="record.id"
                        class="tlrow"
                        :class="{ 'tlrow--selected': isCardSelected(record) }"
                        :style="{ '--rail': healthColor(record.health_index) }"
                    >
                        <Checkbox
                            v-if="can('transformers.delete') || can('transformers.edit')"
                            :checked="isCardSelected(record)"
                            :disabled="isRowLocked(record)"
                            class="tcard__check"
                            @change="toggleCardSelect(record)"
                        />

                        <div class="tlrow__fav">
                            <TransformersFavoriteCell
                                :record="record"
                                :submitting="favoriteSubmitting"
                                @toggle="toggleFavorite"
                            />
                        </div>

                        <span class="tlrow__glyph">
                            <TransformerGlyph
                                :shape="record.transformer_type?.shape || 'tank'"
                                :phases="record.phases || 'three'"
                                :color="healthColor(record.health_index)"
                                :animated="false"
                            />
                        </span>

                        <div class="tlrow__id">
                            <Link :href="route('business_management.transformers.show', record.slug)"
                                class="tcard__name tcard__name--link">{{ record.serial || record.tag || '—' }}</Link>
                            <span v-if="record.serial && record.tag" class="tcard__tag">TAG {{ record.tag }}</span>
                            <span class="tcard__customer">{{ record.customer?.name || '—' }}</span>
                        </div>

                        <div class="tlrow__badges">
                            <Tooltip v-for="(bdg, bi) in cardBadges(record)" :key="bi" :title="bdg.tip">
                                <span class="tbadge">
                                    <component :is="bdg.icon" class="tbadge__ico" />
                                    {{ bdg.text }}<i v-if="bdg.unit">{{ bdg.unit }}</i>
                                </span>
                            </Tooltip>
                        </div>

                        <div class="tlrow__health">
                            <div class="hi-gauge" :title="$t('transformers.health_index')">
                                <svg viewBox="0 0 72 72" class="hi-gauge__svg" aria-hidden="true">
                                    <circle class="hi-gauge__track" cx="36" cy="36" r="30" />
                                    <circle
                                        v-if="record.health_index != null"
                                        class="hi-gauge__fill"
                                        cx="36" cy="36" r="30"
                                        :stroke="healthColor(record.health_index)"
                                        :stroke-dasharray="healthDash(record.health_index)"
                                    />
                                </svg>
                                <span class="hi-gauge__num">
                                    <b :style="{ color: record.health_index != null ? healthColor(record.health_index) : '#9aa0a6' }">{{ record.health_index != null ? Math.round(record.health_index) : '—' }}</b>
                                    <small>HI</small>
                                </span>
                            </div>
                            <Tooltip
                                v-if="trendMeta(record.health_trend)"
                                :title="$t('transformers.fleet.trend') + (record.gassing_rate != null ? ' · ' + record.gassing_rate + ' ppm/mes (TDCG)' : '')"
                            >
                                <span class="tcard__trend" :class="trendMeta(record.health_trend).cls">
                                    {{ trendMeta(record.health_trend).glyph }} {{ trendMeta(record.health_trend).label }}
                                </span>
                            </Tooltip>
                        </div>

                        <div class="tcard__actions">
                            <TransformersActionsCell
                                :record="record"
                                :is-mobile="false"
                                :can-edit="can('transformers.edit')"
                                :can-create="can('transformers.create')"
                                :can-delete="can('transformers.delete')"
                                :duplicating-id="duplicating"
                                @edit="goEdit"
                                @duplicate="duplicate"
                                @delete="goDelete"
                            />
                        </div>
                    </article>
                </div>

                <!-- ── Vista de TARJETAS: grilla de tiles verticales ── -->
                <div v-else class="tcards" v-auto-animate>
                    <article
                        v-for="record in transformers.data"
                        :key="record.id"
                        class="tcard"
                        :class="{ 'tcard--selected': isCardSelected(record) }"
                        :style="{ '--rail': healthColor(record.health_index) }"
                    >
                        <!-- Barra superior: selección (izq) + favorito (der). -->
                        <div class="tcard__bar">
                            <Checkbox
                                v-if="can('transformers.delete') || can('transformers.edit')"
                                :checked="isCardSelected(record)"
                                :disabled="isRowLocked(record)"
                                class="tcard__check"
                                @change="toggleCardSelect(record)"
                            />
                            <div class="tcard__fav">
                                <TransformersFavoriteCell
                                    :record="record"
                                    :submitting="favoriteSubmitting"
                                    @toggle="toggleFavorite"
                                />
                            </div>
                        </div>

                        <!-- Cabecera: glyph + identidad + anillo HI. -->
                        <div class="tcard__head">
                            <span class="tcard__glyph">
                                <TransformerGlyph
                                    :shape="record.transformer_type?.shape || 'tank'"
                                    :phases="record.phases || 'three'"
                                    :color="healthColor(record.health_index)"
                                    :animated="false"
                                />
                            </span>

                            <div class="tcard__id">
                                <Link :href="route('business_management.transformers.show', record.slug)"
                                    class="tcard__name tcard__name--link">{{ record.serial || record.tag || '—' }}</Link>
                                <span v-if="record.serial && record.tag" class="tcard__tag">TAG {{ record.tag }}</span>
                                <span class="tcard__customer">{{ record.customer?.name || '—' }}</span>
                            </div>

                            <div class="tcard__health">
                                <div class="hi-gauge" :title="$t('transformers.health_index')">
                                    <svg viewBox="0 0 72 72" class="hi-gauge__svg" aria-hidden="true">
                                        <circle class="hi-gauge__track" cx="36" cy="36" r="30" />
                                        <circle
                                            v-if="record.health_index != null"
                                            class="hi-gauge__fill"
                                            cx="36" cy="36" r="30"
                                            :stroke="healthColor(record.health_index)"
                                            :stroke-dasharray="healthDash(record.health_index)"
                                        />
                                    </svg>
                                    <span class="hi-gauge__num">
                                        <b :style="{ color: record.health_index != null ? healthColor(record.health_index) : '#9aa0a6' }">{{ record.health_index != null ? Math.round(record.health_index) : '—' }}</b>
                                        <small>HI</small>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Datos como chips. -->
                        <div class="tcard__badges">
                            <Tooltip v-for="(bdg, bi) in cardBadges(record)" :key="bi" :title="bdg.tip">
                                <span class="tbadge">
                                    <component :is="bdg.icon" class="tbadge__ico" />
                                    {{ bdg.text }}<i v-if="bdg.unit">{{ bdg.unit }}</i>
                                </span>
                            </Tooltip>
                        </div>

                        <!-- Pie: tendencia (izq) + acciones (der). -->
                        <div class="tcard__foot">
                            <Tooltip
                                v-if="trendMeta(record.health_trend)"
                                :title="$t('transformers.fleet.trend') + (record.gassing_rate != null ? ' · ' + record.gassing_rate + ' ppm/mes (TDCG)' : '')"
                            >
                                <span class="tcard__trend" :class="trendMeta(record.health_trend).cls">
                                    {{ trendMeta(record.health_trend).glyph }} {{ trendMeta(record.health_trend).label }}
                                </span>
                            </Tooltip>
                            <span v-else class="tcard__trend tcard__trend--none">—</span>

                            <div class="tcard__actions">
                                <TransformersActionsCell
                                    :record="record"
                                    :is-mobile="false"
                                    :can-edit="can('transformers.edit')"
                                    :can-create="can('transformers.create')"
                                    :can-delete="can('transformers.delete')"
                                    :duplicating-id="duplicating"
                                    @edit="goEdit"
                                    @duplicate="duplicate"
                                    @delete="goDelete"
                                />
                            </div>
                        </div>
                    </article>
                </div>

                <div v-if="transformers.data.length" class="tcards__pager">
                    <Pagination
                        :current="tablePagination.current"
                        :page-size="tablePagination.pageSize"
                        :total="tablePagination.total"
                        :show-size-changer="true"
                        :page-size-options="tablePagination.pageSizeOptions"
                        show-less-items
                        @change="onCardsPageChange"
                    />
                </div>
            </Spin>
        </div>
        </div>
        <!-- /mi-resultpanel -->

        <TransformersBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :is-mobile="isMobileScreen"
            :can-delete="can('transformers.delete')"
            :can-share="canUsePlanFeature('report_sharing')"
            :can-export="canUsePlanFeature('export_excel')"
            :share-blocked="shareBlocked"
            @cancel="clearSelection"
            @delete="openBulkDelete"
            @share="openBulkShare"
            @export="exportBulkExcel"
        />

        <!-- Compartir la selección: el modal de flota, ya con los trafos elegidos. -->
        <ShareModal
            v-if="shareCustomerId"
            hide-trigger
            v-model:open-external="shareOpen"
            scope-type="fleet"
            :scope-id="shareCustomerId"
            :preselected="selectedRowKeys"
            :preselected-options="shareOptions"
        />

        <TransformersBulkDeleteModal
            v-model:open="bulkOpen"
            v-model:reason="bulkReason"
            :count="selectedRowKeys.length"
            :submitting="bulkSubmitting"
            :error-msg="bulkError"
            :resource-label="selectedRowKeys.length === 1 ? $t('transformers.record') : $t('transformers.records')"
            @confirm="confirmBulkDelete"
        />

        <ExportDialog
            v-model:open="exportOpen"
            :columns="exportableColumns"
            :selected-ids="selectedRowKeys"
            :has-filters="hasActiveFilters"
            :filters-summary="filtersSummary"
            :current-filters="buildQueryData()"
            :default-title="$t('transformers.export_title')"
            :endpoints="exportEndpoints"
            :limits="props.exportLimits"
            :total-rows="transformers.total ?? 0"
            :total-unfiltered="transformers.total_unfiltered ?? transformers.total ?? 0"
        />

        <ImportDialog
            v-model:open="importOpen"
            :endpoint="route('business_management.transformers.import')"
            :template-url="route('business_management.transformers.import_template')"
            :resource-label="$t('transformers.records')"
        />
    </div>
</template>

<style scoped>
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}

.mono {
    font-family: ui-monospace, Consolas, monospace;
    font-size: 0.8125rem;
    background: var(--color-surface-alt);
    padding: 2px 6px;
    border-radius: 3px;
}
.muted { color: var(--color-text-muted); font-size: 0.8125rem; }
.health-pill {
    display: inline-block;
    min-width: 34px;
    padding: 1px 8px;
    border-radius: 10px;
    color: #fff;
    font-weight: 700;
    font-size: 0.8rem;
    font-variant-numeric: tabular-nums;
}

/* ── Remaster del index (tabla tipo SaaS) ───────────────────────────── */
.bidx-toolbar { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.bidx-clear { padding-left: 4px; padding-right: 4px; }
.bidx-builder { margin-bottom: 0; }

/* ── Remaster: título centrado + CONSOLA de filtros (un solo panel) ───── */
.tx-title { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 4px 0 16px; flex-wrap: wrap; }
.tx-title :deep(.page-header__title) { justify-content: flex-start; }
.tx-title :deep(.page-header__heading) { text-align: left; }
.tx-title :deep(.page-header__title h1) { font-size: 1.6rem; }

/* La consola v2: un solo panel sin etiquetas de zona ni divisores internos.
   Tira de vistas (tabs) arriba como cabecera + busqueda/filtros agrupados. */
.tx-console {
    background: var(--color-surface, #fff);
    border: 1px solid var(--color-border, #e7eaee);
    border-radius: 14px;
    padding: 0;
    margin-bottom: 18px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(16, 24, 40, 0.05);
}
/* Tira de vistas: cabecera sutil del panel, separada por un borde. */
.tx-console .mi-viewsbar {
    margin: 0;
    padding: 8px 14px;
    background: transparent;
    border-bottom: 0;
}
/* Buscador + filtros agrupados (sin divisores internos, solo respiracion). */
.tx-console .mi-builder { margin-top: 10px; }
/* Divisor fino (ya no se usa en la consola; se conserva por compatibilidad). */
.tx-console__div { height: 1px; background: var(--color-border, #eef0f3); margin: 12px 0; }

/* Fila de búsqueda: barra que crece + acciones principales a la derecha. */
.tx-searchrow {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
/* Botones grandes al alto de la barra (52px) para que la fila se vea pareja. */
.tx-searchrow .tx-bigbtn { height: 44px; border-radius: 10px; padding: 0 16px; font-weight: 600; }
.tx-searchrow .tx-gear { width: 44px; padding: 0; font-size: 1.05rem; }
.tx-searchrow .tx-bigbtn .anticon { font-size: 1rem; }
/* SavedViews al mismo alto. */
.tx-savedviews :deep(.saved-views-trigger) { height: 44px; border-radius: 10px; }
/* Host del ColumnSelector oculto (solo necesitamos su open()). */
.tx-colsel-host { display: none; }

/* Fila de controles: orden/dir/favoritos (izq) · vista (der). */
.tx-ctrls { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.tx-ctrls__left, .tx-ctrls__right { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* La barra: pozo de entrada (fondo gris dentro de la tarjeta blanca). */
.tx-hero__bar {
    flex: 1 1 460px;
    max-width: none;
    display: flex;
    align-items: center;
    gap: 10px;
    height: 44px;
    padding: 0 6px 0 16px;
    background: var(--color-surface-alt, #f4f6f8);
    border: 1px solid transparent;
    border-radius: 10px;
    cursor: text;
    transition: box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease;
}
.tx-hero__bar:hover { background: var(--color-surface-alt, #eef1f4); }
.tx-hero__bar:focus-within,
.tx-hero__bar.is-active {
    background: var(--color-surface, #fff);
    border-color: var(--color-primary, #2f6df6);
    box-shadow: 0 0 0 3px rgba(47, 109, 246, 0.12);
}
.tx-hero__icon { font-size: 1.05rem; color: var(--color-text-muted, #8a9099); flex: none; }
.tx-hero__input {
    flex: 1;
    min-width: 0;
    border: 0;
    outline: none;
    background: transparent;
    font-size: 0.95rem;
    color: var(--color-text-strong, #1f2329);
    line-height: 44px;
}
.tx-hero__input::placeholder { color: var(--color-text-muted, #9aa0a6); }
.tx-hero__act {
    flex: none;
    width: 32px;
    height: 32px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: var(--color-text-muted, #5f6b7a);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
    transition: background 0.14s ease, color 0.14s ease;
}
.tx-hero__act:hover { background: rgba(16, 24, 40, 0.07); color: var(--color-text-strong, #1f2329); }
.tx-hero__x:hover { color: var(--color-text-strong, #1f2329); }
.tx-hero__mic:hover { color: var(--color-primary, #0A6ED1); }
.tx-hero__mic--on { color: #C8281D; animation: mic-pulse 1.1s ease-in-out infinite; }
.tx-hero__sep { width: 1px; height: 24px; background: var(--color-border, #e3e6ea); flex: none; }
.tx-hero__controls { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }

/* Micrófono de búsqueda por voz (Google-style). */
.mic-btn {
    color: var(--color-text-muted, #8a9099);
    cursor: pointer;
    transition: color 0.15s ease, transform 0.15s ease;
}
.mic-btn:hover { color: var(--color-primary, #0A6ED1); }
.mic-btn--on { color: #C8281D; animation: mic-pulse 1.1s ease-in-out infinite; }
@keyframes mic-pulse {
    0%, 100% { transform: scale(1);    opacity: 1; }
    50%      { transform: scale(1.18); opacity: 0.65; }
}

/* Contenedor: rounded + sombra suave. SIN overflow:hidden (rompe el sticky). */
.grid-card {
    border-radius: 12px;
    border: 1px solid var(--color-border, #e8eaed);
    box-shadow: 0 1px 2px rgba(16,24,40,0.04), 0 4px 12px rgba(16,24,40,0.04);
}
.grid-card :deep(.ant-table-thead > tr > th:first-child) { border-top-left-radius: 12px; }
.grid-card :deep(.ant-table-thead > tr > th:last-child)  { border-top-right-radius: 12px; }

/* Celda principal: avatar de iniciales + serie + tag (subtítulo). */
.lead { display: flex; align-items: center; gap: 13px; min-width: 0; }
.lead__glyph {
    flex-shrink: 0; width: 46px; height: 46px; display: inline-flex;
    align-items: center; justify-content: center;
}
.lead__glyph :deep(svg) { width: 46px; height: 46px; }
.lead__txt { display: flex; flex-direction: column; min-width: 0; line-height: 1.3; }
.lead__name { font-weight: 600; font-size: 0.92rem; color: var(--color-text-strong, #1f2329); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* La serie como link a la ficha (Show): color del esquema, subrayado al hover.
   .lead__name.lead__link gana al color oscuro de .lead__name. */
.lead__name.lead__link { color: var(--color-primary); text-decoration: none; cursor: pointer; }
.lead__name.lead__link:hover, .lead__name.lead__link:focus-visible { color: var(--color-primary); text-decoration: underline; }
.lead__sub { font-size: 0.75rem; color: var(--color-text-muted, #8a9099); font-family: ui-monospace, Consolas, monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Cabecera minimal + filas aireadas + hover suave. */
.grid-card :deep(.ant-table-thead > tr > th) {
    background: var(--color-surface, #fff);
    text-transform: uppercase; letter-spacing: 0.05em;
    font-size: 0.68rem; font-weight: 600; color: var(--color-text-muted, #8a9099);
    border-bottom: 1px solid var(--color-border, #eceef1);
    padding-top: 12px; padding-bottom: 12px;
}
.grid-card :deep(.ant-table-tbody > tr > td) {
    padding-top: 16px; padding-bottom: 16px;
    border-bottom: 1px solid var(--color-border-subtle, #f2f3f5);
}
.grid-card :deep(.ant-table-tbody > tr:last-child > td) { border-bottom: none; }
.grid-card :deep(.ant-table-tbody > tr) { transition: background 0.12s ease; }
.grid-card :deep(.ant-table-tbody > tr:hover > td) { background: var(--color-surface-hover, #f8fafc) !important; }
.grid-card :deep(.ant-table-tbody > tr > td:first-child) { box-shadow: inset 3px 0 0 transparent; transition: box-shadow 0.12s ease; }
.grid-card :deep(.ant-table-tbody > tr:hover > td:first-child) { box-shadow: inset 3px 0 0 var(--color-primary, #0A6ED1); }

/* Buscador integrado. */
.bidx-search :deep(.ant-input-affix-wrapper) { border-radius: 9px; }

/* Acciones: tenues, se realzan al hover de la fila. */
.grid-card :deep(.ant-table-tbody .row-actions-desktop) { opacity: 0.35; transition: opacity 0.15s ease; }
.grid-card :deep(.ant-table-tbody > tr:hover .row-actions-desktop),
.grid-card :deep(.ant-table-tbody .row-actions-desktop:focus-within) { opacity: 1; }

/* Reserva espacio para que la bulk-bar mobile-sticky no tape la última card. */
.grid-card:has(.bulk-bar--mobile-sticky) {
    padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 80px);
}

/* Mobile: el toolbar desktop se oculta — sus acciones viven en el bottom bar. */
@media (max-width: 768px) {
    .page-header { flex-direction: column; align-items: stretch; }
    .hide-on-mobile { display: none !important; }
}

/* Cluster derecho: orden + favoritos + conmutador de vista. */
.bidx-right { margin-left: auto; display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.sort-btn { display: inline-flex; align-items: center; gap: 6px; }
.sort-btn__label { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* ── Conmutador de vista (Tabla | Tarjetas) estilo Google ───────────── */
.view-toggle {
    display: inline-flex;
    gap: 2px;
    padding: 3px;
    background: var(--color-surface-alt, #f1f3f5);
    border: 1px solid var(--color-border, #e8eaed);
    border-radius: 10px;
}
.view-toggle__btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 30px;
    border: 0;
    border-radius: 7px;
    background: transparent;
    color: var(--color-text-muted, #8a9099);
    cursor: pointer;
    font-size: 1rem;
    transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
}
.view-toggle__btn:hover { color: var(--color-text-strong, #1f2329); }
.view-toggle__btn--active {
    background: var(--color-surface, #fff);
    color: var(--color-primary, #0A6ED1);
    box-shadow: 0 1px 3px rgba(16,24,40,0.12);
}

/* ── Vista de tarjetas horizontales ─────────────────────────────────── */
/* ── Vista de LISTA: filas horizontales (layout previo, restaurado) ──── */
.tlist { display: flex; flex-direction: column; gap: 10px; }
.tlrow {
    position: relative;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 14px 18px 14px 20px;
    background: var(--color-surface, #fff);
    border: 1px solid var(--color-border, #e8eaed);
    border-radius: 12px;
    box-shadow: 0 1px 2px rgba(16,24,40,0.04);
    transition: box-shadow 0.15s ease, transform 0.15s ease, border-color 0.15s ease;
}
.tlrow::before {
    content: '';
    position: absolute;
    left: 0; top: 10px; bottom: 10px;
    width: 4px;
    border-radius: 0 4px 4px 0;
    background: var(--rail, #9aa0a6);
}
.tlrow:hover {
    box-shadow: 0 4px 16px rgba(16,24,40,0.10);
    border-color: var(--color-border-strong, #d6dae0);
    transform: translateY(-1px);
}
.tlrow--selected {
    border-color: var(--color-primary, #0A6ED1);
    box-shadow: 0 0 0 1px var(--color-primary, #0A6ED1), 0 4px 16px rgba(10,110,209,0.12);
}
.tlrow__fav { flex-shrink: 0; display: inline-flex; }
.tlrow__glyph {
    flex-shrink: 0;
    width: 52px; height: 52px;
    display: inline-flex; align-items: center; justify-content: center;
}
.tlrow__glyph :deep(svg) { width: 52px; height: 52px; }
.tlrow__id { display: flex; flex-direction: column; min-width: 150px; max-width: 220px; line-height: 1.35; }
.tlrow__badges { display: flex; flex-wrap: wrap; gap: 6px; flex: 1; min-width: 0; }
.tlrow__health { flex-shrink: 0; display: flex; flex-direction: column; align-items: center; gap: 6px; width: 84px; }
/* En lista, el anillo HI va un poco más grande (como el layout original). */
.tlrow__health .hi-gauge,
.tlrow__health .hi-gauge__svg { width: 62px; height: 62px; }
.tlrow__health .hi-gauge__num b { font-size: 1.15rem; }

/* Vista de tarjetas REALES: grilla responsiva de tiles verticales. */
.tcards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
    gap: 14px;
    align-items: stretch;
}
.tcard {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 10px 16px 12px 18px;
    background: var(--color-surface, #fff);
    border: 1px solid var(--color-border, #e8eaed);
    border-radius: 14px;
    box-shadow: 0 1px 2px rgba(16,24,40,0.04);
    transition: box-shadow 0.15s ease, transform 0.15s ease, border-color 0.15s ease;
}
/* Riel de color = condición de salud (identidad de diagnóstico). */
.tcard::before {
    content: '';
    position: absolute;
    left: 0; top: 12px; bottom: 12px;
    width: 4px;
    border-radius: 0 4px 4px 0;
    background: var(--rail, #9aa0a6);
}
.tcard:hover {
    box-shadow: 0 6px 18px rgba(16,24,40,0.10);
    border-color: var(--color-border-strong, #d6dae0);
    transform: translateY(-2px);
}
.tcard--selected {
    border-color: var(--color-primary, #0A6ED1);
    box-shadow: 0 0 0 1px var(--color-primary, #0A6ED1), 0 4px 16px rgba(10,110,209,0.12);
}

/* Barra superior: check (izq) + favorito (der). */
.tcard__bar { display: flex; align-items: center; gap: 8px; min-height: 22px; }
.tcard__check { flex-shrink: 0; }
.tcard__fav { margin-left: auto; flex-shrink: 0; display: inline-flex; }

/* Cabecera: glyph + identidad + anillo HI. */
.tcard__head { display: flex; align-items: center; gap: 12px; }
.tcard__glyph {
    flex-shrink: 0;
    width: 46px; height: 46px;
    display: inline-flex; align-items: center; justify-content: center;
}
.tcard__glyph :deep(svg) { width: 46px; height: 46px; }

.tcard__id { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; line-height: 1.3; }
.tcard__name {
    font-weight: 600; font-size: 0.98rem; color: var(--color-text-strong, #1f2329);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
/* En list/cards el nombre es link a la ficha (Show): color del esquema. */
.tcard__name--link { color: var(--color-primary); text-decoration: none; cursor: pointer; }
.tcard__name--link:hover, .tcard__name--link:focus-visible { color: var(--color-primary); text-decoration: underline; }
.tcard__tag {
    font-size: 0.7rem; color: var(--color-text-muted, #8a9099);
    font-family: ui-monospace, Consolas, monospace; letter-spacing: 0.02em;
}
.tcard__customer {
    font-size: 0.8rem; color: var(--color-text-muted, #6b7280);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px;
}

/* Badges: los datos como chips (el pedido del usuario). */
.tcard__badges { display: flex; flex-wrap: wrap; gap: 6px; min-width: 0; }
/* Todos los badges IGUALES: misma fuente, mismo tamaño, mismo fondo/borde.
   Solo cambian por el icono (acento tenue) + tooltip. */
.tbadge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px;
    border-radius: 7px;
    font-family: inherit;
    font-size: 0.74rem; font-weight: 500; line-height: 1.15;
    font-variant-numeric: tabular-nums;
    background: var(--color-surface-alt, #f3f5f7);
    color: var(--color-text, #475467);
    border: 1px solid var(--color-border, #e7eaee);
    white-space: nowrap;
}
.tbadge__ico { font-size: 0.74rem; color: var(--color-primary, #2f6df6); opacity: 0.85; }
.tbadge i { font-style: normal; font-weight: 600; color: var(--color-text-muted, #98a2b3); margin-left: 2px; }

.tcard__health { flex-shrink: 0; display: flex; align-items: center; justify-content: center; }

/* Pie de la tarjeta: tendencia (izq) + acciones (der), separado por una línea. */
.tcard__foot {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    margin-top: auto; padding-top: 10px;
    border-top: 1px solid var(--color-border, #f0f2f4);
}
.tcard__foot .tcard__trend { margin: 0; }
.tcard__trend--none { color: var(--color-text-muted, #c0c5cc); }
.tcard__actions { flex-shrink: 0; display: inline-flex; }

/* Anillo del HI (número + "HI" centrados). */
.hi-gauge { position: relative; width: 54px; height: 54px; }
.hi-gauge__svg { width: 54px; height: 54px; display: block; }
.hi-gauge__track { fill: none; stroke: var(--color-surface-alt, #eceef1); stroke-width: 7; }
.hi-gauge__fill {
    fill: none; stroke-width: 7; stroke-linecap: round;
    transform: rotate(-90deg); transform-origin: center;
    /* La animación es de "barrido" del arco: anima stroke-dasharray vía CSS var. */
    animation: hi-gauge-fill .9s cubic-bezier(.22,.61,.36,1) both;
}
.hi-gauge__num {
    position: absolute; inset: 0; display: flex; flex-direction: column;
    align-items: center; justify-content: center; line-height: 1;
}
.hi-gauge__num b { font-size: 1.0rem; font-weight: 700; }
.hi-gauge__num small { font-size: 0.52rem; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-text-muted, #9aa0a6); margin-top: 1px; }

/* Chip de tendencia (health_trend real). */
.tcard__trend {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 0.66rem; font-weight: 700; padding: 2px 8px; border-radius: 999px;
    white-space: nowrap; letter-spacing: 0.02em;
}
.tcard__trend--up   { color: #C8281D; background: #c8281d18; animation: tcard-trend-pulse 2.4s ease-in-out infinite; }
.tcard__trend--down { color: #1D7044; background: #1d704418; }
.tcard__trend--flat { color: var(--color-text-muted, #8a9099); background: var(--color-surface-alt, #eef0f2); }

/* Barrido del arco: de 0 al valor final. Corre UNA vez por render (cheap, paint
   sobre ~25 anillos pequeños). El valor final lo fija el binding inline. */
@keyframes hi-gauge-fill {
    from { stroke-dasharray: 0 188.5; }
}
/* Pulso sutil SOLO en "Empeora" (los que importan), no en toda la página. */
@keyframes tcard-trend-pulse {
    0%, 100% { box-shadow: 0 0 0 0 #c8281d00; }
    50%      { box-shadow: 0 0 0 3px #c8281d1f; }
}
/* Respeta a quien pide menos movimiento (accesibilidad + equipos lentos). */
@media (prefers-reduced-motion: reduce) {
    .hi-gauge__fill { animation: none; }
    .tcard__trend--up { animation: none; }
}

.tcard__actions { flex-shrink: 0; }
/* Acciones tenues, se realzan al hover de la tarjeta (consistente con la tabla). */
.tcard__actions :deep(.row-actions-desktop) { opacity: 0.4; transition: opacity 0.15s ease; }
.tcard:hover .tcard__actions :deep(.row-actions-desktop),
.tcard__actions :deep(.row-actions-desktop:focus-within) { opacity: 1; }

.tcards__pager { display: flex; justify-content: flex-end; margin-top: 16px; }

@media (max-width: 980px) {
    .tcard { flex-wrap: wrap; }
    .tcard__badges { order: 5; width: 100%; flex: 1 0 100%; }
}

/* "Filtros avanzados (3) ⊗" — el badge va con fondo blanco translucido y la
   X de limpiar aparece pegada al texto. Patron estilo Gmail/Linear chips. */
.adv-filter-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.adv-filter-btn__count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 6px;
    border-radius: 9px;
    background: rgba(255, 255, 255, 0.25);
    font-size: 0.7rem;
    font-weight: 600;
    line-height: 1;
}
.adv-filter-btn__clear {
    font-size: 14px;
    opacity: 0.7;
    cursor: pointer;
    transition: opacity 0.15s ease, transform 0.15s ease;
}
.adv-filter-btn__clear:hover {
    opacity: 1;
    transform: scale(1.12);
}
</style>

<style>
/* Espacio inferior para el bottom-bar fijo (mobile). No-scoped: aplica al
   layout shell. Igual que Regions. */
@media (max-width: 767.98px) {
    .below-shell .content {
        padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 150px) !important;
    }
}
</style>
