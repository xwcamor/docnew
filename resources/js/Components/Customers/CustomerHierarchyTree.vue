<script setup>
import { reactive, computed, h } from 'vue';
import { router } from '@inertiajs/vue3';
import { Modal, Input, Button, Empty, notification } from 'ant-design-vue';
import { PlusOutlined, EnvironmentOutlined } from '@ant-design/icons-vue';
import HierarchyNode from './HierarchyNode.vue';
import { useI18n } from '@/Plugins/i18n';

/**
 * CustomerHierarchyTree — árbol interactivo de la jerarquía del cliente.
 *
 * Crea/renombra/borra Ubicaciones, Áreas y Subestaciones vía modales (popups),
 * sin cambiar de página: cada acción hace un request Inertia que vuelve al
 * "ver" del cliente y refresca el árbol. El render es recursivo (HierarchyNode).
 */
const props = defineProps({
    customer:  { type: Object, required: true },   // { id, slug }
    hierarchy: { type: Object, required: true },   // { nodes, totals }
    canEdit:   { type: Boolean, default: false },
});

const { t } = useI18n();

const NEW_TITLE    = { location: 'customers.new_location', area: 'customers.new_area', substation: 'customers.new_substation' };
const RENAME_TITLE = { location: 'customers.rename_location', area: 'customers.rename_area', substation: 'customers.rename_substation' };
const CHILD_LEVEL  = { customer: 'location', location: 'area', area: 'substation' };

const modal = reactive({
    open: false, mode: 'create', level: 'location',
    parentId: null, nodeId: null, name: '', title: '', error: '', saving: false,
});

// Modal de borrado con motivo.
const del = reactive({ open: false, node: null, reason: '', error: '', saving: false });

const openCreate = (parentNode) => {
    const parentType = parentNode?.type ?? 'customer';
    const level = CHILD_LEVEL[parentType];
    if (!level) return;
    Object.assign(modal, {
        open: true, mode: 'create', level,
        parentId: parentNode ? parentNode.id : props.customer.id,
        nodeId: null, name: '', error: '', title: t(NEW_TITLE[level]),
    });
};

const handlers = {
    add: (node) => openCreate(node),
    rename: (node) => Object.assign(modal, {
        open: true, mode: 'rename', level: node.type, nodeId: node.id,
        parentId: null, name: node.name, error: '', title: t(RENAME_TITLE[node.type]),
    }),
    remove: (node) => {
        Object.assign(del, { open: true, node, reason: '', error: '', saving: false });
    },
};

// Busca recursivamente un nodo {type,id} en la jerarquía.
const nodeInHierarchy = (nodes, type, id) => {
    for (const n of nodes) {
        if (n.type === type && n.id === id) return true;
        if (n.children && nodeInHierarchy(n.children, type, id)) return true;
    }
    return false;
};

const confirmDelete = () => {
    const node = del.node;
    if (!node) return;
    const reason = del.reason.trim();
    if (!reason) { del.error = t('customers.delete_reason_required'); return; }
    del.error = '';
    del.saving = true;
    router.delete(
        route('business_management.customers.hierarchy.destroy', [props.customer.slug, node.type, node.id]),
        {
            data: { reason },
            preserveScroll: true,
            // Mostrar UNDO solo si el nodo REALMENTE se borró: tras recargar, si
            // ya no está en la jerarquía es que se eliminó; si sigue, la
            // invariante lo bloqueó (no se borra el último). Robusto, sin
            // depender del flash (que el toast global limpia en paralelo).
            onSuccess: (page) => {
                del.open = false;
                const stillThere = nodeInHierarchy(page?.props?.hierarchy?.nodes ?? [], node.type, node.id);
                if (!stillThere) {
                    showUndo(node);
                }
            },
            onFinish: () => { del.saving = false; },
        },
    );
};

// Toast de UNDO: mismo estilo que el borrado de modulos (abajo a la derecha,
// boton primario). Restaura el nodo recien borrado.
const UNDO_SECONDS = 10;
const showUndo = (node) => {
    const key = `undo-${node.type}-${node.id}`;
    notification.info({
        key,
        message: t('global.deleted_undoable', { seconds: UNDO_SECONDS }),
        description: node.name,
        placement: 'bottomRight',
        duration: UNDO_SECONDS,
        btn: () => h('button', {
            onClick: () => {
                router.post(
                    route('business_management.customers.hierarchy.restore', [props.customer.slug, node.type, node.id]),
                    {},
                    { preserveScroll: true, onSuccess: () => notification.close(key) },
                );
            },
            style: 'background:var(--color-primary);color:var(--color-text-on-dark);border:0;padding:6px 14px;border-radius:4px;cursor:pointer;font-weight:500;font-size:0.8rem;',
        }, t('global.undo')),
    });
};

