<script setup>
import { computed } from 'vue';
import { Button, Space, Tag, Tooltip, Dropdown, Menu, MenuItem } from 'ant-design-vue';
import { Link, router } from '@inertiajs/vue3';
import {
    EyeOutlined, EditOutlined, DeleteOutlined, EllipsisOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

const props = defineProps({
    record:   { type: Object,  required: true },
    isMobile: { type: Boolean, default: false },
    // Compacto (tabla en pantalla chica): las acciones se colapsan en un menú
    // kebab (⋯) para no ocupar una columna ancha.
    compact:  { type: Boolean, default: false },
    isSuper:  { type: Boolean, default: false },
    canEdit:  { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
});

// Registros globales (workspace = Plataforma, tenant_id null): solo el super
// los gestiona. Para el resto se ocultan editar/eliminar.
const canManage = computed(() => props.isSuper || props.record.tenant_id != null);

defineEmits(['edit', 'delete']);

const onMenu = ({ key }) => {
    if (key === 'view')      router.visit(route('business_management.work_types.show', props.record.slug));
    else if (key === 'edit') router.visit(route('business_management.work_types.edit', props.record.slug));
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
                    <MenuItem v-if="canEdit && canManage" key="edit"><EditOutlined /> {{ t('global.edit') }}</MenuItem>
                    <MenuItem v-if="canDelete && canManage" key="delete" danger @click="$emit('delete', record)">
                        <DeleteOutlined /> {{ t('global.delete') }}
                    </MenuItem>
                </Menu>
            </template>
        </Dropdown>
    </div>

    <!-- Mobile: Ver -> Editar -> Eliminar. -->
    <div v-else-if="isMobile" class="row-actions-mobile" @click.stop>
        <Tooltip :title="t('global.view')">
            <Link :href="route('business_management.work_types.show', record.slug)">
                <Button type="text" class="row-icon-btn" :aria-label="t('global.view')">
                    <EyeOutlined />
                </Button>
            </Link>
        </Tooltip>
        <Tooltip v-if="canEdit && canManage" :title="t('global.edit')">
            <Button type="text" class="row-icon-btn" :aria-label="t('global.edit')" @click="$emit('edit', record)">
                <EditOutlined />
            </Button>
        </Tooltip>
        <Tooltip v-if="canDelete && canManage" :title="t('global.delete')">
            <Button type="text" danger class="row-icon-btn" :aria-label="t('global.delete')" @click="$emit('delete', record)">
                <DeleteOutlined />
            </Button>
        </Tooltip>
        <Tag v-if="!canManage" color="purple" :bordered="false">{{ t('global.platform') }}</Tag>
    </div>

    <!-- Desktop: Ver + Editar + Eliminar. -->
    <Space v-else :size="4" class="row-actions-desktop" @click.stop>
        <Tooltip :title="t('global.view')">
            <Link :href="route('business_management.work_types.show', record.slug)">
                <Button size="small" type="text" :aria-label="t('global.view')">
                    <EyeOutlined />
                </Button>
            </Link>
        </Tooltip>
        <Tooltip v-if="canEdit && canManage" :title="t('global.edit')">
            <Link :href="route('business_management.work_types.edit', record.slug)">
                <Button size="small" type="text">
                    <EditOutlined />
                </Button>
            </Link>
        </Tooltip>
        <Tooltip v-if="canDelete && canManage" :title="t('global.delete')">
            <Button size="small" type="text" danger @click.stop="$emit('delete', record)">
                <DeleteOutlined />
            </Button>
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
/* 44px de objetivo de toque: con guantes, menos no se acierta. */
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
