<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Card, Tag, Button, Tooltip, Badge, Dropdown, Menu, MenuItem, Input, Drawer } from 'ant-design-vue';
import {
    PlusOutlined, EditOutlined, InboxOutlined,
    DownloadOutlined, UploadOutlined, QuestionCircleOutlined,
    FilterOutlined, EllipsisOutlined, SearchOutlined,
    SettingOutlined, TableOutlined, CloseOutlined,
    SortAscendingOutlined, SortDescendingOutlined,
    StarOutlined, StarFilled, BarsOutlined, AppstoreOutlined,
    AudioOutlined,
    ControlOutlined, ClearOutlined, SaveOutlined,
} from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import FilterBar from '@/Components/Common/FilterBar.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import ColumnSelector from '@/Components/Common/ColumnSelector.vue';
import SavedViews from '@/Components/Common/SavedViews.vue';
import ExportDialog from '@/Components/Common/ExportDialog.vue';
import ImportDialog from '@/Components/Common/ImportDialog.vue';
import TableSkeleton from '@/Components/Common/TableSkeleton.vue';
import InlineFilterBuilder from '@/Components/Common/InlineFilterBuilder.vue';

import TenantsPageHeader from '@/Components/Tenants/TenantsPageHeader.vue';
import TenantsBulkBar from '@/Components/Tenants/TenantsBulkBar.vue';
import TenantsBulkDeleteModal from '@/Components/Tenants/TenantsBulkDeleteModal.vue';
import TenantsEmptyState from '@/Components/Tenants/TenantsEmptyState.vue';
import TenantsFavoriteCell from '@/Components/Tenants/TenantsFavoriteCell.vue';
import TenantsActionsCell from '@/Components/Tenants/TenantsActionsCell.vue';

import { useAuth } from '@/Composables/useAuth';
import { useKeyboardShortcuts } from '@/Composables/useKeyboardShortcuts';
import { useModuleTour } from '@/Composables/useModuleTour';
import { useModuleFavorites } from '@/Composables/useModuleFavorites';
import { useModuleUndoToast } from '@/Composables/useModuleUndoToast';
import { useModuleBulkActions } from '@/Composables/useModuleBulkActions';
import { useModuleFilters } from '@/Composables/useModuleFilters';
import { useModuleSavedViews } from '@/Composables/useModuleSavedViews';
import { useViewport } from '@/Composables/useViewport';
import { usePageLoading } from '@/Composables/usePageLoading';
import { useModuleListMeta } from '@/Composables/useModuleListMeta';
import { useColumnPreferences } from '@/Composables/useColumnPreferences';
import { useDateFormat } from '@/Composables/useDateFormat';
import { usePlanFeatures } from '@/Composables/usePlanFeatures';
import { useVoiceSearch } from '@/Composables/useVoiceSearch';
import { useI18n } from '@/Plugins/i18n';

import {
    tenantsFilterFields, tenantsEmptyFilters, hydrateTenantsFilters,
    tenantsFiltersToQuery, tenantsFiltersSummary,
    serializeSavedFilters, deserializeSavedFilters,
} from './config/filters';
import { tenantsTableColumns } from './config/columns';
import { tenantsExportableColumns, tenantsExportEndpoints } from './config/exports';
import { moduleTourSteps } from '@/Composables/moduleTourSteps';

defineOptions({ layout: AppLayout });

const props = defineProps({
    tenants:      { type: Object, required: true },
    filters:      { type: Object, required: true },
    exportLimits: { type: Object, default: () => ({}) },
    planOptions:  { type: Array, default: () => [] },
    filterSchema: { type: Array, default: () => [] },
});

const { t } = useI18n();
const { can, isSuper } = useAuth();
const { formatDateTime } = useDateFormat();

// Gates por plan: saved_views (basic+), imports/edit_all (pro+). El toolbar
// inline remasterizado los repite manualmente para no mostrar acciones inertes.
const { canUse: canUsePlanFeature } = usePlanFeatures();

// Avatar de iniciales con color estable (celda principal de la tabla).
const initials = (name) => (name || '').split(/\s+/).filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase() || '—';
const avaStyle = (name) => {
    let h = 0;
    for (const c of (name || '')) h = (h * 31 + c.charCodeAt(0)) % 360;
    return { background: `hsl(${h} 58% 52%)` };
};

// ─── Viewport + loading ──────────────────────────────────────────────────
const { isMobile: isMobileScreen } = useViewport(768);
const { loading: tableLoading } = usePageLoading('/tenants', 'tenants');

