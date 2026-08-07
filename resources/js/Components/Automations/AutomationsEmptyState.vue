<script setup>
/** Empty state del Table. Cambia mensaje + CTAs según hasFilters. */
import { Button, Space } from 'ant-design-vue';
import { Link } from '@inertiajs/vue3';
import { ThunderboltOutlined, PlusOutlined } from '@ant-design/icons-vue';

defineProps({
    hasFilters: { type: Boolean, default: false },
    canCreate:  { type: Boolean, default: false },
});

defineEmits(['clear-filters']);
</script>

<template>
    <div class="empty-state">
        <ThunderboltOutlined class="empty-state__icon" />
        <h3 v-if="hasFilters">{{ $t('global.no_results') }}</h3>
        <h3 v-else>{{ $t('global.no_records') }}</h3>
        <p v-if="hasFilters">{{ $t('global.try_adjust_filters') }}</p>
        <p v-else>{{ $t('automations.no_records') }}</p>
        <Space wrap>
            <Button v-if="hasFilters" @click="$emit('clear-filters')">
                {{ $t('global.clear') }} {{ $t('global.filters').toLowerCase() }}
            </Button>
            <template v-if="!hasFilters && canCreate">
                <Link :href="route('automation_management.automations.create')">
                    <Button type="primary">
                        <PlusOutlined /> {{ $t('automations.new') }}
                    </Button>
                </Link>
            </template>
        </Space>
    </div>
</template>

<style scoped>
.empty-state {
    text-align: center;
    padding: 56px 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}
.empty-state__icon {
    font-size: 56px;
    color: var(--color-icon-soft);
    margin-bottom: 8px;
}
.empty-state h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text);
}
.empty-state p {
    margin: 0 0 12px 0;
    color: var(--color-text-muted);
    font-size: 0.875rem;
}
</style>
