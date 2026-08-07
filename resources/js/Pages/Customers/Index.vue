<script setup>
import { computed, ref, onMounted, watch, nextTick } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Card, Tag, Button, Tooltip, Badge, Dropdown, Menu, MenuItem, Input, Drawer } from 'ant-design-vue';
import {
    PlusOutlined, EditOutlined, InboxOutlined,
    DownloadOutlined, UploadOutlined, QuestionCircleOutlined,
    FilterOutlined, CloseCircleFilled, SaveOutlined, ClearOutlined,
    EllipsisOutlined, SearchOutlined,
    SettingOutlined, TableOutlined, CloseOutlined,
    ControlOutlined, FormOutlined,
    SortAscendingOutlined, SortDescendingOutlined,
    StarOutlined, StarFilled, BarsOutlined, AppstoreOutlined, DownOutlined,
    AudioOutlined,
} from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import ColumnSelector from '@/Components/Common/ColumnSelector.vue';
import FilterBar from '@/Components/Common/FilterBar.vue';
import InlineFilterBuilder from '@/Components/Common/InlineFilterBuilder.vue';
import { countClauses, pruneRules } from '@/utils/filterTree';
import ExportDialog from '@/Components/Common/ExportDialog.vue';
import ImportDialog from '@/Components/Common/ImportDialog.vue';
import SavedViews from '@/Components/Common/SavedViews.vue';

import CustomersBulkBar from '@/Components/Customers/CustomersBulkBar.vue';
import CustomersBulkDeleteModal from '@/Components/Customers/CustomersBulkDeleteModal.vue';
import CustomersFavoriteCell from '@/Components/Customers/CustomersFavoriteCell.vue';
import CustomersPageHeader from '@/Components/Customers/CustomersPageHeader.vue';
import CustomersActionsCell from '@/Components/Customers/CustomersActionsCell.vue';
import CustomersEmptyState from '@/Components/Customers/CustomersEmptyState.vue';

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
import { useVoiceSearch } from '@/Composables/useVoiceSearch';
import { useI18n } from '@/Plugins/i18n';

// Gate per plan: saved_views requiere basic+, imports/edit_all requieren pro+.
// El toolbar inline de Customers (no usa ModuleToolbar) repite manualmente
// estos gates para no mostrar botones que no funcionan al user free/basic.
const { canUse: canUsePlanFeature } = usePlanFeatures();

import {
    customersFilterFields, customersEmptyFilters, hydrateCustomersFilters,
    customersFiltersToQuery, customersFiltersSummary,
    serializeSavedFilters, deserializeSavedFilters,
} from './config/filters';
import { customersTableColumns } from './config/columns';
import { customersExportableColumns, customersExportEndpoints } from './config/exports';
import { moduleTourSteps } from '@/Composables/moduleTourSteps';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTime } = useDateFormat();

// Avatar de iniciales con color estable (para la celda principal de la tabla).
const initials = (name) => (name || '').split(/\s+/).filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase() || '—';
const avaStyle = (name) => {
    let h = 0;
    for (const c of (name || '')) h = (h * 31 + c.charCodeAt(0)) % 360;
    return { background: `hsl(${h} 58% 52%)` };
};

const props = defineProps({
    customers:      { type: Object, required: true },
    filters:        { type: Object, default: () => ({}) },
    countryOptions: { type: Array,  default: () => [] },
    filterSchema:   { type: Array,  default: () => [] },
    exportLimits:   { type: Object, default: () => ({}) },
    // Usuario acotado a clientes asignados: no puede crear/duplicar.
    isCustomerRestricted: { type: Boolean, default: false },
});

// Un usuario acotado a clientes asignados es SOLO-LECTURA en el módulo Clientes:
// gestiona la flota (trafos), no el registro maestro. Por eso todas las escrituras
// (crear/duplicar/importar/editar/eliminar/masivas) suman `!isCustomerRestricted`.
const canCreateCustomer = computed(() => can('customers.create') && !props.isCustomerRestricted);
const canEditCustomer   = computed(() => can('customers.edit')   && !props.isCustomerRestricted);
const canDeleteCustomer = computed(() => can('customers.delete') && !props.isCustomerRestricted);
const canExportCustomer = computed(() => can('customers.export') && !props.isCustomerRestricted);
const canImportCustomer = computed(() => canCreateCustomer.value && can('customers.import'));

