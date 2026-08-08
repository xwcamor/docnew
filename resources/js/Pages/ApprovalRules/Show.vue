<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Card, Tag, Space, Alert } from 'ant-design-vue';
import { NodeIndexOutlined } from '@ant-design/icons-vue';

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
    approvalRule: { type: Object, required: true },
    // El flujo entero del que forma parte: una regla suelta no dice nada, lo
    // que importa es la fila de firmas en la que cae.
    flow:         { type: Object, default: null },
    sequential:   { type: Boolean, default: false },
    activity:     { type: Array,  default: () => [] },
    recordAudit:  { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.approvalRule.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

const fmt = (d) => formatDateTimeFull(d);
</script>

<template>
    <Head :title="approvalRule.approver_role_label" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.approval_rules.index')"
            :title="approvalRule.approver_role_label"
            :icon-bg="iconBg"
        >
            <template #icon><NodeIndexOutlined /></template>
            <template #subtitle>
                <Space :size="6" wrap>
                    <span>{{ approvalRule.country ?? '—' }}</span>
                    <Tag :color="approvalRule.work_type ? 'geekblue' : 'default'" :bordered="false">
                        {{ approvalRule.work_type ?? $t('approval_rules.all_work_types') }}
                    </Tag>
                    <Tag :bordered="false">{{ $t('approval_rules.level_short') }} {{ approvalRule.priority_level }}</Tag>
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="approvalRule.is_active ? 'success' : 'default'" :bordered="false">
                        {{ approvalRule.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="approval_rules"
                    route-prefix="business_management"
                    :slug="approvalRule.slug"
                    :id="approvalRule.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('approval_rules.edit')"
                    :can-delete="can('approval_rules.delete')"
                    :can-see-audit="canSeeAudit"
                    :is-super="isSuper"
                    :lock="approvalRule.lock"
                    :is-global="approvalRule.tenant_id === null"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(approvalRule.deleted_at) }}</div>
                <div v-if="approvalRule.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ approvalRule.deleter.name }}
                </div>
                <div v-if="approvalRule.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ approvalRule.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="approval_rules" route-prefix="business_management" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><NodeIndexOutlined /> {{ $t('global.general_info') }}</template>
                    <div class="spec-grid">
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">ID</span>
                            <span class="spec-cell__value">{{ approvalRule.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code class="muted">{{ approvalRule.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('approval_rules.country') }}</span>
                            <span class="spec-cell__value">{{ approvalRule.country ?? '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('approval_rules.work_type') }}</span>
                            <span class="spec-cell__value">
                                {{ approvalRule.work_type ?? $t('approval_rules.all_work_types') }}
                            </span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('approval_rules.approver_role') }}</span>
                            <span class="spec-cell__value">
                                {{ approvalRule.approver_role_label }} <code class="muted">{{ approvalRule.approver_role }}</code>
                            </span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('approval_rules.priority_level') }}</span>
                            <span class="spec-cell__value">{{ approvalRule.priority_level }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('approval_rules.is_required') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="approvalRule.is_required ? 'red' : 'default'" :bordered="false">
                                    {{ approvalRule.is_required ? $t('approval_rules.required') : $t('approval_rules.optional') }}
                                </Tag>
                            </span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('approval_rules.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="approvalRule.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ approvalRule.is_active ? $t('global.active') : $t('global.inactive') }}
                                </Tag>
                            </span>
                        </div>
                    </div>
                </Card>

                <Card v-if="flow" :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title>{{ $t('approval_rules.flow_of_this_rule') }}</template>
                    <p class="flow-note">
                        {{ sequential ? $t('approval_rules.sequential_on') : $t('approval_rules.sequential_off') }}
                    </p>
                    <ol class="flow-steps">
                        <li
                            v-for="firma in flow.signatures"
                            :key="firma.role"
                            class="flow-step"
                            :class="{ 'flow-step--self': firma.role === approvalRule.approver_role }"
                        >
                            <span class="flow-step__level">{{ firma.level }}</span>
                            <span class="flow-step__label">{{ firma.label }}</span>
                            <Tag :color="firma.required ? 'red' : 'default'" :bordered="false">
                                {{ firma.required ? $t('approval_rules.required') : $t('approval_rules.optional') }}
                            </Tag>
                        </li>
                    </ol>
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
.deleted-alert { margin-bottom: 16px; }
.info-card { margin-bottom: 16px; border-radius: 8px; }
.flow-note { margin: 0 0 10px 0; color: var(--color-text-muted); font-size: 0.82rem; line-height: 1.5; }

.flow-steps { list-style: none; margin: 0; padding: 0; }
.flow-step {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    min-height: 48px; padding: 6px 8px;
    border-bottom: 1px solid var(--color-border-subtle, #f2f3f5);
}
.flow-step:last-child { border-bottom: none; }
/* La firma que corresponde a esta regla, resaltada dentro de su flujo. */
.flow-step--self { background: rgba(10, 110, 209, 0.06); border-radius: 8px; }
.flow-step__level {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 30px; height: 30px; flex-shrink: 0;
    border-radius: 999px; font-weight: 700; font-size: 0.82rem;
    color: var(--color-primary, #0A6ED1);
    background: rgba(10, 110, 209, 0.10);
    border: 1px solid rgba(10, 110, 209, 0.20);
}
.flow-step__label { font-weight: 500; }

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
