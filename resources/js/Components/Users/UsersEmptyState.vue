<script setup>
/** Empty state del Table. Cambia mensaje + CTAs segun hasFilters. */
import { Button, Space } from 'ant-design-vue';
import { Link } from '@inertiajs/vue3';
import { UserOutlined, PlusOutlined } from '@ant-design/icons-vue';

defineProps({
    hasFilters: { type: Boolean, default: false },
    canCreate:  { type: Boolean, default: false },
});

defineEmits(['clear-filters']);
</script>

<template>
    <div class="empty-state">
        <UserOutlined class="empty-state__icon" />
        <h3 v-if="hasFilters">{{ $t('users.no_users_with_filters') }}</h3>
        <h3 v-else>{{ $t('users.no_users') }}</h3>
        <p v-if="hasFilters">{{ $t('global.try_adjust_filters') }}</p>
        <p v-else>{{ $t('users.index_subtitle') }}</p>
        <Space wrap>
            <Button v-if="hasFilters" @click="$emit('clear-filters')">
                {{ $t('global.clear_filters') }}
            </Button>
            <Link v-if="!hasFilters && canCreate" :href="route('user_management.users.create')">
                <Button type="primary">
                    <PlusOutlined /> {{ $t('users.create_first') }}
                </Button>
            </Link>
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
