<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { Button, Drawer, Checkbox, Space, Tooltip, Empty } from 'ant-design-vue';
import { TableOutlined, MenuOutlined, UndoOutlined, PushpinOutlined } from '@ant-design/icons-vue';

// ─── Responsive: drawer full-screen en mobile, lateral 380px en desktop ────
const isMobile = ref(false);
const checkMobile = () => { isMobile.value = window.innerWidth < 768; };
onMounted(() => { checkMobile(); window.addEventListener('resize', checkMobile); });
onBeforeUnmount(() => window.removeEventListener('resize', checkMobile));

/**
 * ColumnSelector — SAP Fiori "Adapt Columns" pattern + drag-reorder.
 *
 * Usage:
 *   <ColumnSelector
 *       :columns="allColumns"
 *       v-model="visibleColumnKeys"
 *       storage-key="regions"
 *   />
 *
 * Each column needs at minimum:  { key, title, alwaysVisible? }
 * `alwaysVisible: true` makes it non-removable (e.g. id, actions).
 *
 * El usuario puede:
 *   - check/uncheck para mostrar/ocultar columnas
 *   - drag handle (≡) para reordenar — el orden persiste
 *
 * `modelValue` es un array ordenado de keys visibles. El padre debe respetar
 * ese orden cuando arma su array de columnas para AntD Table:
 *
 *   const columns = computed(() =>
 *       visibleColumnKeys.value
 *           .map(k => allColumns.value.find(c => c.key === k))
 *           .filter(Boolean)
 *   );
 */

const props = defineProps({
    columns:    { type: Array, required: true },
    modelValue: { type: Array, required: true }, // array ordenado de visible keys
    storageKey: { type: String, default: 'default' },
});
const emit = defineEmits(['update:modelValue']);

const STORAGE_PREFIX = 'col-selector:';
const fullStorageKey = computed(() => STORAGE_PREFIX + props.storageKey);

const alwaysVisible = computed(() =>
    props.columns.filter(c => c.alwaysVisible).map(c => c.key)
);

// ─── Pinned columns ──────────────────────────────────────────────────────
// `fixed: 'left' | 'right'` en AntD Table las hace sticky a un extremo. Si
// el usuario las reordena al medio, el sticky se pelea con el orden — visual
// roto. Las tratamos como ANCLADAS: no draggables, siempre en su extremo.
const pinnedLeftKeys = computed(() =>
    props.columns.filter(c => c.fixed === 'left' || c.fixed === true).map(c => c.key)
);
const pinnedRightKeys = computed(() =>
    props.columns.filter(c => c.fixed === 'right').map(c => c.key)
);
const isPinned = (key) =>
    pinnedLeftKeys.value.includes(key) || pinnedRightKeys.value.includes(key);
const pinnedSide = (key) =>
    pinnedLeftKeys.value.includes(key) ? 'left'
        : pinnedRightKeys.value.includes(key) ? 'right'
        : null;

// On mount, load saved or default visibility + order
onMounted(() => {
    if (props.modelValue.length > 0) return;
    try {
        const raw = localStorage.getItem(fullStorageKey.value);
        if (raw) {
            const stored = JSON.parse(raw);
            // stored es un array de keys — preserva orden tal cual.
            const valid = stored.filter(k => props.columns.some(c => c.key === k));
            // alwaysVisible que no estén en stored los agregamos al final
            const missingAlways = alwaysVisible.value.filter(k => !valid.includes(k));
            emit('update:modelValue', [...valid, ...missingAlways]);
            return;
        }
    } catch (e) {}
    emit('update:modelValue', props.columns.map(c => c.key));
});

const persist = (keys) => {
    try { localStorage.setItem(fullStorageKey.value, JSON.stringify(keys)); } catch (e) {}
};

// ─── Drawer state ────────────────────────────────────────────────────────
const open = ref(false);
// `draftOrder` contiene TODOS los keys (visibles+ocultos) en orden de display.
// `draftVisible` es el subset checked.
const draftOrder   = ref([]);
const draftVisible = ref([]);

const openDrawer = () => {
    const visibleSet = new Set(props.modelValue);
    // Layout invariant: pinned-left → middle (user-ordered) → pinned-right.
    // Las pinned siempre aparecen primero/último en el drawer aunque el
    // localStorage haya guardado otro orden (defense in depth).
    const middle = props.modelValue.filter(k => !isPinned(k));
    const hiddenMiddle = props.columns
        .filter(c => !visibleSet.has(c.key) && !isPinned(c.key))
        .map(c => c.key);
    draftOrder.value = [
        ...pinnedLeftKeys.value,
        ...middle,
        ...hiddenMiddle,
        ...pinnedRightKeys.value,
    ];
    draftVisible.value = [...props.modelValue];
    open.value = true;
};

