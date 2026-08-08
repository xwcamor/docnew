<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Card, Tag, Space, Alert, Empty } from 'ant-design-vue';
import { SolutionOutlined } from '@ant-design/icons-vue';

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
    position:       { type: Object, required: true },
    usages:      { type: Array,  default: () => [] },
    activity:    { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.position.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

const fmt = (d) => formatDateTimeFull(d);
</script>

<template>
    <Head :title="position.code" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.positions.index')"
            :title="position.code"
            :icon-bg="iconBg"
        >
            <template #icon><SolutionOutlined /></template>
            <template #subtitle>
                <Space :size="6" wrap>
                    <Tag v-if="position.is_signature_approver" color="green" :bordered="false">
                        {{ $t('positions.signature_approver_yes') }}
                    </Tag>
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="position.is_active ? 'success' : 'default'" :bordered="false">
                        {{ position.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="positions"
                    route-prefix="business_management"
                    :slug="position.slug"
                    :id="position.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('positions.edit')"
                    :can-delete="can('positions.delete')"
                    :can-see-audit="canSeeAudit"
                    :is-super="isSuper"
                    :lock="position.lock"
                    :is-global="position.tenant_id === null"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(position.deleted_at) }}</div>
                <div v-if="position.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ position.deleter.name }}
                </div>
                <div v-if="position.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ position.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="positions" route-prefix="business_management" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <!-- Abre con lo que se necesita de un vistazo. El id y el slug
                     solo los ve el super: para quien usa la aplicación no
                     significan nada (docs/UI.md §4). -->
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><SolutionOutlined /> {{ $t('global.general_info') }}</template>
                    <div class="spec-grid">
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">ID</span>
                            <span class="spec-cell__value">{{ position.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code class="muted">{{ position.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('positions.code') }}</span>
                            <span class="spec-cell__value">{{ position.code }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('positions.country') }}</span>
                            <span class="spec-cell__value">{{ position.country ?? '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('positions.is_signature_approver') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="position.is_signature_approver ? 'green' : 'default'" :bordered="false">
                                    {{ position.is_signature_approver
                                        ? $t('positions.signature_approver_yes')
                                        : $t('positions.signature_approver_no') }}
                                </Tag>
                            </span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('positions.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="position.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ position.is_active ? $t('global.active') : $t('global.inactive') }}
                                </Tag>
                            </span>
                        </div>
                    </div>
                </Card>

                <!-- Lo que depende de esta fila: la respuesta a «¿por qué no me
                     deja borrarla?», puesta antes de que se pregunte. -->
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title>{{ $t('positions.usage_list_title') }}</template>
                    <Empty v-if="usages.length === 0" :description="$t('positions.usage_list_none')" />
                    <ul v-else class="uses-list">
                        <li v-for="u in usages" :key="u.slug" class="uses-list__item">
                            <span class="uses-list__label">{{ u.label }}</span>
                            <Tag :bordered="false" :color="u.is_active ? 'success' : 'default'">
                                {{ u.is_active ? $t('global.active') : $t('global.inactive') }}
                            </Tag>
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
.deleted-alert { margin-bottom: 16px; }
.info-card { margin-bottom: 16px; border-radius: 8px; }

.uses-list { list-style: none; margin: 0; padding: 0; }
.uses-list__item {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    /* 48px: la fila entera es un objetivo de toque cómodo en tablet. */
    min-height: 48px; padding: 10px 4px;
    border-bottom: 1px solid var(--color-border-subtle, #f2f3f5);
}
.uses-list__item:last-child { border-bottom: none; }
.uses-list__label { font-weight: 500; }

@media (max-width: 767px) {
    :deep(.ant-descriptions-item-content) { word-break: break-word; }
}
</style>
