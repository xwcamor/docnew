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
    AudioOutlined, LockOutlined,
} from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import ColumnSelector from '@/Components/Common/ColumnSelector.vue';
import FilterBar from '@/Components/Common/FilterBar.vue';
import InlineFilterBuilder from '@/Components/Common/InlineFilterBuilder.vue';
import ExportDialog from '@/Components/Common/ExportDialog.vue';
import ImportDialog from '@/Components/Common/ImportDialog.vue';
import SavedViews from '@/Components/Common/SavedViews.vue';

import WorkPlansBulkBar from '@/Components/WorkPlans/WorkPlansBulkBar.vue';
import WorkPlansBulkDeleteModal from '@/Components/WorkPlans/WorkPlansBulkDeleteModal.vue';
import WorkPlansFavoriteCell from '@/Components/WorkPlans/WorkPlansFavoriteCell.vue';
import WorkPlansPageHeader from '@/Components/WorkPlans/WorkPlansPageHeader.vue';
import WorkPlansActionsCell from '@/Components/WorkPlans/WorkPlansActionsCell.vue';
import WorkPlansEmptyState from '@/Components/WorkPlans/WorkPlansEmptyState.vue';

import { useAuth } from '@/Composables/useAuth';
import { diaLiteral } from '@/Utils/fechaLiteral';
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
// El toolbar inline de WorkPlans (no usa ModuleToolbar) repite manualmente
// estos gates para no mostrar botones que no funcionan al user free/basic.
const { canUse: canUsePlanFeature } = usePlanFeatures();

import {
    workPlansFilterFields, workPlansEmptyFilters, hydrateWorkPlansFilters,
    workPlansFiltersToQuery, workPlansFiltersSummary,
    serializeSavedFilters, deserializeSavedFilters,
} from './config/filters';
import { workPlansTableColumns } from './config/columns';
import { workPlansExportableColumns, workPlansExportEndpoints } from './config/exports';
import { moduleTourSteps } from '@/Composables/moduleTourSteps';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const { can, isSuper, canSeeAudit } = useAuth();

// Las fechas del plan son de calendario, no instantes: las escribe una persona
// y se leen literales, sin pasarlas por ningún huso. Ver `Utils/fechaLiteral`.
const plainDate = (v) => diaLiteral(v) || '—';

const props = defineProps({
    work_plans:      { type: Object, required: true },
    filters:        { type: Object, default: () => ({}) },
    filterSchema:   { type: Array,  default: () => [] },
    exportLimits:    { type: Object, default: () => ({}) },
    // Catálogos de obra para los selectores de filtro (los arma el controller).
    companyOptions:      { type: Array, default: () => [] },
    workTypeOptions:     { type: Array, default: () => [] },
    workLocationOptions: { type: Array, default: () => [] },
});

// ─── Filtros (schema + (de)serialización en config/filters.js) ──────────────
const filterFields = computed(() =>
    workPlansFilterFields(t, {
        companyOptions:      props.companyOptions,
        workTypeOptions:     props.workTypeOptions,
        workLocationOptions: props.workLocationOptions,
    }),
);

const {
    filters, reload, isFetching, suspendReload, hasActiveFilters, clearFilters, filtersSummary, buildQueryData,
} = useModuleFilters({
    serverFilters: props.filters,
    hydrate:       hydrateWorkPlansFilters,
    toQuery:       workPlansFiltersToQuery,
    summary:       workPlansFiltersSummary,
    empty:         workPlansEmptyFilters,
    only:          ['work_plans', 'filters'],
    t,
});

// ─── Remaster: filtros colapsados en drawer + buscador inline ───────────────
// El campo `search` es de tipo tags (multi-valor); el buscador inline maneja el
// término principal (primer tag) para no saturar el bar. El resto de filtros
// vive en el drawer "Filtros".
const filtersOpen = ref(false);
const quickSearch = computed({
    get: () => (filters.value.search?.[0]) ?? '',
    set: (v) => { filters.value.search = v ? [v] : []; },
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
        route('business_management.work_plans.index'),
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
    pagination: computed(() => props.work_plans),
    hasActiveFilters,
    t,
});

// ─── Columnas (schema en config/columns.js) ─────────────────────────────────
// Viewport: el ancho de la columna de acciones depende de si es pantalla chica,
// así que se declara ANTES de allColumns (lo consume el computed).
const { isMobile: isMobileScreen } = useViewport(768);

const allColumns = computed(() =>
    workPlansTableColumns(t, { isSuper: isSuper.value, isMobile: isMobileScreen.value }),
);
const { visibleColumnKeys, columns } = useColumnPreferences(allColumns);

