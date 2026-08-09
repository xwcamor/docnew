<script setup>
import { computed, ref, onMounted, watch, nextTick } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Card, Tag, Button, Tooltip, Dropdown, Menu, MenuItem, Drawer } from 'ant-design-vue';
import {
    PlusOutlined, InboxOutlined, QuestionCircleOutlined,
    FilterOutlined, SearchOutlined, SettingOutlined, TableOutlined, CloseOutlined,
    ControlOutlined, ClearOutlined, SaveOutlined,
    SortAscendingOutlined, SortDescendingOutlined,
    BarsOutlined, AppstoreOutlined, AudioOutlined,
    BlockOutlined,
} from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import ColumnSelector from '@/Components/Common/ColumnSelector.vue';
import FilterBar from '@/Components/Common/FilterBar.vue';
import InlineFilterBuilder from '@/Components/Common/InlineFilterBuilder.vue';
import SavedViews from '@/Components/Common/SavedViews.vue';

import CatalogPageHeader from '@/Components/Catalog/CatalogPageHeader.vue';
import CatalogEmptyState from '@/Components/Catalog/CatalogEmptyState.vue';
import CatalogActionsCell from '@/Components/Catalog/CatalogActionsCell.vue';
import CatalogBulkBar from '@/Components/Catalog/CatalogBulkBar.vue';
import CatalogBulkDeleteModal from '@/Components/Catalog/CatalogBulkDeleteModal.vue';

import { useAuth } from '@/Composables/useAuth';
import { useColumnPreferences } from '@/Composables/useColumnPreferences';
import { useModuleFilters } from '@/Composables/useModuleFilters';
import { useModuleBulkActions } from '@/Composables/useModuleBulkActions';
import { useModuleUndoToast } from '@/Composables/useModuleUndoToast';
import { useModuleSavedViews } from '@/Composables/useModuleSavedViews';
import { useModuleListMeta } from '@/Composables/useModuleListMeta';
import { useModuleTour } from '@/Composables/useModuleTour';
import { useKeyboardShortcuts } from '@/Composables/useKeyboardShortcuts';
import { useViewport } from '@/Composables/useViewport';
import { useDateFormat } from '@/Composables/useDateFormat';
import { usePlanFeatures } from '@/Composables/usePlanFeatures';
import { useVoiceSearch } from '@/Composables/useVoiceSearch';
import { useI18n } from '@/Plugins/i18n';

import {
    workstationsFilterFields, workstationsEmptyFilters, hydrateWorkstationsFilters,
    workstationsFiltersToQuery, workstationsFiltersSummary,
    serializeSavedFilters, deserializeSavedFilters,
} from './config/filters';
import { workstationsTableColumns } from './config/columns';
import { moduleTourSteps } from '@/Composables/moduleTourSteps';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTime } = useDateFormat();

// Las vistas guardadas son de plan basic+: se oculta el control en vez de
// enseñar un botón que no hace nada.
const { canUse: canUsePlanFeature } = usePlanFeatures();

const props = defineProps({
    workstations:      { type: Object, required: true },
    filters:      { type: Object, default: () => ({}) },
    filterSchema: { type: Array,  default: () => [] },
});

// ─── Filtros (schema + (de)serialización en config/filters.js) ──────────────
const filterFields = computed(() => workstationsFilterFields(t));

const {
    filters, reload, isFetching, suspendReload, hasActiveFilters, clearFilters, filtersSummary, buildQueryData,
} = useModuleFilters({
    serverFilters: props.filters,
    hydrate:       hydrateWorkstationsFilters,
    toQuery:       workstationsFiltersToQuery,
    summary:       workstationsFiltersSummary,
    empty:         workstationsEmptyFilters,
    only:          ['workstations', 'filters'],
    t,
});

// El buscador de la barra escribe en el primer término del filtro `name`.
const filtersOpen = ref(false);
const quickSearch = computed({
    get: () => (filters.value.name?.[0]) ?? '',
    set: (v) => { filters.value.name = v ? [v] : []; },
});
const { micSupported, listening, startVoiceSearch } = useVoiceSearch(quickSearch);

// ─── Filtros avanzados ──────────────────────────────────────────────────────
// Viven fuera de useModuleFilters porque son un array de cláusulas
// {field, op, value}, no el shape plano del FilterBar. Se persisten vía Inertia
// para que sobrevivan al paginar y al ordenar.
const advancedWhere = ref(Array.isArray(props.filters?.advanced_where) ? props.filters.advanced_where : []);
const advancedCount = computed(() => advancedWhere.value.length);

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