const apply = () => {
    // Emite los keys VISIBLES en el orden del draft, garantizando alwaysVisible.
    // Forzamos el invariant pinned-left → middle → pinned-right por si algo
    // se coló fuera de orden (no debería pero defense in depth).
    const visibleSet = new Set([...draftVisible.value, ...alwaysVisible.value]);
    const middle = draftOrder.value.filter(k => visibleSet.has(k) && !isPinned(k));
    const left   = pinnedLeftKeys.value.filter(k => visibleSet.has(k));
    const right  = pinnedRightKeys.value.filter(k => visibleSet.has(k));
    const ordered = [...left, ...middle, ...right];
    emit('update:modelValue', ordered);
    persist(ordered);
    open.value = false;
};

const cancel = () => { open.value = false; };

// Exponemos `open()` al padre para que pueda disparar el drawer desde otro
// trigger (ej. el bottom bar mobile en AuditLogs/Customers). Sin esto el
// padre tenía que simular click DOM sobre el button interno, frágil.
defineExpose({
    open: () => openDrawer(),
});

const toggleColumn = (key) => {
    if (alwaysVisible.value.includes(key)) return;
    if (draftVisible.value.includes(key)) {
        draftVisible.value = draftVisible.value.filter(k => k !== key);
    } else {
        draftVisible.value = [...draftVisible.value, key];
    }
};

const selectAll  = () => { draftVisible.value = props.columns.map(c => c.key); };
const selectNone = () => { draftVisible.value = [...alwaysVisible.value]; };

// Reset al orden original (orden definido en `props.columns`).
const resetOrder = () => {
    draftOrder.value = props.columns.map(c => c.key);
};

// ─── Drag & drop (HTML5 nativo, sin dependencia) ─────────────────────────
const draggingKey = ref(null);
const dragOverKey = ref(null);

const onDragStart = (e, key) => {
    // Pinned columns no se mueven (Actions, ID con fixed, etc.) — el sticky
    // de AntD se pelea con cualquier reorder al medio.
    if (isPinned(key)) {
        e.preventDefault();
        return;
    }
    draggingKey.value = key;
    e.dataTransfer.effectAllowed = 'move';
    // Firefox necesita algún data set para iniciar el drag.
    try { e.dataTransfer.setData('text/plain', key); } catch (_) {}
};

const onDragOver = (e, key) => {
    e.preventDefault();
    if (draggingKey.value === null || draggingKey.value === key) return;
    // No permitir drop SOBRE una pinned — el orden final tiene que respetar
    // left → middle → right. Drop sobre pinned se ignora visualmente.
    if (isPinned(key)) return;
    dragOverKey.value = key;
    // Reordenar en vivo: mover draggingKey antes de key.
    const order = [...draftOrder.value];
    const fromIdx = order.indexOf(draggingKey.value);
    const toIdx   = order.indexOf(key);
    if (fromIdx === -1 || toIdx === -1 || fromIdx === toIdx) return;
    order.splice(fromIdx, 1);
    order.splice(toIdx, 0, draggingKey.value);
    draftOrder.value = order;
};

const onDragEnd = () => {
    draggingKey.value = null;
    dragOverKey.value = null;
};

// Lookup de columna por key (para iterar el `draftOrder` en el template).
const colByKey = computed(() => {
    const m = {};
    props.columns.forEach(c => { m[c.key] = c; });
    return m;
});

// Counter: "Columnas (12/20)"
const counterLabel = computed(() => {
    const total = props.columns.length;
    const visible = props.modelValue.length;
    return `${visible}/${total}`;
});
</script>

