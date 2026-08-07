<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Descriptions, DescriptionsItem, Alert,
} from 'ant-design-vue';
import { TeamOutlined, AppstoreOutlined } from '@ant-design/icons-vue';

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
    transformerType: { type: Object, required: true },
    activity:   { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.transformerType.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);
</script>

<template>
    <Head :title="transformerType.name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.transformer_types.index')"
            :title="transformerType.name"
            :icon-bg="iconBg"
        >
            <template #icon><AppstoreOutlined /></template>
            <template #subtitle>
                <Space :size="6">
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="transformerType.is_active ? 'success' : 'default'" :bordered="false">
                        {{ transformerType.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="transformer_types"
                    route-prefix="business_management"
                    :slug="transformerType.slug"
                    :id="transformerType.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('transformer_types.edit')"
                    :can-delete="can('transformer_types.delete')"
                    :can-see-audit="canSeeAudit"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(transformerType.deleted_at) }}</div>
                <div v-if="transformerType.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ transformerType.deleter.name }}
                </div>
                <div v-if="transformerType.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ transformerType.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="transformer_types" route-prefix="business_management" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <Card :title="$t('global.general_info')" :bodyStyle="{ padding: 0 }" class="info-card">
                    <Descriptions :column="1" bordered :labelStyle="{ width: '180px' }">
                        <DescriptionsItem v-if="isSuper" label="ID">{{ transformerType.id }}</DescriptionsItem>
                        <DescriptionsItem v-if="isSuper" label="Slug">
                            <code class="muted">{{ transformerType.slug }}</code>
                        </DescriptionsItem>
                        <DescriptionsItem :label="$t('transformer_types.name')">{{ transformerType.name }}</DescriptionsItem>
                        <DescriptionsItem v-if="transformerType.code" :label="$t('transformer_types.code')">
                            <code>{{ transformerType.code }}</code>
                        </DescriptionsItem>
                        <DescriptionsItem :label="$t('transformer_types.is_active')">
                            <Tag :color="transformerType.is_active ? 'success' : 'default'" :bordered="false">
                                {{ transformerType.is_active ? $t('global.active') : $t('global.inactive') }}
                            </Tag>
                        </DescriptionsItem>
                    </Descriptions>
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
.info-card { margin-bottom: 16px; border-radius: 6px; }

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