// ─── Filters (composable + config) ───────────────────────────────────────
const filterFields = computed(() => tenantsFilterFields(t, {
    planOptions: props.planOptions,
}));
const {
    filters, reload, isFetching, suspendReload, hasActiveFilters, clearFilters, filtersSummary, buildQueryData,
} = useModuleFilters({
    serverFilters: props.filters,
    hydrate:       hydrateTenantsFilters,
    toQuery:       tenantsFiltersToQuery,
    summary:       tenantsFiltersSummary,
    empty:         tenantsEmptyFilters,
    only:          ['tenants', 'filters'],
    t,
});

const showSkeleton = computed(() =>
    tableLoading.value && (!props.tenants?.data || props.tenants.data.length === 0)
);

// ─── Remaster: filtros colapsados en drawer + buscador inline ───────────────
// El campo `name` es de tipo tags (multi-valor); el buscador inline maneja el
// término principal (primer tag). El resto de filtros vive en el drawer.
const filtersOpen = ref(false);
const quickSearch = computed({
    get: () => (filters.value.name?.[0]) ?? '',
    set: (v) => { filters.value.name = v ? [v] : []; },
});
const { micSupported, listening, startVoiceSearch } = useVoiceSearch(quickSearch);

// ─── Filtros avanzados (builder inline "Agregar filtro") ────────────────────
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

const applyAdvancedFilters = (clauses) => {
    advancedWhere.value = clauses;
    const data = { ...buildQueryData(), sort: props.filters.sort, direction: props.filters.direction, per_page: props.filters.per_page };
    if (clauses.length > 0) data.advanced_where = JSON.stringify(clauses);
    router.get(route('system_management.tenants.index'), data, { preserveScroll: true, preserveState: true, onStart: () => { isFetching.value = true; }, onFinish: () => { isFetching.value = false; } });
};
const clearAdvancedFilters = () => { advancedWhere.value = []; applyAdvancedFilters([]); };
const applySavedViewState = (clauses, meta) => {
    const data = { ...buildQueryData(), sort: meta.sort, direction: meta.direction, per_page: meta.perPage };
    if (clauses.length > 0) data.advanced_where = JSON.stringify(clauses);
    router.get(route('system_management.tenants.index'), data, {
        preserveScroll: true, preserveState: true,
        onStart: () => { isFetching.value = true; }, onFinish: () => { isFetching.value = false; },
    });
};
let inlineTimer = null;
const applyInlineFilters = (cleaned) => {
    clearTimeout(inlineTimer);
    inlineTimer = setTimeout(() => {
        const data = { ...buildQueryData(), sort: props.filters.sort, direction: props.filters.direction, per_page: props.filters.per_page };
        if (cleaned.length > 0) data.advanced_where = JSON.stringify(cleaned);
        router.get(route('system_management.tenants.index'), data, { preserveScroll: true, preserveState: true, onStart: () => { isFetching.value = true; }, onFinish: () => { isFetching.value = false; } });
    }, 350);
};

// "Limpiar todo": navega a la URL limpia conservando orden/paginación.
const clearAll = () => {
    advancedWhere.value = [];
    router.get(
        route('system_management.tenants.index'),
        {
            sort: props.filters.sort,
            direction: props.filters.direction,
            per_page: props.filters.per_page,
        },
        { preserveScroll: true },
    );
};

// ─── Cross-module composables ────────────────────────────────────────────
const { submitting: favoriteSubmitting, toggle: toggleFavorite } = useModuleFavorites('tenants', 'tenants');
useModuleUndoToast('system_management.tenants.undo_last_delete');

const {
    selectedRowKeys, rowSelection, clearSelection,
    bulkOpen, bulkReason, bulkSubmitting, bulkError, bulkActivating,
    openBulkDelete, bulkSetActive, confirmBulkDelete,
} = useModuleBulkActions({
    bulkSetActiveRoute: 'system_management.tenants.bulk_set_active',
    bulkDeleteRoute:    'system_management.tenants.bulk_delete',
    resourceLabel:      t('tenants.records'),
});

// ─── Counter label ───────────────────────────────────────────────────────
const { isHighlighted, counterLabel } = useModuleListMeta({
    pagination: computed(() => props.tenants),
    hasActiveFilters,
    t,
});

// ─── Columns ─────────────────────────────────────────────────────────────
const allColumns = computed(() => tenantsTableColumns(t, { isMobile: isMobileScreen.value }));
const { visibleColumnKeys, columns } = useColumnPreferences(allColumns);

