<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Card, Tag, Space, Alert, Empty } from 'ant-design-vue';
import { EnvironmentOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const props = defineProps({
    workLocation:       { type: Object, required: true },
    usages:      { type: Array,  default: () => [] },
    activity:    { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();
const { t } = useI18n();

const isDeleted = computed(() => !!props.workLocation.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

const fmt = (d) => formatDateTimeFull(d);

// Lo que cuelga de la sede son dos cosas distintas —puestos y planes— y no se
// dicen igual: un puesto está activo o inactivo, un plan está en curso o
// terminado. Color Y palabra, siempre (docs/UI.md §5).
const stateColor = {
    active:      'success',
    done:        'success',
    in_progress: 'warning',
    inactive:    'default',
};
const usageColor = (u) => stateColor[u.state] ?? 'default';
const usageState = (u) => t(`work_locations.usage_state_${u.state}`);
const usageKind  = (u) => t(`work_locations.usage_kind_${u.kind}`);

// De qué clase es cada línea solo se dice si la lista mezcla clases. Repetir
// «Puesto de trabajo» en veinte filas seguidas es ruido, no información.
const usagesAreMixed = computed(() => new Set(props.usages.map((u) => u.kind)).size > 1);
</script>

<template>
    <Head :title="workLocation.name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.work_locations.index')"
            :title="workLocation.name"
            :icon-bg="iconBg"
        >
            <template #icon><EnvironmentOutlined /></template>
            <template #subtitle>
                <Space :size="6" wrap>
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="workLocation.is_active ? 'success' : 'default'" :bordered="false">
                        {{ workLocation.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="work_locations"
                    route-prefix="business_management"
                    :slug="workLocation.slug"
                    :id="workLocation.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('work_locations.edit')"
                    :can-delete="can('work_locations.delete')"
                    :can-see-audit="canSeeAudit"
                    :is-super="isSuper"
                    :lock="workLocation.lock"
                    :is-global="workLocation.tenant_id === null"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(workLocation.deleted_at) }}</div>
                <div v-if="workLocation.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ workLocation.deleter.name }}
                </div>
                <div v-if="workLocation.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ workLocation.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="work_locations" route-prefix="business_management" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <!-- Abre con lo que se necesita de un vistazo. El id y el slug
                     solo los ve el super: para quien usa la aplicación no
                     significan nada (docs/UI.md §4). -->
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><EnvironmentOutlined /> {{ $t('global.general_info') }}</template>
                    <div class="spec-grid">
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">ID</span>
                            <span class="spec-cell__value">{{ workLocation.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code class="muted">{{ workLocation.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_locations.name') }}</span>
                            <span class="spec-cell__value">{{ workLocation.name }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_locations.country') }}</span>
                            <span class="spec-cell__value">{{ workLocation.country ?? '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_locations.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="workLocation.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ workLocation.is_active ? $t('global.active') : $t('global.inactive') }}
                                </Tag>
                            </span>
                        </div>
                    </div>
                </Card>

                <!-- Lo que depende de esta fila: la respuesta a «¿por qué no me
                     deja borrarla?», puesta antes de que se pregunte. Van los
                     puestos Y los planes, que son las dos cosas que cuentan
                     para el bloqueo. Anatomía de fila: título, subtítulo y
                     estado a la derecha (docs/UI.md §4-bis). -->
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title>{{ $t('work_locations.usage_list_title') }}</template>
                    <Empty v-if="usages.length === 0" :description="$t('work_locations.usage_list_none')" />
                    <ul v-else class="uses-list">
                        <li v-for="u in usages" :key="u.kind + u.slug" class="uses-list__item">
                            <span class="uses-list__txt">
                                <span class="uses-list__label">{{ u.label }}</span>
                                <span v-if="usagesAreMixed" class="uses-list__kind">{{ usageKind(u) }}</span>
                            </span>
                            <Tag :bordered="false" :color="usageColor(u)">{{ usageState(u) }}</Tag>
                        </li>
                    </ul>
                    <p v-if="workLocation.usage_count > usages.length" class="uses-list__more">
                        {{ $t('work_locations.usage_list_more', { count: workLocation.usage_count - usages.length }) }}
                    </p>
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
.uses-list__txt { display: flex; flex-direction: column; min-width: 0; margin-right: auto; }
.uses-list__label { font-weight: 500; }
.uses-list__kind { font-size: 0.75rem; color: var(--color-text-muted); }
.uses-list__more { margin: 10px 0 0; font-size: 0.8125rem; color: var(--color-text-muted); }

@media (max-width: 767px) {
    :deep(.ant-descriptions-item-content) { word-break: break-word; }
}
</style>
