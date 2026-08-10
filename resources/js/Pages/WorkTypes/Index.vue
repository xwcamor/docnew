<script setup>
import { computed, ref, onMounted, watch, nextTick } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Card, Tag, Button, Tooltip, Dropdown, Menu, MenuItem, Drawer, Alert } from 'ant-design-vue';
import {
    PlusOutlined, InboxOutlined, QuestionCircleOutlined,
    FilterOutlined, SearchOutlined, SettingOutlined, TableOutlined, CloseOutlined,
    ControlOutlined, ClearOutlined, SaveOutlined,
    SortAscendingOutlined, SortDescendingOutlined,
    BarsOutlined, AppstoreOutlined, InfoCircleOutlined, AudioOutlined,
} from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import ColumnSelector from '@/Components/Common/ColumnSelector.vue';
import FilterBar from '@/Components/Common/FilterBar.vue';
import InlineFilterBuilder from '@/Components/Common/InlineFilterBuilder.vue';
import SavedViews from '@/Components/Common/SavedViews.vue';

import WorkTypesBulkBar from '@/Components/WorkTypes/WorkTypesBulkBar.vue';
import WorkTypesBulkDeleteModal from '@/Components/WorkTypes/WorkTypesBulkDeleteModal.vue';
import WorkTypesPageHeader from '@/Components/WorkTypes/WorkTypesPageHeader.vue';
import WorkTypesActionsCell from '@/Components/WorkTypes/WorkTypesActionsCell.vue';
import WorkTypesEmptyState from '@/Components/WorkTypes/WorkTypesEmptyState.vue';

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
import { usePlanFeatures } from '@/Composables/usePlanFeatures';
import { useVoiceSearch } from '@/Composables/useVoiceSearch';
import { useI18n } from '@/Plugins/i18n';

// Gate per plan: saved_views requiere basic+.
const { canUse: canUsePlanFeature } = usePlanFeatures();

import {
    workTypesFilterFields, workTypesEmptyFilters, hydrateWorkTypesFilters,
    workTypesFiltersToQuery, workTypesFiltersSummary,
    serializeSavedFilters, deserializeSavedFilters,
} from './config/filters';
import { workTypesTableColumns } from './config/columns';
import { moduleTourSteps } from '@/Composables/moduleTourSteps';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const { can, isSuper } = useAuth();

const props = defineProps({
    work_types:   { type: Object, required: true },
    filters:      { type: Object, default: () => ({}) },
    filterSchema: { type: Array,  default: () => [] },
    // Catálogos de los selectores (países, formatos). Vienen del backend porque
    // son datos, no constantes del frontend.
    options:      { type: Object, default: () => ({}) },
});

// ─── Filtros (schema + (de)serialización en config/filters.js) ──────────────
const filterFields = computed(() => workTypesFilterFields(t, props.options));

const {
    filters, reload, isFetching, suspendReload, hasActiveFilters, clearFilters, filtersSummary, buildQueryData,
} = useModuleFilters({
    serverFilters: props.filters,
    hydrate:       hydrateWorkTypesFilters,
    toQuery:       workTypesFiltersToQuery,
    summary:       workTypesFiltersSummary,
    empty:         workTypesEmptyFilters,
    only:          ['work_types', 'filters'],
    t,
});

// El buscador de la barra escribe en el primer término del filtro `name`, que
// el backend hace valer contra el código.
const filtersOpen = ref(false);
const quickSearch = computed({
    get: () => (filters.value.name?.[0]) ?? '',
    set: (v) => { filters.value.name = v ? [v] : []; },
});
const { micSupported, listening, startVoiceSearch } = useVoiceSearch(quickSearch);

// ─── Filtros avanzados (cláusulas {field, op, value}) ───────────────────────
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

const clearAll = () => {
    advancedWhere.value = [];
    router.get(
        route('business_management.work_types.index'),
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
    pagination: computed(() => props.work_types),
    hasActiveFilters,
    t,
});

// ─── Columnas (schema en config/columns.js) ─────────────────────────────────
const { isMobile: isMobileScreen } = useViewport(768);

const allColumns = computed(() =>
    workTypesTableColumns(t, { isSuper: isSuper.value, isMobile: isMobileScreen.value }),
);
const { visibleColumnKeys, columns } = useColumnPreferences(allColumns);

