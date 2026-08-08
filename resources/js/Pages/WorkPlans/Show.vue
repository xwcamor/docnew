<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Alert, Button, Segmented, Progress,
} from 'ant-design-vue';
import {
    ScheduleOutlined, LockOutlined, ToolOutlined, FormOutlined, IdcardOutlined,
    BankOutlined, EnvironmentOutlined, CalendarOutlined, DashboardOutlined,
    CheckCircleFilled, ExclamationCircleFilled, FileTextOutlined, TeamOutlined,
    SafetyCertificateOutlined, HourglassOutlined,
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
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const props = defineProps({
    workPlan: { type: Object, required: true },
    activity:   { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
    // La ficha es el puesto de mando del supervisor: además del plan trae sus
    // trabajadores, sus formatos y sus aprobaciones, y los accesos a las
    // pantallas de obra. Todo llega resuelto desde WorkPlanController::show().
    crew:      { type: Array,  default: () => [] },
    forms:     { type: Array,  default: () => [] },
    approvals: { type: Array,  default: () => [] },
    setupOptions: { type: Object, default: () => ({ formTemplates: [], approvalRules: [] }) },
    setup:     { type: Object, default: () => ({ can: false, reason: null }) },
    fieldWork: { type: Object, default: () => ({ canOpenForms: false, canSign: false, canExport: false }) },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();
const { t, tc } = useI18n();

const isDeleted = computed(() => !!props.workPlan.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);

// Las fechas del plan llevan hora y son hora de obra (llegan «Y-m-d H:i»): no
// se convierten de zona horaria, sólo se reordenan a dd-mm-aaaa hh:mm.
const fmtDay = (v) => {
    if (!v) return '—';
    const [y, m, d] = String(v).slice(0, 10).split('-');
    return d ? `${d}-${m}-${y}` : String(v);
};

/** Fecha con hora. La hora es media información del plan, no un adorno. */
const fmtMoment = (v) => {
    if (!v) return '—';
    const hora = String(v).slice(11, 16);
    return hora ? `${fmtDay(v)} ${hora}` : fmtDay(v);
};

// Armar el plan (trabajadores, formatos, aprobadores) exige permiso Y que el
// plan siga abierto; lo decide el servidor y aquí solo se obedece. Un plan
// borrado tampoco se toca, aunque esté abierto.
const canSetup = computed(() => props.setup?.can && !isDeleted.value);

// ── Las dos vistas ───────────────────────────────────────────────────────────
// El dueño pidió abrir por el resumen y poder saltar a la lista completa. La
// elección se recuerda porque cada quien mira la ficha para una cosa distinta:
// el supervisor por cómo va, el administrador por los identificadores.
const CLAVE_VISTA = 'docufiz.work_plans.show_view';

const leerVista = () => {
    try {
        return localStorage.getItem(CLAVE_VISTA) === 'full' ? 'full' : 'summary';
    } catch {
        return 'summary';   // modo privado / almacenamiento bloqueado
    }
};

const vista = ref(leerVista());

watch(vista, (v) => {
    try { localStorage.setItem(CLAVE_VISTA, v); } catch { /* sin persistir, no pasa nada */ }
});

// ── Lo que el supervisor mira de un vistazo ─────────────────────────────────

// Dónde se trabaja, en una sola línea: sede · puesto · área. Lo que no esté
// cargado simplemente no aparece, en vez de dejar tres guiones seguidos.
const donde = computed(() => [
    props.workPlan.work_location?.name,
    props.workPlan.workstation?.name,
    props.workPlan.work_area?.name,
].filter(Boolean).join(' · ') || '—');

const dia = (v) => String(v ?? '').slice(0, 10);
const hora = (v) => String(v ?? '').slice(11, 16);

// Un plan que empieza y acaba el mismo día se lee como un día con dos horas
// —«06-08-2026 · 12:26 → 21:30»—, no repitiendo la fecha dos veces. Casi todos
// los planes reales son así: de 3 722, sólo un puñado cruzan la medianoche.
const mismoDia = computed(() =>
    !!props.workPlan.date_end && dia(props.workPlan.date_end) === dia(props.workPlan.date_start));

/** El «Período de Trabajo» de la ficha anterior: inicio → fin, con horas. */
const cuando = computed(() => {
    const { date_start: ini, date_end: fin } = props.workPlan;

    if (!ini) return '—';
    if (!fin) return fmtMoment(ini);

    return mismoDia.value
        ? `${fmtDay(ini)} · ${hora(ini)} → ${hora(fin)}`
        : `${fmtMoment(ini)} → ${fmtMoment(fin)}`;
});

/**
 * «Tiempo Trabajado». Lo calcula el servidor (WorkPlan::getWorkedTimeAttribute)
 * porque la frase va traducida y pluralizada; aquí sólo se decide qué poner
 * cuando todavía no hay fin, que es lo normal en un plan del día en curso.
 */
const tiempoTrabajado = computed(() =>
    props.workPlan.worked_time || t('work_plans.worked_time_open'));

const formatosLlenos = computed(() => props.forms.filter((f) => f.status === 'confirmed').length);
const firmasCrew     = computed(() => props.crew.filter((p) => p.signed).length);
const aprobFirmadas  = computed(() => props.approvals.filter((a) => a.signed).length);

/** Un avance es "completo" cuando no queda nada; sin nada asignado es neutro. */
const avance = (hechos, total) => ({
    hechos, total,
    pct: total ? Math.round((hechos / total) * 100) : 0,
    estado: total === 0 ? 'vacio' : (hechos >= total ? 'ok' : 'falta'),
});

const marcadores = computed(() => [
    { clave: 'forms',      icono: FileTextOutlined,           etiqueta: t('work_plans.progress_forms'),      ...avance(formatosLlenos.value, props.forms.length) },
    { clave: 'signatures', icono: TeamOutlined,               etiqueta: t('work_plans.progress_signatures'), ...avance(firmasCrew.value, props.crew.length) },
    { clave: 'approvals',  icono: SafetyCertificateOutlined,  etiqueta: t('work_plans.progress_approvals'),  ...avance(aprobFirmadas.value, props.approvals.length) },
]);

// Aprobaciones obligatorias sin firmar, con el nombre del rol: decirle al
// supervisor "falta 1 aprobación" sin decirle cuál lo manda a buscarla.
const aprobPendientes = computed(() =>
    props.approvals.filter((a) => a.required && !a.signed));

/**
 * Qué le falta al plan para poder darse por terminado, en frases que se puedan
 * ejecutar. Es la pregunta que el supervisor trae al abrir la ficha, así que la
 * respuesta va escrita, no deducida de tres barras de progreso.
 */
const pendientes = computed(() => {
    if (props.workPlan.is_done) return [];

    const lista = [];

    if (!props.crew.length) {
        lista.push(t('work_plans.missing_crew'));
    } else if (firmasCrew.value < props.crew.length) {
        lista.push(tc('work_plans.missing_signatures', props.crew.length - firmasCrew.value));
    }

    const sinConfirmar = props.forms.length - formatosLlenos.value;
    if (sinConfirmar > 0) {
        lista.push(tc('work_plans.missing_forms', sinConfirmar));
    }

    if (aprobPendientes.value.length) {
        lista.push(tc('work_plans.missing_approvals', aprobPendientes.value.length, {
            roles: aprobPendientes.value
                .map((a) => (a.role ? t('work_plans.approver_role.' + a.role) : '—'))
                .join(', '),
        }));
    }

    return lista;
});
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
                <!-- ── El trabajo: de qué se trata, para quién, dónde y cuándo ── -->
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title>
                        <ScheduleOutlined />
                        {{ vista === 'summary' ? $t('work_plans.summary_work') : $t('global.general_info') }}
                    </template>
                    <template #extra>
                        <Segmented
                            v-model:value="vista"
                            class="wp-viewswitch"
                            :options="[
                                { value: 'summary', label: $t('work_plans.view_summary') },
                                { value: 'full',    label: $t('work_plans.view_full') },
                            ]"
                        />
                    </template>

                    <!-- Vista por defecto: lo que se necesita de un vistazo. -->
                    <template v-if="vista === 'summary'">
                        <p class="wp-headline">{{ workPlan.description || $t('work_plans.summary_no_desc') }}</p>

                        <div class="wp-facts">
                            <div class="wp-fact">
                                <span class="wp-fact__label"><BankOutlined /> {{ $t('work_plans.company') }}</span>
                                <span class="wp-fact__value">{{ workPlan.company?.name || '—' }}</span>
                            </div>
                            <div class="wp-fact">
                                <span class="wp-fact__label"><ToolOutlined /> {{ $t('work_plans.work_type') }}</span>
                                <span class="wp-fact__value">{{ workPlan.work_type?.code || '—' }}</span>
                            </div>
                            <div class="wp-fact">
                                <span class="wp-fact__label"><EnvironmentOutlined /> {{ $t('work_plans.summary_where') }}</span>
                                <span class="wp-fact__value">{{ donde }}</span>
                            </div>
                            <div class="wp-fact">
                                <span class="wp-fact__label"><CalendarOutlined /> {{ $t('work_plans.period_work') }}</span>
                                <span class="wp-fact__value">
                                    {{ cuando }}
                                    <small v-if="mismoDia" class="muted">{{ $t('work_plans.summary_same_day') }}</small>
                                </span>
                            </div>
                            <!-- Cuánto duró el trabajo. Era una tarjeta propia en
                                 la ficha anterior y la había perdido al pasar las
                                 fechas a día de calendario. -->
                            <div class="wp-fact">
                                <span class="wp-fact__label"><HourglassOutlined /> {{ $t('work_plans.worked_time') }}</span>
                                <span class="wp-fact__value">{{ tiempoTrabajado }}</span>
                            </div>
                            <div class="wp-fact">
                                <span class="wp-fact__label"><DashboardOutlined /> {{ $t('work_plans.is_done') }}</span>
                                <span class="wp-fact__value">
                                    <Tag :color="workPlan.is_done ? 'success' : 'warning'" :bordered="false">
                                        {{ workPlan.is_done ? $t('work_plans.state_done') : $t('work_plans.state_pending') }}
                                    </Tag>
                                </span>
                            </div>
                            <!-- La orden de servicio solo cuando existe: un campo
                                 vacío en la vista corta es ruido. -->
                            <div v-if="workPlan.num_os" class="wp-fact">
                                <span class="wp-fact__label"><FormOutlined /> {{ $t('work_plans.num_os') }}</span>
                                <span class="wp-fact__value">{{ workPlan.num_os }}</span>
                            </div>
                        </div>
                    </template>

                    <!-- Vista larga: la de siempre, con lo técnico incluido. -->
                    <div v-else class="spec-grid">
                        <div class="spec-cell spec-cell--wide">
                            <span class="spec-cell__label">{{ $t('work_plans.description') }}</span>
                            <span class="spec-cell__value">{{ workPlan.description || '—' }}</span>
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
                            <span class="spec-cell__value">{{ fmtMoment(workPlan.date_start) }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.date_end') }}</span>
                            <span class="spec-cell__value">{{ fmtMoment(workPlan.date_end) }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.worked_time') }}</span>
                            <span class="spec-cell__value">{{ tiempoTrabajado }}</span>
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
                            <span class="spec-cell__label">{{ $t('work_plans.is_done') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="workPlan.is_done ? 'success' : 'warning'" :bordered="false">
                                    {{ workPlan.is_done ? $t('work_plans.state_done') : $t('work_plans.state_pending') }}
                                </Tag>
                            </span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.is_closed') }}</span>
                            <span class="spec-cell__value">
                                {{ workPlan.is_closed ? $t('global.yes') : $t('global.no') }}
                            </span>
                        </div>

                        <!-- Datos del registro: quién lo dio de alta y cuándo. No
                             sirven en obra, pero es aquí donde se vienen a buscar. -->
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.registered_by') }}</span>
                            <span class="spec-cell__value">{{ workPlan.registered_by?.name || '—' }}</span>
                        </div>
                        <div v-if="workPlan.creator" class="spec-cell">
                            <span class="spec-cell__label">{{ $t('global.created_by') }}</span>
                            <span class="spec-cell__value">{{ workPlan.creator.name }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('global.created_at') }}</span>
                            <span class="spec-cell__value">{{ fmt(workPlan.created_at) }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('global.updated_at') }}</span>
                            <span class="spec-cell__value">{{ fmt(workPlan.updated_at) }}</span>
                        </div>
                        <!-- ID y slug: identificadores internos, solo el super. -->
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_plans.id') }}</span>
                            <span class="spec-cell__value">{{ workPlan.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code class="muted">{{ workPlan.slug }}</code></span>
                        </div>
                    </div>
                </Card>

                <!-- ── Cómo va y qué falta ─────────────────────────────────
                     Se queda en las dos vistas: es la pregunta que el supervisor
                     trae al abrir la ficha, y cambiar de vista no la responde. -->
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><DashboardOutlined /> {{ $t('work_plans.progress_title') }}</template>
                    <template #extra>
                        <Tag :color="workPlan.is_done ? 'success' : 'warning'" :bordered="false" class="wp-statetag">
                            {{ workPlan.is_done ? $t('work_plans.state_done') : $t('work_plans.state_pending') }}
                        </Tag>
                    </template>

                    <div class="wp-meters">
                        <div v-for="m in marcadores" :key="m.clave" class="wp-meter" :class="'is-' + m.estado">
                            <div class="wp-meter__head">
                                <component :is="m.icono" class="wp-meter__icon" />
                                <span class="wp-meter__label">{{ m.etiqueta }}</span>
                            </div>
                            <p class="wp-meter__num">
                                <template v-if="m.total">{{ m.hechos }}<span class="wp-meter__of">/{{ m.total }}</span></template>
                                <template v-else>—</template>
                            </p>
                            <Progress
                                :percent="m.pct"
                                :show-info="false"
                                size="small"
                                :stroke-color="m.estado === 'ok' ? '#107E3E' : '#E9730C'"
                            />
                            <p v-if="!m.total" class="wp-meter__note">{{ $t('work_plans.progress_empty') }}</p>
                        </div>
                    </div>

                    <!-- El titulo solo cuando hay algo que listar: "Falta para
                         cerrarlo — nada" es una frase que se lee dos veces. -->
                    <h4 v-if="pendientes.length" class="wp-missing__title">{{ $t('work_plans.missing_title') }}</h4>
                    <p v-if="workPlan.is_done" class="wp-missing wp-missing--ok wp-missing--solo">
                        <CheckCircleFilled /> {{ $t('work_plans.missing_done') }}
                    </p>
                    <p v-else-if="!pendientes.length" class="wp-missing wp-missing--ok wp-missing--solo">
                        <CheckCircleFilled /> {{ $t('work_plans.missing_none') }}
                    </p>
                    <ul v-else class="wp-missing__list">
                        <li v-for="(p, i) in pendientes" :key="i" class="wp-missing wp-missing--todo">
                            <ExclamationCircleFilled /> {{ p }}
                        </li>
                    </ul>
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
                                <span v-if="crew.length">&nbsp;· {{ firmasCrew }}/{{ crew.length }}</span>
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

                <!-- El flujo espera a que la cuadrilla firme, como en el
                     sistema anterior: `crewPending` es lo que lo bloquea. -->
                <WorkPlanApprovalsCard
                    :plan-slug="workPlan.slug"
                    :approvals="approvals"
                    :can-edit="canSetup"
                    :can-sign="fieldWork.canSign"
                    :crew-pending="crew.length - firmasCrew"
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

/* El interruptor de vista es un objetivo de toque: en la tablet se pulsa con
   guantes, no con el puntero. */
.wp-viewswitch :deep(.ant-segmented-item-label) {
    min-height: 44px; display: flex; align-items: center; justify-content: center;
    padding-inline: 16px; font-weight: 600;
}

/* ── Resumen: el trabajo ─────────────────────────────────────────────────── */
.wp-headline {
    margin: 0 0 16px;
    font-size: 1.25rem; line-height: 1.35; font-weight: 600;
    color: var(--color-text-strong, #1f2329);
}
.wp-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; }
.wp-fact {
    display: flex; flex-direction: column; gap: 4px; min-width: 0;
    padding: 12px 14px; border-radius: 8px;
    background: var(--color-surface-alt, #f5f6f7);
    border: 1px solid var(--color-border, #e5e5e5);
}
.wp-fact__label {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 0.75rem; font-weight: 600;
    color: var(--color-text-muted, #6A6D70);
}
.wp-fact__value {
    font-size: 1.0625rem; font-weight: 600; color: var(--color-text-strong, #1f2329);
    word-break: break-word; overflow-wrap: anywhere;
}
.wp-fact__value small { display: block; font-weight: 400; }

/* ── Resumen: cómo va ────────────────────────────────────────────────────── */
.wp-statetag { font-size: 0.875rem; padding: 4px 12px; }
.wp-meters { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.wp-meter {
    padding: 14px; border-radius: 10px; min-width: 0;
    background: var(--color-surface-alt, #f5f6f7);
    border: 1px solid var(--color-border, #e5e5e5);
    border-left: 4px solid var(--color-border, #e5e5e5);
}
.wp-meter.is-ok    { border-left-color: #107E3E; }
.wp-meter.is-falta { border-left-color: #E9730C; }
.wp-meter__head { display: flex; align-items: center; gap: 8px; }
.wp-meter__icon  { color: var(--color-text-muted, #6A6D70); }
.wp-meter__label { font-size: 0.8125rem; font-weight: 600; color: var(--color-text-muted, #6A6D70); }
.wp-meter__num   { margin: 4px 0 8px; font-size: 1.75rem; font-weight: 700; line-height: 1; color: var(--color-text-strong, #1f2329); }
.wp-meter__of    { font-size: 1rem; font-weight: 500; color: var(--color-text-muted, #6A6D70); }
.wp-meter__note  { margin: 6px 0 0; font-size: 0.75rem; color: var(--color-text-muted, #6A6D70); }

.wp-missing__title {
    margin: 18px 0 8px; font-size: 0.8125rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
    color: var(--color-text-muted, #6A6D70);
}
.wp-missing__list { list-style: none; margin: 0; padding: 0; }
.wp-missing {
    display: flex; align-items: flex-start; gap: 8px;
    margin: 0 0 6px; padding: 10px 12px; border-radius: 8px;
    font-size: 0.9375rem; line-height: 1.4;
}
.wp-missing:last-child { margin-bottom: 0; }
/* Sin titulo encima necesita su propio aire respecto de los marcadores. */
.wp-missing--solo { margin-top: 18px; }
.wp-missing--ok   { background: color-mix(in srgb, #107E3E 10%, transparent); color: #0b5f2f; }
.wp-missing--todo { background: color-mix(in srgb, #E9730C 12%, transparent); color: #8a4405; }
/* En oscuro el verde y el naranja oscuros se pierden contra el fondo. */
html[data-theme="dark"] .wp-missing--ok   { color: #4cc37c; }
html[data-theme="dark"] .wp-missing--todo { color: #f0a256; }

/* ── Tablet en vertical y móvil: una columna, nada de scroll horizontal ─── */
@media (max-width: 768px) {
    .wp-facts, .wp-meters { grid-template-columns: 1fr; }
    .wp-headline { font-size: 1.125rem; }
    .wp-viewswitch { width: 100%; }
    .wp-viewswitch :deep(.ant-segmented-group) { width: 100%; }
    .wp-viewswitch :deep(.ant-segmented-item) { flex: 1 1 0; }
    :deep(.ant-descriptions-item-label) {
        width: auto !important;
        min-width: 0 !important;
        white-space: normal !important;
        font-weight: 500;
    }
    :deep(.ant-descriptions-item-content) { word-break: break-word; }
}
</style>