// ─── Paginacion + sort ──────────────────────────────────────────────────────
const tablePagination = computed(() => ({
    current:  props.work_plans.current_page,
    pageSize: props.work_plans.per_page,
    total:    props.work_plans.total,
    showSizeChanger: true,
    pageSizeOptions: ['10', '25', '50', '100'],
}));

const onTableChange = (pag, _f, sorter) => {
    // Las columnas de relación (empresa, tipo, sede) no tienen dataIndex: su
    // clave de orden es la `key` de la columna, que el modelo sabe resolver.
    const sort = sorter?.field || sorter?.columnKey || props.filters.sort;
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
        if (cleaned.length > 0) data.advanced_where = JSON.stringify(cleaned);
        router.get(route('business_management.work_plans.index'), data, {
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
    router.get(route('business_management.work_plans.index'), data, {
        preserveScroll: true,
        preserveState: true,
        onStart:  () => { isFetching.value = true; },
        onFinish: () => { isFetching.value = false; },
    });
};

// ─── Vista: tabla | lista | tarjetas (persistida en localStorage) ──────────
const VIEW_KEY = 'work_plans_view_mode';
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
const normField = (di) => Array.isArray(di) ? di[0] : (typeof di === 'string' && di.includes('.') ? di.split('.')[0] : di);
const sortOptions = computed(() =>
    allColumns.value
        .filter((c) => c.sorter)
        .map((c) => ({ value: normField(c.dataIndex) ?? c.key, label: typeof c.title === 'string' ? c.title : c.key }))
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
useModuleUndoToast('business_management.work_plans.undo_last_delete');

// ─── Favoritos polimorficos ────────────────────────────────────────────────
const { submitting: favoriteSubmitting, toggle: toggleFavorite } = useModuleFavorites('work_plans', 'work_plans');

// ─── Bulk ───────────────────────────────────────────────────────────────────
const {
    selectedRowKeys, rowSelection, clearSelection,
    bulkOpen, bulkReason, bulkSubmitting, bulkError, bulkActivating,
    openBulkDelete, bulkSetActive, confirmBulkDelete,
} = useModuleBulkActions({
    bulkSetActiveRoute: 'business_management.work_plans.bulk_set_active',
    bulkDeleteRoute:    'business_management.work_plans.bulk_delete',
    resourceLabel:      t('work_plans.records'),
    rowDisabled:      (r) => (!isSuper.value && r.tenant_id == null) || !!(r.is_locked ?? r.locked_at),
});

// ─── Duplicate ──────────────────────────────────────────────────────────────
const duplicating = ref(null);
const duplicate = (record) => {
    duplicating.value = record.id;
    router.post(route('business_management.work_plans.duplicate', record.slug), {}, {
        preserveScroll: true,
        onFinish: () => { duplicating.value = null; },
    });
};

// ─── Export / Import (columnas + endpoints en config/exports.js) ────────────
const exportOpen = ref(false);
const importOpen = ref(false);
// Ref al ColumnSelector (montado oculto) para abrirlo desde el engranaje.
const colSel = ref(null);
const exportableColumns = computed(() => workPlansExportableColumns(t, { isSuper: isSuper.value }));
const exportEndpoints   = computed(() => workPlansExportEndpoints());

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
const tour = useModuleTour({ module: 'work_plans', steps: () => moduleTourSteps(t, { moduleName: t('work_plans.plural') }) });

// ─── Keyboard shortcuts ────────────────────────────────────────────────────
useKeyboardShortcuts({
    'ctrl+n': () => can('work_plans.create') && router.visit(route('business_management.work_plans.create')),
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
const goEdit   = (record) => router.visit(route('business_management.work_plans.edit',   record.slug));
const goDelete = (record) => router.visit(route('business_management.work_plans.delete', record.slug));
</script>

<template>
    <Head :title="$t('work_plans.plural')" />

    <div class="sap-index">
        <!-- Título + acciones (las acciones viven en el toolbar de la tabla). -->
        <div class="mi-title" data-tour="module">
            <WorkPlansPageHeader
                :title="$t('work_plans.plural')"
                :subtitle="$t('work_plans.index_subtitle')"
            />
        </div>

        <!-- Consola de filtros: búsqueda + builder + controles. -->
        <div class="mi-console mi-console--v2">
            <div v-if="canUsePlanFeature('saved_views')" class="mi-viewsbar" data-tour="saved-views">
                <SavedViews
                    ref="savedViewsRef"
                    layout="bar"
                    variant="tabs"
                    module="work_plans"
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
                    storage-key="work_plans"
                />
            </span>
        </div>

        <!-- Drawer de filtros (desktop): reusa el FilterBar completo. -->
        <Drawer v-model:open="filtersOpen" :title="$t('global.filters')" placement="right" :width="380">
            <FilterBar :fields="filterFields" v-model="filters" storage-key="work_plans" />
        </Drawer>

        <!-- Toolbar Fiori (fuera del card): conteo izq · acciones/vistas/crear der. -->
        <div class="mi-tabletoolbar">
            <div class="mi-tabletoolbar__left">
                <span class="mi-toolbar-count">{{ counterLabel }}</span>
            </div>
            <div class="mi-tabletoolbar__right">
                <label class="mi-bar mi-bar--toolbar" :class="{ 'is-active': quickSearch }">
                    <SearchOutlined class="mi-bar__icon" />
                    <input v-model="quickSearch" class="mi-bar__input" :placeholder="$t('work_plans.search_placeholder')" autocomplete="off" spellcheck="false" type="text" />
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
                <Tooltip v-if="can('work_plans.export')" :title="$t('global.export_hint')" data-tour="export-import">
                    <Button class="mi-iconbtn" @click="exportOpen = true"><DownloadOutlined /></Button>
                </Tooltip>
                <Dropdown :trigger="['click']" placement="bottomRight">
                    <Tooltip :title="$t('global.tools')" data-tour="tools">
                        <Button class="mi-iconbtn"><SettingOutlined /></Button>
                    </Tooltip>
                    <template #overlay>
                        <Menu>
                            <MenuItem v-if="can('work_plans.create') && can('work_plans.import') && canUsePlanFeature('imports')" key="import" @click="importOpen = true"><UploadOutlined /> {{ $t('global.import') }}</MenuItem>
                            <MenuItem v-if="can('work_plans.edit') && canUsePlanFeature('edit_all')" key="editall" @click="router.visit(route('business_management.work_plans.edit_all'))"><FormOutlined /> {{ $t('global.edit_all') }}</MenuItem>
                            <MenuItem v-if="isSuper" key="trash" @click="router.visit(route('business_management.work_plans.trash'))"><InboxOutlined /> {{ $t('global.view_deleted') }}</MenuItem>
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

                <Tooltip v-if="can('work_plans.create')" :title="$t('work_plans.new')" data-tour="new">
                    <Link :href="route('business_management.work_plans.create')">
                        <Button type="primary" class="mi-iconbtn mi-create-btn" :aria-label="$t('work_plans.new')"><PlusOutlined /></Button>
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
                :dataSource="work_plans.data"
                :columns="columns"
                :pagination="tablePagination"
                :row-selection="(can('work_plans.delete') || can('work_plans.edit')) ? rowSelection : null"
                :scroll="{ x: 'max-content' }"
                :view="vistaEfectiva"
                rowKey="id"
                @change="onTableChange"
                data-tour="bulk"
            >
                <template #empty>
                    <WorkPlansEmptyState
                        :has-filters="hasActiveFilters"
                        :can-create="can('work_plans.create')"
                        @clear-filters="clearFilters"
                        @open-import="importOpen = true"
                    />
                </template>
                <template #bodyCell="{ column, record, text, isMobile, compact }">
                    <WorkPlansFavoriteCell
                        v-if="column.key === 'favorite'"
                        :record="record"
                        :submitting="favoriteSubmitting"
                        :data-tour="record === work_plans.data[0] ? 'favorites' : null"
                        @toggle="toggleFavorite"
                    />

                    <!-- Celda principal: el código identifica el plan; la orden
                         de servicio va debajo porque es lo segundo que se busca. -->
                    <template v-else-if="column.key === 'code'">
                        <div class="lead">
                            <div class="lead__txt">
                                <Link :href="route('business_management.work_plans.show', record.slug)" class="lead__name lead__link mono">{{ record.code }}</Link>
                                <span v-if="record.num_os" class="lead__sub">{{ $t('work_plans.num_os') }}: {{ record.num_os }}</span>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="column.key === 'company'">
                        <span v-if="record.company">{{ record.company.name }}</span>
                        <span v-else class="muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'work_type'">
                        <Tag v-if="record.work_type" color="geekblue" :bordered="false">{{ record.work_type.code }}</Tag>
                        <span v-else class="muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'work_location'">
                        <span v-if="record.work_location">{{ record.work_location.name }}</span>
                        <span v-else class="muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'work_area'">
                        <span v-if="record.work_area">{{ record.work_area.name }}</span>
                        <span v-else class="muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'workstation'">
                        <span v-if="record.workstation">{{ record.workstation.name }}</span>
                        <span v-else class="muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'registered_by'">
                        <span v-if="record.user">{{ record.user.name }}</span>
                        <span v-else class="muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'tenant'">
                        <Tag v-if="record.tenant" color="blue" :bordered="false">
                            {{ record.tenant.name }}
                        </Tag>
                        <Tag v-else color="purple" :bordered="false">{{ $t('global.platform') }}</Tag>
                    </template>

                    <!-- Sólo el avance: Terminado o Pendiente.
                         El candado de «cerrado» estaba también aquí, y era la
                         misma información dos veces en la misma fila: un plan
                         terminado está cerrado, y que ya no se pueda tocar lo
                         dice la columna de acciones, donde sale el candado en
                         lugar de Editar y Eliminar. -->
                    <!-- Tres estados, no dos. «Reabierto» tambien esta en
                         curso, pero no por lo mismo que un pendiente: ya estuvo
                         terminado y alguien lo abrio para corregirlo. Sin
                         distinguirlo, el plan reabierto de la semana pasada se
                         pierde entre los pendientes del dia — y es justo el que
                         alguien tiene que acordarse de volver a cerrar. -->
                    <template v-else-if="column.key === 'status'">
                        <span class="pill" :class="record.reopened_at ? 'pill--info' : (record.is_done ? 'pill--ok' : 'pill--warn')">
                            <span class="pill__dot" />
                            {{ record.reopened_at
                                ? $t('work_plans.state_reopened')
                                : (record.is_done ? $t('work_plans.state_done') : $t('work_plans.state_pending')) }}
                        </span>
                    </template>

                    <template v-else-if="column.key === 'date_start' || column.key === 'date_end'">
                        {{ plainDate(record[column.dataIndex]) }}
                    </template>

                    <WorkPlansActionsCell
                        v-else-if="column.key === 'actions'"
                        :record="record"
                        :is-mobile="isMobile"
                        :compact="compact"
                        :is-super="isSuper"
                        :can-edit="can('work_plans.edit')"
                        :can-create="can('work_plans.create')"
                        :can-delete="can('work_plans.delete')"
                        :duplicating-id="duplicating"
                        @edit="goEdit"
                        @duplicate="duplicate"
                        @delete="goDelete"
                    />

                    <template v-else>{{ text ?? record[column.dataIndex] ?? '' }}</template>
                </template>
            </ResponsiveTable>
        </Card>

        <WorkPlansBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :is-mobile="isMobileScreen"
            :bulk-activating="bulkActivating"
            :can-edit="can('work_plans.edit')"
            :can-delete="can('work_plans.delete')"
            @cancel="clearSelection"
            @set-active="bulkSetActive"
            @delete="openBulkDelete"
        />

        <WorkPlansBulkDeleteModal
            v-model:open="bulkOpen"
            v-model:reason="bulkReason"
            :count="selectedRowKeys.length"
            :submitting="bulkSubmitting"
            :error-msg="bulkError"
            :resource-label="selectedRowKeys.length === 1 ? $t('work_plans.record') : $t('work_plans.records')"
            @confirm="confirmBulkDelete"
        />

        <ExportDialog
            v-model:open="exportOpen"
            :columns="exportableColumns"
            :selected-ids="selectedRowKeys"
            :has-filters="hasActiveFilters"
            :filters-summary="filtersSummary"
            :current-filters="buildQueryData()"
            :default-title="$t('work_plans.export_title')"
            :endpoints="exportEndpoints"
            :limits="exportLimits"
            :total-rows="work_plans.total ?? 0"
            :total-unfiltered="work_plans.total_unfiltered ?? work_plans.total ?? 0"
        />

        <ImportDialog
            v-model:open="importOpen"
            :endpoint="route('business_management.work_plans.import')"
            :template-url="route('business_management.work_plans.import_template')"
            :resource-label="$t('work_plans.records')"
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
/* Reabierto: azul informativo. Estuvo terminado y alguien lo abrio para
   corregirlo — no es el ambar del pendiente normal, porque no es el mismo
   trabajo: este ya se hizo una vez y hay que acordarse de volver a cerrarlo.
   Con tokens, no con hex: los de al lado son deuda de antes del estandar. */
.pill--info { color: var(--state-info-text); background: var(--state-info-bg); border-color: var(--state-info-border); }
.pill--info .pill__dot { background: var(--state-info-text); box-shadow: 0 0 0 3px var(--state-info-bg); }

/* Pendiente: ámbar. Un plan sin terminar no es un error, es trabajo en curso. */
.pill--warn { color: #8a5a00; background: rgba(224,150,0,0.10); border-color: rgba(224,150,0,0.22); }
.pill--warn .pill__dot { background: #e09600; box-shadow: 0 0 0 3px rgba(224,150,0,0.12); }

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

