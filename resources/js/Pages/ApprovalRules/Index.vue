<script setup>
import { computed, ref, onMounted, watch, nextTick } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Card, Tag, Button, Tooltip, Space, Badge, Dropdown, Menu, MenuItem, Input, Drawer, Alert } from 'ant-design-vue';
import {
    PlusOutlined, EditOutlined, InboxOutlined,
    DownloadOutlined, UploadOutlined, QuestionCircleOutlined,
    FilterOutlined, CloseCircleFilled, EllipsisOutlined, SearchOutlined,
    SettingOutlined, TableOutlined, CloseOutlined,
    ControlOutlined, FormOutlined, ClearOutlined, SaveOutlined,
    SortAscendingOutlined, SortDescendingOutlined,
    BarsOutlined, AppstoreOutlined, NodeIndexOutlined, InfoCircleOutlined,
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

import ApprovalRulesBulkBar from '@/Components/ApprovalRules/ApprovalRulesBulkBar.vue';
import ApprovalRulesBulkDeleteModal from '@/Components/ApprovalRules/ApprovalRulesBulkDeleteModal.vue';
import ApprovalRulesPageHeader from '@/Components/ApprovalRules/ApprovalRulesPageHeader.vue';
import ApprovalRulesActionsCell from '@/Components/ApprovalRules/ApprovalRulesActionsCell.vue';
import ApprovalRulesEmptyState from '@/Components/ApprovalRules/ApprovalRulesEmptyState.vue';

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

// Gate per plan: saved_views requiere basic+, imports/edit_all requieren pro+.
// El toolbar inline de ApprovalRules (no usa ModuleToolbar) repite manualmente
// estos gates para no mostrar botones que no funcionan al user free/basic.
const { canUse: canUsePlanFeature } = usePlanFeatures();

import {
    approvalRulesFilterFields, approvalRulesEmptyFilters, hydrateApprovalRulesFilters,
    approvalRulesFiltersToQuery, approvalRulesFiltersSummary,
    serializeSavedFilters, deserializeSavedFilters,
} from './config/filters';
import { approvalRulesTableColumns } from './config/columns';
import { approvalRulesExportableColumns, approvalRulesExportEndpoints } from './config/exports';
import { moduleTourSteps } from '@/Composables/moduleTourSteps';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTime } = useDateFormat();

const props = defineProps({
    approval_rules: { type: Object, required: true },
    filters:        { type: Object, default: () => ({}) },
    filterSchema:   { type: Array,  default: () => [] },
    exportLimits:   { type: Object, default: () => ({}) },
    // Catálogos de los selectores (países, tipos de trabajo, roles). Vienen del
    // backend porque son datos, no constantes del frontend.
    options:        { type: Object, default: () => ({}) },
    // Si el workspace exige firmar en orden, el nivel deja de ser solo estético.
    sequential:     { type: Boolean, default: false },
});

// ─── Filtros (schema + (de)serialización en config/filters.js) ──────────────
const filterFields = computed(() =>
    approvalRulesFilterFields(t, props.options),
);

const {
    filters, reload, isFetching, suspendReload, hasActiveFilters, clearFilters, filtersSummary, buildQueryData,
} = useModuleFilters({
    serverFilters: props.filters,
    hydrate:       hydrateApprovalRulesFilters,
    toQuery:       approvalRulesFiltersToQuery,
    summary:       approvalRulesFiltersSummary,
    empty:         approvalRulesEmptyFilters,
    only:          ['approval_rules', 'filters'],
    t,
});

// El buscador de la barra escribe en el primer término del filtro `name`, que
// el backend hace valer contra el código del rol que firma.
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
        route('business_management.approval_rules.index'),
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
    pagination: computed(() => props.approval_rules),
    hasActiveFilters,
    t,
});

// ─── Columnas (schema en config/columns.js) ─────────────────────────────────
// Viewport: el ancho de la columna de acciones depende de si es pantalla chica,
// así que se declara ANTES de allColumns (lo consume el computed).
const { isMobile: isMobileScreen } = useViewport(768);

const allColumns = computed(() =>
    approvalRulesTableColumns(t, { isSuper: isSuper.value, isMobile: isMobileScreen.value }),
);
const { visibleColumnKeys, columns } = useColumnPreferences(allColumns);