const submit = () => {
    const name = modal.name.trim();
    if (!name) { modal.error = t('customers.node_name_required'); return; }
    modal.saving = true;
    const opts = {
        preserveScroll: true,
        onSuccess: () => { modal.open = false; },
        onError: (errors) => { modal.error = errors.name || errors.level || ''; },
        onFinish: () => { modal.saving = false; },
    };
    if (modal.mode === 'create') {
        router.post(
            route('business_management.customers.hierarchy.store', props.customer.slug),
            { level: modal.level, parent_id: modal.parentId, name },
            opts,
        );
    } else {
        router.put(
            route('business_management.customers.hierarchy.update', [props.customer.slug, modal.level, modal.nodeId]),
            { name },
            opts,
        );
    }
};

const Textarea = Input.TextArea;
const isEmpty = computed(() => !props.hierarchy?.nodes?.length);
</script>

<template>
    <div class="htree">
        <div v-if="isEmpty" class="htree__empty">
            <Empty :description="$t('customers.empty_hierarchy')" />
            <Button v-if="canEdit" type="primary" @click="openCreate(null)">
                <template #icon><PlusOutlined /></template>
                {{ $t('customers.add_location') }}
            </Button>
        </div>

        <template v-else>
            <div class="htree__toolbar" v-if="canEdit">
                <Button size="small" @click="openCreate(null)">
                    <template #icon><EnvironmentOutlined /></template>
                    {{ $t('customers.add_location') }}
                </Button>
            </div>
            <ul class="htree__root">
                <HierarchyNode
                    v-for="node in hierarchy.nodes"
                    :key="`${node.type}-${node.id}`"
                    :node="node"
                    :can-edit="canEdit"
                    :handlers="handlers"
                    :can-delete-node="hierarchy.nodes.length > 1"
                />
            </ul>
        </template>

        <!-- Modal crear / renombrar -->
        <Modal
            v-model:open="modal.open"
            :title="modal.title"
            :confirm-loading="modal.saving"
            :ok-text="$t('global.save')"
            :cancel-text="$t('global.cancel')"
            :mask-closable="false"
            :keyboard="false"
            @ok="submit"
        >
            <div class="hmodal">
                <label class="hmodal__label">{{ $t('customers.node_name') }}</label>
                <Input
                    v-model:value="modal.name"
                    size="large"
                    :maxlength="150"
                    autofocus
                    :status="modal.error ? 'error' : ''"
                    @pressEnter="submit"
                />
                <div v-if="modal.error" class="hmodal__error">{{ modal.error }}</div>
            </div>
        </Modal>

        <!-- Modal de borrado con motivo (obligatorio) -->
        <Modal
            v-model:open="del.open"
            :title="$t('customers.delete_node_confirm', { name: del.node?.name ?? '' })"
            :confirm-loading="del.saving"
            :ok-text="$t('global.delete')"
            :ok-type="'danger'"
            :cancel-text="$t('global.cancel')"
            :mask-closable="false"
            :keyboard="false"
            @ok="confirmDelete"
        >
            <div class="hmodal">
                <label class="hmodal__label">{{ $t('customers.delete_reason') }} *</label>
                <Textarea
                    v-model:value="del.reason"
                    :rows="3"
                    :maxlength="500"
                    :status="del.error ? 'error' : ''"
                    :placeholder="$t('customers.delete_reason_placeholder')"
                />
                <div v-if="del.error" class="hmodal__error">{{ del.error }}</div>
            </div>
        </Modal>
    </div>
</template>

<style scoped>
.htree__empty { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 28px 0; }
.htree__toolbar { margin-bottom: 10px; }
.htree__root { list-style: none; margin: 0; padding: 0; }
.hmodal__label { display: block; font-size: 0.85rem; color: var(--color-text-muted, #6A6D70); margin-bottom: 6px; }
.hmodal__error { color: #C8281D; font-size: 0.8rem; margin-top: 6px; }
</style>
