<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import {
    Dropdown, Menu, MenuItem, MenuDivider, Button, Modal, Drawer,
    Input, Checkbox, Tooltip, Tag, Popconfirm, message, Empty,
} from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();
import {
    BookOutlined, DownOutlined, PlusOutlined, EditOutlined,
    DeleteOutlined, StarFilled, StarOutlined, AppstoreOutlined,
    SaveOutlined, CheckOutlined,
} from '@ant-design/icons-vue';

/**
 * SavedViews — dropdown SAP Fiori-style con vistas guardadas para un módulo.
 *
 * Pensado para vivir en el header del listado, junto al título. El usuario
 * configura sus filtros + columnas + sort y los guarda con un nombre
 * ("Mis activos", "Creadas este mes"). Después aplica la vista con un click.
 *
 * Usage:
 *   <SavedViews
 *       module="regions"
 *       :current-state="currentState"
 *       @apply="onApplyView"
 *       @default-loaded="onDefaultLoaded"
 *   />
 *
 * Props:
 *   - module: string identificador del módulo (regions, users, ...)
 *   - currentState: objeto con el estado actual (filters, columns, sort, ...)
 *                   — el shape lo define el padre, este componente no lo
 *                   interpreta, solo lo guarda y lo emite de vuelta al aplicar.
 *
 * Emits:
 *   - apply(state): cuando el usuario elige una vista guardada (o "Por defecto")
 *   - default-loaded(state): se emite UNA vez al montarse si hay una vista
 *                            marcada como default — el padre puede aplicarla
 *                            al cargar la página.
 */

const props = defineProps({
    module:       { type: String, required: true },
    currentState: { type: Object, required: true },
    // 'dropdown' (default) = botón "Vistas ▾"; 'bar' = tira horizontal de tabs.
    layout:       { type: String, default: 'dropdown' },
    // 'pills' (default) = chips redondos; 'tabs' = tabs sutiles con subrayado.
    variant:      { type: String, default: 'pills' },
    // Chip fijo "Solo favoritos" en la barra (controlado por el padre).
    showFavorites:   { type: Boolean, default: false },
    favoritesActive: { type: Boolean, default: false },
    // Filtros rápidos predefinidos (se ven como el chip de favoritos), en la
    // misma barra. [{ value, label }]; `presetActive` = el valor activo o null.
    presets:         { type: Array, default: () => [] },
    presetActive:    { type: [String, null], default: null },
    // Mostrar el chip "+" (guardar vista) en la barra. Si el padre prefiere
    // ofrecer "Guardar" en otro lado (ej. junto a los filtros), lo apaga y usa
    // el método expuesto openSave().
    showAdd:         { type: Boolean, default: true },
});
const emit = defineEmits(['apply', 'default-loaded', 'toggle-favorites', 'toggle-preset']);

// ─── State ────────────────────────────────────────────────────────────────
const views = ref([]);
const loading = ref(false);
const activeViewId = ref(null);  // null = "Por defecto" (sin vista aplicada)

// Save-current modal
const saveOpen = ref(false);
const saveName = ref('');
const saveAsDefault = ref(false);
const saving = ref(false);

// Manage drawer
const manageOpen = ref(false);
const renamingId = ref(null);
const renameValue = ref('');

const activeView = computed(() =>
    views.value.find(v => v.id === activeViewId.value) ?? null
);

// ─── API calls (usamos window.axios) ─────────────────────────────────────
const fetchViews = async () => {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('saved_views.index'), {
            params: { module: props.module },
        });
        views.value = data.views ?? [];
    } catch (e) {
        message.error('No se pudieron cargar las vistas guardadas.');
    } finally {
        loading.value = false;
    }
};

const submitSave = async () => {
    const name = saveName.value.trim();
    if (!name) {
        message.warning('Ponele un nombre a la vista.');
        return;
    }
    saving.value = true;
    try {
        const { data } = await window.axios.post(route('saved_views.store'), {
            module:     props.module,
            name:       name,
            is_default: saveAsDefault.value,
            state:      props.currentState,
        });
        message.success('Vista guardada.');
        await fetchViews();
        activeViewId.value = data.view.id;
        saveOpen.value = false;
        saveName.value = '';
        saveAsDefault.value = false;
    } catch (e) {
        const msg = e.response?.data?.errors?.name?.[0]
            || e.response?.data?.message
            || 'No se pudo guardar la vista.';
        message.error(msg);
    } finally {
        saving.value = false;
    }
};

