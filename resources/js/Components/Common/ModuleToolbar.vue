<script setup>
/**
 * ModuleToolbar — toolbar desktop genérico para cualquier módulo CRUD del core.
 *
 * Reemplaza a los XxxToolbar.vue copy-paste (RegionsToolbar, LanguagesToolbar,
 * CountriesToolbar, etc.). Cualquier módulo consume con:
 *
 *   <ModuleToolbar
 *       module="regions"
 *       :all-columns="allColumns"
 *       :visible-columns="visibleColumns"
 *       :can-create="can('regions.create')"
 *       :can-edit="can('regions.edit')"
 *       :is-super="isSuper"
 *       :can-see-audit="canSeeAudit"
 *       @update:visible-columns="visibleColumns = $event"
 *       @open-export="..."
 *       @open-import="..."
 *       @restart-tour="..."
 *   />
 *
 * Rutas y labels se construyen dinámicamente desde el prop `module`:
 *   - route('system_management.{module}.create')
 *   - $t('{module}.new')
 *   - storage-key=`{module}`
 *
 * Slots:
 *   - `extra-actions` — botones extra entre Audit y el primary
 *   - `before-cta` — algo antes del botón "Nuevo X"
 *
 * Mobile: el toolbar se oculta (los módulos usan BottomBar en mobile).
 */
import { computed } from 'vue';
import { Button, Tooltip } from 'ant-design-vue';
import { Link } from '@inertiajs/vue3';
import {
    DownloadOutlined, UploadOutlined, EditOutlined, InboxOutlined,
    PlusOutlined, QuestionCircleOutlined,
} from '@ant-design/icons-vue';

import ColumnSelector from '@/Components/Common/ColumnSelector.vue';
import SavedViews from '@/Components/Common/SavedViews.vue';
import { usePlanFeatures } from '@/Composables/usePlanFeatures';

const { canUse } = usePlanFeatures();
// `saved_views` requiere plan basic+. `imports` y `edit_all` requieren pro+.
// Si el tenant no los tiene, ocultamos el control para no confundir (el patrón
// ya lo usa ExportDialog filtrando formatos por plan_feature).
const canUseSavedViews = computed(() => canUse('saved_views'));
const canUseImports    = computed(() => canUse('imports'));
const canUseEditAll    = computed(() => canUse('edit_all'));

const props = defineProps({
    module:          { type: String,  required: true },  // 'regions' / 'languages' / etc.
    allColumns:      { type: Array,   required: true },
    visibleColumns:  { type: Array,   required: true },
    canCreate:       { type: Boolean, default: false },
    canEdit:         { type: Boolean, default: false },
    isSuper:    { type: Boolean, default: false },
    canSeeAudit:     { type: Boolean, default: false },
    // Override del label "Nuevo X" si el módulo usa una key i18n distinta a
    // `{module}.new` (ej. tenants.new_workspace en lugar de tenants.new).
    createLabelKey:  { type: String,  default: null },
    // State actual del listado para SavedViews (filtros + columnas + sort).
    viewState:       { type: Object,  default: null },
    // Prefix de routes — default `system_management` cubre el core. Módulos
    // como Automations viven en `automation_management.*` y pasan el suyo.
    routePrefix:     { type: String,  default: 'system_management' },
    // Ocultar Export/Import si el módulo no los implementó todavía.
    showExportImport: { type: Boolean, default: true },
});

defineEmits([
    'update:visibleColumns',
    'open-export',
    'open-import',
    'restart-tour',
    'apply-view',
]);

// Rutas dinámicas — se construyen desde `module` + `routePrefix`. Para Automations
// se pasa routePrefix="automation_management"; el core usa el default.
const routes = computed(() => {
    const base = `${props.routePrefix}.${props.module}`;
    return {
        create:  `${base}.create`,
        editAll: `${base}.edit_all`,
        trash:   `${base}.trash`,
    };
});

const createLabel = computed(() => props.createLabelKey ?? `${props.module}.new`);
</script>

<template>
    <div class="header-actions hide-on-mobile">
        <span v-if="viewState && canUseSavedViews" data-tour="saved-views">
            <SavedViews
                :module="module"
                :current-state="viewState"
                @apply="$emit('apply-view', $event)"
                @default-loaded="$emit('apply-view', $event)"
            />
        </span>

        <ColumnSelector
            :columns="allColumns"
            :model-value="visibleColumns"
            :storage-key="module"
            data-tour="columns"
            @update:model-value="$emit('update:visibleColumns', $event)"
        />

        <span v-if="showExportImport" data-tour="export-import">
            <Tooltip :title="$t('global.export_hint')">
                <Button @click="$emit('open-export')">
                    <DownloadOutlined /> {{ $t('global.export') }}
                </Button>
            </Tooltip>
            <Tooltip v-if="canCreate && canUseImports" :title="$t('global.import_hint')">
                <Button style="margin-left: 8px;" @click="$emit('open-import')">
                    <UploadOutlined /> {{ $t('global.import') }}
                </Button>
            </Tooltip>
        </span>

        <Tooltip v-if="canEdit && canUseEditAll" :title="$t('global.edit_all_hint')">
            <Link :href="route(routes.editAll)" data-tour="edit-all">
                <Button>
                    <EditOutlined /> {{ $t('global.edit_all') }}
                </Button>
            </Link>
        </Tooltip>

        <Tooltip :title="$t('global.tour_show_again')" data-tour="tour-help">
            <Button @click="$emit('restart-tour')">
                <QuestionCircleOutlined />
            </Button>
        </Tooltip>

        <Tooltip v-if="isSuper" :title="$t('global.view_deleted_hint')">
            <Link :href="route(routes.trash)" data-tour="trash">
                <Button>
                    <InboxOutlined /> {{ $t('global.view_deleted') }}
                </Button>
            </Link>
        </Tooltip>

        <!-- Slot para acciones específicas del módulo entre Audit y CTA -->
        <slot name="extra-actions" />

        <slot name="before-cta" />

        <Tooltip v-if="canCreate" :title="$t('global.create_record_hint')">
            <Link :href="route(routes.create)" class="header-actions__cta" data-tour="new-record">
                <Button type="primary" size="large">
                    <PlusOutlined /> {{ $t(createLabel) }}
                </Button>
            </Link>
        </Tooltip>
    </div>
</template>

<style scoped>
.header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}
.header-actions__cta { margin-left: auto; }

@media (max-width: 768px) {
    .hide-on-mobile { display: none !important; }
    .header-actions { width: 100%; }
    .header-actions > * { flex: 0 1 auto; }
}
</style>
