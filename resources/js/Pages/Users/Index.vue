<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Button, Card, Tag, Tooltip, Badge, Dropdown, Menu, MenuItem, Input, Drawer,
} from 'ant-design-vue';
import UserAvatar from '@/Components/Common/UserAvatar.vue';
import FilterBar from '@/Components/Common/FilterBar.vue';
import InlineFilterBuilder from '@/Components/Common/InlineFilterBuilder.vue';
import ColumnSelector from '@/Components/Common/ColumnSelector.vue';
import ResponsiveTable from '@/Components/Common/ResponsiveTable.vue';
import UsersBulkBar from '@/Components/Users/UsersBulkBar.vue';
import UsersPageHeader from '@/Components/Users/UsersPageHeader.vue';
import UsersActionsCell from '@/Components/Users/UsersActionsCell.vue';
import UsersEmptyState from '@/Components/Users/UsersEmptyState.vue';
import UsersBulkDeleteModal from '@/Components/Users/UsersBulkDeleteModal.vue';
import {
    PlusOutlined,
    EditOutlined,
    InboxOutlined,
    DownloadOutlined,
    UploadOutlined,
    FilterOutlined,
    EllipsisOutlined,
    SearchOutlined,
    SettingOutlined,
    TableOutlined,
    CloseOutlined,
    SortAscendingOutlined,
    SortDescendingOutlined,
    StarOutlined,
    StarFilled,
    BarsOutlined,
    AppstoreOutlined,
    AudioOutlined,
    ControlOutlined, ClearOutlined, SaveOutlined,
} from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import ExportDialog from '@/Components/Common/ExportDialog.vue';
import ImportDialog from '@/Components/Common/ImportDialog.vue';
import SavedViews from '@/Components/Common/SavedViews.vue';
import UsersFavoriteCell from '@/Components/Users/UsersFavoriteCell.vue';
import { usePageLoading } from '@/Composables/usePageLoading';
import { useColumnPreferences } from '@/Composables/useColumnPreferences';
import { usePlanFeatures } from '@/Composables/usePlanFeatures';
import { useVoiceSearch } from '@/Composables/useVoiceSearch';
import { useModuleFilters } from '@/Composables/useModuleFilters';
import { useModuleTour } from '@/Composables/useModuleTour';
import { useModuleListMeta } from '@/Composables/useModuleListMeta';
import { useModuleSavedViews } from '@/Composables/useModuleSavedViews';
import { useModuleFavorites } from '@/Composables/useModuleFavorites';
import { useModuleUndoToast } from '@/Composables/useModuleUndoToast';
import { useDateFormat } from '@/Composables/useDateFormat';
const { canUse: canUsePlanFeature } = usePlanFeatures();
const { formatDateTime } = useDateFormat();
import { useI18n } from '@/Plugins/i18n';
import { QuestionCircleOutlined } from '@ant-design/icons-vue';

import {
    usersFilterFields, usersEmptyFilters, hydrateUsersFilters,
    usersFiltersToQuery, usersFiltersSummary,
    serializeSavedFilters, deserializeSavedFilters,
} from './config/filters';
import { usersTableColumns } from './config/columns';
import { usersExportableColumns, usersExportEndpoints } from './config/exports';
import { moduleTourSteps } from '@/Composables/moduleTourSteps';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

const props = defineProps({
    users:         { type: Object, required: true },
    filters:       { type: Object, required: true },
    roleOptions:   { type: Array,  default: () => [] },
    tenantOptions: { type: Array,  default: () => [] },
    filterSchema:  { type: Array,  default: () => [] },
    exportLimits:  { type: Object, default: () => ({}) },
});

const page = usePage();
const can = (perm) => {
    const u = page.props.auth?.user;
    if (!u) return false;
    if (u.roles?.includes('super')) return true;
    return u.permissions?.includes(perm) ?? false;
};
const isSuper = computed(() => page.props.auth?.user?.roles?.includes('super') ?? false);
const canSeeAudit = computed(() => {
    const r = page.props.auth?.user?.roles ?? [];
    return r.includes('super') || r.includes('admin');
});

// Avatar de iniciales con color estable (para la celda principal de la tabla).
const initials = (name) => (name || '').split(/\s+/).filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase() || '—';
const avaStyle = (name) => {
    let h = 0;
    for (const c of (name || '')) h = (h * 31 + c.charCodeAt(0)) % 360;
    return { background: `hsl(${h} 58% 52%)` };
};