// ─── Filtros (schema + (de)serialización en config/filters.js) ──────────────
const filterFields = computed(() =>
    customersFilterFields(t, { countryOptions: props.countryOptions }),
);

// Chips rápidos (ninguno por defecto): activos / inactivos / con / sin trafos.
const customerChips = computed(() => [
    { value: 'active',     label: t('customers.chip_active'),     color: '#1D7044' },
    { value: 'inactive',   label: t('customers.chip_inactive'),   color: '#9aa0a6' },
    { value: 'with_tx',    label: t('customers.chip_with_tx'),    color: '#0A6ED1' },
    { value: 'without_tx', label: t('customers.chip_without_tx'), color: '#E9A23B' },
]);

const {
    filters, reload, isFetching, suspendReload, hasActiveFilters, clearFilters, filtersSummary, buildQueryData,
} = useModuleFilters({
    serverFilters: props.filters,
    hydrate:       hydrateCustomersFilters,
    toQuery:       customersFiltersToQuery,
    summary:       customersFiltersSummary,
    empty:         customersEmptyFilters,
    only:          ['customers', 'filters'],
    t,
});

// ─── Remaster: filtros colapsados en drawer + buscador inline ───────────────
// El campo `name` es de tipo tags (multi-valor); el buscador inline maneja el
// término principal (primer tag) para no saturar el bar. El resto de filtros
// vive en el drawer "Filtros".
const filtersOpen = ref(false);
const quickSearch = computed({
    get: () => (filters.value.name?.[0]) ?? '',
    set: (v) => { filters.value.name = v ? [v] : []; },
});
const { micSupported, listening, startVoiceSearch } = useVoiceSearch(quickSearch);

// ─── Filtros avanzados (drawer con query builder dinámico) ──────────────────
// Estos NO viven en useModuleFilters porque son un array de clausulas
// estructuradas {field, op, value}, distinto al shape plano del FilterBar.
// Se persisten via Inertia (filters.advanced_where) para que sobreviva al
// paginate/sort sin perder el filtro aplicado.
const advancedWhere = ref(Array.isArray(props.filters?.advanced_where) ? props.filters.advanced_where : []);
// Lista PLANA de cláusulas (estilo RENATI). Cada cláusula (menos la 1ª) lleva su
// propio conector 'conj' ('and'|'or') → no hay conector global ni sub-grupos.
const advancedCount = computed(() => countClauses(advancedWhere.value));

// Ref a SavedViews para abrir el modal "Guardar Filtro" desde el área de filtros.
const savedViewsRef = ref(null);
const builderRef = ref(null);
// El área de filtros se muestra/oculta con el icono de filtro del toolbar.
// Arranca abierta si ya hay filtros activos (para no esconderlos).
const showFilters = ref(advancedWhere.value.length > 0 || hasActiveFilters.value);

// Una cláusula está "completa" si tiene campo, operador y un valor real.
const isFilterComplete = (c) => {
    if (!c || !c.field || !c.op) return false;
    if (Array.isArray(c.value)) return c.value.length > 0;
    return c.value !== '' && c.value !== null && c.value !== undefined;
};
// Cantidad de filtros REALES (con valor) → contador en el icono de filtro.
const activeFilterCount = computed(() => countClauses(advancedWhere.value));

// Toggle del panel de filtros: al ABRIR, si no hay ninguna fila, agrega una
// (arranca con un filtro activado). Al CERRAR, descarta las filas vacías/
// incompletas → el icono no queda marcado si no llegaste a poner un valor.
const toggleFilters = async () => {
    if (showFilters.value) {
        advancedWhere.value = pruneRules(advancedWhere.value);
        showFilters.value = false;
        return;
    }
    showFilters.value = true;
    await nextTick();
    // Arranca con una condición vacía si no hay ninguna (antes era builderRef.addRow).
    if (advancedWhere.value.length === 0) {
        const first = props.filterSchema?.[0];
        if (first) advancedWhere.value = [{ field: first.key, op: (first.operators?.[0]) ?? '=', value: '' }];
    }
};
// Si se borra el último filtro (queda vacío), se cierra el panel solo.
watch(() => advancedWhere.value.length, (n) => {
    if (n === 0 && showFilters.value) showFilters.value = false;
});

