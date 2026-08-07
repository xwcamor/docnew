<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Card, Button, Tooltip, Badge, Dropdown, Menu, MenuItem, Input, Drawer } from 'ant-design-vue';
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
import ExportDialog from '@/Components/Common/ExportDialog.vue';
import ImportDialog from '@/Components/Common/ImportDialog.vue';
import TableSkeleton from '@/Components/Common/TableSkeleton.vue';
import ColumnSelector from '@/Components/Common/ColumnSelector.vue';
import SavedViews from '@/Components/Common/SavedViews.vue';
import InlineFilterBuilder from '@/Components/Common/InlineFilterBuilder.vue';

import LocalesPageHeader from '@/Components/Locales/LocalesPageHeader.vue';
import LocalesBulkBar from '@/Components/Locales/LocalesBulkBar.vue';
import LocalesBulkDeleteModal from '@/Components/Locales/LocalesBulkDeleteModal.vue';
import LocalesEmptyState from '@/Components/Locales/LocalesEmptyState.vue';
import LocalesFavoriteCell from '@/Components/Locales/LocalesFavoriteCell.vue';
import LocalesActionsCell from '@/Components/Locales/LocalesActionsCell.vue';

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
    localesFilterFields, localesEmptyFilters, hydrateLocalesFilters,
    localesFiltersToQuery, localesFiltersSummary,
    serializeSavedFilters, deserializeSavedFilters,
} from './config/filters';
import { localesTableColumns } from './config/columns';
import { localesExportableColumns, localesExportEndpoints } from './config/exports';
import { moduleTourSteps } from '@/Composables/moduleTourSteps';

defineOptions({ layout: AppLayout });

const props = defineProps({
    locales:        { type: Object, required: true },
    filters:        { type: Object, required: true },
    exportLimits:   { type: Object, default: () => ({}) },
    languageOptions:{ type: Array,  default: () => [] },
    filterSchema:   { type: Array,  default: () => [] },
});

const { t } = useI18n();
const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTime } = useDateFormat();

// Gate per plan: saved_views requiere basic+, imports/edit_all requieren pro+.
const { canUse: canUsePlanFeature } = usePlanFeatures();

// Avatar de iniciales con color estable (para la celda principal de la tabla).
const initials = (name) => (name || '').split(/\s+/).filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase() || '—';
const avaStyle = (name) => {
    let h = 0;
    for (const c of (name || '')) h = (h * 31 + c.charCodeAt(0)) % 360;
    return { background: `hsl(${h} 58% 52%)` };
};

// ─── Viewport + loading ──────────────────────────────────────────────────
const { isMobile: isMobileScreen } = useViewport(768);
const { loading: tableLoading } = usePageLoading('/locales', 'locales');

// ─── Filters (composable + config) ───────────────────────────────────────
const filterFields = computed(() => localesFilterFields(t, {
    languageOptions: props.languageOptions,
}));
const {
    filters, reload, isFetching, suspendReload, hasActiveFilters, clearFilters, filtersSummary, buildQueryData,
} = useModuleFilters({
    serverFilters: props.filters,
    hydrate:       hydrateLocalesFilters,
    toQuery:       localesFiltersToQuery,
    summary:       localesFiltersSummary,
    empty:         localesEmptyFilters,
    only:          ['locales', 'filters'],
    t,
});

// ─── Remaster: filtros colapsados en drawer + buscador inline ───────────────
// El campo `name` es de tipo tags (multi-valor); el buscador inline maneja el
// término principal (primer tag). El resto de filtros vive en el drawer "Filtros".
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

const applySavedViewState = (clauses, meta) => {
    const data = { ...buildQueryData(), sort: meta.sort, direction: meta.direction, per_page: meta.perPage };
    if (clauses.length > 0) data.advanced_where = JSON.stringify(clauses);
    router.get(route('system_management.locales.index'), data, {
        preserveScroll: true, preserveState: true,
        onStart: () => { isFetching.value = true; }, onFinish: () => { isFetching.value = false; },
    });
};

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
        router.get(route('system_management.locales.index'), data, {
            preserveScroll: true,
            preserveState: true,
            onStart:  () => { isFetching.value = true; },
            onFinish: () => { isFetching.value = false; },
        });
    }, 350);
};

// "Limpiar todo": navega a la URL limpia conservando orden/paginación.
const clearAll = () => {
    advancedWhere.value = [];
    router.get(
        route('system_management.locales.index'),
        {
            sort: props.filters.sort,
            direction: props.filters.direction,
            per_page: props.filters.per_page,
        },
        { preserveScroll: true },
    );
};

const showSkeleton = computed(() =>
    tableLoading.value && (!props.locales?.data || props.locales.data.length === 0)
);

// ─── Cross-module composables ────────────────────────────────────────────
const { submitting: favoriteSubmitting, toggle: toggleFavorite } = useModuleFavorites('locales', 'locales');
useModuleUndoToast('system_management.locales.undo_last_delete');