// ─── Filtros (schema + (de)serialización en config/filters.js) ──────────────
const filterFields = computed(() => usersFilterFields(t, {
    isSuper:  isSuper.value,
    roleOptions:   props.roleOptions,
    tenantOptions: props.tenantOptions,
}));

const {
    filters, reload, isFetching, suspendReload, hasActiveFilters, clearFilters, filtersSummary, buildQueryData,
} = useModuleFilters({
    serverFilters: props.filters,
    hydrate:       hydrateUsersFilters,
    toQuery:       usersFiltersToQuery,
    summary:       usersFiltersSummary,
    empty:         usersEmptyFilters,
    only:          ['users', 'filters'],
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
    router.get(route('user_management.users.index'), data, {
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
        router.get(route('user_management.users.index'), data, { preserveScroll: true, preserveState: true, onStart: () => { isFetching.value = true; }, onFinish: () => { isFetching.value = false; } });
    }, 350);
};

// "Limpiar todo": resetea filtros y navega a la URL limpia (conservando
// orden/paginación) para no dejar ningún param pegado.
const clearAll = () => {
    advancedWhere.value = [];
    router.get(
        route('user_management.users.index'),
        {
            sort: props.filters.sort,
            direction: props.filters.direction,
            per_page: props.filters.per_page,
        },
        { preserveScroll: true },
    );
};

// ─── Favoritos polimorficos ────────────────────────────────────────────────
const { submitting: favoriteSubmitting, toggle: toggleFavorite } = useModuleFavorites('users', 'users');

// Toast de UNDO (60s) — aparece al eliminar; el admin lo puede usar.
useModuleUndoToast('user_management.users.undo_last_delete');

// ─── Onboarding tour (pasos en config/tour.js) ──────────────────────────────
const tour = useModuleTour({ module: 'users', steps: () => moduleTourSteps(t, { moduleName: t('users.plural') }) });

// ─── Export / Import (columnas + endpoints en config/exports.js) ────────────
const exportOpen = ref(false);
const importOpen = ref(false);
// Ref al ColumnSelector (montado oculto) para abrirlo desde el engranaje.
const colSel = ref(null);
const exportableColumns = computed(() => usersExportableColumns(t, { isSuper: isSuper.value }));
const exportEndpoints   = computed(() => usersExportEndpoints());

// ─── Bulk selection ─────────────────────────────────────────────────────────
const selectedRowKeys = ref([]);
const onSelectChange = (keys) => { selectedRowKeys.value = keys; };

const bulkDeleteModalOpen = ref(false);
const bulkDeleteReason = ref('');
const submitBulkDelete = () => {
    if (bulkDeleteReason.value.trim().length < 3) return;
    router.post(route('user_management.users.bulk_delete'), {
        ids: selectedRowKeys.value,
        deleted_description: bulkDeleteReason.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedRowKeys.value = [];
            bulkDeleteModalOpen.value = false;
            bulkDeleteReason.value = '';
        },
    });
};

const bulkSetActive = (active) => {
    router.post(route('user_management.users.bulk_set_active'), {
        ids: selectedRowKeys.value,
        is_active: active,
    }, {
        preserveScroll: true,
        onSuccess: () => { selectedRowKeys.value = []; },
    });
};

const roleTagColor = (roleName) => {
    if (roleName === 'admin') return 'purple';
    if (roleName === 'super') return 'red';
    return 'cyan';
};

// Contador adaptativo "X registros" / "X de Y registros" — mismo composable
// que Customers/Roles/Regions (numero animado + formato consistente).
const { counterLabel } = useModuleListMeta({
    pagination: computed(() => props.users),
    hasActiveFilters,
    t,
});

const screenWidth = ref(typeof window !== 'undefined' ? window.innerWidth : 1024);
const onResize = () => { screenWidth.value = window.innerWidth; };
onMounted(() => window.addEventListener('resize', onResize));
onBeforeUnmount(() => window.removeEventListener('resize', onResize));
const isMobileScreen = computed(() => screenWidth.value < 768);

// ─── Loading state during Inertia partial reloads ──────────────────────────
// propKey='users' filtra polling cross-módulo del inbox (AppLayout cada 4s).
const { loading: tableLoading } = usePageLoading('/users', 'users');

// ─── Columnas (schema en config/columns.js) ─────────────────────────────────
const allColumns = computed(() =>
    usersTableColumns(t, { isSuper: isSuper.value, isMobile: isMobileScreen.value }),
);