const setDefault = async (view) => {
    try {
        await window.axios.put(route('saved_views.update', view.id), {
            name:       view.name,
            is_default: !view.is_default,
            state:      view.state,
        });
        message.success(view.is_default ? 'Vista por defecto removida.' : 'Vista marcada como por defecto.');
        await fetchViews();
    } catch (e) {
        message.error('No se pudo actualizar la vista.');
    }
};

const updateState = async (view) => {
    try {
        await window.axios.put(route('saved_views.update', view.id), {
            name:       view.name,
            is_default: view.is_default,
            state:      props.currentState,
        });
        message.success('Vista actualizada con el estado actual.');
        await fetchViews();
    } catch (e) {
        message.error('No se pudo actualizar la vista.');
    }
};

const submitRename = async (view) => {
    const newName = renameValue.value.trim();
    if (!newName || newName === view.name) {
        renamingId.value = null;
        return;
    }
    try {
        await window.axios.put(route('saved_views.update', view.id), {
            name:       newName,
            is_default: view.is_default,
            state:      view.state,
        });
        await fetchViews();
        renamingId.value = null;
    } catch (e) {
        message.error('No se pudo renombrar.');
    }
};

const deleteView = async (view) => {
    try {
        await window.axios.delete(route('saved_views.destroy', view.id));
        message.success('Vista eliminada.');
        if (activeViewId.value === view.id) {
            activeViewId.value = null;
            emit('apply', null);
        }
        await fetchViews();
    } catch (e) {
        message.error('No se pudo eliminar.');
    }
};

// ─── Apply ────────────────────────────────────────────────────────────────
const applyView = (view) => {
    activeViewId.value = view.id;
    emit('apply', view.state);
};

const applyDefault = () => {
    activeViewId.value = null;
    emit('apply', null);
};

// Chip "Solo favoritos" (variante barra): deselecciona la vista guardada y
// avisa al padre para alternar el filtro de favoritos. Así nunca quedan dos
// chips activos a la vez (comportamiento de tabs).
const onFavoritesChip = () => {
    activeViewId.value = null;
    emit('toggle-favorites');
};

// ─── Mount ────────────────────────────────────────────────────────────────
onMounted(async () => {
    await fetchViews();
    // Si hay una default, emitirla al padre para que se aplique al cargar.
    const def = views.value.find(v => v.is_default);
    if (def) {
        activeViewId.value = def.id;
        emit('default-loaded', def.state);
    }
});

// Trigger label
const triggerLabel = computed(() => activeView.value?.name ?? t('global.default_view'));

// ─── Barra compacta (pantalla chica): un solo dropdown con todas las opciones ─
// Etiqueta del chip activo (Todos / Solo favoritos / preset / vista guardada).
const activeBarLabel = computed(() => {
    if (props.favoritesActive) return t('global.only_favorites');
    if (props.presetActive) return props.presets.find(p => p.value === props.presetActive)?.label ?? t('global.all');
    if (activeViewId.value != null) return views.value.find(v => v.id === activeViewId.value)?.name ?? t('global.all');
    return t('global.all');
});
// Selección desde el menú compacto → mismo efecto que tocar el chip.
const onCompactSelect = ({ key }) => {
    if (key === '__all') return applyDefault();
    if (key === '__fav') return onFavoritesChip();
    if (key === '__save') { saveOpen.value = true; return; }
    if (key === '__manage') { manageOpen.value = true; return; }
    if (key.startsWith('p:')) return emit('toggle-preset', key.slice(2));
    if (key.startsWith('v:')) {
        const v = views.value.find(view => String(view.id) === key.slice(2));
        if (v) applyView(v);
    }
};