// ─── Paginacion + sort ──────────────────────────────────────────────────────
const tablePagination = computed(() => ({
    current:  props.work_types.current_page,
    pageSize: props.work_types.per_page,
    total:    props.work_types.total,
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

// ─── Builder inline de filtros (aplica solo las cláusulas completas) ────────
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
        router.get(route('business_management.work_types.index'), data, {
            preserveScroll: true,
            preserveState: true,
            onStart:  () => { isFetching.value = true; },
            onFinish: () => { isFetching.value = false; },
        });
    }, 350);
};

const applySavedViewState = (clauses, meta) => {
    const data = { ...buildQueryData(), sort: meta.sort, direction: meta.direction, per_page: meta.perPage };
    if (clauses.length > 0) data.advanced_where = JSON.stringify(clauses);
    router.get(route('business_management.work_types.index'), data, {
        preserveScroll: true,
        preserveState: true,
        onStart:  () => { isFetching.value = true; },
        onFinish: () => { isFetching.value = false; },
    });
};

// ─── Vista: tabla | lista | tarjetas (persistida en localStorage) ──────────
const VIEW_KEY = 'work_types_view_mode';
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
        .map((c) => ({ value: normField(c.dataIndex), label: typeof c.title === 'string' ? c.title : c.key }))
        .filter((o) => o.value),
);
const currentSort = computed(() => props.filters?.sort ?? 'code');
const currentDir  = computed(() => props.filters?.direction ?? 'asc');
const currentSortLabel = computed(() =>
    sortOptions.value.find((o) => o.value === currentSort.value)?.label ?? t('work_types.code'),
);
const setSort = ({ key }) => {
    const dir = key === currentSort.value && currentDir.value === 'asc' ? 'desc' : 'asc';
    reload({ sort: key, direction: dir, page: 1 });
};

// ─── Undo toast (60s window) ────────────────────────────────────────────────
useModuleUndoToast('business_management.work_types.undo_last_delete');

// ─── Bulk ───────────────────────────────────────────────────────────────────
const {
    selectedRowKeys, rowSelection, clearSelection,
    bulkOpen, bulkReason, bulkSubmitting, bulkError, bulkActivating,
    openBulkDelete, bulkSetActive, confirmBulkDelete,
} = useModuleBulkActions({
    bulkSetActiveRoute: 'business_management.work_types.bulk_set_active',
    bulkDeleteRoute:    'business_management.work_types.bulk_delete',
    resourceLabel:      t('work_types.records'),
    // Un bloqueado no se puede borrar ni desactivar: el servidor lo aparta y
    // devuelve «N bloqueados se omitieron». Mejor no dejar marcarlo.
    rowDisabled:      (r) => (!isSuper.value && r.tenant_id == null) || !!(r.is_locked ?? r.locked_at),
});

// Ref al ColumnSelector (montado oculto) para abrirlo desde el engranaje.
const colSel = ref(null);

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

// ─── Onboarding tour ────────────────────────────────────────────────────────
const tour = useModuleTour({ module: 'work_types', steps: () => moduleTourSteps(t, { moduleName: t('work_types.plural') }) });

// ─── Keyboard shortcuts ────────────────────────────────────────────────────
useKeyboardShortcuts({
    'ctrl+n': () => can('work_types.create') && router.visit(route('business_management.work_types.create')),
    'esc': () => { if (bulkOpen.value) bulkOpen.value = false; },
    'ctrl+f': () => {
        showFilters.value = true;
        document.querySelector('.mi-bar--toolbar input')?.focus();
    },
});

// ─── Acciones ───────────────────────────────────────────────────────────────
const goEdit   = (record) => router.visit(route('business_management.work_types.edit',   record.slug));
const goDelete = (record) => router.visit(route('business_management.work_types.delete', record.slug));
</script>

