<script setup>
import { Tooltip } from 'ant-design-vue';
import {
    EnvironmentFilled, AppstoreFilled, ClusterOutlined,
    PlusOutlined, EditOutlined, DeleteOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

/**
 * HierarchyNode — nodo recursivo del árbol de la jerarquía del cliente.
 *
 * Se renderiza a sí mismo para los hijos (Ubicación → Área → Subestación). Las
 * acciones (agregar hijo, renombrar, borrar) se delegan a `handlers` (pasados
 * desde el árbol contenedor), así funcionan a cualquier profundidad sin
 * burbujeo de eventos. Agregar un nivel = una entrada en los mapas de config,
 * sin tocar el render.
 *
 * Tenía un cuarto nivel «transformador» del sistema viejo: enlazaba a una ruta
 * que ya no existe y pintaba junto a cada subestación la clave sin traducir
 * `customers.transformers_count`. Purgado.
 */
const props = defineProps({
    node:         { type: Object, required: true },
    canEdit:      { type: Boolean, default: false },
    handlers:     { type: Object, required: true }, // { add, rename, remove }
    // false cuando este nodo es el único hijo de su padre (no se puede borrar).
    canDeleteNode: { type: Boolean, default: true },
});

const { t } = useI18n();

// Config por tipo de nodo (escalable: el render no conoce los niveles).
const ICONS = {
    location: EnvironmentFilled,
    area: AppstoreFilled,
    substation: ClusterOutlined,
};
const CHILD_LEVEL = { location: 'area', area: 'substation' }; // la subestación es la hoja
const ADD_LABEL = { location: 'customers.add_area', area: 'customers.add_substation' };

const icon = (type) => ICONS[type] ?? AppstoreFilled;
const canAddChild = (type) => !!CHILD_LEVEL[type];
</script>

<template>
    <li class="hnode" :class="`hnode--${node.type}`">
        <div class="hnode__row">
            <component :is="icon(node.type)" class="hnode__icon" />

            <span class="hnode__name">{{ node.name }}</span>

            <!-- Las acciones NO se esconden tras el hover: en una tablet no hay
                 hover y quedaban invisibles. Se atenúan y se realzan al tocar. -->
            <span v-if="canEdit" class="hnode__actions">
                <Tooltip v-if="canAddChild(node.type)" :title="$t(ADD_LABEL[node.type])">
                    <button class="hbtn hbtn--add" :aria-label="$t(ADD_LABEL[node.type])" @click="handlers.add(node)"><PlusOutlined /></button>
                </Tooltip>
                <Tooltip :title="$t('global.edit')">
                    <button class="hbtn" :aria-label="$t('global.edit')" @click="handlers.rename(node)"><EditOutlined /></button>
                </Tooltip>
                <Tooltip :title="canDeleteNode ? $t('global.delete') : $t('customers.cannot_delete_last_hint')">
                    <button
                        class="hbtn hbtn--danger"
                        :class="{ 'hbtn--disabled': !canDeleteNode }"
                        :disabled="!canDeleteNode"
                        :aria-label="canDeleteNode ? $t('global.delete') : $t('customers.cannot_delete_last_hint')"
                        @click="canDeleteNode && handlers.remove(node)"
                    ><DeleteOutlined /></button>
                </Tooltip>
            </span>
        </div>

        <ul v-if="node.children && node.children.length" class="hnode__children">
            <HierarchyNode
                v-for="child in node.children"
                :key="`${child.type}-${child.id}`"
                :node="child"
                :can-edit="canEdit"
                :handlers="handlers"
                :can-delete-node="node.children.length > 1"
            />
        </ul>
    </li>
</template>

<style scoped>
.hnode { list-style: none; position: relative; }
.hnode__children { list-style: none; margin: 0; padding: 0 0 0 28px; position: relative; }
.hnode__children::before {
    content: ''; position: absolute; left: 11px; top: 0; bottom: 14px; width: 1px;
    background: var(--color-border, #e0e0e0);
}
.hnode__row {
    display: flex; align-items: center; gap: 9px;
    padding: 7px 8px; border-radius: 8px;
    transition: background 0.12s ease;
}
.hnode__row:hover { background: var(--color-surface-alt, #f5f7fa); }
.hnode__icon { font-size: 0.95rem; }
.hnode--location > .hnode__row > .hnode__icon { color: #0A6ED1; }
.hnode--area > .hnode__row > .hnode__icon { color: #6A6D70; }
.hnode--substation > .hnode__row > .hnode__icon { color: #8254c8; }
.hnode__name { font-weight: 500; color: var(--color-text, #1f2937); }

/* Visibles siempre (a media opacidad) y a tamaño de dedo con guante: 44 px,
   que es el minimo de `docs/UI.md §3`. Con `opacity: 0` + `:hover` las tres
   acciones de la estructura no existian en una tablet. */
.hnode__actions { display: inline-flex; gap: 2px; margin-left: 6px; opacity: 0.55; transition: opacity 0.12s ease; }
.hnode__row:hover .hnode__actions,
.hnode__actions:focus-within { opacity: 1; }
.hbtn {
    border: none; background: transparent; cursor: pointer;
    width: 44px; height: 44px; border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #6A6D70; font-size: 0.95rem; transition: all 0.12s ease;
}
.hbtn:hover { background: rgba(10,110,209,0.1); color: #0A6ED1; }
.hbtn--add:hover { background: rgba(29,112,68,0.12); color: #1D7044; }
.hbtn--danger:hover { background: rgba(200,40,29,0.1); color: #C8281D; }
.hbtn--disabled { opacity: 0.3; cursor: not-allowed; }
.hbtn--disabled:hover { background: transparent; color: #6A6D70; }

html[data-theme="dark"] .hnode__row:hover { background: #2c3034; }
html[data-theme="dark"] .hnode__name { color: #e5e6e7; }
html[data-theme="dark"] .hnode__children::before { background: #3f4448; }
</style>