<template>
    <Tooltip :title="$t('global.configure_columns')">
        <Button @click="openDrawer">
            <TableOutlined />
            {{ $t('global.columns') }}
            <span class="col-counter">({{ counterLabel }})</span>
        </Button>
    </Tooltip>

    <Drawer
        v-model:open="open"
        :title="$t('global.configure_columns')"
        :width="isMobile ? '100%' : 380"
        :placement="isMobile ? 'bottom' : 'right'"
        :height="isMobile ? '90vh' : undefined"
    >
        <p class="col-help">{{ $t('global.columns_help_with_drag') }}</p>

        <div class="col-actions">
            <Button size="small" type="link" @click="selectAll">{{ $t('global.show_all') }}</Button>
            <Button size="small" type="link" @click="selectNone">{{ $t('global.show_only_required') }}</Button>
            <Button size="small" type="link" @click="resetOrder">
                <UndoOutlined /> {{ $t('global.reset_order') }}
            </Button>
        </div>

        <div class="col-list">
            <div
                v-for="key in draftOrder"
                :key="key"
                class="col-item"
                :class="{
                    'col-item--locked':   colByKey[key]?.alwaysVisible,
                    'col-item--pinned':   isPinned(key),
                    'col-item--dragging': draggingKey === key,
                    'col-item--over':     dragOverKey === key,
                }"
                :draggable="!isPinned(key)"
                @dragstart="onDragStart($event, key)"
                @dragover="onDragOver($event, key)"
                @dragend="onDragEnd"
                @drop.prevent="onDragEnd"
            >
                <Checkbox
                    :checked="draftVisible.includes(key)"
                    :disabled="colByKey[key]?.alwaysVisible"
                    @click.stop
                    @change="toggleColumn(key)"
                />
                <PushpinOutlined v-if="isPinned(key)" class="col-item__pin" />
                <MenuOutlined v-else class="col-item__drag" />
                <span class="col-item__label" @click="toggleColumn(key)">
                    {{ colByKey[key]?.title }}
                </span>
                <span v-if="isPinned(key)" class="col-item__pinned-tag">
                    {{ pinnedSide(key) === 'left' ? $t('global.pinned_left') : $t('global.pinned_right') }}
                </span>
                <span v-else-if="colByKey[key]?.alwaysVisible" class="col-item__lock">
                    {{ $t('global.mandatory_column') }}
                </span>
            </div>
        </div>

        <Empty v-if="props.columns.length === 0" :description="$t('global.no_records')" />

        <template #footer>
            <Space>
                <Button @click="cancel">{{ $t('global.cancel') }}</Button>
                <Button type="primary" @click="apply">{{ $t('global.apply') }}</Button>
            </Space>
        </template>
    </Drawer>
</template>

<style scoped>
.col-counter {
    margin-left: 4px;
    font-size: 0.75rem;
    color: #6A6D70;
    font-weight: 500;
}

.col-help {
    color: #6A6D70;
    font-size: 0.875rem;
    margin: 0 0 14px 0;
    line-height: 1.5;
}

.col-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 4px;
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid #f0f0f0;
    flex-wrap: wrap;
}

.col-list { display: flex; flex-direction: column; gap: 2px; }

.col-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 4px;
    cursor: grab;
    transition: background 0.12s ease, transform 0.12s ease, box-shadow 0.12s ease;
    user-select: none;
    /* Línea sutil para que se vea reordenable */
    border: 1px solid transparent;
}
.col-item:hover { background: var(--color-surface-hover, #F5F5F5); }
.col-item:active { cursor: grabbing; }

.col-item--locked { cursor: not-allowed; opacity: 0.7; }
.col-item--locked:hover { background: transparent; }

/* Pinned: ancladas a un extremo, no se reordenan. */
.col-item--pinned {
    cursor: default;
    background: var(--color-surface-alt, #fafafa);
}
.col-item--pinned:hover { background: var(--color-surface-alt, #fafafa); }
.col-item--pinned .col-item__label { cursor: default; }

.col-item__pin {
    color: #0A6ED1;
    font-size: 0.9rem;
}
.col-item__pinned-tag {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #0A6ED1;
    background: #E6F1FB;
    padding: 2px 6px;
    border-radius: 2px;
    font-weight: 600;
}

/* Estados de drag */
.col-item--dragging {
    opacity: 0.5;
    background: #E6F4FF;
}
.col-item--over {
    border-color: #0A6ED1;
    background: #F0F8FF;
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(10, 110, 209, 0.15);
}

.col-item__drag {
    color: #c0c0c0;
    font-size: 0.95rem;
    cursor: grab;
}
.col-item__label {
    flex: 1;
    color: #32363A;
    font-weight: 500;
    font-size: 0.9rem;
    cursor: pointer;
}
.col-item--locked .col-item__label { cursor: not-allowed; }

.col-item__lock {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #8c8c8c;
    background: var(--color-surface-hover, #F0F0F0);
    padding: 2px 6px;
    border-radius: 2px;
}
</style>

<style>
html[data-theme="dark"] .col-counter         { color: #a8aaae; }
html[data-theme="dark"] .col-help            { color: #a8aaae; }
html[data-theme="dark"] .col-actions         { border-bottom-color: #3f4448; }
html[data-theme="dark"] .col-item:hover      { background: #313a44; }
html[data-theme="dark"] .col-item__label     { color: #e5e6e7; }
html[data-theme="dark"] .col-item__drag      { color: #6A6D70; }
html[data-theme="dark"] .col-item__lock      { background: #2c3034; color: #a8aaae; }
html[data-theme="dark"] .col-item--dragging  { background: #1e3a5f; }
html[data-theme="dark"] .col-item--over      { background: #1a2c4a; border-color: #4d9cf2; }
html[data-theme="dark"] .col-item--pinned         { background: #2a3038; }
html[data-theme="dark"] .col-item--pinned:hover   { background: #2a3038; }
html[data-theme="dark"] .col-item__pin            { color: #4d9cf2; }
html[data-theme="dark"] .col-item__pinned-tag     { background: #1a2c4a; color: #4d9cf2; }
</style>
