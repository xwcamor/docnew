<script setup>
import { Link } from '@inertiajs/vue3';
import { Tooltip } from 'ant-design-vue';
import {
    EnvironmentFilled, AppstoreFilled, ClusterOutlined, ThunderboltFilled,
    PlusOutlined, EditOutlined, DeleteOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import { useConditions } from '@/Composables/useConditions';

/**
 * HierarchyNode — nodo recursivo del árbol de la jerarquía del cliente.
 *
 * Se renderiza a sí mismo para los hijos (Ubicación → Área → Subestación →
 * Transformador). Las acciones (agregar hijo, renombrar, borrar) se delegan a
 * `handlers` (pasados desde el árbol contenedor), así funcionan a cualquier
 * profundidad sin burbujeo de eventos. Agregar un nivel = una entrada en los
 * mapas de config, sin tocar el render.
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
    transformer: ThunderboltFilled,
};
const CHILD_LEVEL = { location: 'area', area: 'substation' }; // substation: trafos via módulo
const ADD_LABEL = { location: 'customers.add_area', area: 'customers.add_substation' };

const icon = (type) => ICONS[type] ?? AppstoreFilled;
const canAddChild = (type) => !!CHILD_LEVEL[type];

const { ratingLabel } = useConditions();

const healthColor = (hi) => {
    if (hi === null || hi === undefined) return '#9aa0a6';
    const v = Number(hi);
    if (v > 85) return '#1D7044';
    if (v > 70) return '#3FA66A';
    if (v > 50) return '#E9A23B';
    if (v > 30) return '#E8833A';
    return '#C8281D';
};
</script>

<template>
    <li class="hnode" :class="`hnode--${node.type}`">
        <div class="hnode__row">
            <component :is="icon(node.type)" class="hnode__icon" />

            <!-- Transformador: hoja con link al detalle -->
            <template v-if="node.type === 'transformer'">
                <Link
                    :href="route('business_management.transformers.show', node.slug)"
                    class="hnode__name hnode__name--link"
                >
                    {{ node.name }}
                </Link>
                <span class="hnode__health" :style="{ background: healthColor(node.health_index) }"></span>
                <span v-if="node.health_rating !== null && node.health_rating !== undefined" class="hnode__health-label">{{ ratingLabel(node.health_rating) }}</span>
            </template>

            <!-- Nodo de estructura -->
            <template v-else>
                <span class="hnode__name">{{ node.name }}</span>
                <span v-if="node.type === 'substation'" class="hnode__count">
                    {{ $tc('customers.transformers_count', node.count ?? 0) }}
                </span>

                <span v-if="canEdit" class="hnode__actions">
                    <Tooltip v-if="canAddChild(node.type)" :title="$t(ADD_LABEL[node.type])">
                        <button class="hbtn hbtn--add" @click="handlers.add(node)"><PlusOutlined /></button>
                    </Tooltip>
                    <Tooltip :title="$t('global.edit')">
                        <button class="hbtn" @click="handlers.rename(node)"><EditOutlined /></button>
                    </Tooltip>
                    <Tooltip :title="canDeleteNode ? $t('global.delete') : $t('customers.cannot_delete_last_hint')">
                        <button
                            class="hbtn hbtn--danger"
                            :class="{ 'hbtn--disabled': !canDeleteNode }"
                            :disabled="!canDeleteNode"
                            @click="canDeleteNode && handlers.remove(node)"
                        ><DeleteOutlined /></button>
                    </Tooltip>
                </span>
            </template>
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
.hnode--transformer > .hnode__row > .hnode__icon { color: #E9A23B; }
.hnode__name { font-weight: 500; color: var(--color-text, #1f2937); }
.hnode__name--link { color: #0A6ED1; text-decoration: none; }
.hnode__name--link:hover { text-decoration: underline; }
.hnode__count { font-size: 0.78rem; color: var(--color-text-muted, #6A6D70); }
.hnode__health { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
.hnode__health-label { font-size: 0.78rem; color: var(--color-text-muted, #6A6D70); }

.hnode__actions { display: inline-flex; gap: 2px; margin-left: 6px; opacity: 0; transition: opacity 0.12s ease; }
.hnode__row:hover .hnode__actions { opacity: 1; }
.hbtn {
    border: none; background: transparent; cursor: pointer;
    width: 26px; height: 26px; border-radius: 6px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #6A6D70; font-size: 0.82rem; transition: all 0.12s ease;
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