// Permite al padre abrir el modal de "guardar vista" desde otro lugar (ej. un
// botón "Guardar Filtro" junto a los filtros) cuando showAdd está apagado.
defineExpose({ openSave: () => { saveOpen.value = true; } });
</script>

<template>
    <!-- Variante BARRA: tabs horizontales (atajos de resultados). -->
    <div v-if="layout === 'bar'" class="sv-bar" :class="{ 'sv-bar--tabs': variant === 'tabs' }">
        <!-- Fijos (no borrables): Todos + (opcional) Solo favoritos. -->
        <button
            type="button"
            class="sv-chip"
            :class="{ 'sv-chip--active': activeViewId === null && !favoritesActive && !presetActive }"
            @click="applyDefault"
        >
            {{ $t('global.all') }}
        </button>
        <button
            v-if="showFavorites"
            type="button"
            class="sv-chip"
            :class="{ 'sv-chip--active': favoritesActive }"
            @click="onFavoritesChip"
        >
            <StarFilled v-if="favoritesActive" class="sv-chip__star" />
            <StarOutlined v-else class="sv-chip__star" />
            {{ $t('global.only_favorites') }}
        </button>
        <!-- Filtros rápidos (mismo estilo que favoritos). -->
        <button
            v-for="p in presets"
            :key="p.value"
            type="button"
            class="sv-chip"
            :class="{ 'sv-chip--active': presetActive === p.value }"
            @click="$emit('toggle-preset', presetActive === p.value ? null : p.value)"
        >
            {{ p.label }}
        </button>
        <!-- Separador entre fijos (sistema) y vistas del usuario. -->
        <span v-if="views.length > 0" class="sv-sep"></span>
        <!-- Guardadas por el usuario (gestionables). -->
        <button
            v-for="view in views"
            :key="view.id"
            type="button"
            class="sv-chip"
            :class="{ 'sv-chip--active': activeViewId === view.id && !favoritesActive && !presetActive }"
            @click="applyView(view)"
        >
            <StarFilled v-if="view.is_default" class="sv-chip__star" />
            {{ view.name }}
        </button>
        <Tooltip v-if="showAdd" :title="$t('global.save_current_view')">
            <button type="button" class="sv-chip sv-chip--ghost" @click="saveOpen = true">
                <PlusOutlined />
            </button>
        </Tooltip>
        <Tooltip v-if="views.length > 0" :title="$t('global.manage_views')">
            <button type="button" class="sv-chip sv-chip--ghost" @click="manageOpen = true">
                <AppstoreOutlined />
            </button>
        </Tooltip>
    </div>

    <!-- Barra COMPACTA (pantalla chica): un solo dropdown con Todos / Favoritos /
         presets / vistas guardadas, en lugar de los chips en varias líneas. Se
         alterna con .sv-bar vía CSS (media query), sin lógica de viewport en JS. -->
    <span v-if="layout === 'bar'" class="sv-barcompact">
        <Dropdown :trigger="['click']" overlayClassName="saved-views-overlay">
            <button type="button" class="sv-compact-btn">
                <span class="sv-compact-btn__label">{{ activeBarLabel }}</span>
                <DownOutlined class="sv-compact-btn__caret" />
            </button>
            <template #overlay>
                <Menu :selected-keys="[]" @click="onCompactSelect">
                    <MenuItem key="__all">{{ $t('global.all') }}</MenuItem>
                    <MenuItem v-if="showFavorites" key="__fav">
                        <StarFilled v-if="favoritesActive" /><StarOutlined v-else /> {{ $t('global.only_favorites') }}
                    </MenuItem>
                    <MenuItem v-for="p in presets" :key="'p:' + p.value">{{ p.label }}</MenuItem>
                    <MenuDivider v-if="views.length > 0" />
                    <MenuItem v-for="view in views" :key="'v:' + view.id">
                        <StarFilled v-if="view.is_default" /> {{ view.name }}
                    </MenuItem>
                    <MenuDivider v-if="showAdd || views.length > 0" />
                    <MenuItem v-if="showAdd" key="__save">
                        <PlusOutlined /> {{ $t('global.save_current_view') }}
                    </MenuItem>
                    <MenuItem v-if="views.length > 0" key="__manage">
                        <AppstoreOutlined /> {{ $t('global.manage_views') }}
                    </MenuItem>
                </Menu>
            </template>
        </Dropdown>
    </span>

    <Dropdown v-else :trigger="['click']" overlayClassName="saved-views-overlay">
        <Tooltip :title="$t('global.saved_views_hint')">
            <Button class="saved-views-trigger">
                <BookOutlined />
                <span class="saved-views-trigger__kicker">{{ $t('global.views') }}</span>
                <span class="saved-views-trigger__sep">·</span>
                <span class="saved-views-trigger__label">{{ triggerLabel }}</span>
                <DownOutlined class="saved-views-trigger__chev" />
            </Button>
        </Tooltip>
        <template #overlay>
            <Menu class="saved-views-menu">
                <MenuItem key="__default" @click="applyDefault">
                    <span class="sv-item">
                        <span class="sv-item__name">{{ $t('global.default_view') }}</span>
                        <CheckOutlined v-if="activeViewId === null" class="sv-item__check" />
                    </span>
                </MenuItem>

                <MenuDivider v-if="views.length > 0" />

                <MenuItem
                    v-for="view in views"
                    :key="view.id"
                    @click="applyView(view)"
                >
                    <span class="sv-item">
                        <StarFilled v-if="view.is_default" class="sv-item__star" />
                        <span class="sv-item__name">{{ view.name }}</span>
                        <CheckOutlined v-if="activeViewId === view.id" class="sv-item__check" />
                    </span>
                </MenuItem>

                <MenuDivider />

                <MenuItem key="__save" @click="saveOpen = true">
                    <PlusOutlined /> {{ $t('global.save_current_view') }}
                </MenuItem>
                <MenuItem key="__manage" :disabled="views.length === 0" @click="manageOpen = true">
                    <AppstoreOutlined /> {{ $t('global.manage_views') }} ({{ views.length }})
                </MenuItem>
            </Menu>
        </template>
    </Dropdown>

    <!-- ── Modal: Guardar vista actual ────────────────────────────────── -->
    <Modal
        v-model:open="saveOpen"
        :title="$t('global.save_current_view')"
        :confirm-loading="saving"
        :ok-text="$t('global.save')"
        :cancel-text="$t('global.cancel')"
        @ok="submitSave"
    >
        <p class="sv-modal-help">{{ $t('global.save_view_help') }}</p>

        <label class="sv-label">{{ $t('regions.name') }}</label>
        <Input
            v-model:value="saveName"
            :placeholder="$t('global.save_view_name_placeholder')"
            :maxlength="120"
            show-count
            size="large"
            @press-enter="submitSave"
        />

        <Checkbox v-model:checked="saveAsDefault" class="sv-default-check">
            {{ $t('global.set_as_default_hint') }}
        </Checkbox>
    </Modal>

    <!-- ── Drawer: Administrar vistas ──────────────────────────────────── -->
    <Drawer
        v-model:open="manageOpen"
        :title="$t('global.saved_views')"
        :width="420"
        placement="right"
    >
        <Empty v-if="views.length === 0" :description="$t('global.no_saved_views')">
            <Button type="primary" @click="manageOpen = false; saveOpen = true">
                <PlusOutlined /> {{ $t('global.save_current_view') }}
            </Button>
        </Empty>

        <ul v-else class="sv-manage-list">
            <li v-for="view in views" :key="view.id" class="sv-manage-item">
                <div class="sv-manage-item__main">
                    <Tooltip :title="view.is_default ? $t('global.remove_default') : $t('global.set_as_default')">
                        <button class="sv-star-btn" @click="setDefault(view)">
                            <StarFilled v-if="view.is_default" style="color: #F0B100" />
                            <StarOutlined v-else style="color: #b8c5d0" />
                        </button>
                    </Tooltip>

                    <Input
                        v-if="renamingId === view.id"
                        v-model:value="renameValue"
                        :maxlength="120"
                        size="small"
                        autofocus
                        class="sv-rename-input"
                        @press-enter="submitRename(view)"
                        @blur="submitRename(view)"
                    />
                    <span v-else class="sv-manage-item__name">{{ view.name }}</span>
                </div>

                <div class="sv-manage-item__actions">
                    <Tooltip title="Renombrar">
                        <Button
                            size="small"
                            type="text"
                            @click="renamingId = view.id; renameValue = view.name"
                        >
                            <EditOutlined />
                        </Button>
                    </Tooltip>
                    <Tooltip title="Sobrescribir con el estado actual">
                        <Popconfirm
                            title="¿Sobrescribir esta vista con el estado actual?"
                            ok-text="Sobrescribir"
                            cancel-text="Cancelar"
                            @confirm="updateState(view)"
                        >
                            <Button size="small" type="text">
                                <SaveOutlined />
                            </Button>
                        </Popconfirm>
                    </Tooltip>
                    <Popconfirm
                        :title="`¿Eliminar la vista \&quot;${view.name}\&quot;?`"
                        ok-text="Eliminar"
                        cancel-text="Cancelar"
                        :ok-button-props="{ danger: true }"
                        @confirm="deleteView(view)"
                    >
                        <Button size="small" type="text" danger>
                            <DeleteOutlined />
                        </Button>
                    </Popconfirm>
                </div>
            </li>
        </ul>
    </Drawer>