const visibleColumnKeys = ref([]);
// Initialize / sync visible keys whenever the columns list changes (e.g. on
// initial mount o si cambia el rol). Respeta `defaultHidden`: las columnas
// marcadas asi arrancan ocultas (ej. created_at) — el usuario las habilita
// desde el ColumnSelector.
watch(allColumns, (cols) => {
    visibleColumnKeys.value = cols.filter(c => !c.defaultHidden).map(c => c.key);
}, { immediate: true });

const columns = computed(() =>
    visibleColumnKeys.value
        .map(key => allColumns.value.find(c => c.key === key))
        .filter(Boolean)
);

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

const tablePagination = computed(() => ({
    current:  props.users.current_page,
    pageSize: props.users.per_page,
    total:    props.users.total,
    showSizeChanger: true,
    pageSizeOptions: ['10', '25', '50', '100', '200'],
    showTotal: (total, range) => `${range[0]}-${range[1]} de ${total}`,
}));

const onTableChange = (pag, _filters, sorter) => {
    const direction = sorter?.order === 'ascend' ? 'asc'
                    : sorter?.order === 'descend' ? 'desc'
                    : props.filters.direction;
    const sort = sorter?.field || props.filters.sort;
    reload({ page: pag.current, per_page: pag.pageSize, sort, direction });
};

// ─── Vista: tabla | lista | tarjetas (persistida en localStorage) ──────────
const VIEW_KEY = 'users_view_mode';
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

</script>

<template>
    <Head :title="$t('users.index_title')" />

    <div class="sap-index">
        <!-- Título centrado (estándar de índice). -->
        <div class="mi-title" data-tour="module">
            <UsersPageHeader
                :title="$t('users.index_title')"
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
                    module="users"
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
                    storage-key="users"
                />
            </span>
        </div>

        <!-- Drawer de filtros (desktop): reusa el FilterBar completo. -->
        <Drawer v-model:open="filtersOpen" :title="$t('global.filters')" placement="right" :width="380">
            <FilterBar :fields="filterFields" v-model="filters" storage-key="users" />
        </Drawer>

        <!-- Tabla -->
        <!-- Toolbar Fiori (fuera del card): conteo izq · acciones/vistas/crear der. -->
        <div class="mi-tabletoolbar">
            <div class="mi-tabletoolbar__left">
                <span class="mi-toolbar-count">{{ counterLabel }}</span>
            </div>
            <div class="mi-tabletoolbar__right">
                <label class="mi-bar mi-bar--toolbar" :class="{ 'is-active': quickSearch }">
                    <SearchOutlined class="mi-bar__icon" />
                    <input v-model="quickSearch" class="mi-bar__input" :placeholder="$t('global.search_in', { item: $t('users.singular').toLowerCase() })" autocomplete="off" spellcheck="false" type="text" />
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
                            <MenuItem v-if="can('users.create') && canUsePlanFeature('imports')" key="import" @click="importOpen = true">
                                <UploadOutlined /> {{ $t('global.import') }}
                            </MenuItem>
                            <MenuItem v-if="can('users.edit') && canUsePlanFeature('edit_all')" key="editall" @click="router.visit(route('user_management.users.edit_all'))">
                                <EditOutlined /> {{ $t('global.edit_all') }}
                            </MenuItem>
                            <MenuItem v-if="isSuper" key="trash" @click="router.visit(route('user_management.users.trash'))">
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
                <Tooltip v-if="can('users.create')" :title="$t('users.new')" data-tour="new">
                    <Link :href="route('user_management.users.create')">
                        <Button type="primary" class="mi-iconbtn mi-create-btn" :aria-label="$t('users.new')"><PlusOutlined /></Button>
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
            <!-- Bulk bar: dentro del card. Mobile: sticky al pie (patrón Regions). -->

            <!-- Toolbar de resultados, pegada a la tabla (no flota suelta). -->
            <ResponsiveTable
                :dataSource="props.users.data"
                :columns="columns"
                :pagination="tablePagination"
                :loading="tableLoading"
                :scroll="{ x: 'max-content' }"
                rowKey="id"
                :row-selection="{ selectedRowKeys, onChange: onSelectChange }"
                :view="viewMode"
                data-tour="bulk"
                @change="onTableChange"
            >
                <template #bodyCell="{ column, record, isMobile, compact }">
                    <UsersFavoriteCell
                        v-if="column.key === 'favorite'"
                        :record="record"
                        :submitting="favoriteSubmitting"
                        :data-tour="record === props.users.data[0] ? 'favorites' : null"
                        @toggle="toggleFavorite"
                    />

                    <template v-else-if="column.key === 'name'">
                        <div class="lead">
                            <UserAvatar class="lead__ava" :photo="record.photo" :name="record.name" :size="40" :updated-at="record.updated_at" />
                            <div class="lead__txt">
                                <Link :href="route('user_management.users.show', record.slug)" class="lead__name lead__link">{{ record.name }}</Link>
                                <span v-if="record.email" class="lead__sub">{{ record.email }}</span>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="column.key === 'country'">
                        <span v-if="record.country">{{ record.country.name }}</span>
                        <span v-else class="muted">—</span>
                    </template>

                    <template v-else-if="column.key === 'role'">
                        <Tag v-if="record.role === 'admin'" color="purple" :bordered="false">{{ record.role }}</Tag>
                        <span v-else-if="record.role">{{ record.role }}</span>
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

                    <template v-else-if="column.key === 'created_at'">
                        {{ formatDateTime(record.created_at) }}
                    </template>

                    <UsersActionsCell
                        v-else-if="column.key === 'actions'"
                        :record="record"
                        :is-mobile="isMobile"
                        :compact="compact"
                        :can-edit="can('users.edit')"
                        :can-create="can('users.create')"
                        :can-delete="can('users.delete')"
                    />
                </template>

                <template #empty>
                    <UsersEmptyState
                        :has-filters="hasActiveFilters"
                        :can-create="can('users.create')"
                        @clear-filters="clearFilters"
                    />
                </template>
            </ResponsiveTable>
        </Card>

        <UsersBulkBar
            v-if="selectedRowKeys.length > 0"
            :count="selectedRowKeys.length"
            :is-mobile="isMobileScreen"
            :can-edit="can('users.edit')"
            :can-delete="can('users.delete')"
            @cancel="selectedRowKeys = []"
            @set-active="bulkSetActive"
            @delete="bulkDeleteModalOpen = true"
        />

        <UsersBulkDeleteModal
            v-model:open="bulkDeleteModalOpen"
            v-model:reason="bulkDeleteReason"
            :count="selectedRowKeys.length"
            :resource-label="$t('users.records')"
            @confirm="submitBulkDelete"
        />

        <ExportDialog
            v-model:open="exportOpen"
            :columns="exportableColumns"
            :selected-ids="selectedRowKeys"
            :has-filters="hasActiveFilters"
            :current-filters="buildQueryData()"
            :default-title="$t('users.index_title')"
            :endpoints="exportEndpoints"
            :limits="exportLimits"
            :total-rows="users.total ?? 0"
            :total-unfiltered="users.total_unfiltered ?? users.total ?? 0"
        />

        <ImportDialog
            v-model:open="importOpen"
            :endpoint="route('user_management.users.import')"
            :template-url="route('user_management.users.import_template')"
            :resource-label="$t('users.records')"
        />
    </div>