// router.get con params EXPLÍCITOS (no router.reload({data}), que mezcla mal con
// la URL actual y dejaba params vacíos pegados → lista en 0 sin filtros). Aquí la
// URL se arma limpia desde cero: solo lo que tiene valor. advanced_where se omite
// cuando está vacío.
const applyAdvancedFilters = (clauses) => {
    advancedWhere.value = clauses;
    const data = {
        ...buildQueryData(),
        sort: props.filters.sort,
        direction: props.filters.direction,
        per_page: props.filters.per_page,
    };
    const pruned = pruneRules(clauses);
    if (pruned.length > 0) {
        data.advanced_where = JSON.stringify(pruned);
    }
    router.get(route('business_management.customers.index'), data, {
        preserveScroll: true,
        preserveState: true,
        onStart:  () => { isFetching.value = true; },
        onFinish: () => { isFetching.value = false; },
    });
};

const clearAdvancedFilters = () => {
    advancedWhere.value = [];
    applyAdvancedFilters([]);
};

// "Limpiar todo": resetea filtros normales Y avanzados de una. Navega a la URL
// limpia (conservando orden/paginación) para no dejar ningún param pegado.
const clearAll = () => {
    advancedWhere.value = [];
    router.get(
        route('business_management.customers.index'),
        {
            sort: props.filters.sort,
            direction: props.filters.direction,
            per_page: props.filters.per_page,
        },
        { preserveScroll: true },
    );
};

// Aplica una vista guardada (filtros simples + cláusulas avanzadas + sort) en
// UNA sola navegación con params EXPLÍCITOS (mismo camino limpio que
// applyAdvancedFilters; router.reload({data}) mezclaba mal con la URL → no filtraba).
const applySavedViewState = (clauses, meta) => {
    const data = {
        ...buildQueryData(),
        sort: meta.sort,
        direction: meta.direction,
        per_page: meta.perPage,
    };
    const pruned = pruneRules(clauses);
    if (pruned.length > 0) {
        data.advanced_where = JSON.stringify(pruned);
    }
    router.get(route('business_management.customers.index'), data, {
        preserveScroll: true,
        // preserveState conserva la vista activa en SavedViews (sin esto el
        // indicador volvía siempre a "Todos").
        preserveState: true,
        onStart:  () => { isFetching.value = true; },
        onFinish: () => { isFetching.value = false; },
    });
};

// ─── Contador adaptativo "X registros" / "X de Y registros" ────────────────
const { counterLabel } = useModuleListMeta({
    pagination: computed(() => props.customers),
    hasActiveFilters,
    t,
});

// ─── Columnas (schema en config/columns.js) ─────────────────────────────────
// Viewport: el ancho de la columna de acciones depende de si es pantalla chica,
// así que se declara ANTES de allColumns (lo consume el computed).
const { isMobile: isMobileScreen } = useViewport(768);

const allColumns = computed(() =>
    customersTableColumns(t, { isSuper: isSuper.value, isMobile: isMobileScreen.value }),
);
const { visibleColumnKeys, columns } = useColumnPreferences(allColumns);

// ─── Paginacion + sort ──────────────────────────────────────────────────────
const tablePagination = computed(() => ({
    current:  props.customers.current_page,
    pageSize: props.customers.per_page,
    total:    props.customers.total,
    showSizeChanger: true,
    pageSizeOptions: ['10', '25', '50', '100'],
}));

const onTableChange = (pag, _f, sorter) => {
    // `field` viene del dataIndex; las columnas sin dataIndex (ej. país) sortean
    // por su `columnKey` (= key de la columna, que el backend mapea con un join).
    let sort = sorter?.field || sorter?.columnKey || props.filters.sort;
    if (Array.isArray(sort)) sort = sort[0];
    else if (typeof sort === 'string' && sort.includes('.')) sort = sort.split('.')[0];
    const direction = sorter?.order === 'ascend' ? 'asc'
                    : sorter?.order === 'descend' ? 'desc'
                    : props.filters.direction;
    reload({ page: pag.current, per_page: pag.pageSize, sort, direction });
};