// ─── Paginacion + sort ──────────────────────────────────────────────────────
const tablePagination = computed(() => ({
    current:  props.approval_rules.current_page,
    pageSize: props.approval_rules.per_page,
    total:    props.approval_rules.total,
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
        router.get(route('business_management.approval_rules.index'), data, {
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
    router.get(route('business_management.approval_rules.index'), data, {
        preserveScroll: true,
        preserveState: true,
        onStart:  () => { isFetching.value = true; },
        onFinish: () => { isFetching.value = false; },
    });
};

// ─── Vista: tabla | lista | tarjetas (persistida en localStorage) ──────────
const VIEW_KEY = 'approval_rules_view_mode';
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

// ─── Undo toast (60s window) ────────────────────────────────────────────────
useModuleUndoToast('business_management.approval_rules.undo_last_delete');

// ─── Bulk ───────────────────────────────────────────────────────────────────
const {
    selectedRowKeys, rowSelection, clearSelection,
    bulkOpen, bulkReason, bulkSubmitting, bulkError, bulkActivating,
    openBulkDelete, bulkSetActive, confirmBulkDelete,
} = useModuleBulkActions({
    bulkSetActiveRoute: 'business_management.approval_rules.bulk_set_active',
    bulkDeleteRoute:    'business_management.approval_rules.bulk_delete',
    resourceLabel:      t('approval_rules.records'),
    // Una regla BLOQUEADA no se puede marcar: el servidor la aparta igual, así
    // que dejar marcarla solo servía para que la masiva volviera con un aviso.
    // Las tres reglas migradas llegan bloqueadas, o sea que era el caso normal.
    rowDisabled:      (r) => (!isSuper.value && r.tenant_id == null) || !!r.locked_at,
});

// ─── Export / Import (columnas + endpoints en config/exports.js) ────────────
const exportOpen = ref(false);
const importOpen = ref(false);
// Ref al ColumnSelector (montado oculto) para abrirlo desde el engranaje.
const colSel = ref(null);
const exportableColumns = computed(() => approvalRulesExportableColumns(t, { isSuper: isSuper.value }));
const exportEndpoints   = computed(() => approvalRulesExportEndpoints());

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
const tour = useModuleTour({ module: 'approval_rules', steps: () => moduleTourSteps(t, { moduleName: t('approval_rules.plural') }) });

// ─── Keyboard shortcuts ────────────────────────────────────────────────────
useKeyboardShortcuts({
    'ctrl+n': () => can('approval_rules.create') && router.visit(route('business_management.approval_rules.create')),
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
const goEdit   = (record) => router.visit(route('business_management.approval_rules.edit',   record.slug));
const goDelete = (record) => router.visit(route('business_management.approval_rules.delete', record.slug));
</script>

<template>
    <Head :title="$t('approval_rules.plural')" />

    <div class="sap-index">
        <!-- Título + acciones (las acciones viven en el toolbar de la tabla). -->
        <div class="mi-title" data-tour="module">
            <ApprovalRulesPageHeader
                :title="$t('approval_rules.plural')"
            />
            <Tooltip :title="$t('approval_rules.preview_subtitle')">
                <Link :href="route('business_management.approval_rules.preview')" data-tour="preview">
                    <Button size="large" class="preview-btn">
                        <NodeIndexOutlined /> {{ $t('approval_rules.preview_open') }}
                    </Button>
                </Link>
            </Tooltip>
        </div>

        <!-- Las tres cosas que hay que saber para no configurar a ciegas. Se
             dicen aquí, en el listado, y no escondidas en una ayuda: la regla
             que se ve en la tabla no siempre es la que se aplica. -->
        <Alert type="info" show-icon class="how-it-works">
            <template #icon><InfoCircleOutlined /></template>
            <template #message>{{ $t('approval_rules.how_it_works_title') }}</template>
            <template #description>
                <ul class="how-it-works__list">
                    <li>{{ $t('approval_rules.how_it_works_general') }}</li>
                    <li>{{ $t('approval_rules.how_it_works_override') }}</li>
                    <li>{{ sequential ? $t('approval_rules.sequential_on') : $t('approval_rules.sequential_off') }}</li>
                </ul>
            </template>
        </Alert>

        <!-- Consola de filtros: búsqueda + builder + controles. -->
        <div class="mi-console mi-console--v2">
            <div v-if="canUsePlanFeature('saved_views')" class="mi-viewsbar" data-tour="saved-views">
                <SavedViews
                    ref="savedViewsRef"
                    layout="bar"
                    variant="tabs"
                    module="approval_rules"
                    :show-add="false"
                    :current-state="currentViewState"
                    @apply="applySavedState"
                    @default-loaded="applySavedState"
                />
            </div>

            <!-- ColumnSelector montado oculto: solo expone open() al engranaje/Columnas. -->
            <span class="mi-colsel-host" aria-hidden="true">
                <ColumnSelector
                    ref="colSel"
                    :columns="allColumns"
                    v-model="visibleColumnKeys"
                    storage-key="approval_rules"
                />
            </span>
        </div>

        <!-- Drawer de filtros (desktop): reusa el FilterBar completo. -->
        <Drawer v-model:open="filtersOpen" :title="$t('global.filters')" placement="right" :width="380">
            <FilterBar :fields="filterFields" v-model="filters" storage-key="approval_rules" />
        </Drawer>

        <!-- Toolbar Fiori (fuera del card): conteo izq · acciones/vistas/crear der. -->
        <div class="mi-tabletoolbar">
            <div class="mi-tabletoolbar__left">
                <span class="mi-toolbar-count">{{ counterLabel }}</span>
            </div>
            <div class="mi-tabletoolbar__right">
                <label class="mi-bar mi-bar--toolbar" :class="{ 'is-active': quickSearch }">
                    <SearchOutlined class="mi-bar__icon" />
                    <input v-model="quickSearch" class="mi-bar__input" :placeholder="$t('global.search_in', { item: $t('approval_rules.singular').toLowerCase() })" autocomplete="off" spellcheck="false" type="text" />
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
                <Tooltip v-if="can('approval_rules.export')" :title="$t('global.export_hint')" data-tour="export-import">
                    <Button class="mi-iconbtn" @click="exportOpen = true"><DownloadOutlined /></Button>
                </Tooltip>
                <Dropdown :trigger="['click']" placement="bottomRight">
                    <Tooltip :title="$t('global.tools')" data-tour="tools">
                        <Button class="mi-iconbtn"><SettingOutlined /></Button>
                    </Tooltip>
                    <template #overlay>
                        <Menu>
                            <MenuItem v-if="can('approval_rules.create') && can('approval_rules.import') && canUsePlanFeature('imports')" key="import" @click="importOpen = true"><UploadOutlined /> {{ $t('global.import') }}</MenuItem>
                            <MenuItem v-if="can('approval_rules.edit') && canUsePlanFeature('edit_all')" key="editall" @click="router.visit(route('business_management.approval_rules.edit_all'))"><FormOutlined /> {{ $t('global.edit_all') }}</MenuItem>
                            <MenuItem v-if="isSuper" key="trash" @click="router.visit(route('business_management.approval_rules.trash'))"><InboxOutlined /> {{ $t('global.view_deleted') }}</MenuItem>
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

                <Tooltip v-if="can('approval_rules.create')" :title="$t('approval_rules.new')" data-tour="new">
                    <Link :href="route('business_management.approval_rules.create')">
                        <Button type="primary" class="mi-iconbtn mi-create-btn" :aria-label="$t('approval_rules.new')"><PlusOutlined /></Button>
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
                :dataSource="approval_rules.data"
                :columns="columns"
                :pagination="tablePagination"
                :row-selection="(can('approval_rules.delete') || can('approval_rules.edit')) ? rowSelection : null"
                :scroll="{ x: 'max-content' }"
                :view="vistaEfectiva"
                rowKey="id"
                @change="onTableChange"
                data-tour="bulk"
            >
                <template #empty>
                    <ApprovalRulesEmptyState
                        :has-filters="hasActiveFilters"
                        :can-create="can('approval_rules.create')"
                        @clear-filters="clearFilters"
                        @open-import="importOpen = true"
                    />
                </template>
                <template #bodyCell="{ column, record, text, isMobile, compact }">
                    <!-- El nivel se pinta como una ficha: es el orden de firma. -->
                    <template v-if="column.key === 'priority_level'">
                        <span class="level-chip">{{ record.priority_level }}</span>
                    </template>

                    <!-- Lo que identifica la regla es el nombre de la firma.
                         Si no lo tiene, se cae al rol y se dice que se está
                         cayendo, en vez de dejar la celda a medias. -->
                    <template v-else-if="column.key === 'name'">
                        <div class="lead">
                            <div class="lead__txt">
                                <span class="lead__name-row">
                                    <Link :href="route('business_management.approval_rules.show', record.slug)" class="lead__name lead__link">
                                        {{ record.display_name ?? record.name ?? record.approver_role_label }}
                                    </Link>
                                    <Tooltip v-if="record.locked_at" :title="$t('locks.locked_hint')">
                                        <Tag color="gold" :bordered="false" class="lock-tag">
                                            <LockOutlined /> {{ $t('locks.locked_tag') }}
                                        </Tag>
                                    </Tooltip>
                                </span>
                                <span v-if="!record.name" class="lead__sub">{{ $t('approval_rules.name_missing') }}</span>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="column.key === 'approver_role'">
                        {{ record.approver_role_label }}
                    </template>

                    <!-- Sin tipo de trabajo la regla vale para todos: se dice,
                         no se deja la celda en blanco. -->
                    <template v-else-if="column.key === 'work_type'">
                        <Tag v-if="record.work_type" color="geekblue" :bordered="false">{{ record.work_type.code }}</Tag>
                        <Tag v-else :bordered="false">{{ $t('approval_rules.all_work_types') }}</Tag>
                    </template>

                    <template v-else-if="column.key === 'country'">
                        {{ record.country?.name ?? '—' }}
                    </template>

                    <template v-else-if="column.key === 'is_required'">
                        <Tag :color="record.is_required ? 'red' : 'default'" :bordered="false">
                            {{ record.is_required ? $t('approval_rules.required') : $t('approval_rules.optional') }}
                        </Tag>
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


                    <template v-else-if="column.key === 'created_at'">
                        {{ formatDateTime(record.created_at) }}
                    </template>

                    <ApprovalRulesActionsCell
                        v-else-if="column.key === 'actions'"
                        :record="record"
                        :is-mobile="isMobile"
                        :compact="compact"
                        :is-super="isSuper"
                        :can-edit="can('approval_rules.edit')"
                        :can-delete="can('approval_rules.delete')"
                        @edit="goEdit"
                        @delete="goDelete"
                    />

                    <template v-else>{{ text ?? record[column.dataIndex] ?? '' }}</template>
                </template>
            </ResponsiveTable>
        </Card>

        <ApprovalRulesBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :is-mobile="isMobileScreen"
            :bulk-activating="bulkActivating"
            :can-edit="can('approval_rules.edit')"
            :can-delete="can('approval_rules.delete')"
            @cancel="clearSelection"
            @set-active="bulkSetActive"
            @delete="openBulkDelete"
        />

        <ApprovalRulesBulkDeleteModal
            v-model:open="bulkOpen"
            v-model:reason="bulkReason"
            :count="selectedRowKeys.length"
            :submitting="bulkSubmitting"
            :error-msg="bulkError"
            :resource-label="selectedRowKeys.length === 1 ? $t('approval_rules.record') : $t('approval_rules.records')"
            @confirm="confirmBulkDelete"
        />

        <ExportDialog
            v-model:open="exportOpen"
            :columns="exportableColumns"
            :selected-ids="selectedRowKeys"
            :has-filters="hasActiveFilters"
            :filters-summary="filtersSummary"
            :current-filters="buildQueryData()"
            :default-title="$t('approval_rules.export_title')"
            :endpoints="exportEndpoints"
            :limits="exportLimits"
            :total-rows="approval_rules.total ?? 0"
            :total-unfiltered="approval_rules.total_unfiltered ?? approval_rules.total ?? 0"
        />

        <ImportDialog
            v-model:open="importOpen"
            :endpoint="route('business_management.approval_rules.import')"
            :template-url="route('business_management.approval_rules.import_template')"
            :resource-label="$t('approval_rules.records')"
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

/* Aviso de cómo se resuelve el flujo. */
.how-it-works { margin-bottom: 14px; }
.how-it-works__list { margin: 4px 0 0 0; padding-left: 18px; line-height: 1.6; }

/* Botón a la vista previa: objetivo de toque grande, va en tablet. */
.preview-btn { min-height: 44px; }

/* Nombre de la firma + candado en la misma línea, sin que el candado empuje
   el nombre fuera de la celda. */
.lead__name-row { display: flex; align-items: center; gap: 8px; min-width: 0; }
.lock-tag { flex-shrink: 0; }

/* El nivel, como ficha numerada. */
.level-chip {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 30px; height: 30px; padding: 0 8px;
    border-radius: 999px; font-weight: 700; font-size: 0.82rem;
    color: var(--color-primary, #0A6ED1);
    background: rgba(10, 110, 209, 0.10);
    border: 1px solid rgba(10, 110, 209, 0.20);
}

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

