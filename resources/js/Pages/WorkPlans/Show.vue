<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Alert, Button,
} from 'ant-design-vue';
import {
    ScheduleOutlined, LockOutlined, ToolOutlined, FormOutlined, IdcardOutlined,
} from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import WorkPlanCrewCard from '@/Components/WorkPlans/WorkPlanCrewCard.vue';
import WorkPlanFormsCard from '@/Components/WorkPlans/WorkPlanFormsCard.vue';
import WorkPlanApprovalsCard from '@/Components/WorkPlans/WorkPlanApprovalsCard.vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const props = defineProps({
    workPlan: { type: Object, required: true },
    activity:   { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
    // La ficha es el puesto de mando del supervisor: además del plan trae su
    // cuadrilla, sus formatos y sus aprobaciones, y los accesos a las pantallas
    // de obra. Todo llega resuelto desde WorkPlanController::show().
    crew:      { type: Array,  default: () => [] },
    forms:     { type: Array,  default: () => [] },
    approvals: { type: Array,  default: () => [] },
    setupOptions: { type: Object, default: () => ({ formTemplates: [], approvalRules: [] }) },
    setup:     { type: Object, default: () => ({ can: false, reason: null }) },
    fieldWork: { type: Object, default: () => ({ canOpenForms: false, canSign: false, canExport: false }) },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.workPlan.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);

// Las fechas del plan son días de calendario (llegan Y-m-d): no se convierten
// de zona horaria, solo se reordenan a dd-mm-aaaa.
const fmtDay = (v) => {
    if (!v) return '—';
    const [y, m, d] = String(v).slice(0, 10).split('-');
    return d ? `${d}-${m}-${y}` : String(v);
};

// Armar el plan (cuadrilla, formatos, aprobadores) exige permiso Y que el plan
// siga abierto; lo decide el servidor y aquí solo se obedece. Un plan borrado
// tampoco se toca, aunque esté abierto.
const canSetup = computed(() => props.setup?.can && !isDeleted.value);

// Cuántos de la cuadrilla ya firmaron: es el número que el supervisor mira
// antes de dar el plan por cerrado.
const signedCount = computed(() => props.crew.filter((p) => p.signed).length);
</script>