const tablePagination = computed(() => ({
    current:  props.tenants.current_page,
    pageSize: props.tenants.per_page,
    total:    props.tenants.total,
    showSizeChanger: true,
    pageSizeOptions: ['10', '25', '50', '100', '200'],
    showTotal: (total, range) => `${range[0]}-${range[1]} ${t('global.of')} ${total}`,
}));

const onTableChange = (pag, _filters, sorter) => {
    const direction = sorter?.order === 'ascend' ? 'asc'
                    : sorter?.order === 'descend' ? 'desc'
                    : props.filters.direction;
    const sort = sorter?.field || props.filters.sort;
    reload({ page: pag.current, per_page: pag.pageSize, sort, direction });
};

// ─── Vista: tabla | lista | tarjetas (persistida en localStorage) ──────────
const VIEW_KEY = 'tenants_view_mode';
const viewMode = ref('table');
onMounted(() => { const s = localStorage.getItem(VIEW_KEY); if (s==='cards'||s==='table'||s==='list') viewMode.value = s; });
watch(viewMode, (v) => localStorage.setItem(VIEW_KEY, v));
const viewOptions = computed(() => [
    { value: 'table', label: t('global.view_table'),      icon: TableOutlined },
    { value: 'list',  label: t('global.view_list_short'), icon: BarsOutlined },
    { value: 'cards', label: t('global.view_cards'),      icon: AppstoreOutlined },
]);
const currentView = computed(() => viewOptions.value.find((o) => o.value === viewMode.value) ?? viewOptions.value[0]);
const setView = ({ key }) => { viewMode.value = key; };

const normField = (di) => Array.isArray(di) ? di[0] : (typeof di === 'string' && di.includes('.') ? di.split('.')[0] : di);
const sortOptions = computed(() => allColumns.value.filter((c) => c.sorter).map((c) => ({ value: normField(c.dataIndex), label: typeof c.title === 'string' ? c.title : c.key })).filter((o) => o.value));
const currentSort = computed(() => props.filters?.sort ?? 'id');
const currentDir  = computed(() => props.filters?.direction ?? 'desc');
const currentSortLabel = computed(() => sortOptions.value.find((o) => o.value === currentSort.value)?.label ?? t('global.created_at'));
const setSort = ({ key }) => { const dir = key === currentSort.value && currentDir.value === 'asc' ? 'desc' : 'asc'; reload({ sort: key, direction: dir, page: 1 }); };
const toggleSortDir = () => reload({ sort: currentSort.value, direction: currentDir.value === 'asc' ? 'desc' : 'asc', page: 1 });

const onlyFavorites = computed({ get: () => !!filters.value.only_favorites, set: (v) => { filters.value.only_favorites = v; } });
const toggleOnlyFavorites = () => {
    const next = !onlyFavorites.value;
    suspendReload(() => { clearFilters(); filters.value.only_favorites = next; });
    advancedWhere.value = [];
    applySavedViewState([], { sort: props.filters.sort, direction: props.filters.direction, perPage: props.filters.per_page });
};

// ─── Export / Import ─────────────────────────────────────────────────────
const exportOpen = ref(false);
const importOpen = ref(false);
// Ref al ColumnSelector (montado oculto) para abrirlo desde el engranaje.
const colSel = ref(null);
const openImport = () => { importOpen.value = true; };
const exportableColumns = computed(() => tenantsExportableColumns(t));
const exportEndpoints   = computed(() => tenantsExportEndpoints());

// ─── Row navigation (ActionsCell emits) ─────────────────────────────────
const goToEdit   = (record) => router.visit(route('system_management.tenants.edit',   record.slug));
const goToDelete = (record) => router.visit(route('system_management.tenants.delete', record.slug));

// ─── Duplicate ───────────────────────────────────────────────────────────
const duplicating = ref(null);
const duplicate = (record) => {
    duplicating.value = record.id;
    router.post(route('system_management.tenants.duplicate', record.slug), {}, {
        preserveScroll: true,
        onFinish: () => { duplicating.value = null; },
    });
};

// ─── Keyboard shortcuts ──────────────────────────────────────────────────
useKeyboardShortcuts({
    'ctrl+n': () => can('tenants.create') && router.visit(route('system_management.tenants.create')),
    'esc': () => {
        if (exportOpen.value)             exportOpen.value = false;
        else if (importOpen.value)        importOpen.value = false;
        else if (bulkOpen.value)          bulkOpen.value = false;
    },
    'ctrl+f': () => {
        showFilters.value = true;
        document.querySelector('.mi-bar--toolbar input')?.focus();
    },
});

