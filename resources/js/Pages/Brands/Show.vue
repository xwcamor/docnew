<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Alert,
} from 'ant-design-vue';
import { TagsOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const props = defineProps({
    brand: { type: Object, required: true },
    activity:   { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.brand.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);
</script>

<template>
    <Head :title="brand.name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.brands.index')"
            :title="brand.name"
            :icon-bg="iconBg"
        >
            <template #icon><TagsOutlined /></template>
            <template #subtitle>
                <Space :size="6">
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="brand.is_active ? 'success' : 'default'" :bordered="false">
                        {{ brand.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="brands"
                    route-prefix="business_management"
                    :slug="brand.slug"
                    :id="brand.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('brands.edit')"
                    :can-delete="can('brands.delete')"
                    :can-see-audit="canSeeAudit"
                    :is-super="isSuper"
                    :is-global="brand.tenant_id === null"
                    :lock="brand.lock"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(brand.deleted_at) }}</div>
                <div v-if="brand.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ brand.deleter.name }}
                </div>
                <div v-if="brand.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ brand.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="brands" route-prefix="business_management" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><TagsOutlined /> {{ $t('global.general_info') }}</template>
                    <div class="spec-grid">
                        <!-- ID y slug: solo el super (datos técnicos), y van primero. -->
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">ID</span>
                            <span class="spec-cell__value">{{ brand.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code class="muted">{{ brand.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('brands.name') }}</span>
                            <span class="spec-cell__value">{{ brand.name }}</span>
                        </div>
                        <div v-if="brand.code" class="spec-cell">
                            <span class="spec-cell__label">{{ $t('brands.code') }}</span>
                            <span class="spec-cell__value"><code>{{ brand.code }}</code></span>
                        </div>
                        <!-- Estado: siempre al final. -->
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('brands.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="brand.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ brand.is_active ? $t('global.active') : $t('global.inactive') }}
                                </Tag>
                            </span>
                        </div>
                    </div>
                </Card>
            </template>

            <template #history>
                <RecordHistory :record-audit="recordAudit" :activity="activity" :can-see-activity="canSeeAudit" />
            </template>
        </EntityShowTabs>
    </div>
</template>

<style scoped>
.show-page { /* fullscreen — sin max-width, ocupa todo el ancho del content */ }
.muted { color: var(--color-text-muted); font-size: 0.8125rem; }
.deleted-alert { margin-bottom: 16px; }
.info-card { margin-bottom: 16px; border-radius: 8px; }

@media (max-width: 767px) {
    :deep(.ant-descriptions-item-label) {
        width: auto !important;
        min-width: 0 !important;
        white-space: normal !important;
        font-weight: 500;
    }
    :deep(.ant-descriptions-item-content) { word-break: break-word; }
}
</style>
