<script setup>
/**
 * Las acciones de una fila de catálogo: ver, editar, eliminar.
 *
 * Tres anatomías según el sitio, y las tres con el mismo orden de acciones:
 *   - compacto (tabla estrecha): un menú ⋯, porque una columna de tres botones
 *     empuja la tabla a scroll horizontal y eso está prohibido (docs/UI.md §3);
 *   - móvil: iconos de 40px, que es lo que se acierta con guantes;
 *   - escritorio: iconos que se realzan al pasar por la fila.
 *
 * Una fila GLOBAL (sin workspace) solo la gestiona el super: al resto se le
 * ocultan editar y eliminar y se le enseña la etiqueta, en vez de dejar botones
 * que el servidor va a rechazar.
 *
 * Lo mismo con una fila BLOQUEADA (trait Lockable): se enseña el candado y se
 * quitan editar y eliminar. El candado se saca desde la ficha, no desde aquí:
 * es una decisión, no un clic de paso por el listado.
 */
import { computed } from 'vue';
import { Button, Space, Tag, Tooltip, Dropdown, Menu, MenuItem } from 'ant-design-vue';
import { Link, router } from '@inertiajs/vue3';
import { EyeOutlined, EditOutlined, DeleteOutlined, LockOutlined, EllipsisOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

const props = defineProps({
    module:      { type: String,  required: true },
    routePrefix: { type: String,  default: 'business_management' },
    record:      { type: Object,  required: true },
    isMobile:    { type: Boolean, default: false },
    compact:     { type: Boolean, default: false },
    isSuper:     { type: Boolean, default: false },
    canEdit:     { type: Boolean, default: false },
    canDelete:   { type: Boolean, default: false },
});

const emit = defineEmits(['edit', 'delete']);

const canManage = computed(() => props.isSuper || props.record.tenant_id != null);
const isLocked  = computed(() => !!(props.record.is_locked ?? props.record.locked_at));

const routes = computed(() => {
    const base = `${props.routePrefix}.${props.module}`;
    return { show: `${base}.show`, edit: `${base}.edit` };
});

const onMenu = ({ key }) => {
    if (key === 'view')      router.visit(route(routes.value.show, props.record.slug));
    else if (key === 'edit') router.visit(route(routes.value.edit, props.record.slug));
    else if (key === 'delete') emit('delete', props.record);
};
</script>

<template>
    <div v-if="compact" class="row-actions-compact" @click.stop>
        <Dropdown :trigger="['click']" placement="bottomRight">
            <Button type="text" class="row-icon-btn" :aria-label="t('global.actions')">
                <EllipsisOutlined />
            </Button>
            <template #overlay>
                <Menu @click="onMenu">
                    <MenuItem key="view"><EyeOutlined /> {{ t('global.view') }}</MenuItem>
                    <MenuItem v-if="canEdit && canManage && !isLocked" key="edit"><EditOutlined /> {{ t('global.edit') }}</MenuItem>
                    <MenuItem v-if="canDelete && canManage && !isLocked" key="delete" danger><DeleteOutlined /> {{ t('global.delete') }}</MenuItem>
                    <MenuItem v-if="isLocked" key="locked" disabled><LockOutlined /> {{ t('locks.locked_hint') }}</MenuItem>
                </Menu>
            </template>
        </Dropdown>
    </div>

    <div v-else-if="isMobile" class="row-actions-mobile" @click.stop>
        <Tooltip :title="t('global.view')">
            <Link :href="route(routes.show, record.slug)">
                <Button type="text" class="row-icon-btn" :aria-label="t('global.view')"><EyeOutlined /></Button>
            </Link>
        </Tooltip>
        <Tooltip v-if="canEdit && canManage && !isLocked" :title="t('global.edit')">
            <Button type="text" class="row-icon-btn" :aria-label="t('global.edit')" @click="emit('edit', record)">
                <EditOutlined />
            </Button>
        </Tooltip>
        <Tooltip v-if="canDelete && canManage && !isLocked" :title="t('global.delete')">
            <Button type="text" danger class="row-icon-btn" :aria-label="t('global.delete')" @click="emit('delete', record)">
                <DeleteOutlined />
            </Button>
        </Tooltip>
        <Tooltip v-if="isLocked" :title="t('locks.locked_hint')">
            <Tag color="gold" :bordered="false"><LockOutlined /></Tag>
        </Tooltip>
        <Tag v-if="!canManage" color="purple" :bordered="false">{{ t('global.platform') }}</Tag>
    </div>

    <Space v-else :size="4" class="row-actions-desktop" @click.stop>
        <Tooltip :title="t('global.view')">
            <Link :href="route(routes.show, record.slug)">
                <Button size="small" type="text" :aria-label="t('global.view')"><EyeOutlined /></Button>
            </Link>
        </Tooltip>
        <Tooltip v-if="canEdit && canManage && !isLocked" :title="t('global.edit')">
            <Link :href="route(routes.edit, record.slug)">
                <Button size="small" type="text"><EditOutlined /></Button>
            </Link>
        </Tooltip>
        <Tooltip v-if="canDelete && canManage && !isLocked" :title="t('global.delete')">
            <Button size="small" type="text" danger @click.stop="emit('delete', record)"><DeleteOutlined /></Button>
        </Tooltip>
        <Tooltip v-if="isLocked" :title="t('locks.locked_hint')">
            <Tag color="gold" :bordered="false"><LockOutlined /></Tag>
        </Tooltip>
        <Tag v-if="!canManage" color="purple" :bordered="false">{{ t('global.platform') }}</Tag>
    </Space>
</template>

<style scoped>
.row-actions-compact { display: flex; justify-content: center; width: 100%; }
.row-actions-mobile  { display: flex; justify-content: flex-end; gap: 4px; width: 100%; }
.row-icon-btn {
    width: 44px !important;
    height: 44px !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 !important;
}
.row-icon-btn :deep(.anticon) { font-size: 18px; }
</style>