// ─── Saved Views ─────────────────────────────────────────────────────────
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

// ─── Onboarding tour ─────────────────────────────────────────────────────
const tour = useModuleTour({ module: 'tenants', steps: () => moduleTourSteps(t, { moduleName: t('tenants.plural') }) });
</script>

<template>
    <Head :title="$t('sidebar.tenants')" />

    <div class="sap-index">
        <!-- Título centrado (estándar de índice). -->
        <div class="mi-title" data-tour="module">
            <TenantsPageHeader
                :title="$t('sidebar.tenants')"
            />
        </div>

        <!-- Consola de filtros: un panel que agrupa búsqueda + acciones + filtros.
             Desktop only; en mobile las acciones viven en el bottom bar + drawers. -->
        <div class="mi-console mi-console--v2">
            <div v-if="canUsePlanFeature('saved_views')" class="mi-viewsbar" data-tour="saved-views">
                <SavedViews
                    ref="savedViewsRef"
                    layout="bar"
                    variant="tabs"
                    module="tenants"
                    :show-add="false"
                    :current-state="currentViewState"
                    :show-favorites="true"
                    :favorites-active="onlyFavorites"
                    @apply="applySavedState"
                    @default-loaded="applySavedState"
                    @toggle-favorites="toggleOnlyFavorites"
                />
            </div>
            <!-- ColumnSelector oculto: expone open() al engranaje/Columnas. -->
            <span class="mi-colsel-host" aria-hidden="true">
                <ColumnSelector
                    ref="colSel"
                    :columns="allColumns"
                    v-model="visibleColumnKeys"
                    storage-key="tenants"
                />
            </span>
        </div>

        <Drawer v-model:open="filtersOpen" :title="$t('global.filters')" placement="right" :width="380">
            <FilterBar :fields="filterFields" v-model="filters" storage-key="tenants" />
        </Drawer>

        <!-- Toolbar Fiori (fuera del card): conteo izq · acciones/vistas/crear der. -->
        <div class="mi-tabletoolbar">
            <div class="mi-tabletoolbar__left">
                <span class="mi-toolbar-count">{{ counterLabel }}</span>
            </div>
            <div class="mi-tabletoolbar__right">
                <label class="mi-bar mi-bar--toolbar" :class="{ 'is-active': quickSearch }">
                    <SearchOutlined class="mi-bar__icon" />
                    <input v-model="quickSearch" class="mi-bar__input" :placeholder="$t('global.search_in', { item: $t('tenants.singular').toLowerCase() })" autocomplete="off" spellcheck="false" type="text" />
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
                <Tooltip :title="$t('global.export_hint')" data-tour="export-import">
                    <Button class="mi-iconbtn" @click="exportOpen = true"><DownloadOutlined /></Button>
                </Tooltip>
                <Dropdown :trigger="['click']" placement="bottomRight">
                    <Tooltip :title="$t('global.tools')" data-tour="tools">
                        <Button class="mi-iconbtn"><SettingOutlined /></Button>
                    </Tooltip>
                    <template #overlay>
                        <Menu>
                            <MenuItem v-if="can('tenants.create') && canUsePlanFeature('imports')" key="import" @click="openImport">
                                <UploadOutlined /> {{ $t('global.import') }}
                            </MenuItem>
                            <MenuItem v-if="can('tenants.edit') && canUsePlanFeature('edit_all')" key="editall" @click="router.visit(route('system_management.tenants.edit_all'))">
                                <EditOutlined /> {{ $t('global.edit_all') }}
                            </MenuItem>
                            <MenuItem v-if="isSuper" key="trash" @click="router.visit(route('system_management.tenants.trash'))">
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
                <Tooltip v-if="can('tenants.create')" :title="$t('tenants.new')" data-tour="new">
                    <Link :href="route('system_management.tenants.create')">
                        <Button type="primary" class="mi-iconbtn mi-create-btn" :aria-label="$t('tenants.new')"><PlusOutlined /></Button>
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
            <TableSkeleton v-if="showSkeleton" :rows="6" :columns="visibleColumnKeys.length" />

            <ResponsiveTable
                v-else
                :dataSource="props.tenants.data"
                :columns="columns"
                :pagination="tablePagination"
                :loading="tableLoading"
                :row-selection="(can('tenants.delete') || can('tenants.edit')) ? rowSelection : null"
                :row-class-name="(record) => isHighlighted(record.id) ? 'row-highlight' : ''"
                :scroll="{ x: 'max-content' }"
                :view="viewMode"
                rowKey="id"
                @change="onTableChange"
                data-tour="bulk"
            >
                <template #empty>
                    <TenantsEmptyState
                        :has-filters="hasActiveFilters"
                        :can-create="can('tenants.create')"
                        @clear-filters="clearFilters"
                        @open-import="openImport"
                    />
                </template>
                <template #bodyCell="{ column, record, isMobile, compact, text }">
                    <TenantsFavoriteCell
                        v-if="column.key === 'favorite'"
                        :record="record"
                        :submitting="favoriteSubmitting"
                        :tour-target="record === props.tenants.data[0]"
                        @toggle="toggleFavorite"
                    />

                    <template v-else-if="column.key === 'name'">
                        <div class="lead">
                            <div class="lead__txt">
                                <Link :href="route('system_management.tenants.show', record.slug)" class="lead__name lead__link">{{ record.name }}</Link>
                                <span v-if="record.slug" class="lead__sub">{{ record.slug }}</span>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="column.key === 'status'">
                        <span class="pill" :class="record.is_active ? 'pill--ok' : 'pill--off'">
                            <span class="pill__dot" />
                            {{ record.is_active ? $t('global.active') : $t('global.inactive') }}
                        </span>
                    </template>

                    <template v-else-if="column.key === 'created_at'">
                        {{ formatDateTime(record.created_at) }}
                    </template>

                    <template v-else-if="column.key === 'plan'">
                        <Tag :color="record.plan === 'enterprise' ? 'gold' : (record.plan === 'pro' ? 'blue' : 'default')" :bordered="false">
                            {{ (record.plan ?? 'free').toUpperCase() }}
                        </Tag>
                    </template>

                    <template v-else-if="column.key === 'users_count'">
                        {{ record.users_count ?? 0 }}
                    </template>

                    <TenantsActionsCell
                        v-else-if="column.key === 'actions'"
                        :record="record"
                        :is-mobile="isMobile"
                        :compact="compact"
                        :can-edit="can('tenants.edit')"
                        :can-create="can('tenants.create')"
                        :can-delete="can('tenants.delete')"
                        :duplicating-id="duplicating"
                        @edit="goToEdit"
                        @duplicate="duplicate"
                        @delete="goToDelete"
                    />

                    <template v-else>
                        {{ text ?? record[column.dataIndex] ?? '' }}
                    </template>
                </template>
            </ResponsiveTable>
        </Card>

        <TenantsBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :is-mobile="isMobileScreen"
            :bulk-activating="bulkActivating"
            :can-edit="can('tenants.edit')"
            :can-delete="can('tenants.delete')"
            @cancel="clearSelection"
            @set-active="bulkSetActive"
            @delete="openBulkDelete"
        />

        <TenantsBulkDeleteModal
            v-model:open="bulkOpen"
            v-model:reason="bulkReason"
            :count="selectedRowKeys.length"
            :submitting="bulkSubmitting"
            :error-msg="bulkError"
            :resource-label="selectedRowKeys.length === 1 ? $t('tenants.record') : $t('tenants.records')"
            @confirm="confirmBulkDelete"
        />

        <ExportDialog
            v-model:open="exportOpen"
            :columns="exportableColumns"
            :selected-ids="selectedRowKeys"
            :has-filters="hasActiveFilters"
            :filters-summary="filtersSummary"
            :current-filters="buildQueryData()"
            :default-title="$t('tenants.export_title')"
            :endpoints="exportEndpoints"
            :limits="props.exportLimits"
            :total-rows="props.tenants.total ?? 0"
            :total-unfiltered="props.tenants.total_unfiltered ?? props.tenants.total ?? 0"
        />

        <ImportDialog
            v-model:open="importOpen"
            :endpoint="route('system_management.tenants.import')"
            :template-url="route('system_management.tenants.import_template')"
            :resource-label="$t('tenants.records')"
            :extra-preview-columns="[
                { title: $t('tenants.plan'), dataIndex: 'plan', key: 'plan', width: 100 },
            ]"
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

/* ── Remaster del index (tabla tipo SaaS) ───────────────────────────── */
.bidx-toolbar { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.bidx-filters { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.bidx-search { max-width: 340px; }
.bidx-clear { padding-left: 4px; padding-right: 4px; }

/* Contenedor: rounded + sombra suave.
   OJO: nada de overflow:hidden aquí — rompe el position:sticky del thead. */
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
</style>

<style>
/* Espacio inferior para el bottom-bar fijo (mobile). */
@media (max-width: 767.98px) {
    .below-shell .content {
        padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 150px) !important;
    }
}
</style>
