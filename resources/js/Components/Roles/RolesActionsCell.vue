<script setup>
import { Button, Space, Tag, Tooltip, Popconfirm, Dropdown, Menu, MenuItem } from 'ant-design-vue';
import { Link, router } from '@inertiajs/vue3';
import {
    EyeOutlined, EditOutlined, CopyOutlined, DeleteOutlined, EllipsisOutlined, LockOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

const props = defineProps({
    record:        { type: Object,  required: true },
    isMobile:      { type: Boolean, default: false },
    // Compacto (tabla en pantalla chica): las acciones se colapsan en un menú
    // kebab (⋯) para no ocupar una columna ancha.
    compact:       { type: Boolean, default: false },
    canEdit:       { type: Boolean, default: false },
    canCreate:     { type: Boolean, default: false },
    canDelete:     { type: Boolean, default: false },
    duplicatingId: { type: [Number, String, null], default: null },
});

const emit = defineEmits(['duplicate']);

// El backend marca is_editable (admin: solo los de su tenant; super: todo salvo
// sistema). Fallback a !is_system si el payload no lo trae (otras vistas).
const isEditable = (record) =>
    record.is_editable !== undefined ? record.is_editable : !record.is_system;

// Menú kebab (modo compacto): Ver/Editar/Eliminar navegan; Duplicar emite.
const onMenu = ({ key }) => {
    if (key === 'view')           router.visit(route('user_management.roles.show', props.record.slug));
    else if (key === 'edit')      router.visit(route('user_management.roles.edit', props.record.slug));
    else if (key === 'duplicate') emit('duplicate', props.record);
    else if (key === 'delete')    router.visit(route('user_management.roles.delete', props.record.slug));
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
                    <template v-if="isEditable(record)">
                        <MenuItem v-if="canEdit" key="edit"><EditOutlined /> {{ t('global.edit') }}</MenuItem>
                        <MenuItem v-if="canCreate" key="duplicate"><CopyOutlined /> {{ t('global.duplicate') }}</MenuItem>
                        <MenuItem v-if="canDelete" key="delete" danger><DeleteOutlined /> {{ t('global.delete') }}</MenuItem>
                    </template>
                    <MenuItem v-else-if="record.is_system" key="protected" disabled><LockOutlined /> {{ t('roles.protected') }}</MenuItem>
                    <MenuItem v-else key="global" disabled><LockOutlined /> {{ t('roles.tag_global') }}</MenuItem>
                </Menu>
            </template>
        </Dropdown>
    </div>

    <!-- Mobile: Ver -> Editar -> Eliminar. Sistema: Tag protegido. -->
    <div v-else-if="isMobile" class="row-actions-mobile" @click.stop>
        <Tooltip :title="t('global.view')">
            <Link :href="route('user_management.roles.show', record.slug)">
                <Button type="text" class="row-icon-btn" :aria-label="t('global.view')">
                    <EyeOutlined />
                </Button>
            </Link>
        </Tooltip>
        <template v-if="isEditable(record)">
            <Tooltip v-if="canEdit" :title="t('global.edit')">
                <Link :href="route('user_management.roles.edit', record.slug)">
                    <Button type="text" class="row-icon-btn" :aria-label="t('global.edit')">
                        <EditOutlined />
                    </Button>
                </Link>
            </Tooltip>
            <Tooltip v-if="canDelete" :title="t('global.delete')">
                <Link :href="route('user_management.roles.delete', record.slug)">
                    <Button type="text" danger class="row-icon-btn" :aria-label="t('global.delete')">
                        <DeleteOutlined />
                    </Button>
                </Link>
            </Tooltip>
        </template>
        <Tag v-else-if="record.is_system" color="default" :bordered="false">{{ t('roles.protected') }}</Tag>
        <Tag v-else color="purple" :bordered="false">{{ t('roles.tag_global') }}</Tag>
    </div>

    <!-- Desktop: Ver + Editar + Duplicar (con Popconfirm) + Eliminar. -->
    <Space v-else :size="4" class="row-actions-desktop" @click.stop>
        <Tooltip :title="t('global.view')">
            <Link :href="route('user_management.roles.show', record.slug)">
                <Button size="small" type="text">
                    <EyeOutlined />
                </Button>
            </Link>
        </Tooltip>
        <template v-if="isEditable(record)">
            <Tooltip v-if="canEdit" :title="t('global.edit')">
                <Link :href="route('user_management.roles.edit', record.slug)">
                    <Button size="small" type="text">
                        <EditOutlined />
                    </Button>
                </Link>
            </Tooltip>
            <Tooltip v-if="canCreate" :title="t('global.duplicate')">
                <Popconfirm
                    :title="t('roles.confirm_duplicate') || '¿Duplicar este perfil?'"
                    :ok-text="t('global.duplicate')"
                    :cancel-text="t('global.cancel')"
                    @confirm="$emit('duplicate', record)"
                >
                    <Button size="small" type="text" :loading="duplicatingId === record.id">
                        <CopyOutlined />
                    </Button>
                </Popconfirm>
            </Tooltip>
            <Tooltip v-if="canDelete" :title="t('global.delete')">
                <Link :href="route('user_management.roles.delete', record.slug)">
                    <Button size="small" type="text" danger>
                        <DeleteOutlined />
                    </Button>
                </Link>
            </Tooltip>
        </template>
        <Tag v-else-if="record.is_system" color="default" :bordered="false">{{ t('roles.protected') }}</Tag>
        <Tag v-else color="purple" :bordered="false">{{ t('roles.tag_global') }}</Tag>
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