<template>
    <Head :title="workPlan.code" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.work_plans.index')"
            :title="workPlan.code"
            :icon-bg="iconBg"
        >
            <template #icon><ScheduleOutlined /></template>
            <template #subtitle>
                <Space :size="6" wrap>
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="workPlan.is_done ? 'success' : 'warning'" :bordered="false">
                        {{ workPlan.is_done ? $t('work_plans.state_done') : $t('work_plans.state_pending') }}
                    </Tag>
                    <Tag v-if="workPlan.is_closed" color="gold" :bordered="false">
                        <LockOutlined /> {{ $t('work_plans.state_locked') }}
                    </Tag>
                    <!-- El plan cerrado del sistema anterior: no es el candado
                         administrativo, pero también deja el plan solo lectura. -->
                    <Tag v-if="workPlan.is_closed && !workPlan.is_done" color="gold" :bordered="false">
                        <LockOutlined /> {{ $t('work_plans.state_closed') }}
                    </Tag>
                    <span v-if="workPlan.company" class="muted">{{ workPlan.company.name }}</span>
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
                    <template #title><ScheduleOutlined /> {{ $t('global.general_info') }}</template>
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
                            <span class="spec-cell__label">{{ $t('work_plans.code') }}</span>
                            <span class="spec-cell__value"><code>{{ workPlan.code }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.num_os') }}</span>
                            <span class="spec-cell__value">{{ workPlan.num_os || '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.company') }}</span>
                            <span class="spec-cell__value">
                                {{ workPlan.company?.name || '—' }}
                                <code v-if="workPlan.company?.num_doc" class="muted">{{ workPlan.company.num_doc }}</code>
                            </span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.work_type') }}</span>
                            <span class="spec-cell__value">{{ workPlan.work_type?.code || '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.work_location') }}</span>
                            <span class="spec-cell__value">{{ workPlan.work_location?.name || '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.workstation') }}</span>
                            <span class="spec-cell__value">{{ workPlan.workstation?.name || '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.work_area') }}</span>
                            <span class="spec-cell__value">{{ workPlan.work_area?.name || '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.date_start') }}</span>
                            <span class="spec-cell__value">{{ fmtDay(workPlan.date_start) }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.date_end') }}</span>
                            <span class="spec-cell__value">{{ fmtDay(workPlan.date_end) }}</span>
                        </div>
                        <div class="spec-cell spec-cell--wide">
                            <span class="spec-cell__label">{{ $t('work_plans.description') }}</span>
                            <span class="spec-cell__value">{{ workPlan.description || '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.people_count') }}</span>
                            <span class="spec-cell__value">{{ workPlan.people_count ?? 0 }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.forms_count') }}</span>
                            <span class="spec-cell__value">{{ workPlan.submissions_count ?? 0 }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.registered_by') }}</span>
                            <span class="spec-cell__value">{{ workPlan.registered_by?.name || '—' }}</span>
                        </div>
                        <!-- Estado: siempre al final. -->
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.is_done') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="workPlan.is_done ? 'success' : 'warning'" :bordered="false">
                                    {{ workPlan.is_done ? $t('work_plans.state_done') : $t('work_plans.state_pending') }}
                                </Tag>
                            </span>
                        </div>
                    </div>
                </Card>

                <!-- Por qué no se puede armar el plan. Sale antes de las
                     tarjetas para que nadie busque el botón que no está. -->
                <Alert
                    v-if="setup.reason && !isDeleted"
                    type="warning"
                    show-icon
                    class="deleted-alert"
                    :message="$t('work_plans.setup_blocked_hint')"
                    :description="setup.reason"
                />

                <!-- Accesos a obra: las dos pantallas que se usan en la tablet.
                     Existían desde el principio pero había que escribir la URL
                     a mano; el trabajo del día empieza aquí. -->
                <Card
                    v-if="fieldWork.canOpenForms || fieldWork.canSign"
                    :bodyStyle="{ padding: 18 }"
                    class="info-card"
                >
                    <template #title><ToolOutlined /> {{ $t('work_plans.field_work_title') }}</template>
                    <p class="ff-cardhint">{{ $t('work_plans.field_work_subtitle') }}</p>
                    <div class="ff-addrow">
                        <Link v-if="fieldWork.canOpenForms" :href="route('field_work.forms.index', workPlan.slug)">
                            <Button type="primary" class="ff-add">
                                <template #icon><FormOutlined /></template>
                                {{ $t('work_plans.field_work_forms') }}
                            </Button>
                        </Link>
                        <Link v-if="fieldWork.canSign" :href="route('field_work.signatures.show', workPlan.slug)">
                            <Button class="ff-add">
                                <template #icon><IdcardOutlined /></template>
                                {{ $t('work_plans.field_work_sign') }}
                                <span v-if="crew.length"> · {{ signedCount }}/{{ crew.length }}</span>
                            </Button>
                        </Link>
                    </div>
                </Card>

                <WorkPlanCrewCard
                    :plan-slug="workPlan.slug"
                    :crew="crew"
                    :can-edit="canSetup"
                />

                <WorkPlanFormsCard
                    :plan-slug="workPlan.slug"
                    :forms="forms"
                    :options="setupOptions.formTemplates"
                    :can-edit="canSetup"
                    :can-open="fieldWork.canOpenForms"
                    :can-export="fieldWork.canExport"
                />

                <WorkPlanApprovalsCard
                    :plan-slug="workPlan.slug"
                    :approvals="approvals"
                    :rules="setupOptions.approvalRules"
                    :can-edit="canSetup"
                />
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
