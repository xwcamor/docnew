<script setup>
import { computed, ref, onMounted, watch, nextTick } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Card, Tag, Button, Tooltip, Space, Badge, Dropdown, Menu, MenuItem, Input, Drawer } from 'ant-design-vue';
import {
    PlusOutlined, EditOutlined, InboxOutlined,
    DownloadOutlined, UploadOutlined, QuestionCircleOutlined,
    FilterOutlined, CloseCircleFilled, EllipsisOutlined, SearchOutlined,
    SettingOutlined, TableOutlined, CloseOutlined,
    ControlOutlined, FormOutlined, ClearOutlined, SaveOutlined,
    SortAscendingOutlined, SortDescendingOutlined,
    StarOutlined, StarFilled, BarsOutlined, AppstoreOutlined,
    AudioOutlined,
} from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import ColumnSelector from '@/Components/Common/ColumnSelector.vue';
import FilterBar from '@/Components/Common/FilterBar.vue';
import InlineFilterBuilder from '@/Components/Common/InlineFilterBuilder.vue';
import ExportDialog from '@/Components/Common/ExportDialog.vue';
import ImportDialog from '@/Components/Common/ImportDialog.vue';
import SavedViews from '@/Components/Common/SavedViews.vue';

import PeopleBulkBar from '@/Components/People/PeopleBulkBar.vue';
import PeopleBulkDeleteModal from '@/Components/People/PeopleBulkDeleteModal.vue';
import PeopleFavoriteCell from '@/Components/People/PeopleFavoriteCell.vue';
import PeoplePageHeader from '@/Components/People/PeoplePageHeader.vue';
import PeopleActionsCell from '@/Components/People/PeopleActionsCell.vue';
import PeopleEmptyState from '@/Components/People/PeopleEmptyState.vue';

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
import { usePlanFeatures } from '@/Composables/usePlanFeatures';
import { useVoiceSearch } from '@/Composables/useVoiceSearch';
import { useI18n } from '@/Plugins/i18n';

// Gate per plan: saved_views requiere basic+, imports/edit_all requieren pro+.
// El toolbar inline de People (no usa ModuleToolbar) repite manualmente
// estos gates para no mostrar botones que no funcionan al user free/basic.
const { canUse: canUsePlanFeature } = usePlanFeatures();

import {
    peopleFilterFields, peopleEmptyFilters, hydratePeopleFilters,
    peopleFiltersToQuery, peopleFiltersSummary,
    serializeSavedFilters, deserializeSavedFilters,
} from './config/filters';
import { peopleTableColumns } from './config/columns';
import { peopleExportableColumns, peopleExportEndpoints } from './config/exports';
import { moduleTourSteps } from '@/Composables/moduleTourSteps';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const { can, isSuper, canSeeAudit } = useAuth();

// Avatar de iniciales con color estable (para la celda principal de la tabla).
const initials = (name) => (name || '').split(/\s+/).filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase() || '—';
const avaStyle = (name) => {
    let h = 0;
    for (const c of (name || '')) h = (h * 31 + c.charCodeAt(0)) % 360;
    return { background: `hsl(${h} 58% 52%)` };
};

// Empresas y cargos de una persona, sin repetidos y en orden alfabético: es el
// mismo criterio con el que ordena el servidor (`min(companies.name)`), así que
// lo que la celda enseña primero es justo por lo que se ordena.
const deLosVinculos = (record, saca) => [...new Set(
    (record?.company_links ?? []).map(saca).filter(Boolean),
)].sort((a, b) => a.localeCompare(b));
const empresasDe = (record) => deLosVinculos(record, (l) => l.company?.name);
const cargosDe   = (record) => deLosVinculos(record, (l) => l.position?.code);

const props = defineProps({
    people:      { type: Object, required: true },
    filters:        { type: Object, default: () => ({}) },
    filterSchema:   { type: Array,  default: () => [] },
    exportLimits:    { type: Object, default: () => ({}) },
    // Catálogos para los selectores de filtro (los arma el controller).
    countryOptions:  { type: Array, default: () => [] },
    docTypeOptions:  { type: Array, default: () => [] },
    companyOptions:  { type: Array, default: () => [] },
    roleOptions:     { type: Array, default: () => [] },
});

// ─── Filtros (schema + (de)serialización en config/filters.js) ──────────────
const filterFields = computed(() =>
    peopleFilterFields(t, {
        countryOptions: props.countryOptions,
        docTypeOptions: props.docTypeOptions,
        companyOptions: props.companyOptions,
        roleOptions:    props.roleOptions,
    }),
);

