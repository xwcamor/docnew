<script setup>
/**
 * EntityShowActions — botones del header del Show page (Audit + Edit + Delete).
 *
 * Reemplaza el copy-paste de los Show.vue de cada módulo.
 *
 * Uso:
 *   <SectionHeader ...>
 *       <template #actions>
 *           <EntityShowActions
 *               module="regions"
 *               :slug="region.slug"
 *               :id="region.id"
 *               :is-deleted="isDeleted"
 *               :can-edit="can('regions.edit')"
 *               :can-delete="can('regions.delete')"
 *               :can-see-audit="canSeeAudit"
 *           />
 *       </template>
 *   </SectionHeader>
 *
 * Tooltips y efectos hover heredados (global CSS + Tooltip de Antd).
 */
import { computed } from 'vue';
import { Button, Tag, Tooltip, Popconfirm } from 'ant-design-vue';
import { Link, router } from '@inertiajs/vue3';
import {
    EditOutlined, DeleteOutlined, LockOutlined, UnlockOutlined,
} from '@ant-design/icons-vue';

const props = defineProps({
    module:        { type: String,  required: true },
    slug:          { type: [String, Number], required: true },
    id:            { type: [String, Number], default: null }, // para filtrar el audit log
    isDeleted:     { type: Boolean, default: false },
    canEdit:       { type: Boolean, default: false },
    canDelete:     { type: Boolean, default: false },
    canSeeAudit:   { type: Boolean, default: false },
    // Route prefix — default 'system_management'. Override para módulos en
    // otros clusters (user_management.users.edit, etc.).
    routePrefix:   { type: String,  default: 'system_management' },
    // Cuando canEdit=false porque el registro está protegido (no por permisos
    // ni por estar deleted), pasar la i18n key del label para mostrar Tag en
    // lugar de ocultar todo (ej. 'roles.protected' para rol is_system).
    editProtectedKey: { type: String, default: '' },
    // Registro GLOBAL (workspace = Global, tenant_id null): solo el super lo
    // gestiona. Para el resto se ocultan editar/eliminar (igual que en el índice)
    // y se muestra un tag 'Global'.
    isSuper:  { type: Boolean, default: false },
    isGlobal: { type: Boolean, default: false },
    // Lock por registro (trait Lockable). Objeto del payload:
    // { is_locked, can_lock, can_unlock, lock_scope }. Si está bloqueado se
    // ocultan editar/eliminar y se muestra el candado + (des)bloquear según nivel.
    lock: { type: Object, default: null },
});

const canManageGlobal = computed(() => props.isSuper || !props.isGlobal);
const isLocked  = computed(() => !!props.lock?.is_locked);
const canLock   = computed(() => !!props.lock?.can_lock);
const canUnlock = computed(() => !!props.lock?.can_unlock);
const lockedBySystem = computed(() => props.lock?.lock_scope === 'super');

const routes = computed(() => {
    const base = `${props.routePrefix}.${props.module}`;
    return {
        edit:   `${base}.edit`,
        delete: `${base}.delete`,
        lock:   `${base}.lock`,
        unlock: `${base}.unlock`,
    };
});

const doLock   = () => router.post(route(routes.value.lock, props.slug), {}, { preserveScroll: true });
const doUnlock = () => router.post(route(routes.value.unlock, props.slug), {}, { preserveScroll: true });
</script>

<template>
    <div class="esa">
        <Tooltip v-if="!isDeleted && !isLocked && canEdit && canManageGlobal" :title="$t('global.edit')">
            <Link :href="route(routes.edit, slug)">
                <Button type="primary">
                    <EditOutlined /><span class="esa__label"> {{ $t('global.edit') }}</span>
                </Button>
            </Link>
        </Tooltip>

        <Tooltip v-if="!isDeleted && !isLocked && canDelete && canManageGlobal" :title="$t('global.delete')">
            <Link :href="route(routes.delete, slug)">
                <Button danger>
                    <DeleteOutlined /><span class="esa__label"> {{ $t('global.delete') }}</span>
                </Button>
            </Link>
        </Tooltip>

        <!-- Bloquear: solo si no está bloqueado y el usuario puede. -->
        <Popconfirm
            v-if="!isDeleted && !isLocked && canLock"
            :title="$t('locks.lock_confirm')"
            :ok-text="$t('locks.lock')"
            @confirm="doLock"
        >
            <Tooltip :title="$t('locks.lock')">
                <Button><LockOutlined /><span class="esa__label"> {{ $t('locks.lock') }}</span></Button>
            </Tooltip>
        </Popconfirm>

        <!-- Desbloquear: solo si está bloqueado y el usuario tiene el nivel. -->
        <Popconfirm
            v-if="!isDeleted && isLocked && canUnlock"
            :title="$t('locks.unlock_confirm')"
            :ok-text="$t('locks.unlock')"
            @confirm="doUnlock"
        >
            <Tooltip :title="$t('locks.unlock')">
                <Button><UnlockOutlined /><span class="esa__label"> {{ $t('locks.unlock') }}</span></Button>
            </Tooltip>
        </Popconfirm>

        <!-- Tag de bloqueo: 'Bloqueado' (nivel tenant) o 'Bloqueado por el sistema'
             (nivel super, que el admin no puede sacar). -->
        <Tooltip v-if="!isDeleted && isLocked" :title="lockedBySystem ? $t('locks.locked_by_super_hint') : $t('locks.locked_hint')">
            <Tag color="gold" :bordered="false">
                <LockOutlined /> {{ lockedBySystem ? $t('locks.locked_by_super') : $t('locks.locked_tag') }}
            </Tag>
        </Tooltip>

        <!-- Tags de solo-lectura (independientes): 'Protegido' si es un registro
             de sistema; 'Global' si es un dato global visto por un no-super.
             Pueden coexistir si un registro fuera ambas cosas. -->
        <Tag v-if="!isDeleted && editProtectedKey && !canEdit" :bordered="false">
            {{ $t(editProtectedKey) }}
        </Tag>
        <Tag v-if="!isDeleted && isGlobal && !isSuper" color="purple" :bordered="false">
            {{ $t('global.platform') }}
        </Tag>
    </div>
</template>

<style scoped>
.esa { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
/* Mobile: solo iconos (con tooltip) — look de app, consistente con los footers
   de los drawers. El texto se oculta; la alineación la maneja el contenedor. */
@media (max-width: 768px) {
    .esa { gap: 10px; }
    .esa__label { display: none; }
}
</style>
