<script setup>
/**
 * ViewDeletedButton — botón "Ver eliminados" del Alert de Show cuando el
 * registro está soft-deleted. Tooltip + spring hover heredados.
 */
import { Link } from '@inertiajs/vue3';
import { Button, Tooltip } from 'ant-design-vue';
import { UndoOutlined } from '@ant-design/icons-vue';

defineProps({
    module:      { type: String, required: true },
    routePrefix: { type: String, default: 'system_management' },
});
</script>

<template>
    <Tooltip :title="$t('global.view_deleted_hint')">
        <Link :href="route(`${routePrefix}.${module}.trash`)">
            <Button size="small" type="primary" ghost>
                <UndoOutlined /> {{ $t('global.view_deleted') }}
            </Button>
        </Link>
    </Tooltip>
</template>