</template>

<style scoped>
.muted { color: var(--color-text-muted); font-size: 0.78rem; }

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

/* Contenedor: rounded + sombra suave. Nada de overflow:hidden (rompe el
   position:sticky del thead). */
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
.grid-card :deep(.ant-table-tbody > tr > td:first-child) { box-shadow: inset 3px 0 0 transparent; transition: box-shadow 0.12s ease; }
.grid-card :deep(.ant-table-tbody > tr:hover > td:first-child) { box-shadow: inset 3px 0 0 var(--color-primary, #0A6ED1); }

.bidx-search :deep(.ant-input-affix-wrapper) { border-radius: 9px; }

.grid-card :deep(.ant-table-tbody .row-actions-desktop) { opacity: 0.35; transition: opacity 0.15s ease; }
.grid-card :deep(.ant-table-tbody > tr:hover .row-actions-desktop),
.grid-card :deep(.ant-table-tbody .row-actions-desktop:focus-within) { opacity: 1; }

@media (max-width: 768px) {
    .page-header { flex-direction: column; align-items: stretch; }
    .hide-on-mobile { display: none !important; }
}
</style>

<style>
html[data-theme="dark"] .grid-card .ant-table-thead > tr > th {
    background: #2c3034 !important; color: #e5e6e7 !important;
}

/* Espacio inferior para el bottom-bar fijo (mobile). Igual que Regions. */
@media (max-width: 767.98px) {
    .below-shell .content {
        padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 150px) !important;
    }
}
</style>