const {
    filters, reload, isFetching, suspendReload, hasActiveFilters, clearFilters, filtersSummary, buildQueryData,
} = useModuleFilters({
    serverFilters: props.filters,
    hydrate:       hydratePeopleFilters,
    toQuery:       peopleFiltersToQuery,
    summary:       peopleFiltersSummary,
    empty:         peopleEmptyFilters,
    only:          ['people', 'filters'],
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

// "Limpiar todo": resetea filtros normales Y avanzados de una. Navega a la URL
// limpia (conservando orden/paginación) para no dejar ningún param pegado.
const clearAll = () => {
    advancedWhere.value = [];
    router.get(
        route('business_management.people.index'),
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
    pagination: computed(() => props.people),
    hasActiveFilters,
    t,
});

// ─── Columnas (schema en config/columns.js) ─────────────────────────────────
// Viewport: el ancho de la columna de acciones depende de si es pantalla chica,
// así que se declara ANTES de allColumns (lo consume el computed).
const { isMobile: isMobileScreen } = useViewport(768);

const allColumns = computed(() =>
    peopleTableColumns(t, { isSuper: isSuper.value, isMobile: isMobileScreen.value }),
);
const { visibleColumnKeys, columns } = useColumnPreferences(allColumns);

// ─── Paginacion + sort ──────────────────────────────────────────────────────
const tablePagination = computed(() => ({
    current:  props.people.current_page,
    pageSize: props.people.per_page,
    total:    props.people.total,
    showSizeChanger: true,
    pageSizeOptions: ['10', '25', '50', '100'],
}));

// La clave de orden de una columna. Documento, país, empresa y cargo no tienen
// `dataIndex` —no son una columna de `people`— y ordenan por su `key`; el
// workspace sí lo tiene pero es un camino (`['tenant','name']`), y AntD lo pasa
// tal cual en `sorter.field`: al mandarlo sin aplanar salía `sort[]=tenant&
// sort[]=name`, el servidor no reconocía el array y la cabecera del workspace
// no ordenaba nada.
const normField = (di) => Array.isArray(di) ? di[0] : (typeof di === 'string' && di.includes('.') ? di.split('.')[0] : di);
const sortKeyOf = (c) => normField(c.dataIndex) ?? c.key;

/**
 * Los roles de una fila, como se leen y en el orden en el que se ordenan.
 *
 * El nombre sale del CATALOGO (`approver_roles`, que llega en `roleOptions`) y
 * no de una clave de idioma. Se pintaba con `$t('people.role_' + codigo)`, y eso
 * solo funciona con los tres que trae el producto: el catalogo es editable —un
 * cliente puede añadir «Jefe de Izaje»— y a ese la celda le sacaba el literal
 * «people.role_jefe_izaje» en pantalla.
 *
 * Y van alfabeticos porque asi es como los ordena el servidor: se ordena por el
 * primero de la lista, o sea el que se lee primero. Sin esto la celda enseñaria
 * uno y la cabecera ordenaria por otro.
 */
const etiquetasDeRol = computed(() =>
    Object.fromEntries((props.roleOptions ?? []).map((o) => [o.value, o.label])));

const rolesDe = (record) => (record.roles ?? [])
    .map((r) => {
        const code = r.role ?? r;
        return { code, label: etiquetasDeRol.value[code] ?? code };
    })
    .sort((a, b) => a.label.localeCompare(b.label));

const onTableChange = (pag, _f, sorter) => {
    const pedida = normField(sorter?.field) || sorter?.columnKey;
    // AntD cicla ascendente → descendente → sin orden, y el tercer clic llega
    // con la columna puesta y `order` vacío. Eso es «quítame el orden», así que
    // se vuelve al de siempre —lo último dado de alta arriba— en vez de dejar la
    // lista ordenada con la flecha apagada. Ojo: la paginación de las vistas de
    // lista y tarjetas emite un `sorter` vacío del todo, y eso NO es un clic en
    // una cabecera; por eso se mira que venga la columna.
    const quitaOrden = !!pedida && !sorter?.order;
    const sort = quitaOrden ? 'id' : (pedida || props.filters.sort);
    const direction = quitaOrden ? 'desc'
                    : sorter?.order === 'ascend' ? 'asc'
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
        if (cleaned.length > 0) data.advanced_where = JSON.stringify(cleaned);
        router.get(route('business_management.people.index'), data, {
            preserveScroll: true,
            preserveState: true,
            onStart:  () => { isFetching.value = true; },
            onFinish: () => { isFetching.value = false; },
        });
    }, 350);
};

