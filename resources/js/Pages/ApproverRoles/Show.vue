<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Card, Tag, Space, Alert, Empty } from 'ant-design-vue';
import { SafetyCertificateOutlined } from '@ant-design/icons-vue';

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
    approverRole: { type: Object, required: true },
    rules:        { type: Array,  default: () => [] },
    activity:     { type: Array,  default: () => [] },
    recordAudit:  { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.approverRole.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

const fmt = (d) => formatDateTimeFull(d);
</script>

<template>
    <Head :title="approverRole.name_es" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.approver_roles.index')"
            :title="approverRole.name_es"
            :icon-bg="iconBg"
        >
            <template #icon><SafetyCertificateOutlined /></template>
            <template #subtitle>
                <Space :size="6" wrap>
                    <code class="mono">{{ approverRole.code }}</code>
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="approverRole.is_active ? 'success' : 'default'" :bordered="false">
                        {{ approverRole.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                    <Tag v-if="approverRole.is_system" color="purple" :bordered="false">{{ $t('approver_roles.system') }}</Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="approver_roles"
                    route-prefix="business_management"
                    :slug="approverRole.slug"
                    :id="approverRole.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('approver_roles.edit')"
                    :can-delete="can('approver_roles.delete')"
                    :can-see-audit="canSeeAudit"
                    :is-super="isSuper"
                    :is-global="approverRole.tenant_id === null"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(approverRole.deleted_at) }}</div>
                <div v-if="approverRole.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ approverRole.deleter.name }}
                </div>
                <div v-if="approverRole.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ approverRole.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="approver_roles" route-prefix="business_management" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><SafetyCertificateOutlined /> {{ $t('global.general_info') }}</template>
                    <div class="spec-grid">
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">ID</span>
                            <span class="spec-cell__value">{{ approverRole.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code class="muted">{{ approverRole.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('approver_roles.code') }}</span>
                            <span class="spec-cell__value"><code>{{ approverRole.code }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('approver_roles.name_es') }}</span>
                            <span class="spec-cell__value">{{ approverRole.name_es }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('approver_roles.name_en') }}</span>
                            <span class="spec-cell__value">{{ approverRole.name_en }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('approver_roles.sort_order') }}</span>
                            <span class="spec-cell__value">{{ approverRole.sort_order }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('approver_roles.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="approverRole.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ approverRole.is_active ? $t('global.active') : $t('global.inactive') }}
                                </Tag>
                            </span>
                        </div>
                    </div>
                </Card>

                <!-- Las reglas que firman con este rol: la respuesta a «¿por qué
                     no me deja borrarlo?», puesta antes de que se pregunte. -->
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title>{{ $t('approver_roles.rules_using_it') }}</template>
                    <Empty v-if="rules.length === 0" :description="$t('approver_roles.rules_none')" />
                    <ul v-else class="rules-list">
                        <li v-for="r in rules" :key="r.slug" class="rules-list__item">
                            <Link :href="route('business_management.approval_rules.show', r.slug)" class="rules-list__link">
                                <span class="rules-list__level">{{ $t('approval_rules.level_short') }} {{ r.priority_level }}</span>
                                <span class="rules-list__country">{{ r.country ?? '—' }}</span>
                                <Tag :bordered="false" :color="r.work_type ? 'geekblue' : 'default'">
                                    {{ r.work_type ?? $t('approver_roles.all_work_types') }}
                                </Tag>
                                <Tag :bordered="false" :color="r.is_required ? 'red' : 'default'">
                                    {{ r.is_required ? $t('approval_rules.required') : $t('approval_rules.optional') }}
                                </Tag>
                                <Tag :bordered="false" :color="r.is_active ? 'success' : 'default'">
                                    {{ r.is_active ? $t('global.active') : $t('global.inactive') }}
                                </Tag>
                            </Link>
                        </li>
                    </ul>
                </Card>
            </template>

            <template #history>
                <RecordHistory :record-audit="recordAudit" :activity="activity" :can-see-activity="canSeeAudit" />
            </template>
        </EntityShowTabs>
    </div>
</template>

<style scoped>
.muted { color: var(--color-text-muted); font-size: 0.8125rem; }
.mono { font-family: ui-monospace, Consolas, monospace; font-size: 0.8125rem; }
.deleted-alert { margin-bottom: 16px; }
.info-card { margin-bottom: 16px; border-radius: 8px; }

.rules-list { list-style: none; margin: 0; padding: 0; }
.rules-list__item { border-bottom: 1px solid var(--color-border-subtle, #f2f3f5); }
.rules-list__item:last-child { border-bottom: none; }
/* Objetivo de toque cómodo en tablet: la fila entera es el enlace. */
.rules-list__link {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    min-height: 48px; padding: 10px 4px;
    color: var(--color-text); text-decoration: none;
}
.rules-list__link:hover { background: var(--color-surface-hover, #f8fafc); }
.rules-list__level { font-weight: 600; min-width: 80px; }
.rules-list__country { color: var(--color-text-muted); }

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
