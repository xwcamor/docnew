<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Alert,
} from 'ant-design-vue';
import { ExperimentOutlined, BgColorsOutlined } from '@ant-design/icons-vue';

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
    oilType: { type: Object, required: true },
    activity:   { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.oilType.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);
</script>

<template>
    <Head :title="oilType.name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.oil_types.index')"
            :title="oilType.name"
            :icon-bg="iconBg"
        >
            <template #icon><BgColorsOutlined /></template>
            <template #subtitle>
                <Space :size="6">
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="oilType.is_active ? 'success' : 'default'" :bordered="false">
                        {{ oilType.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="oil_types"
                    route-prefix="business_management"
                    :slug="oilType.slug"
                    :id="oilType.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('oil_types.edit')"
                    :can-delete="can('oil_types.delete')"
                    :can-see-audit="canSeeAudit"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(oilType.deleted_at) }}</div>
                <div v-if="oilType.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ oilType.deleter.name }}
                </div>
                <div v-if="oilType.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ oilType.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="oil_types" route-prefix="business_management" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><ExperimentOutlined /> {{ $t('global.general_info') }}</template>
                    <div class="spec-grid">
                        <!-- ID y slug: solo el super (datos técnicos), y van primero. -->
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">ID</span>
                            <span class="spec-cell__value">{{ oilType.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code class="muted">{{ oilType.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('oil_types.name') }}</span>
                            <span class="spec-cell__value">{{ oilType.name }}</span>
                        </div>
                        <div v-if="oilType.code" class="spec-cell">
                            <span class="spec-cell__label">{{ $t('oil_types.code') }}</span>
                            <span class="spec-cell__value"><code>{{ oilType.code }}</code></span>
                        </div>
                        <!-- Reglas de diagnóstico: si no tiene, link a editar (donde se pueden copiar). -->
                        <div v-if="oilType.has_rules !== undefined" class="spec-cell">
                            <span class="spec-cell__label">{{ $t('oil_types.rules') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="oilType.has_rules ? 'green' : 'default'" :bordered="false">
                                    {{ oilType.has_rules ? $t('oil_types.has_rules_yes') : $t('oil_types.has_rules_no') }}
                                </Tag>
                                <Link
                                    v-if="!oilType.has_rules && !isDeleted && can('oil_types.edit')"
                                    :href="route('business_management.oil_types.edit', oilType.slug)"
                                    class="rules-cta"
                                >
                                    {{ $t('oil_types.clone_rules') }}
                                </Link>
                            </span>
                        </div>
                        <!-- Estado: siempre al final. -->
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('oil_types.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="oilType.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ oilType.is_active ? $t('global.active') : $t('global.inactive') }}
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

/* Información general remasterizada: grilla de celdas suaves. */
.spec-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.spec-cell {
    display: flex; flex-direction: column; gap: 4px;
    padding: 12px 14px; border-radius: 8px;
    background: var(--color-surface-alt, #f5f6f7);
    border: 1px solid var(--color-border, #e5e5e5);
}
.spec-cell__label {
    font-size: 0.72rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
    color: var(--color-text-muted, #6A6D70);
}
.spec-cell__value { font-size: 0.95rem; color: var(--color-text, #32363A); }
.rules-cta { margin-left: 10px; font-size: 0.82rem; }
@media (max-width: 640px) { .spec-grid { grid-template-columns: 1fr; } }
</style>