</template>

<style scoped>
/* ── Variante BARRA (tabs de vistas / atajos) ──────────────────────────── */
.sv-bar { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

/* Barra compacta (dropdown): oculta en desktop, visible solo en pantalla chica.
   Reemplaza los chips multilínea por un único desplegable. */
.sv-barcompact { display: none; }
.sv-compact-btn {
    display: inline-flex; align-items: center; gap: 8px;
    height: 32px; padding: 0 12px; border-radius: 8px;
    background: var(--color-surface, #fff); border: 1px solid var(--color-border, #d6dde6);
    color: var(--color-text, #32363a); font-size: 0.85rem; font-weight: 600; cursor: pointer;
}
.sv-compact-btn__caret { font-size: 0.7rem; color: var(--color-text-muted, #8a9099); }
@media (max-width: 640px) {
    .sv-bar { display: none; }
    .sv-barcompact { display: inline-flex; }
}
.sv-chip {
    display: inline-flex; align-items: center; gap: 5px;
    height: 30px; padding: 0 12px;
    border: 1px solid var(--color-border, #e3e6ea);
    border-radius: 999px;
    background: var(--color-surface, #fff);
    color: var(--color-text, #475467);
    font-size: 0.82rem; font-weight: 500; cursor: pointer; white-space: nowrap;
    transition: background 0.14s ease, border-color 0.14s ease, color 0.14s ease;
}
.sv-chip:hover { border-color: var(--color-text-muted, #c4c9d0); color: var(--color-text-strong, #1f2329); }
.sv-chip--active {
    background: var(--color-primary, #2f6df6);
    border-color: var(--color-primary, #2f6df6);
    color: #fff;
}
.sv-chip--active:hover { color: #fff; }
.sv-chip__star { font-size: 0.72rem; color: #f4b400; }
.sv-chip--active .sv-chip__star { color: #ffe08a; }
.sv-chip--ghost { width: 30px; padding: 0; justify-content: center; color: var(--color-text-muted, #8a9099); }

/* ─── Variante TABS (sutil, subrayado) — rediseño de jerarquía ──────────────
   Las vistas dejan de competir con el buscador: pasan a ser tabs livianas con
   indicador inferior. Activa = texto azul + subrayado; inactiva = texto gris
   sin borde ni relleno. */
.sv-bar--tabs { gap: 2px; }
.sv-bar--tabs .sv-chip {
    height: 30px; padding: 0 10px;
    border: 0; border-radius: 7px;
    background: transparent; color: var(--color-text-muted, #6b7280);
    font-weight: 500; position: relative;
}
.sv-bar--tabs .sv-chip:hover {
    background: rgba(16, 24, 40, 0.04); color: var(--color-text-strong, #1f2329);
}
.sv-bar--tabs .sv-chip--active {
    background: transparent; color: var(--color-primary, #2f6df6); font-weight: 600;
}
.sv-bar--tabs .sv-chip--active::after {
    content: ''; position: absolute; left: 10px; right: 10px; bottom: -8px; height: 2px;
    background: var(--color-primary, #2f6df6); border-radius: 2px;
}
.sv-bar--tabs .sv-chip--active:hover { background: rgba(47, 109, 246, 0.06); }
.sv-bar--tabs .sv-chip--ghost { color: var(--color-text-muted, #9aa0a6); }
.sv-bar--tabs .sv-chip--ghost:hover { color: var(--color-primary, #2f6df6); }
.sv-bar--tabs .sv-chip--active .sv-chip__star { color: #f4b400; }
/* Separador entre fijos del sistema y vistas del usuario. */
.sv-sep { width: 1px; height: 18px; background: var(--color-border, #e3e6ea); margin: 0 6px; flex: none; }

.saved-views-trigger {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 38px;
    border-radius: 4px;
    font-weight: 500;
}
.saved-views-trigger__kicker {
    font-weight: 600;
    color: var(--color-text-strong, #32363A);
}
.saved-views-trigger__sep {
    color: #c2c8cf;
    margin: 0 -2px;
}
.saved-views-trigger__label {
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--color-text-muted, #6A6D70);
}
.saved-views-trigger__chev {
    font-size: 0.7rem;
    color: #6A6D70;
    margin-left: 2px;
}

/* Modal */
.sv-modal-help {
    color: #6A6D70;
    font-size: 0.875rem;
    margin: 0 0 14px 0;
    line-height: 1.5;
}
.sv-label {
    display: block;
    font-size: 0.78rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.sv-default-check {
    margin-top: 14px;
    font-size: 0.875rem;
}

/* Manage drawer */
.sv-manage-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.sv-manage-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 6px;
    transition: background 0.12s ease;
}
.sv-manage-item:hover {
    background: #F8FAFC;
}
.sv-manage-item__main {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 0;
}
.sv-manage-item__name {
    font-size: 0.875rem;
    color: #32363A;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.sv-manage-item__actions {
    display: inline-flex;
    gap: 2px;
    flex-shrink: 0;
}
.sv-star-btn {
    background: transparent;
    border: 0;
    cursor: pointer;
    width: 28px;
    height: 28px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.12s ease;
}
.sv-star-btn:hover { background: rgba(240, 177, 0, 0.10); }
.sv-rename-input { flex: 1; }
</style>

<style>
/* Dropdown items styling (NOT scoped — el portal vive afuera) */
.saved-views-overlay .ant-dropdown-menu {
    border-radius: 6px !important;
    padding: 6px !important;
    min-width: 220px;
    box-shadow: 0 8px 28px rgba(15, 23, 42, 0.18) !important;
}
.saved-views-menu .ant-dropdown-menu-item {
    border-radius: 4px !important;
    padding: 8px 12px !important;
    font-size: 0.875rem;
}
.saved-views-menu .ant-dropdown-menu-title-content {
    display: block;
    width: 100%;
}

.sv-item {
    display: flex;
    align-items: center;
    width: 100%;
    gap: 8px;
}
.sv-item__name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.sv-item__star {
    color: #F0B100;
    font-size: 0.85rem;
}
.sv-item__check {
    color: #0A6ED1;
    font-size: 0.85rem;
}

/* Dark mode */
html[data-theme="dark"] .saved-views-overlay .ant-dropdown-menu {
    background: #2c3034 !important;
}
html[data-theme="dark"] .saved-views-menu .ant-dropdown-menu-item {
    color: #e5e6e7 !important;
}
html[data-theme="dark"] .saved-views-menu .ant-dropdown-menu-item:hover {
    background: #313a44 !important;
    color: #4db6e8 !important;
}
html[data-theme="dark"] .sv-item__check { color: #4db6e8; }
html[data-theme="dark"] .sv-manage-item:hover { background: #313a44; }
html[data-theme="dark"] .sv-manage-item__name { color: #e5e6e7; }
</style>