const {
    selectedRowKeys, rowSelection, clearSelection,
    bulkOpen, bulkReason, bulkSubmitting, bulkError, bulkActivating,
    openBulkDelete, bulkSetActive, confirmBulkDelete,
} = useModuleBulkActions({
    bulkSetActiveRoute: 'system_management.locales.bulk_set_active',
    bulkDeleteRoute:    'system_management.locales.bulk_delete',
    resourceLabel:      t('locales.records'),
});

// ─── Counter label ───────────────────────────────────────────────────────
const { isHighlighted, counterLabel } = useModuleListMeta({
    pagination: computed(() => props.locales),
    hasActiveFilters,
    t,
});

// ─── Columns ─────────────────────────────────────────────────────────────
const allColumns = computed(() => localesTableColumns(t, { isMobile: isMobileScreen.value }));
const { visibleColumnKeys, columns } = useColumnPreferences(allColumns);

const tablePagination = computed(() => ({
    current:  props.locales.current_page,
    pageSize: props.locales.per_page,
    total:    props.locales.total,
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
const VIEW_KEY = 'locales_view_mode';
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

// ─── Orden global (dropdown — funciona en tabla, lista y tarjetas) ─────────
const normField = (di) => Array.isArray(di) ? di[0] : (typeof di === 'string' && di.includes('.') ? di.split('.')[0] : di);
const sortOptions = computed(() => allColumns.value.filter((c) => c.sorter).map((c) => ({ value: normField(c.dataIndex), label: typeof c.title === 'string' ? c.title : c.key })).filter((o) => o.value));
const currentSort = computed(() => props.filters?.sort ?? 'id');
const currentDir  = computed(() => props.filters?.direction ?? 'desc');
const currentSortLabel = computed(() => sortOptions.value.find((o) => o.value === currentSort.value)?.label ?? t('global.created_at'));
const setSort = ({ key }) => { const dir = key === currentSort.value && currentDir.value === 'asc' ? 'desc' : 'asc'; reload({ sort: key, direction: dir, page: 1 }); };
const toggleSortDir = () => reload({ sort: currentSort.value, direction: currentDir.value === 'asc' ? 'desc' : 'asc', page: 1 });

// ─── Solo favoritos (toggle) ────────────────────────────────────────────────
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
const openExport = () => { exportOpen.value = true; };
const openImport = () => { importOpen.value = true; };
const exportableColumns = computed(() => localesExportableColumns(t));
const exportEndpoints   = computed(() => localesExportEndpoints());

// ─── Navigation ───────────────────────────────────────────────────────────
const goToTrash  = () => router.visit(route('system_management.locales.trash'));
const goToEditAll = () => router.visit(route('system_management.locales.edit_all'));
const goToEdit   = (record) => router.visit(route('system_management.locales.edit',   record.slug));
const goToDelete = (record) => router.visit(route('system_management.locales.delete', record.slug));

// ─── Duplicate ───────────────────────────────────────────────────────────
const duplicating = ref(null);
const duplicate = (record) => {
    duplicating.value = record.id;
    router.post(route('system_management.locales.duplicate', record.slug), {}, {
        preserveScroll: true,
        onFinish: () => { duplicating.value = null; },
    });
};

// ─── Keyboard shortcuts ──────────────────────────────────────────────────
useKeyboardShortcuts({
    'ctrl+n': () => can('locales.create') && router.visit(route('system_management.locales.create')),
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
const tour = useModuleTour({ module: 'locales', steps: () => moduleTourSteps(t, { moduleName: t('locales.plural') }) });
</script>

<template>
    <Head :title="$t('sidebar.locales')" />

    <div class="sap-index">
        <!-- Título centrado (estándar de índice). -->
        <div class="mi-title" data-tour="module">
            <LocalesPageHeader
                :title="$t('sidebar.locales')"
            />
        </div>

        <!-- Consola de filtros: un panel que agrupa búsqueda + acciones + filtros. -->
        <div class="mi-console mi-console--v2">
            <div v-if="canUsePlanFeature('saved_views')" class="mi-viewsbar" data-tour="saved-views">
                <SavedViews
                    ref="savedViewsRef"
                    layout="bar"
                    variant="tabs"
                    module="locales"
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
                    storage-key="locales"
                />
            </span>
        </div>

        <!-- Drawer de filtros (desktop): reusa el FilterBar completo. -->
        <Drawer v-model:open="filtersOpen" :title="$t('global.filters')" placement="right" :width="380">
            <FilterBar :fields="filterFields" v-model="filters" storage-key="locales" />
        </Drawer>

        <!-- Toolbar Fiori (fuera del card): conteo izq · acciones/vistas/crear der. -->
        <div class="mi-tabletoolbar">
            <div class="mi-tabletoolbar__left">
                <span class="mi-toolbar-count">{{ counterLabel }}</span>
            </div>
            <div class="mi-tabletoolbar__right">
                <label class="mi-bar mi-bar--toolbar" :class="{ 'is-active': quickSearch }">
                    <SearchOutlined class="mi-bar__icon" />
                    <input v-model="quickSearch" class="mi-bar__input" :placeholder="$t('global.search_in', { item: $t('locales.singular').toLowerCase() })" autocomplete="off" spellcheck="false" type="text" />
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
                            <MenuItem v-if="can('locales.create') && canUsePlanFeature('imports')" key="import" @click="openImport">
                                <UploadOutlined /> {{ $t('global.import') }}
                            </MenuItem>
                            <MenuItem v-if="can('locales.edit') && canUsePlanFeature('edit_all')" key="editall" @click="goToEditAll">
                                <EditOutlined /> {{ $t('global.edit_all') }}
                            </MenuItem>
                            <MenuItem v-if="isSuper" key="trash" @click="goToTrash">
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
                <Tooltip v-if="can('locales.create')" :title="$t('locales.new')" data-tour="new">
                    <Link :href="route('system_management.locales.create')">
                        <Button type="primary" class="mi-iconbtn mi-create-btn" :aria-label="$t('locales.new')"><PlusOutlined /></Button>
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
                :dataSource="props.locales.data"
                :columns="columns"
                :pagination="tablePagination"
                :loading="tableLoading"
                :row-selection="(can('locales.delete') || can('locales.edit')) ? rowSelection : null"
                :row-class-name="(record) => isHighlighted(record.id) ? 'row-highlight' : ''"
                :scroll="{ x: 'max-content' }"
                :view="viewMode"
                rowKey="id"
                @change="onTableChange"
                data-tour="bulk"
            >
                <template #empty>
                    <LocalesEmptyState
                        :has-filters="hasActiveFilters"
                        :can-create="can('locales.create')"
                        @clear-filters="clearFilters"
                        @open-import="openImport"
                    />
                </template>
                <template #bodyCell="{ column, record, isMobile, compact, text }">
                    <LocalesFavoriteCell
                        v-if="column.key === 'favorite'"
                        :record="record"
                        :submitting="favoriteSubmitting"
                        :tour-target="record === props.locales.data[0]"
                        @toggle="toggleFavorite"
                    />

                    <template v-else-if="column.key === 'name'">
                        <div class="lead">
                            <div class="lead__txt">
                                <Link :href="route('system_management.locales.show', record.slug)" class="lead__name lead__link">{{ record.name }}</Link>
                                <span v-if="record.code" class="lead__sub">{{ record.code }}</span>
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

                    <template v-else-if="column.key === 'code'">
                        <code>{{ record.code }}</code>
                    </template>

                    <template v-else-if="column.key === 'language'">
                        {{ record.language?.name ?? '—' }}
                        <span v-if="record.language" class="muted">({{ record.language.iso_code }})</span>
                    </template>

                    <LocalesActionsCell
                        v-else-if="column.key === 'actions'"
                        :record="record"
                        :is-mobile="isMobile"
                        :compact="compact"
                        :can-edit="can('locales.edit')"
                        :can-create="can('locales.create')"
                        :can-delete="can('locales.delete')"
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

        <LocalesBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :is-mobile="isMobileScreen"
            :bulk-activating="bulkActivating"
            :can-edit="can('locales.edit')"
            :can-delete="can('locales.delete')"
            @cancel="clearSelection"
            @set-active="bulkSetActive"
            @delete="openBulkDelete"
        />

        <LocalesBulkDeleteModal
            v-model:open="bulkOpen"
            v-model:reason="bulkReason"
            :count="selectedRowKeys.length"
            :submitting="bulkSubmitting"
            :error-msg="bulkError"
            :resource-label="selectedRowKeys.length === 1 ? $t('locales.record') : $t('locales.records')"
            @confirm="confirmBulkDelete"
        />

        <ExportDialog
            v-model:open="exportOpen"
            :columns="exportableColumns"
            :selected-ids="selectedRowKeys"
            :has-filters="hasActiveFilters"
            :filters-summary="filtersSummary"
            :current-filters="buildQueryData()"
            :default-title="$t('locales.export_title')"
            :endpoints="exportEndpoints"
            :limits="props.exportLimits"
            :total-rows="props.locales.total ?? 0"
            :total-unfiltered="props.locales.total_unfiltered ?? props.locales.total ?? 0"
        />

        <ImportDialog
            v-model:open="importOpen"
            :endpoint="route('system_management.locales.import')"
            :template-url="route('system_management.locales.import_template')"
            :resource-label="$t('locales.records')"
            :extra-preview-columns="[
                { title: $t('locales.code'),     dataIndex: 'code',     key: 'code',     width: 110 },
                { title: $t('locales.language'), dataIndex: 'language', key: 'language', width: 160, ellipsis: true },
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
