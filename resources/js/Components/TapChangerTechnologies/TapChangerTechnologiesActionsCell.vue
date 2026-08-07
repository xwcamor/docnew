<script setup>
import { computed } from 'vue';
import { Button, Space, Tag, Tooltip, Dropdown, Menu, MenuItem } from 'ant-design-vue';
import { Link, router } from '@inertiajs/vue3';
import {
    EyeOutlined, EditOutlined, CopyOutlined, DeleteOutlined, LockOutlined, EllipsisOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

const props = defineProps({
    record:        { type: Object,  required: true },
    isMobile:      { type: Boolean, default: false },
    // Compacto (tabla en pantalla chica): las acciones se colapsan en un menú
    // kebab (⋯) para no ocupar una columna ancha.
    compact:       { type: Boolean, default: false },
    isSuper:       { type: Boolean, default: false },
    canEdit:       { type: Boolean, default: false },
    canCreate:     { type: Boolean, default: false },
    canDelete:     { type: Boolean, default: false },
    duplicatingId: { type: [Number, String, null], default: null },
});

// Registros globales (workspace = Plataforma, tenant_id null): solo el super
// los gestiona. Para el resto se ocultan editar/duplicar/eliminar.
const canManage = computed(() => props.isSuper || props.record.tenant_id != null);

// Registro BLOQUEADO (Lockable): se ocultan editar y eliminar (el server también
// los rechaza). Se muestra un candado; desbloquear se hace desde la ficha.
const isLocked = computed(() => !!(props.record.is_locked ?? props.record.locked_at));

const emit = defineEmits(['edit', 'duplicate', 'delete']);

// Menú kebab (modo compacto): Ver/Editar navegan; Duplicar/Eliminar emiten.
const onMenu = ({ key }) => {
    if (key === 'view')       router.visit(route('business_management.tap_changer_technologies.show', props.record.slug));
    else if (key === 'edit')  router.visit(route('business_management.tap_changer_technologies.edit', props.record.slug));
    else if (key === 'duplicate') emit('duplicate', props.record);
    else if (key === 'delete')    emit('delete', props.record);
};
</script>

<template>
    <!-- Compacto (tabla en pantalla chica): kebab ⋯ con las acciones en un menú. -->
    <div v-if="compact" class="row-actions-compact" @click.stop>
        <Dropdown :trigger="['click']" placement="bottomRight">
            <Button type="text" class="row-icon-btn" :aria-label="t('global.actions')">
                <EllipsisOutlined />
            </Button>
            <template #overlay>
                <Menu @click="onMenu">
                    <MenuItem key="view"><EyeOutlined /> {{ t('global.view') }}</MenuItem>
                    <MenuItem v-if="canEdit && canManage && !isLocked" key="edit"><EditOutlined /> {{ t('global.edit') }}</MenuItem>
                    <MenuItem v-if="canCreate && canManage" key="duplicate"><CopyOutlined /> {{ t('global.duplicate') }}</MenuItem>
                    <MenuItem v-if="canDelete && canManage && !isLocked" key="delete" danger><DeleteOutlined /> {{ t('global.delete') }}</MenuItem>
                    <MenuItem v-if="isLocked" key="locked" disabled><LockOutlined /> {{ t('locks.locked_hint') }}</MenuItem>
                </Menu>
            </template>
        </Dropdown>
    </div>

    <!-- Mobile: Vista rápida -> Ver -> Editar -> Duplicar -> Eliminar. -->
    <div v-else-if="isMobile" class="row-actions-mobile" @click.stop>
        <Tooltip :title="t('global.view')">
            <Link :href="route('business_management.tap_changer_technologies.show', record.slug)">
                <Button
                    type="text"
                    class="row-icon-btn"
                    :aria-label="t('global.view')"
                >
                    <EyeOutlined />
                </Button>
            </Link>
        </Tooltip>
        <Tooltip v-if="canEdit && canManage && !isLocked" :title="t('global.edit')">
            <Button
                type="text"
                class="row-icon-btn"
                :aria-label="t('global.edit')"
                @click="$emit('edit', record)"
            >
                <EditOutlined />
            </Button>
        </Tooltip>
        <Tooltip v-if="canCreate && canManage" :title="t('global.duplicate')">
            <Button
                type="text"
                class="row-icon-btn"
                :aria-label="t('global.duplicate')"
                :loading="duplicatingId === record.id"
                @click="$emit('duplicate', record)"
            >
                <CopyOutlined />
            </Button>
        </Tooltip>
        <Tooltip v-if="canDelete && canManage && !isLocked" :title="t('global.delete')">
            <Button
                type="text"
                danger
                class="row-icon-btn"
                :aria-label="t('global.delete')"
                @click="$emit('delete', record)"
            >
                <DeleteOutlined />
            </Button>
        </Tooltip>
        <Tooltip v-if="isLocked" :title="t('locks.locked_hint')">
            <Tag color="gold" :bordered="false"><LockOutlined /></Tag>
        </Tooltip>
        <Tag v-if="!canManage" color="purple" :bordered="false">{{ t('global.platform') }}</Tag>
    </div>

    <!-- Desktop: Vista rápida + Ver + Editar + Duplicar + Eliminar. -->
    <Space v-else :size="4" class="row-actions-desktop" @click.stop>
        <Tooltip :title="t('global.view')">
            <Link :href="route('business_management.tap_changer_technologies.show', record.slug)">
                <Button size="small" type="text" :aria-label="t('global.view')">
                    <EyeOutlined />
                </Button>
            </Link>
        </Tooltip>
        <Tooltip v-if="canEdit && canManage && !isLocked" :title="t('global.edit')">
            <Link :href="route('business_management.tap_changer_technologies.edit', record.slug)">
                <Button size="small" type="text">
                    <EditOutlined />
                </Button>
            </Link>
        </Tooltip>
        <Tooltip v-if="canCreate && canManage" :title="t('global.duplicate')">
            <Button
                size="small"
                type="text"
                :loading="duplicatingId === record.id"
                @click.stop="$emit('duplicate', record)"
            >
                <CopyOutlined />
            </Button>
        </Tooltip>
        <Tooltip v-if="canDelete && canManage && !isLocked" :title="t('global.delete')">
            <Button
                size="small"
                type="text"
                danger
                @click.stop="$emit('delete', record)"
            >
                <DeleteOutlined />
            </Button>
        </Tooltip>
        <Tooltip v-if="isLocked" :title="t('locks.locked_hint')">
            <Tag color="gold" :bordered="false"><LockOutlined /></Tag>
        </Tooltip>
        <Tag v-if="!canManage" color="purple" :bordered="false">{{ t('global.platform') }}</Tag>
    </Space>
</template>

<style scoped>
.row-actions-compact {
    display: flex;
    justify-content: center;
    width: 100%;
}
.row-actions-mobile {
    display: flex;
    justify-content: flex-end;
    gap: 4px;
    width: 100%;
}
.row-icon-btn {
    width: 40px !important;
    height: 40px !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 !important;
}
.row-icon-btn :deep(.anticon) { font-size: 18px; }
</style>