// ─── Builder inline de filtros (desktop): reemplaza los drawers Filtros/Avanzados.
// Aplica SOLO las cláusulas completas (debounce), sin reescribir advancedWhere.
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
        if (cleaned.length > 0) {
            data.advanced_where = JSON.stringify(cleaned);
        }
        router.get(route('business_management.customers.index'), data, {
            preserveScroll: true,
            preserveState: true,
            onStart:  () => { isFetching.value = true; },
            onFinish: () => { isFetching.value = false; },
        });
    }, 350);
};

// ─── Vista: tabla | lista | tarjetas (persistida en localStorage) ──────────
const VIEW_KEY = 'customers_view_mode';
const viewMode = ref('table');
onMounted(() => {
    const saved = localStorage.getItem(VIEW_KEY);
    if (saved === 'cards' || saved === 'table' || saved === 'list') viewMode.value = saved;
});
watch(viewMode, (v) => localStorage.setItem(VIEW_KEY, v));
// Opciones de vista (dropdown, mismo patrón que "Ordenar").
const viewOptions = computed(() => [
    { value: 'table', label: t('global.view_table'),      icon: TableOutlined },
    { value: 'list',  label: t('global.view_list_short'), icon: BarsOutlined },
    { value: 'cards', label: t('global.view_cards'),      icon: AppstoreOutlined },
]);
const currentView = computed(() => viewOptions.value.find((o) => o.value === viewMode.value) ?? viewOptions.value[0]);
const setView = ({ key }) => { viewMode.value = key; };