<template>
    <Head :title="$t('work_types.plural')" />

    <div class="sap-index">
        <div class="mi-title" data-tour="module">
            <WorkTypesPageHeader :title="$t('work_types.plural')" />
        </div>

        <!-- Las tres cosas que hay que saber para no configurar a ciegas. Se
             dicen aquí, en el listado, y no escondidas en una ayuda: un
             documento obligatorio no se puede quitar del plan de un martes. -->
        <Alert type="info" show-icon class="how-it-works">
            <template #icon><InfoCircleOutlined /></template>
            <template #message>{{ $t('work_types.how_it_works_title') }}</template>
            <template #description>
                <ul class="how-it-works__list">
                    <li>{{ $t('work_types.how_it_works_pivot') }}</li>
                    <li>{{ $t('work_types.how_it_works_required') }}</li>
                    <li>{{ $t('work_types.how_it_works_live') }}</li>
                </ul>
            </template>
        </Alert>

        <!-- Consola de filtros: vistas guardadas + selector de columnas oculto. -->
        <div class="mi-console mi-console--v2">
            <div v-if="canUsePlanFeature('saved_views')" class="mi-viewsbar" data-tour="saved-views">
                <SavedViews
                    ref="savedViewsRef"
                    layout="bar"
                    variant="tabs"
                    module="work_types"
                    :show-add="false"
                    :current-state="currentViewState"
                    @apply="applySavedState"
                    @default-loaded="applySavedState"
                />
            </div>

            <span class="mi-colsel-host" aria-hidden="true">
                <ColumnSelector
                    ref="colSel"
                    :columns="allColumns"
                    v-model="visibleColumnKeys"
                    storage-key="work_types"
                />
            </span>
        </div>

        <Drawer v-model:open="filtersOpen" :title="$t('global.filters')" placement="right" :width="380">
            <FilterBar :fields="filterFields" v-model="filters" storage-key="work_types" />
        </Drawer>

        <div class="mi-tabletoolbar">
            <div class="mi-tabletoolbar__left">
                <span class="mi-toolbar-count">{{ counterLabel }}</span>
            </div>
            <div class="mi-tabletoolbar__right">
                <label class="mi-bar mi-bar--toolbar" :class="{ 'is-active': quickSearch }">
                    <SearchOutlined class="mi-bar__icon" />
                    <input v-model="quickSearch" class="mi-bar__input" :placeholder="$t('global.search_in', { item: $t('work_types.singular').toLowerCase() })" autocomplete="off" spellcheck="false" type="text" />
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
                            <MenuItem v-if="isSuper" key="trash" @click="router.visit(route('business_management.work_types.trash'))"><InboxOutlined /> {{ $t('global.view_deleted') }}</MenuItem>
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

                <Tooltip v-if="can('work_types.create')" :title="$t('work_types.new')" data-tour="new">
                    <Link :href="route('business_management.work_types.create')">
                        <Button type="primary" class="mi-iconbtn mi-create-btn" :aria-label="$t('work_types.new')"><PlusOutlined /></Button>
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
            <ResponsiveTable
                :loading="isFetching"
                :dataSource="work_types.data"
                :columns="columns"
                :pagination="tablePagination"
                :row-selection="(can('work_types.delete') || can('work_types.edit')) ? rowSelection : null"
                :scroll="{ x: 'max-content' }"
                :view="vistaEfectiva"
                rowKey="id"
                @change="onTableChange"
                data-tour="bulk"
            >
                <template #empty>
                    <WorkTypesEmptyState
                        :has-filters="hasActiveFilters"
                        :can-create="can('work_types.create')"
                        @clear-filters="clearFilters"
                    />
                </template>
                <template #bodyCell="{ column, record, text, isMobile, compact }">
                    <template v-if="column.key === 'code'">
                        <div class="lead">
                            <div class="lead__txt">
                                <Link :href="route('business_management.work_types.show', record.slug)" class="lead__name lead__link">{{ record.code }}</Link>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="column.key === 'country'">
                        {{ record.country?.name ?? '—' }}
                    </template>

                    <!-- «3 documentos» no dice nada: lo que importa es cuántos
                         de ellos no se pueden quitar de un plan. Y un tipo sin
                         ninguno se avisa en rojo, porque deja salir el trabajo
                         sin papeles. -->
                    <template v-else-if="column.key === 'forms'">
                        <Tag v-if="(record.forms_count ?? 0) === 0" color="red" :bordered="false">
                            {{ $t('work_types.filter_has_forms_no') }}
                        </Tag>
                        <span v-else class="forms-cell">
                            <Tag :color="(record.required_forms_count ?? 0) > 0 ? 'red' : 'default'" :bordered="false">
                                {{ record.required_forms_count ?? 0 }} {{ $t('work_types.required').toLowerCase() }}
                            </Tag>
                            <span class="forms-cell__total">{{ $t('global.of') }} {{ record.forms_count }}</span>
                        </span>
                    </template>

                    <template v-else-if="column.key === 'open_plans'">
                        <span :class="(record.open_plans_count ?? 0) > 0 ? 'plans-chip' : 'muted'">
                            {{ record.open_plans_count ?? 0 }}
                        </span>
                    </template>

                    <template v-else-if="column.key === 'status'">
                        <span class="pill" :class="record.is_active ? 'pill--ok' : 'pill--off'">
                            <span class="pill__dot" />
                            {{ record.is_active ? $t('global.active') : $t('global.inactive') }}
                        </span>
                    </template>

                    <WorkTypesActionsCell
                        v-else-if="column.key === 'actions'"
                        :record="record"
                        :is-mobile="isMobile"
                        :compact="compact"
                        :is-super="isSuper"
                        :can-edit="can('work_types.edit')"
                        :can-delete="can('work_types.delete')"
                        @edit="goEdit"
                        @delete="goDelete"
                    />

                    <template v-else>{{ text ?? record[column.dataIndex] ?? '' }}</template>
                </template>
            </ResponsiveTable>
        </Card>

        <WorkTypesBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :is-mobile="isMobileScreen"
            :bulk-activating="bulkActivating"
            :can-edit="can('work_types.edit')"
            :can-delete="can('work_types.delete')"
            @cancel="clearSelection"
            @set-active="bulkSetActive"
            @delete="openBulkDelete"
        />

        <WorkTypesBulkDeleteModal
            v-model:open="bulkOpen"
            v-model:reason="bulkReason"
            :count="selectedRowKeys.length"
            :submitting="bulkSubmitting"
            :error-msg="bulkError"
            :resource-label="selectedRowKeys.length === 1 ? $t('work_types.record') : $t('work_types.records')"
            @confirm="confirmBulkDelete"
        />
    </div>