// Aplica una vista guardada (filtros simples + cláusulas avanzadas + sort) en
// UNA sola navegación con params EXPLÍCITOS.
const applySavedViewState = (clauses, meta) => {
    const data = { ...buildQueryData(), sort: meta.sort, direction: meta.direction, per_page: meta.perPage };
    if (clauses.length > 0) data.advanced_where = JSON.stringify(clauses);
    router.get(route('business_management.people.index'), data, {
        preserveScroll: true,
        preserveState: true,
        onStart:  () => { isFetching.value = true; },
        onFinish: () => { isFetching.value = false; },
    });
};

// ─── Vista: tabla | lista | tarjetas (persistida en localStorage) ──────────
const VIEW_KEY = 'people_view_mode';
const viewMode = ref('table');
const vistaElegida = ref(false);
onMounted(() => {
    const saved = localStorage.getItem(VIEW_KEY);
    if (saved === 'cards' || saved === 'table' || saved === 'list') viewMode.value = saved;
    if (saved) vistaElegida.value = true;
});
watch(viewMode, (v) => { localStorage.setItem(VIEW_KEY, v); vistaElegida.value = true; });
// Mientras el usuario no elija vista, `auto`: tabla en escritorio y tarjetas
// en el movil. La tabla en un movil sale a dos columnas y con la cabecera
// de acciones partida; la tarjeta enseña nombre, codigo, estado y los cinco
// botones. `columns.js` ya declara que va en cada tarjeta (`mobile.role`),
// solo que nadie lo veia: pasar 'table' explicito apaga las tarjetas, y eso
// es justo lo que hacian los 25 indices. En cuanto elige una, manda la suya.
const vistaEfectiva = computed(() => (! vistaElegida.value && viewMode.value === 'table') ? 'auto' : viewMode.value);
const viewOptions = computed(() => [
    { value: 'table', label: t('global.view_table'),      icon: TableOutlined },
    { value: 'list',  label: t('global.view_list_short'), icon: BarsOutlined },
    { value: 'cards', label: t('global.view_cards'),      icon: AppstoreOutlined },
]);
const currentView = computed(() => viewOptions.value.find((o) => o.value === viewMode.value) ?? viewOptions.value[0]);
const setView = ({ key }) => { viewMode.value = key; };

// ─── Orden global (dropdown — funciona en tabla, lista y tarjetas) ─────────
// La etiqueta sale del `title` de la columna, que ya viene traducido; si alguna
// vez llegara un título que no es texto (un render con icono) se cae a la clave
// antes que dejar el menú vacío.
const sortOptions = computed(() =>
    allColumns.value
        .filter((c) => c.sorter)
        .map((c) => ({ value: sortKeyOf(c), label: typeof c.title === 'string' ? c.title : c.key }))
        .filter((o) => o.value),
);
const currentSort = computed(() => props.filters?.sort ?? 'id');
const currentDir  = computed(() => props.filters?.direction ?? 'desc');
const currentSortLabel = computed(() =>
    sortOptions.value.find((o) => o.value === currentSort.value)?.label ?? t('global.created_at'),
);
// La flecha de la cabecera la manda el servidor, no el estado interno de AntD.
// Sin esto, entrar por una vista guardada o por un enlace con `?sort=` mostraba
// la lista ya ordenada y ninguna cabecera marcada, y el desplegable de orden y
// las cabeceras podían decir cosas distintas a la vez.
const tableColumns = computed(() => columns.value.map((c) => (c.sorter
    ? { ...c, sortOrder: sortKeyOf(c) === currentSort.value ? (currentDir.value === 'asc' ? 'ascend' : 'descend') : null }
    : c)));

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

// ─── Undo toast (60s window) ────────────────────────────────────────────────
useModuleUndoToast('business_management.people.undo_last_delete');

// ─── Favoritos polimorficos ────────────────────────────────────────────────
const { submitting: favoriteSubmitting, toggle: toggleFavorite } = useModuleFavorites('people', 'people');

// ─── Bulk ───────────────────────────────────────────────────────────────────
const {
    selectedRowKeys, rowSelection, clearSelection,
    bulkOpen, bulkReason, bulkSubmitting, bulkError, bulkActivating,
    openBulkDelete, bulkSetActive, confirmBulkDelete,
} = useModuleBulkActions({
    bulkSetActiveRoute: 'business_management.people.bulk_set_active',
    bulkDeleteRoute:    'business_management.people.bulk_delete',
    resourceLabel:      t('people.records'),
    rowDisabled:      (r) => (!isSuper.value && r.tenant_id == null) || !!(r.is_locked ?? r.locked_at),
});