// "Limpiar todo": los normales Y los avanzados de una, navegando a la URL
// limpia para no dejar ningún parámetro pegado.
const clearAll = () => {
    advancedWhere.value = [];
    router.get(
        route('business_management.workstations.index'),
        { sort: props.filters.sort, direction: props.filters.direction, per_page: props.filters.per_page },
        { preserveScroll: true },
    );
};

const { counterLabel } = useModuleListMeta({
    pagination: computed(() => props.workstations),
    hasActiveFilters,
    t,
});

// ─── Columnas ───────────────────────────────────────────────────────────────
// El viewport se lee ANTES de allColumns: el ancho de la columna de acciones
// depende de si la pantalla es chica.
const { isMobile: isMobileScreen } = useViewport(768);

const allColumns = computed(() =>
    workstationsTableColumns(t, { isSuper: isSuper.value, isMobile: isMobileScreen.value }),
);
const { visibleColumnKeys, columns } = useColumnPreferences(allColumns);

const tablePagination = computed(() => ({
    current:  props.workstations.current_page,
    pageSize: props.workstations.per_page,
    total:    props.workstations.total,
    showSizeChanger: true,
    pageSizeOptions: ['10', '25', '50', '100'],
}));

const onTableChange = (pag, _f, sorter) => {
    const sort = sorter?.field || props.filters.sort;
    const direction = sorter?.order === 'ascend' ? 'asc'
                    : sorter?.order === 'descend' ? 'desc'
                    : props.filters.direction;
    reload({ page: pag.current, per_page: pag.pageSize, sort, direction });
};

// Aplica SOLO las cláusulas completas, con debounce: el builder emite en cada
// tecla y sin esto se lanzaría una consulta por letra.
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
        router.get(route('business_management.workstations.index'), data, {
            preserveScroll: true,
            preserveState: true,
            onStart:  () => { isFetching.value = true; },
            onFinish: () => { isFetching.value = false; },
        });
    }, 350);
};

// Una vista guardada se aplica en UNA sola navegación con parámetros
// explícitos: en dos, la segunda pisaba a la primera y la vista salía a medias.
const applySavedViewState = (clauses, meta) => {
    const data = { ...buildQueryData(), sort: meta.sort, direction: meta.direction, per_page: meta.perPage };
    if (clauses.length > 0) data.advanced_where = JSON.stringify(clauses);
    router.get(route('business_management.workstations.index'), data, {
        preserveScroll: true,
        preserveState: true,
        onStart:  () => { isFetching.value = true; },
        onFinish: () => { isFetching.value = false; },
    });
};

// ─── Vista: tabla | lista | tarjetas (se recuerda en el navegador) ──────────
const VIEW_KEY = 'workstations_view_mode';
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

// ─── Orden global (funciona en tabla, lista y tarjetas) ────────────────────
const normField = (di) => Array.isArray(di) ? di[0] : (typeof di === 'string' && di.includes('.') ? di.split('.')[0] : di);
const sortOptions = computed(() =>
    allColumns.value
        .filter((c) => c.sorter)
        .map((c) => ({ value: normField(c.dataIndex), label: typeof c.title === 'string' ? c.title : c.key }))
        .filter((o) => o.value),
);
const currentSort = computed(() => props.filters?.sort ?? 'name');
const currentDir  = computed(() => props.filters?.direction ?? 'asc');
const currentSortLabel = computed(() =>
    sortOptions.value.find((o) => o.value === currentSort.value)?.label ?? t('workstations.name'),
);
const setSort = ({ key }) => {
    const dir = key === currentSort.value && currentDir.value === 'asc' ? 'desc' : 'asc';
    reload({ sort: key, direction: dir, page: 1 });
};

useModuleUndoToast('business_management.workstations.undo_last_delete');

// ─── Masivas ────────────────────────────────────────────────────────────────
const {
    selectedRowKeys, rowSelection, clearSelection,
    bulkOpen, bulkReason, bulkSubmitting, bulkError, bulkActivating,
    openBulkDelete, bulkSetActive, confirmBulkDelete,
} = useModuleBulkActions({
    bulkSetActiveRoute: 'business_management.workstations.bulk_set_active',
    bulkDeleteRoute:    'business_management.workstations.bulk_delete',
    resourceLabel:      t('workstations.records'),
    // Una fila en uso, bloqueada, o global vista por quien no es super, se saca
    // de la selección: la barra no promete algo que el servidor va a rechazar.
    // El candado no es un caso raro aquí — los dieciséis puestos que trajo la
    // migración nacen bloqueados, así que sin esta condición la casilla se deja
    // marcar en todos y el borrado masivo devuelve «N saltados (bloqueados)».
    rowDisabled: (r) => (!isSuper.value && r.tenant_id == null)
        || r.usage_count > 0
        || !!(r.is_locked ?? r.locked_at),
});

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

