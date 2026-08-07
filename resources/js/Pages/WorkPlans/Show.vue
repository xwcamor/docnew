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
    workPlan: { type: Object, required: true },
    activity:   { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.workPlan.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);
</script>

<template>
    <Head :title="workPlan.name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.work_plans.index')"
            :title="workPlan.name"
            :icon-bg="iconBg"
        >
            <template #icon><TagsOutlined /></template>
            <template #subtitle>
                <Space :size="6">
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="workPlan.is_active ? 'success' : 'default'" :bordered="false">
                        {{ workPlan.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="work_plans"
                    route-prefix="business_management"
                    :slug="workPlan.slug"
                    :id="workPlan.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('work_plans.edit')"
                    :can-delete="can('work_plans.delete')"
                    :can-see-audit="canSeeAudit"
                    :is-super="isSuper"
                    :is-global="workPlan.tenant_id === null"
                    :lock="workPlan.lock"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(workPlan.deleted_at) }}</div>
                <div v-if="workPlan.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ workPlan.deleter.name }}
                </div>
                <div v-if="workPlan.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ workPlan.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="work_plans" route-prefix="business_management" />
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
                            <span class="spec-cell__value">{{ workPlan.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code class="muted">{{ workPlan.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.name') }}</span>
                            <span class="spec-cell__value">{{ workPlan.name }}</span>
                        </div>
                        <div v-if="workPlan.code" class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.code') }}</span>
                            <span class="spec-cell__value"><code>{{ workPlan.code }}</code></span>
                        </div>
                        <!-- Estado: siempre al final. -->
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="workPlan.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ workPlan.is_active ? $t('global.active') : $t('global.inactive') }}
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