// ─── Duplicate ──────────────────────────────────────────────────────────────
const duplicating = ref(null);
const duplicate = (record) => {
    duplicating.value = record.id;
    router.post(route('business_management.people.duplicate', record.slug), {}, {
        preserveScroll: true,
        onFinish: () => { duplicating.value = null; },
    });
};

// ─── Export / Import (columnas + endpoints en config/exports.js) ────────────
const exportOpen = ref(false);
const importOpen = ref(false);
// Ref al ColumnSelector (montado oculto) para abrirlo desde el engranaje.
const colSel = ref(null);
const exportableColumns = computed(() => peopleExportableColumns(t, { isSuper: isSuper.value }));
const exportEndpoints   = computed(() => peopleExportEndpoints());

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
const tour = useModuleTour({ module: 'people', steps: () => moduleTourSteps(t, { moduleName: t('people.plural') }) });

// ─── Keyboard shortcuts ────────────────────────────────────────────────────
useKeyboardShortcuts({
    'ctrl+n': () => can('people.create') && router.visit(route('business_management.people.create')),
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
const goEdit   = (record) => router.visit(route('business_management.people.edit',   record.slug));
const goDelete = (record) => router.visit(route('business_management.people.delete', record.slug));
</script>

<template>
    <Head :title="$t('people.plural')" />

    <div class="sap-index">
        <!-- Título + acciones (las acciones viven en el toolbar de la tabla). -->
        <div class="mi-title" data-tour="module">
            <PeoplePageHeader
                :title="$t('people.plural')"
            />
        </div>

        <!-- Consola de filtros: búsqueda + builder + controles. -->
        <div class="mi-console mi-console--v2">
            <div v-if="canUsePlanFeature('saved_views')" class="mi-viewsbar" data-tour="saved-views">
                <SavedViews
                    ref="savedViewsRef"
                    layout="bar"
                    variant="tabs"
                    module="people"
                    :show-add="false"
                    :current-state="currentViewState"
                    :show-favorites="true"
                    :favorites-active="onlyFavorites"
                    @apply="applySavedState"
                    @default-loaded="applySavedState"
                    @toggle-favorites="toggleOnlyFavorites"
                />
            </div>

            <!-- ColumnSelector montado oculto: solo expone open() al engranaje/Columnas. -->
            <span class="mi-colsel-host" aria-hidden="true">
                <ColumnSelector
                    ref="colSel"
                    :columns="allColumns"
                    v-model="visibleColumnKeys"
                    storage-key="people"
                />
            </span>
        </div>

        <!-- Drawer de filtros (desktop): reusa el FilterBar completo. -->
        <Drawer v-model:open="filtersOpen" :title="$t('global.filters')" placement="right" :width="380">
            <FilterBar :fields="filterFields" v-model="filters" storage-key="people" />
        </Drawer>

        <!-- Toolbar Fiori (fuera del card): conteo izq · acciones/vistas/crear der. -->
        <div class="mi-tabletoolbar">
            <div class="mi-tabletoolbar__left">
                <span class="mi-toolbar-count">{{ counterLabel }}</span>
            </div>
            <div class="mi-tabletoolbar__right">
                <label class="mi-bar mi-bar--toolbar" :class="{ 'is-active': quickSearch }">
                    <SearchOutlined class="mi-bar__icon" />
                    <input v-model="quickSearch" class="mi-bar__input" :placeholder="$t('people.search_placeholder')" autocomplete="off" spellcheck="false" type="text" />
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
                <Tooltip v-if="can('people.export')" :title="$t('global.export_hint')" data-tour="export-import">
                    <Button class="mi-iconbtn" @click="exportOpen = true"><DownloadOutlined /></Button>
                </Tooltip>
                <Dropdown :trigger="['click']" placement="bottomRight">
                    <Tooltip :title="$t('global.tools')" data-tour="tools">
                        <Button class="mi-iconbtn"><SettingOutlined /></Button>
                    </Tooltip>
                    <template #overlay>
                        <Menu>
                            <MenuItem v-if="can('people.create') && can('people.import') && canUsePlanFeature('imports')" key="import" @click="importOpen = true"><UploadOutlined /> {{ $t('global.import') }}</MenuItem>
                            <MenuItem v-if="can('people.edit') && canUsePlanFeature('edit_all')" key="editall" @click="router.visit(route('business_management.people.edit_all'))"><FormOutlined /> {{ $t('global.edit_all') }}</MenuItem>
                            <MenuItem v-if="isSuper" key="trash" @click="router.visit(route('business_management.people.trash'))"><InboxOutlined /> {{ $t('global.view_deleted') }}</MenuItem>
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

                <Tooltip v-if="can('people.create')" :title="$t('people.new')" data-tour="new">
                    <Link :href="route('business_management.people.create')">
                        <Button type="primary" class="mi-iconbtn mi-create-btn" :aria-label="$t('people.new')"><PlusOutlined /></Button>
                    </Link>
                </Tooltip>
            </div>
        </div>

        <div v-if="showFilters" class="mi-builder mi-builder--table">
            <InlineFilterBuilder ref="builderRef" v-model="advancedWhere" :schema="props.filterSchema" show-conjunction @change="applyInlineFilters">
                <template #actions>
                    <Button v-if="hasActiveFilters || advancedCount > 0" type="link" class="bidx-clear" @click="clearAll"><ClearOutlined /> {{ $t('global.clear_filters') }}</Button>
                    <Button v-if="canUsePlanFeature('saved_views')" type="link" class="bidx-savefilter" @click="savedViewsRef?.openSave()"><SaveOutlined /> {{ $t('global.save_filter') }}</Button>
                </template>
            </InlineFilterBuilder>
        </div>

        <Card :bodyStyle="{ padding: 0 }" class="grid-card">

            <!-- Toolbar de resultados, pegada a la tabla (no flota suelta). -->
            <ResponsiveTable
                :loading="isFetching"
                :dataSource="people.data"
                :columns="tableColumns"
                :pagination="tablePagination"
                :row-selection="(can('people.delete') || can('people.edit')) ? rowSelection : null"
                :scroll="{ x: 'max-content' }"
                :view="vistaEfectiva"
                rowKey="id"
                @change="onTableChange"
                data-tour="bulk"
            >
                <template #empty>
                    <PeopleEmptyState
                        :has-filters="hasActiveFilters"
                        :can-create="can('people.create')"
                        @clear-filters="clearFilters"
                        @open-import="importOpen = true"
                    />
                </template>
                <template #bodyCell="{ column, record, text, isMobile, compact }">
                    <PeopleFavoriteCell
                        v-if="column.key === 'favorite'"
                        :record="record"
                        :submitting="favoriteSubmitting"
                        :data-tour="record === people.data[0] ? 'favorites' : null"
                        @toggle="toggleFavorite"
                    />

                    <!-- APELLIDO, Nombre: es como se busca a alguien en una
                         lista de asistencia. -->
                    <template v-else-if="column.key === 'person'">
                        <div class="lead">
                            <div class="lead__txt">
                                <Link :href="route('business_management.people.show', record.slug)" class="lead__name lead__link">
                                    {{ record.lastname }}<template v-if="record.name">, {{ record.name }}</template>
                                </Link>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="column.key === 'document'">
                        <!-- `safe_num_doc`, no `num_doc`: el numero entero ni
                             siquiera sale del servidor sin
                             `people.view_private_info`. -->
                        <span class="doc"><span class="doc__type">{{ record.doc_type }}</span> <code class="mono">{{ record.safe_num_doc }}</code></span>
                    </template>

                    <template v-else-if="column.key === 'country'">
                        <span v-if="record.country">{{ record.country.name }}</span>
                        <span v-else class="muted">—</span>
                    </template>

                    <!-- Empresa y cargo cuelgan del vínculo y una persona puede
                         tener varios: se enseña el primero por orden alfabético
                         —el mismo por el que ordena el servidor, para que lo que
                         se lee y lo que se ordena coincidan— y el resto se
                         cuenta en un «+N» con la lista entera en el tooltip. -->
                    <template v-else-if="column.key === 'company'">
                        <span v-if="empresasDe(record).length" class="multi">
                            <span class="multi__first">{{ empresasDe(record)[0] }}</span>
                            <Tooltip v-if="empresasDe(record).length > 1" :title="empresasDe(record).join(' · ')">
                                <Tag :bordered="false" class="multi__more">+{{ empresasDe(record).length - 1 }}</Tag>
                            </Tooltip>
                        </span>
                        <span v-else class="muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'position'">
                        <span v-if="cargosDe(record).length" class="multi">
                            <span class="multi__first">{{ cargosDe(record)[0] }}</span>
                            <Tooltip v-if="cargosDe(record).length > 1" :title="cargosDe(record).join(' · ')">
                                <Tag :bordered="false" class="multi__more">+{{ cargosDe(record).length - 1 }}</Tag>
                            </Tooltip>
                        </span>
                        <span v-else class="muted">—</span>
                    </template>

                    <!-- Sin biometría vigente la persona no puede firmar en obra:
                         se marca en rojo para que salte a la vista. -->
                    <template v-else-if="column.key === 'biometric'">
                        <span class="pill" :class="record.active_biometrics_count > 0 ? 'pill--ok' : 'pill--none'">
                            <span class="pill__dot" />
                            {{ record.active_biometrics_count > 0 ? $t('people.biometric_yes') : $t('people.biometric_no') }}
                        </span>
                    </template>

                    <template v-else-if="column.key === 'roles'">
                        <template v-if="record.roles?.length">
                            <Tag v-for="r in rolesDe(record)" :key="r.code" color="geekblue" :bordered="false">{{ r.label }}</Tag>
                        </template>
                        <span v-else class="muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'tenant'">
                        <Tag v-if="record.tenant" color="blue" :bordered="false">
                            {{ record.tenant.name }}
                        </Tag>
                        <Tag v-else color="purple" :bordered="false">{{ $t('global.platform') }}</Tag>
                    </template>

                    <template v-else-if="column.key === 'status'">
                        <span class="pill" :class="record.is_active ? 'pill--ok' : 'pill--off'">
                            <span class="pill__dot" />
                            {{ record.is_active ? $t('global.active') : $t('global.inactive') }}
                        </span>
                    </template>


                    <PeopleActionsCell
                        v-else-if="column.key === 'actions'"
                        :record="record"
                        :is-mobile="isMobile"
                        :compact="compact"
                        :is-super="isSuper"
                        :can-edit="can('people.edit')"
                        :can-create="can('people.create')"
                        :can-delete="can('people.delete')"
                        :duplicating-id="duplicating"
                        @edit="goEdit"
                        @duplicate="duplicate"
                        @delete="goDelete"
                    />

                    <template v-else>{{ text ?? record[column.dataIndex] ?? '' }}</template>
                </template>
            </ResponsiveTable>
        </Card>

        <PeopleBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :is-mobile="isMobileScreen"
            :bulk-activating="bulkActivating"
            :can-edit="can('people.edit')"
            :can-delete="can('people.delete')"
            @cancel="clearSelection"
            @set-active="bulkSetActive"
            @delete="openBulkDelete"
        />

        <PeopleBulkDeleteModal
            v-model:open="bulkOpen"
            v-model:reason="bulkReason"
            :count="selectedRowKeys.length"
            :submitting="bulkSubmitting"
            :error-msg="bulkError"
            :resource-label="selectedRowKeys.length === 1 ? $t('people.record') : $t('people.records')"
            @confirm="confirmBulkDelete"
        />

        <ExportDialog
            v-model:open="exportOpen"
            :columns="exportableColumns"
            :selected-ids="selectedRowKeys"
            :has-filters="hasActiveFilters"
            :filters-summary="filtersSummary"
            :current-filters="buildQueryData()"
            :default-title="$t('people.export_title')"
            :endpoints="exportEndpoints"
            :limits="exportLimits"
            :total-rows="people.total ?? 0"
            :total-unfiltered="people.total_unfiltered ?? people.total ?? 0"
        />

        <ImportDialog
            v-model:open="importOpen"
            :endpoint="route('business_management.people.import')"
            :template-url="route('business_management.people.import_template')"
            :resource-label="$t('people.records')"
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
/* Sin cara enrolada: no es un estado neutro, es un bloqueo para firmar. */
.pill--none { color: #a8261c; background: rgba(200,40,29,0.08); border-color: rgba(200,40,29,0.20); }
.pill--none .pill__dot { background: #c8281d; }
.doc__type { font-size: 0.7rem; font-weight: 600; color: var(--color-text-muted); letter-spacing: 0.04em; }

/* Empresa / cargo: el primero completo y el resto contado, sin que la celda
   crezca hasta romper el ancho de la tabla en la tablet. */
.multi { display: inline-flex; align-items: center; gap: 6px; min-width: 0; }
.multi__first { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.multi__more { flex-shrink: 0; background: var(--color-surface-alt); color: var(--color-text-muted); font-size: 0.7rem; }

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
    .page-header { flex-direction: column; align-items: stretch; }
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