</template>

<style scoped>
.mi-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.muted { color: var(--color-text-muted); font-size: 0.8125rem; }

/* Aviso de cómo funciona el pivote. */
.how-it-works { margin-bottom: 14px; }
.how-it-works__list { margin: 4px 0 0 0; padding-left: 18px; line-height: 1.6; }

.forms-cell { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.forms-cell__total { color: var(--color-text-muted); font-size: 0.8rem; }

.plans-chip {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 30px; height: 30px; padding: 0 8px;
    border-radius: 999px; font-weight: 700; font-size: 0.82rem;
    color: var(--color-primary, #0A6ED1);
    background: rgba(10, 110, 209, 0.10);
    border: 1px solid rgba(10, 110, 209, 0.20);
}

.bidx-clear { padding-left: 4px; padding-right: 4px; }

/* Contenedor: rounded + sombra suave. Sin overflow:hidden — rompe el sticky
   del thead y esconde la primera fila bajo la cabecera. */
.grid-card {
    border-radius: 12px;
    border: 1px solid var(--color-border, #e8eaed);
    box-shadow: 0 1px 2px rgba(16,24,40,0.04), 0 4px 12px rgba(16,24,40,0.04);
}
.grid-card :deep(.ant-table-thead > tr > th:first-child) { border-top-left-radius: 12px; }
.grid-card :deep(.ant-table-thead > tr > th:last-child)  { border-top-right-radius: 12px; }

/* Estado como pill con punto: color Y palabra, nunca solo el color. */
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
.grid-card :deep(.ant-table-tbody > tr) { transition: background 0.12s ease; }
.grid-card :deep(.ant-table-tbody > tr:hover > td) { background: var(--color-surface-hover, #f8fafc) !important; }
.grid-card :deep(.ant-table-tbody > tr > td:first-child) { box-shadow: inset 3px 0 0 transparent; transition: box-shadow 0.12s ease; }
.grid-card :deep(.ant-table-tbody > tr:hover > td:first-child) { box-shadow: inset 3px 0 0 var(--color-primary, #0A6ED1); }

.grid-card :deep(.ant-table-tbody .row-actions-desktop) { opacity: 0.35; transition: opacity 0.15s ease; }
.grid-card :deep(.ant-table-tbody > tr:hover .row-actions-desktop),
.grid-card :deep(.ant-table-tbody .row-actions-desktop:focus-within) { opacity: 1; }

/* Reserva espacio para que la bulk-bar mobile-sticky no tape la última card. */
.grid-card:has(.bulk-bar--mobile-sticky) {
    padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 80px);
}
</style>