const tour = useModuleTour({ module: 'workstations', steps: () => moduleTourSteps(t, { moduleName: t('workstations.plural') }) });

useKeyboardShortcuts({
    'ctrl+n': () => can('workstations.create') && router.visit(route('business_management.workstations.create')),
    'esc': () => { if (bulkOpen.value) bulkOpen.value = false; },
    'ctrl+f': () => {
        showFilters.value = true;
        document.querySelector('.mi-bar--toolbar input')?.focus();
    },
});

const colSel = ref(null);
const goEdit   = (record) => router.visit(route('business_management.workstations.edit',   record.slug));
const goDelete = (record) => router.visit(route('business_management.workstations.delete', record.slug));
</script>

<template>
    <Head :title="$t('workstations.plural')" />

    <div class="sap-index">
        <div class="mi-title" data-tour="module">
            <CatalogPageHeader :title="$t('workstations.plural')">
                <template #icon><BlockOutlined /></template>
            </CatalogPageHeader>
        </div>

        <div class="mi-console mi-console--v2">
            <div v-if="canUsePlanFeature('saved_views')" class="mi-viewsbar" data-tour="saved-views">
                <SavedViews
                    ref="savedViewsRef"
                    layout="bar"
                    variant="tabs"
                    module="workstations"
                    :show-add="false"
                    :current-state="currentViewState"
                    @apply="applySavedState"
                    @default-loaded="applySavedState"
                />
            </div>

            <!-- ColumnSelector montado oculto: solo expone open() al engranaje. -->
            <span class="mi-colsel-host" aria-hidden="true">
                <ColumnSelector
                    ref="colSel"
                    :columns="allColumns"
                    v-model="visibleColumnKeys"
                    storage-key="workstations"
                />
            </span>
        </div>

        <Drawer v-model:open="filtersOpen" :title="$t('global.filters')" placement="right" :width="380">
            <FilterBar :fields="filterFields" v-model="filters" storage-key="workstations" />
        </Drawer>

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
                        :placeholder="$t('global.search_in', { item: $t('workstations.singular').toLowerCase() })"
                        autocomplete="off"
                        spellcheck="false"
                        type="text"
                    />
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

                <Dropdown :trigger="['click']" placement="bottomRight">
                    <Tooltip :title="$t('global.tools')" data-tour="tools">
                        <Button class="mi-iconbtn"><SettingOutlined /></Button>
                    </Tooltip>
                    <template #overlay>
                        <Menu>
                            <MenuItem v-if="isSuper" key="trash" @click="router.visit(route('business_management.workstations.trash'))">
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

                <Tooltip v-if="can('workstations.create')" :title="$t('workstations.new')" data-tour="new">
                    <Link :href="route('business_management.workstations.create')">
                        <Button type="primary" class="mi-iconbtn mi-create-btn" :aria-label="$t('workstations.new')"><PlusOutlined /></Button>
                    </Link>
                </Tooltip>
            </div>
        </div>

        <div v-if="showFilters" class="mi-builder mi-builder--table">
            <InlineFilterBuilder ref="builderRef" v-model="advancedWhere" :schema="props.filterSchema" show-conjunction @change="applyInlineFilters">
                <template #actions>
                    <Button v-if="hasActiveFilters || advancedCount > 0" type="link" class="bidx-clear" @click="clearAll"><ClearOutlined /> {{ $t('global.clear_filters') }}</Button>
                    <Button v-if="canUsePlanFeature('saved_views')" type="link" @click="savedViewsRef?.openSave()"><SaveOutlined /> {{ $t('global.save_filter') }}</Button>
                </template>
            </InlineFilterBuilder>
        </div>

        <Card :bodyStyle="{ padding: 0 }" class="grid-card">
            <ResponsiveTable
                :loading="isFetching"
                :dataSource="workstations.data"
                :columns="columns"
                :pagination="tablePagination"
                :row-selection="(can('workstations.delete') || can('workstations.edit')) ? rowSelection : null"
                :scroll="{ x: 'max-content' }"
                :view="vistaEfectiva"
                rowKey="id"
                @change="onTableChange"
                data-tour="bulk"
            >
                <template #empty>
                    <CatalogEmptyState
                        module="workstations"
                        :has-filters="hasActiveFilters"
                        :can-create="can('workstations.create')"
                        @clear-filters="clearFilters"
                    >
                        <template #icon><BlockOutlined /></template>
                    </CatalogEmptyState>
                </template>

                <template #bodyCell="{ column, record, text, isMobile, compact }">
                    <template v-if="column.key === 'name'">
                        <Link :href="route('business_management.workstations.show', record.slug)" class="lead__name lead__link">
                            {{ record.name }}
                        </Link>
                    </template>

                    <!-- La sede se pinta AQUÍ y no colgada del nombre. En la vista
                         de lista y en la de tarjetas el subtítulo de la ficha sale
                         de esta columna, y su `dataIndex` es una ruta anidada
                         (['work_location','name']) que el render de tarjeta no
                         sabe resolver solo: sin esta plantilla la línea salía en
                         blanco y dieciséis puestos llamados «Celda 1» quedaban
                         indistinguibles. -->
                    <template v-else-if="column.key === 'work_location'">
                        {{ record.work_location?.name ?? '—' }}
                    </template>

                    <!-- De cuántos registros depende la fila: es lo que decide si se
                         puede borrar, así que se ve aquí y no al intentarlo. -->
                    <template v-else-if="column.key === 'usage_count'">
                        <Tag v-if="record.usage_count > 0" color="blue" :bordered="false">{{ record.usage_count }}</Tag>
                        <span v-else class="muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'tenant'">
                        <Tag v-if="record.tenant" color="blue" :bordered="false">{{ record.tenant.name }}</Tag>
                        <Tag v-else color="purple" :bordered="false">{{ $t('global.platform') }}</Tag>
                    </template>

                    <!-- El estado va con color Y palabra: al sol el matiz se
                         pierde, y hay quien no distingue el rojo del verde. -->
                    <template v-else-if="column.key === 'status'">
                        <span class="pill" :class="record.is_active ? 'pill--ok' : 'pill--off'">
                            <span class="pill__dot" />
                            {{ record.is_active ? $t('global.active') : $t('global.inactive') }}
                        </span>
                    </template>

                    <template v-else-if="column.key === 'created_at'">
                        {{ formatDateTime(record.created_at) }}
                    </template>

                    <CatalogActionsCell
                        v-else-if="column.key === 'actions'"
                        module="workstations"
                        :record="record"
                        :is-mobile="isMobile"
                        :compact="compact"
                        :is-super="isSuper"
                        :can-edit="can('workstations.edit')"
                        :can-delete="can('workstations.delete')"
                        @edit="goEdit"
                        @delete="goDelete"
                    />

                    <template v-else>{{ text ?? record[column.dataIndex] ?? '' }}</template>
                </template>
            </ResponsiveTable>
        </Card>

        <CatalogBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :is-mobile="isMobileScreen"
            :bulk-activating="bulkActivating"
            :can-edit="can('workstations.edit')"
            :can-delete="can('workstations.delete')"
            @cancel="clearSelection"
            @set-active="bulkSetActive"
            @delete="openBulkDelete"
        />

        <CatalogBulkDeleteModal
            v-model:open="bulkOpen"
            v-model:reason="bulkReason"
            :count="selectedRowKeys.length"
            :submitting="bulkSubmitting"
            :error-msg="bulkError"
            :resource-label="selectedRowKeys.length === 1 ? $t('workstations.record') : $t('workstations.records')"
            @confirm="confirmBulkDelete"
        />
    </div>
