<script setup>
/** Empty state del Table. Cambia mensaje + CTAs según hasFilters. */
import { computed } from 'vue';
import { Button, Space } from 'ant-design-vue';
import { Link } from '@inertiajs/vue3';
import { GlobalOutlined, PlusOutlined, UploadOutlined } from '@ant-design/icons-vue';
import { usePlanFeatures } from '@/Composables/usePlanFeatures';

const { canUse } = usePlanFeatures();
// `imports` requiere plan pro+. Ocultamos el CTA si el tenant no lo tiene.
const canUseImports = computed(() => canUse('imports'));

defineProps({
    hasFilters: { type: Boolean, default: false },
    canCreate:  { type: Boolean, default: false },
});

defineEmits(['clear-filters', 'open-import']);
</script>

<template>
    <div class="empty-state">
        <GlobalOutlined class="empty-state__icon" />
        <h3 v-if="hasFilters">{{ $t('global.no_results') }}</h3>
        <h3 v-else>{{ $t('global.no_records') }}</h3>
        <p v-if="hasFilters">{{ $t('global.try_adjust_filters') }}</p>
        <p v-else>{{ $t('regions.empty_hint') }}</p>
        <Space wrap>
            <Button v-if="hasFilters" @click="$emit('clear-filters')">
                {{ $t('global.clear') }} {{ $t('global.filters').toLowerCase() }}
            </Button>
            <template v-if="!hasFilters && canCreate">
                <Link :href="route('system_management.regions.create')">
                    <Button type="primary">
                        <PlusOutlined /> {{ $t('regions.new') }}
                    </Button>
                </Link>
                <Button v-if="canUseImports" @click="$emit('open-import')">
                    <UploadOutlined /> {{ $t('global.import') }}
                </Button>
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