// ─── Orden global (dropdown — funciona en tabla, lista y tarjetas) ─────────
const normField = (di) => Array.isArray(di) ? di[0] : (typeof di === 'string' && di.includes('.') ? di.split('.')[0] : di);
const sortOptions = computed(() =>
    allColumns.value
        .filter((c) => c.sorter)
        // Columnas sin dataIndex (ej. país) sortean por su key (el backend la
        // mapea con un join), igual que en la tabla vía columnKey.
        .map((c) => ({ value: normField(c.dataIndex) || c.key, label: typeof c.title === 'string' ? c.title : c.key }))
        .filter((o) => o.value),
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
// El chip "Solo favoritos" es independiente (como "Todos"): limpia el resto de
// filtros simples + avanzados y deja SOLO el toggle de favoritos, navegando en
// una sola request limpia. Sin esto, al venir de "solo peru" quedaban pegadas
// las condiciones de pais del builder.
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

// Chip de filtro rápido: exclusivo como "Todos"/"Favoritos". Limpia el resto
// (incl. favoritos y vista activa) y deja SOLO el preset, navegando limpio.
const onTogglePreset = (val) => {
    suspendReload(() => {
        clearFilters();
        filters.value.customer_group = val;
    });
    advancedWhere.value = [];
    applySavedViewState([], {
        sort:      props.filters.sort,
        direction: props.filters.direction,
        perPage:   props.filters.per_page,
    });
};

// ─── Undo toast (60s window) ────────────────────────────────────────────────
useModuleUndoToast('business_management.customers.undo_last_delete');

// ─── Favoritos polimorficos ────────────────────────────────────────────────
const { submitting: favoriteSubmitting, toggle: toggleFavorite } = useModuleFavorites('customers', 'customers');

// ─── Bulk ───────────────────────────────────────────────────────────────────
const {
    selectedRowKeys, rowSelection, clearSelection,
    bulkOpen, bulkReason, bulkSubmitting, bulkError, bulkActivating,
    openBulkDelete, bulkSetActive, confirmBulkDelete,
} = useModuleBulkActions({
    bulkSetActiveRoute: 'business_management.customers.bulk_set_active',
    bulkDeleteRoute:    'business_management.customers.bulk_delete',
    resourceLabel:      t('customers.records'),
    // Los clientes globales (Plataforma) solo los gestiona el super → un admin
    // no los puede seleccionar para acciones masivas.
    rowDisabled:        (r) => (!isSuper.value && r.tenant_id == null) || !!(r.is_locked ?? r.locked_at),
});

// ─── Duplicate ──────────────────────────────────────────────────────────────
const duplicating = ref(null);
const duplicate = (record) => {
    duplicating.value = record.id;
    router.post(route('business_management.customers.duplicate', record.slug), {}, {
        preserveScroll: true,
        onFinish: () => { duplicating.value = null; },
    });
};

// ─── Export / Import (columnas + endpoints en config/exports.js) ────────────
const exportOpen = ref(false);
const importOpen = ref(false);
// Ref al ColumnSelector (montado oculto) para abrirlo desde el engranaje.
const colSel = ref(null);
const exportableColumns = computed(() => customersExportableColumns(t, { isSuper: isSuper.value }));
const exportEndpoints   = computed(() => customersExportEndpoints());

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

// Al aplicar una vista guardada o "Todos", applySavedState reemplaza filtros
// simples + avanzados + favoritos DENTRO de suspendReload (sin disparar el watch)
// y navega una sola vez via applySavedViewState. No tocar favoritos/advancedWhere
// aquí: lanzaría un reload competidor que revierte la vista.
const onBarApply = (state) => {
    applySavedState(state);
};

// ─── Onboarding tour (pasos en config/tour.js) ──────────────────────────────
const tour = useModuleTour({ module: 'customers', steps: () => moduleTourSteps(t, { moduleName: t('customers.plural') }) });

// ─── Keyboard shortcuts ────────────────────────────────────────────────────
useKeyboardShortcuts({
    'ctrl+n': () => canCreateCustomer.value && router.visit(route('business_management.customers.create')),
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
const goEdit   = (record) => router.visit(route('business_management.customers.edit',   record.slug));
const goDelete = (record) => router.visit(route('business_management.customers.delete', record.slug));
</script>

<template>
    <Head :title="$t('customers.plural')" />

    <div class="sap-index">
        <!-- Título (izq) + acciones (Vistas, Exportar, engranaje, Nuevo) a la derecha. -->
        <div class="mi-title" data-tour="module">
            <CustomersPageHeader
                :title="$t('customers.plural')"
            />
        </div>

        <!-- Consola de filtros: vistas guardadas + chips + ColumnSelector host.
             Responsive: en pantallas chicas la barra hace scroll horizontal. -->
        <div class="mi-console mi-console--v2">
            <!-- Vistas guardadas + chips rápidos (misma fila, junto a favoritos). -->
            <div v-if="canUsePlanFeature('saved_views')" class="mi-viewsbar" data-tour="saved-views">
                <SavedViews
                    ref="savedViewsRef"
                    layout="bar"
                    variant="tabs"
                    module="customers"
                    :show-add="false"
                    :current-state="currentViewState"
                    :show-favorites="true"
                    :favorites-active="onlyFavorites"
                    @apply="onBarApply"
                    @default-loaded="applySavedState"
                    @toggle-favorites="toggleOnlyFavorites"
                    :presets="customerChips"
                    :preset-active="filters.customer_group"
                    @toggle-preset="onTogglePreset"
                />
            </div>

            <!-- ColumnSelector montado oculto: solo expone open() al engranaje/Columnas. -->
            <span class="mi-colsel-host" aria-hidden="true">
                <ColumnSelector
                    ref="colSel"
                    :columns="allColumns"
                    v-model="visibleColumnKeys"
                    storage-key="customers"
                />
            </span>
        </div>

        <!-- Drawer de filtros (desktop): reusa el FilterBar completo. -->
        <Drawer v-model:open="filtersOpen" :title="$t('global.filters')" placement="right" :width="380">
            <FilterBar :fields="filterFields" v-model="filters" storage-key="customers" />
        </Drawer>

            <!-- Toolbar de resultados, pegada a la tabla (no flota suelta).
                 Responsive: en pantallas chicas las etiquetas de los botones se
                 ocultan vía CSS y quedan solo iconos. -->
            <div class="mi-tabletoolbar">
                <div class="mi-tabletoolbar__left">
                    <span class="mi-toolbar-count">{{ counterLabel }}</span>
                </div>

                <div class="mi-tabletoolbar__right">
                    <label class="mi-bar mi-bar--toolbar" :class="{ 'is-active': quickSearch }">
                        <SearchOutlined class="mi-bar__icon" />
                        <input
                            v-model="quickSearch"
                            class="mi-bar__input"
                            :placeholder="$t('global.search_in', { item: $t('customers.singular').toLowerCase() })"
                            autocomplete="off"
                            spellcheck="false"
                            type="text"
                        />
                        <button v-if="quickSearch" type="button" class="mi-bar__act" :title="$t('global.clear')" @click="quickSearch = ''">
                            <CloseOutlined />
                        </button>
                        <Tooltip v-if="micSupported" :title="$t('global.voice_search')">
                            <button type="button" class="mi-bar__act mi-bar__mic" :class="{ 'mi-bar__mic--on': listening }" @click="startVoiceSearch">
                                <AudioOutlined />
                            </button>
                        </Tooltip>
                    </label>

                    <Tooltip :title="$t('global.filters')">
                        <Button class="mi-iconbtn" :class="{ 'mi-iconbtn--active': showFilters || activeFilterCount > 0 }" @click="toggleFilters" data-tour="advanced-filters">
                            <FilterOutlined />
                            <span v-if="activeFilterCount > 0" class="mi-iconbtn__count">{{ activeFilterCount }}</span>
                        </Button>
                    </Tooltip>

                    <!-- El dropdown de orden se necesita cuando NO hay cabeceras
                         clicables: vista lista/tarjetas, o en pantalla chica donde
                         la tabla se renderiza como tarjetas. -->
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
                        <Button class="mi-iconbtn" @click="colSel?.open()">
                            <ControlOutlined />
                        </Button>
                    </Tooltip>
                    <Tooltip v-if="canExportCustomer" :title="$t('global.export_hint')" data-tour="export-import">
                        <Button class="mi-iconbtn" @click="exportOpen = true">
                            <DownloadOutlined />
                        </Button>
                    </Tooltip>
                    <!-- Engranaje: Importar / Editar todo / Ver eliminados / ayuda. -->
                    <Dropdown :trigger="['click']" placement="bottomRight">
                        <Tooltip :title="$t('global.tools')" data-tour="tools">
                            <Button class="mi-iconbtn"><SettingOutlined /></Button>
                        </Tooltip>
                        <template #overlay>
                            <Menu>
                                <MenuItem v-if="canImportCustomer && canUsePlanFeature('imports')" key="import" @click="importOpen = true">
                                    <UploadOutlined /> {{ $t('global.import') }}
                                </MenuItem>
                                <MenuItem v-if="canEditCustomer && canUsePlanFeature('edit_all')" key="editall" @click="router.visit(route('business_management.customers.edit_all'))">
                                    <FormOutlined /> {{ $t('global.edit_all') }}
                                </MenuItem>
                                <MenuItem v-if="isSuper" key="trash" @click="router.visit(route('business_management.customers.trash'))">
                                    <InboxOutlined /> {{ $t('global.view_deleted') }}
                                </MenuItem>
                                <MenuItem key="help" @click="tour.restart()">
                                    <QuestionCircleOutlined /> {{ $t('global.tour_show_again') }}
                                </MenuItem>
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

                    <Tooltip v-if="canCreateCustomer" :title="$t('customers.new')" data-tour="new">
                        <Link :href="route('business_management.customers.create')">
                            <Button type="primary" class="mi-iconbtn mi-create-btn" :aria-label="$t('customers.new')">
                                <PlusOutlined />
                            </Button>
                        </Link>
                    </Tooltip>
                </div>
            </div>

            <!-- "Agregar filtro": builder inline, debajo del toolbar (en el card de la tabla). -->
            <div v-if="showFilters" class="mi-builder mi-builder--table">
                <InlineFilterBuilder
                    ref="builderRef"
                    v-model="advancedWhere"
                    :schema="props.filterSchema"
                    show-conjunction
                    @change="applyInlineFilters"
                >
                    <template #actions>
                        <Button v-if="hasActiveFilters || advancedCount > 0" type="link" class="bidx-clear" @click="clearAll">
                            <ClearOutlined /> {{ $t('global.clear_filters') }}
                        </Button>
                        <Button v-if="canUsePlanFeature('saved_views')" type="link" class="bidx-savefilter" @click="savedViewsRef?.openSave()">
                            <SaveOutlined /> {{ $t('global.save_filter') }}
                        </Button>
                    </template>
                </InlineFilterBuilder>
            </div>
        <Card :bodyStyle="{ padding: 0 }" class="grid-card">


            <ResponsiveTable
                :loading="isFetching"
                :dataSource="customers.data"
                :columns="columns"
                :pagination="tablePagination"
                :row-selection="(canDeleteCustomer || canEditCustomer) ? rowSelection : null"
                :scroll="{ x: 'max-content' }"
                :view="viewMode"
                rowKey="id"
                @change="onTableChange"
                data-tour="bulk"
            >
                <template #empty>
                    <CustomersEmptyState
                        :has-filters="hasActiveFilters"
                        :can-create="canCreateCustomer"
                        @clear-filters="clearFilters"
                        @open-import="importOpen = true"
                    />
                </template>
                <template #bodyCell="{ column, record, text, isMobile, compact }">
                    <CustomersFavoriteCell
                        v-if="column.key === 'favorite'"
                        :record="record"
                        :submitting="favoriteSubmitting"
                        :data-tour="record === customers.data[0] ? 'favorites' : null"
                        @toggle="toggleFavorite"
                    />

                    <template v-else-if="column.key === 'name'">
                        <div class="lead">
                            <span v-if="record.logo_url" class="lead__ava lead__ava--img">
                                <img :src="record.logo_url" :alt="record.name" />
                            </span>
                            <div class="lead__txt">
                                <Link :href="route('business_management.customers.show', record.slug)"
                                    class="lead__name lead__link">{{ record.name }}</Link>
                                <span v-if="record.cod" class="lead__sub">{{ record.cod }}</span>
                                <span v-else-if="record.country" class="lead__sub">{{ record.country.iso_code }} · {{ record.country.name }}</span>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="['locations_count', 'areas_count', 'substations_count', 'transformers_count'].includes(column.key)">
                        <span :class="{ muted: !(record[column.dataIndex] > 0) }">{{ record[column.dataIndex] ?? 0 }}</span>
                    </template>

                    <template v-else-if="column.key === 'status'">
                        <span class="pill" :class="record.is_active ? 'pill--ok' : 'pill--off'">
                            <span class="pill__dot" />
                            {{ record.is_active ? $t('global.active') : $t('global.inactive') }}
                        </span>
                    </template>

                    <template v-else-if="column.key === 'country'">
                        <span v-if="record.country">{{ record.country.iso_code }} · {{ record.country.name }}</span>
                        <span v-else class="muted">—</span>
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

                    <CustomersActionsCell
                        v-else-if="column.key === 'actions'"
                        :record="record"
                        :is-mobile="isMobile"
                        :compact="compact"
                        :is-super="isSuper"
                        :can-edit="canEditCustomer"
                        :can-create="canCreateCustomer"
                        :can-delete="canDeleteCustomer"
                        :duplicating-id="duplicating"
                        @edit="goEdit"
                        @duplicate="duplicate"
                        @delete="goDelete"
                    />

                    <template v-else>{{ text ?? record[column.dataIndex] ?? '' }}</template>
                </template>
            </ResponsiveTable>
        </Card>

        <CustomersBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :is-mobile="isMobileScreen"
            :bulk-activating="bulkActivating"
            :can-edit="canEditCustomer"
            :can-delete="canDeleteCustomer"
            @cancel="clearSelection"
            @set-active="bulkSetActive"
            @delete="openBulkDelete"
        />


        <CustomersBulkDeleteModal
            v-model:open="bulkOpen"
            v-model:reason="bulkReason"
            :count="selectedRowKeys.length"
            :submitting="bulkSubmitting"
            :error-msg="bulkError"
            :resource-label="selectedRowKeys.length === 1 ? $t('customers.record') : $t('customers.records')"
            @confirm="confirmBulkDelete"
        />

        <ExportDialog
            v-model:open="exportOpen"
            :columns="exportableColumns"
            :selected-ids="selectedRowKeys"
            :has-filters="hasActiveFilters"
            :filters-summary="filtersSummary"
            :current-filters="buildQueryData()"
            :default-title="$t('customers.export_title')"
            :endpoints="exportEndpoints"
            :limits="exportLimits"
            :total-rows="customers.total ?? 0"
            :total-unfiltered="customers.total_unfiltered ?? customers.total ?? 0"
        />

        <ImportDialog
            v-model:open="importOpen"
            :endpoint="route('business_management.customers.import')"
            :template-url="route('business_management.customers.import_template')"
            :resource-label="$t('customers.records')"
        />

    </div>
</template>

<style scoped>
.muted { color: var(--color-text-muted); font-size: 0.8125rem; }

/* Nombre del cliente como identificador clicable (Fiori): navega a la ficha
   (Show). Color del esquema, subrayado solo al hover. */
.lead__link { color: var(--color-primary); cursor: pointer; outline: none; text-decoration: none; }
.lead__link:hover, .lead__link:focus-visible { color: var(--color-primary); text-decoration: underline; }

/* ── Remaster del index (tabla tipo SaaS) ───────────────────────────── */
.bidx-toolbar { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.bidx-filters { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.bidx-search { max-width: 340px; }
.bidx-clear { padding-left: 4px; padding-right: 4px; }

/* Contenedor: rounded + sombra suave.
   OJO: nada de overflow:hidden aquí — rompe el position:sticky del thead (con
   offsetHeader 44px) y escondía la primera fila bajo la cabecera. Las esquinas
   se redondean en el thead/última fila, no clippeando el contenedor. */
.grid-card {
    border-radius: 12px;
    border: 1px solid var(--color-border, #e8eaed);
    box-shadow: 0 1px 2px rgba(16,24,40,0.04), 0 4px 12px rgba(16,24,40,0.04);
}
.grid-card :deep(.ant-table-thead > tr > th:first-child) { border-top-left-radius: 12px; }
.grid-card :deep(.ant-table-thead > tr > th:last-child)  { border-top-right-radius: 12px; }


/* Estado como pill con punto. */
.pill {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 3px 11px 3px 9px; border-radius: 999px;
    font-size: 0.76rem; font-weight: 600; line-height: 1.5; border: 1px solid transparent;
}
.pill__dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.pill--ok  { color: #137a43; background: rgba(29,122,68,0.10); border-color: rgba(29,122,68,0.18); }
.pill--ok  .pill__dot { background: #1d7a44; box-shadow: 0 0 0 3px rgba(29,122,68,0.12); }
.pill--off { color: #6a6d70; background: var(--color-surface-alt, #f3f4f6); border-color: var(--color-border, #e5e7eb); }
.pill--off .pill__dot { background: #9aa0a6; }

/* Cabecera minimal + filas COMPACTAS (densidad Fiori) + hover suave. */
.grid-card :deep(.ant-table-thead > tr > th) {
    background: var(--color-surface-alt, #f7f8fa);
    text-transform: uppercase; letter-spacing: 0.05em;
    font-size: 0.68rem; font-weight: 600; color: var(--color-text-muted, #8a9099);
    border-bottom: 1px solid var(--color-border, #eceef1);
    padding-top: 8px; padding-bottom: 8px;
}
.grid-card :deep(.ant-table-tbody > tr > td) {
    padding-top: 9px; padding-bottom: 9px;
    border-bottom: 1px solid var(--color-border-subtle, #f2f3f5);
}
/* Filas de datos SIEMPRE blancas (sin cebra); solo la fila de títulos va en gris. */
.grid-card :deep(.ant-table-tbody > tr > td) { background: var(--color-surface, #fff); }
.grid-card :deep(.ant-table-tbody > tr:last-child > td) { border-bottom: none; }
.grid-card :deep(.ant-table-tbody > tr) { transition: background 0.12s ease; }
.grid-card :deep(.ant-table-tbody > tr:hover > td) { background: var(--color-surface-hover, #f8fafc) !important; }
/* Acento sutil a la izquierda al pasar el mouse (detalle premium). */
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
    .hide-on-mobile { display: none !important; }
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