</template>

<style scoped>
.muted { color: var(--color-text-muted); font-size: 0.8125rem; }

/* Nada de overflow:hidden aquí — rompe el sticky del thead y esconde la
   primera fila bajo la cabecera. Las esquinas se redondean en el thead. */
.grid-card {
    border-radius: 12px;
    border: 1px solid var(--color-border, #e8eaed);
    box-shadow: 0 1px 2px rgba(16,24,40,0.04), 0 4px 12px rgba(16,24,40,0.04);
}
.grid-card :deep(.ant-table-thead > tr > th:first-child) { border-top-left-radius: 12px; }
.grid-card :deep(.ant-table-thead > tr > th:last-child)  { border-top-right-radius: 12px; }

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
.grid-card :deep(.ant-table-tbody > tr:hover > td) { background: var(--color-surface-hover, #f8fafc) !important; }

/* Acciones tenues que se realzan al pasar por la fila. */
.grid-card :deep(.ant-table-tbody .row-actions-desktop) { opacity: 0.35; transition: opacity 0.15s ease; }
.grid-card :deep(.ant-table-tbody > tr:hover .row-actions-desktop),
.grid-card :deep(.ant-table-tbody .row-actions-desktop:focus-within) { opacity: 1; }

/* Reserva sitio para que la barra masiva pegada al pie no tape la última fila. */
.grid-card:has(.bulk-bar--mobile-sticky) {
    padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 80px);
}

@media (max-width: 768px) {
    .hide-on-mobile { display: none !important; }
}
</style>
