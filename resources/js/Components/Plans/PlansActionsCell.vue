<script setup>
/**
 * Action cell para Plans — idéntico patrón visual a RegionsActionsCell:
 *   - Desktop: icon-only buttons (Ver / Editar / Eliminar) con tooltips
 *   - Mobile: mismo set pero con buttons más grandes
 *   - Spring hover heredado del CSS global de `.ant-btn`
 *   - Hover-to-reveal opacity manejado por el padre (.row-actions-desktop)
 */
import { Button, Space, Tooltip } from 'ant-design-vue';
import { Link } from '@inertiajs/vue3';
import { EyeOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineProps({
    record:   { type: Object,  required: true },
    isMobile: { type: Boolean, default: false },
});

defineEmits(['delete']);
</script>

<template>
    <!-- Mobile: misma estructura que Regions, buttons grandes -->
    <div v-if="isMobile" class="row-actions-mobile">
        <Tooltip :title="t('global.view')">
            <Link :href="route('system_management.plans.show', record.id)">
                <Button type="text" class="row-icon-btn" :aria-label="t('global.view')">
                    <EyeOutlined />
                </Button>
            </Link>
        </Tooltip>
        <Tooltip :title="t('global.edit')">
            <Link :href="route('system_management.plans.edit', record.id)">
                <Button type="text" class="row-icon-btn" :aria-label="t('global.edit')">
                    <EditOutlined />
                </Button>
            </Link>
        </Tooltip>
        <Tooltip :title="t('global.delete')">
            <Button
                type="text"
                danger
                class="row-icon-btn"
                :aria-label="t('global.delete')"
                @click.stop="$emit('delete', record)"
            >
                <DeleteOutlined />
            </Button>
        </Tooltip>
    </div>

    <!-- Desktop: hover-to-reveal opacity en el padre -->
    <Space v-else size="small" class="row-actions-desktop">
        <Tooltip :title="t('global.view')">
            <Link :href="route('system_management.plans.show', record.id)">
                <Button size="small" type="text" :aria-label="t('global.view')">
                    <EyeOutlined />
                </Button>
            </Link>
        </Tooltip>
        <Tooltip :title="t('global.edit')">
            <Link :href="route('system_management.plans.edit', record.id)">
                <Button size="small" type="text" :aria-label="t('global.edit')">
                    <EditOutlined />
                </Button>
            </Link>
        </Tooltip>
        <Tooltip :title="t('global.delete')">
            <Button
                size="small"
                type="text"
                danger
                :aria-label="t('global.delete')"
                @click.stop="$emit('delete', record)"
            >
                <DeleteOutlined />
            </Button>
        </Tooltip>
    </Space>
</template>

<style scoped>
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
