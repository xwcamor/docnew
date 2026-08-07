<script setup>
import { Button, Space, Tooltip, Popconfirm, Dropdown, Menu, MenuItem } from 'ant-design-vue';
import { Link, router } from '@inertiajs/vue3';
import {
    EyeOutlined, EditOutlined, CopyOutlined, DeleteOutlined, PlayCircleOutlined, EllipsisOutlined,
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

const emit = defineEmits(['edit', 'duplicate', 'delete', 'run-now']);

// Menú kebab (modo compacto): Ver navega; el resto emite (igual que en el Index).
const onMenu = ({ key }) => {
    if (key === 'view')           router.visit(route('automation_management.automations.show', props.record.id));
    else if (key === 'run-now')   emit('run-now', props.record);
    else if (key === 'edit')      emit('edit', props.record);
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
                    <MenuItem key="run-now"><PlayCircleOutlined /> {{ t('automations.run_now') }}</MenuItem>
                    <MenuItem v-if="canEdit" key="edit"><EditOutlined /> {{ t('global.edit') }}</MenuItem>
                    <MenuItem v-if="canCreate" key="duplicate"><CopyOutlined /> {{ t('global.duplicate') }}</MenuItem>
                    <MenuItem v-if="canDelete" key="delete" danger><DeleteOutlined /> {{ t('global.delete') }}</MenuItem>
                </Menu>
            </template>
        </Dropdown>
    </div>

    <!-- Mobile: Ver → Run-now → Editar → Duplicar → Eliminar -->
    <div v-else-if="isMobile" class="row-actions-mobile">
        <Tooltip :title="t('global.view')">
            <Link :href="route('automation_management.automations.show', record.id)">
                <Button
                    type="text"
                    class="row-icon-btn"
                    :aria-label="t('global.view')"
                >
                    <EyeOutlined />
                </Button>
            </Link>
        </Tooltip>
        <Popconfirm
            :title="t('automations.run_now') + '?'"
            :ok-text="t('automations.run_now')"
            :cancel-text="t('global.cancel')"
            @confirm="$emit('run-now', record)"
        >
            <Tooltip :title="t('automations.run_now_hint')">
                <Button
                    type="text"
                    class="row-icon-btn"
                    :aria-label="t('automations.run_now')"
                    @click.stop
                >
                    <PlayCircleOutlined />
                </Button>
            </Tooltip>
        </Popconfirm>
        <Tooltip v-if="canEdit" :title="t('global.edit')">
            <Button
                type="text"
                class="row-icon-btn"
                :aria-label="t('global.edit')"
                @click="$emit('edit', record)"
            >
                <EditOutlined />
            </Button>
        </Tooltip>
        <Tooltip v-if="canCreate" :title="t('global.duplicate')">
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
        <Tooltip v-if="canDelete" :title="t('global.delete')">
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
    </div>

    <!-- Desktop: Ver + Run-now + Editar + Duplicar + Eliminar -->
    <Space v-else size="small" class="row-actions-desktop">
        <Tooltip :title="t('global.view')">
            <Link :href="route('automation_management.automations.show', record.id)">
                <Button size="small" type="text" :aria-label="t('global.view')">
                    <EyeOutlined />
                </Button>
            </Link>
        </Tooltip>
        <Popconfirm
            :title="t('automations.run_now') + '?'"
            :ok-text="t('automations.run_now')"
            :cancel-text="t('global.cancel')"
            @confirm="$emit('run-now', record)"
        >
            <Tooltip :title="t('automations.run_now_hint')">
                <Button size="small" type="text" @click.stop>
                    <PlayCircleOutlined />
                </Button>
            </Tooltip>
        </Popconfirm>
        <Tooltip v-if="canEdit" :title="t('global.edit')">
            <Link :href="route('automation_management.automations.edit', record.id)">
                <Button size="small" type="text">
                    <EditOutlined />
                </Button>
            </Link>
        </Tooltip>
        <Tooltip v-if="canCreate" :title="t('global.duplicate')">
            <Button
                size="small"
                type="text"
                :loading="duplicatingId === record.id"
                @click.stop="$emit('duplicate', record)"
            >
                <CopyOutlined />
            </Button>
        </Tooltip>
        <Tooltip v-if="canDelete" :title="t('global.delete')">
            <Link :href="route('automation_management.automations.delete', record.id)">
                <Button size="small" type="text" danger>
                    <DeleteOutlined />
                </Button>
            </Link>
        </Tooltip>
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
