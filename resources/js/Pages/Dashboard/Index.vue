<script setup>
/**
 * El panel: la primera pantalla despues de entrar.
 *
 * Lo primero que se ve es **el dia en la obra** — cuantos planes hay hoy, que
 * falta por firmar, que formatos quedan sin confirmar y, debajo, plan por plan,
 * que le falta a cada uno. Todo lo demas (el workspace del admin, la plataforma
 * del super) va despues y en su propio bloque, para que nadie confunda el
 * estado de la cuenta con el estado del trabajo.
 *
 * Antes de esto la rama del usuario que no es super estaba literalmente vacia:
 * un supervisor entraba y solo veia el saludo.
 */
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Card, Tag, Empty, Tooltip, Button } from 'ant-design-vue';
import {
    DashboardOutlined, BankOutlined, CrownOutlined, ClockCircleOutlined,
    ThunderboltOutlined, UserOutlined, WarningOutlined,
    FileDoneOutlined, EditOutlined, FormOutlined, FolderOpenOutlined,
    SafetyCertificateOutlined,
    CheckCircleFilled, CloseCircleFilled, LoadingOutlined,
    PlusOutlined, RightOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
dayjs.extend(relativeTime);

import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/Plugins/i18n';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const page = usePage();
const { formatDateTimeFull } = useDateFormat();

const props = defineProps({
    isSuper:           { type: Boolean, default: false },
    // El panel del dia. null = el perfil no puede ver planes de trabajo.
    today:             { type: Object, default: null },
    // Estado de la cuenta — solo para el admin del workspace.
    workspaceWidgets:  { type: Array, default: () => [] },
    // Estado de la plataforma — solo super.
    widgets:           { type: Array, default: () => [] },
    recentAutomations: { type: Array, default: () => [] },
    expiringSoon:      { type: Array, default: () => [] },
});

const userName = computed(() => page.props.auth?.user?.name ?? '');
const roles    = computed(() => page.props.auth?.user?.roles ?? []);
const greeting = computed(() => {
    if (roles.value.includes('super')) return t('dashboard.role_super');
    if (roles.value.includes('admin')) return t('dashboard.role_admin');
    return t('dashboard.role_user');
});

const iconMap = {
    BankOutlined, CrownOutlined, ClockCircleOutlined, ThunderboltOutlined,
    UserOutlined, WarningOutlined, FileDoneOutlined, EditOutlined,
    FormOutlined, FolderOpenOutlined, SafetyCertificateOutlined,
};
const resolveIcon = (key) => iconMap[key] ?? DashboardOutlined;

const widgetColor = (color) => ({
    blue: '#0A6ED1', green: '#1D7044', cyan: '#13c2c2',
    orange: '#E76500', red: '#C8281D', gold: '#faad14',
    default: '#6A6D70',
}[color] ?? '#0A6ED1');

const runStatusIcon = (status) => ({
    success: CheckCircleFilled,
    failed:  CloseCircleFilled,
    running: LoadingOutlined,
}[status] ?? CheckCircleFilled);

const runStatusColor = (status) => ({
    success: '#1D7044', failed: '#C8281D', running: '#0A6ED1',
}[status] ?? '#6A6D70');

/**
 * El estado del plan, siempre color Y palabra: al sol el matiz se pierde y hay
 * quien no distingue el rojo del verde (docs/UI.md §5).
 */
const planState = (plan) => {
    if (plan.is_done)   return { color: 'green',  label: t('dashboard.plan_state_done') };
    if (plan.is_closed) return { color: 'default', label: t('dashboard.plan_state_closed') };
    return { color: 'orange', label: t('dashboard.plan_state_pending') };
};

const plans      = computed(() => props.today?.plans ?? []);
const plansExtra = computed(() => Math.max(0, (props.today?.plansTotal ?? 0) - plans.value.length));

const fmt    = (d) => formatDateTimeFull(d);
const fmtRel = (d) => (d ? dayjs(d).fromNow() : '—');
</script>

<template>
    <Head :title="$t('dashboard.title')" />

    <div class="sap-index dashboard">
        <!-- Cabecera: quien eres y que panel estas mirando -->
        <div class="mi-title" data-tour="module">
            <div class="page-header__title">
                <div class="page-header__icon">
                    <DashboardOutlined />
                </div>
                <div class="page-header__heading">
                    <h1>{{ greeting }}</h1>
                    <p>{{ $t('dashboard.hello', { name: userName || $t('dashboard.user') }) }}</p>
                </div>
            </div>
            <div v-if="today?.canCreate" class="mi-title__actions">
                <Link :href="today.createUrl">
                    <Button type="primary" size="large">
                        <template #icon><PlusOutlined /></template>
                        {{ $t('dashboard.plans_new') }}
                    </Button>
                </Link>
            </div>
        </div>

        <!-- ─── EL PANEL DEL DÍA ─────────────────────────────────────────── -->
        <template v-if="today">
            <div class="block-head">
                <h2>{{ $t('dashboard.today_title') }}</h2>
                <p>
                    {{ $t('dashboard.today_subtitle') }}
                    <span v-if="isSuper" class="muted">{{ $t('dashboard.today_all_scope') }}</span>
                </p>
            </div>

            <div class="widgets-grid">
                <component
                    v-for="w in today.widgets"
                    :key="w.key"
                    :is="w.href ? Link : 'div'"
                    :href="w.href"
                    class="widget-card"
                    :class="{ 'widget-card--link': !!w.href }"
                >
                    <div class="widget-card__icon" :style="{ background: widgetColor(w.color) }">
                        <component :is="resolveIcon(w.icon)" />
                    </div>
                    <div class="widget-card__body">
                        <div class="widget-card__value">{{ w.value }}</div>
                        <div class="widget-card__label">{{ $t('dashboard.widget_' + w.key) }}</div>
                        <div v-if="w.hint" class="widget-card__hint">{{ w.hint }}</div>
                    </div>
                </component>
            </div>

            <!-- Los planes de hoy, y qué le falta a cada uno -->
            <Card class="block-card" :bodyStyle="{ padding: 0 }">
                <template #title>
                    <FileDoneOutlined /> {{ $t('dashboard.plans_title') }}
                    <Tag v-if="today.plansTotal > 0" color="blue" :bordered="false">{{ today.plansTotal }}</Tag>
                </template>
                <template #extra>
                    <Link v-if="today.plansTotal > 0" :href="today.allUrl" class="block-card__link">
                        {{ $t('dashboard.plans_see_all') }} <RightOutlined />
                    </Link>
                </template>

                <Empty
                    v-if="plans.length === 0"
                    :description="$t('dashboard.plans_none')"
                    style="padding: 32px 16px"
                >
                    <p class="muted">{{ $t('dashboard.plans_none_hint') }}</p>
                    <Link v-if="today.canCreate" :href="today.createUrl">
                        <Button type="primary">
                            <template #icon><PlusOutlined /></template>
                            {{ $t('dashboard.plans_new') }}
                        </Button>
                    </Link>
                </Empty>

                <ul v-else class="row-list">
                    <li v-for="p in plans" :key="p.slug" class="row-item plan-row">
                        <!-- marca │ título + subtítulo │ estado + acción (docs/UI.md §4-bis) -->
                        <span class="plan-row__mark" :class="`plan-row__mark--${planState(p).color}`" aria-hidden="true"></span>

                        <div class="row-item__main">
                            <div class="plan-row__title">
                                <strong>{{ p.code }}</strong>
                                <span v-if="p.time" class="plan-row__time"><ClockCircleOutlined /> {{ p.time }}</span>
                            </div>
                            <div class="plan-row__sub muted">
                                {{ p.company || $t('dashboard.plan_no_company') }}
                                · {{ p.location || $t('dashboard.plan_no_location') }}
                            </div>
                            <div v-if="p.missing.length" class="plan-row__missing">
                                <ExclamationCircleOutlined />
                                <span>{{ $t('dashboard.plan_missing') }}: {{ p.missing.join(' · ') }}</span>
                            </div>
                        </div>

                        <div class="row-item__meta">
                            <Tag :color="planState(p).color" :bordered="false">{{ planState(p).label }}</Tag>
                            <Link :href="p.href" class="plan-row__open" :aria-label="p.code">
                                <RightOutlined />
                            </Link>
                        </div>
                    </li>
                </ul>

                <div v-if="plansExtra > 0" class="row-more">
                    <Link :href="today.allUrl">{{ $t('dashboard.plans_more', { count: plansExtra }) }}</Link>
                </div>
            </Card>
        </template>

        <!-- Sin permiso para ver planes: se dice, no se enseña una rejilla de ceros -->
        <Card v-else class="block-card">
            <h2 class="welcome-title">{{ $t('dashboard.welcome_title') }}</h2>
            <p class="welcome-text">{{ $t('dashboard.welcome_body') }}</p>
        </Card>

        <!-- ─── TU WORKSPACE (admin del tenant) ──────────────────────────── -->
        <template v-if="workspaceWidgets.length > 0">
            <div class="block-head">
                <h2>{{ $t('dashboard.workspace_title') }}</h2>
                <p>{{ $t('dashboard.workspace_subtitle') }}</p>
            </div>
            <div class="widgets-grid">
                <component
                    v-for="w in workspaceWidgets"
                    :key="w.key"
                    :is="w.href ? Link : 'div'"
                    :href="w.href"
                    class="widget-card"
                    :class="{ 'widget-card--link': !!w.href }"
                >
                    <div class="widget-card__icon" :style="{ background: widgetColor(w.color) }">
                        <component :is="resolveIcon(w.icon)" />
                    </div>
                    <div class="widget-card__body">
                        <div class="widget-card__value">{{ w.value }}</div>
                        <div class="widget-card__label">{{ $t('dashboard.widget_' + w.key) }}</div>
                        <div v-if="w.hint" class="widget-card__hint">{{ w.hint }}</div>
                    </div>
                </component>
            </div>
        </template>

        <!-- ─── PLATAFORMA (super) ───────────────────────────────────────── -->
        <template v-if="isSuper">
            <div class="block-head">
                <h2>{{ $t('dashboard.platform_title') }}</h2>
                <p>{{ $t('dashboard.platform_subtitle') }}</p>
            </div>

            <div class="widgets-grid">
                <component
                    v-for="w in widgets"
                    :key="w.key"
                    :is="w.href ? Link : 'div'"
                    :href="w.href"
                    class="widget-card"
                    :class="{ 'widget-card--link': !!w.href }"
                >
                    <div class="widget-card__icon" :style="{ background: widgetColor(w.color) }">
                        <component :is="resolveIcon(w.icon)" />
                    </div>
                    <div class="widget-card__body">
                        <div class="widget-card__value">{{ w.value }}</div>
                        <div class="widget-card__label">{{ $t('dashboard.widget_' + w.key) }}</div>
                        <div v-if="w.hint" class="widget-card__hint">{{ w.hint }}</div>
                    </div>
                </component>
            </div>

            <Card v-if="expiringSoon.length > 0" class="block-card" :bodyStyle="{ padding: 0 }">
                <template #title>
                    <ClockCircleOutlined /> {{ $t('dashboard.expiring_soon') }}
                    <Tag color="orange" :bordered="false">{{ expiringSoon.length }}</Tag>
                </template>
                <ul class="row-list">
                    <li v-for="s in expiringSoon" :key="s.id" class="row-item">
                        <div class="row-item__main">
                            <strong>{{ s.tenant_name }}</strong>
                            <Tag :bordered="false" class="row-item__tag">{{ s.plan?.toUpperCase() }}</Tag>
                        </div>
                        <div class="row-item__meta">
                            <Tag :color="s.days_remaining <= 3 ? 'red' : 'orange'" :bordered="false">
                                {{ $t('dashboard.days_left', { n: s.days_remaining }) }}
                            </Tag>
                            <Tooltip :title="fmt(s.ends_at)">
                                <span class="muted">{{ fmtRel(s.ends_at) }}</span>
                            </Tooltip>
                        </div>
                    </li>
                </ul>
            </Card>

            <Card class="block-card" :bodyStyle="{ padding: 0 }">
                <template #title>
                    <ThunderboltOutlined /> {{ $t('dashboard.recent_automations') }}
                </template>
                <Empty v-if="recentAutomations.length === 0" :description="$t('dashboard.no_automations_yet')" style="padding: 40px 16px" />
                <ul v-else class="row-list">
                    <li v-for="r in recentAutomations" :key="r.id" class="row-item">
                        <component :is="runStatusIcon(r.status)" :style="{ color: runStatusColor(r.status), fontSize: '18px' }" />
                        <div class="row-item__main">
                            <strong>{{ r.automation_name }}</strong>
                            <div v-if="r.output_summary" class="muted">{{ r.output_summary }}</div>
                        </div>
                        <div class="row-item__meta">
                            <Tooltip v-if="r.records_matched !== null" :title="$t('dashboard.records_processed')">
                                <Tag :bordered="false">{{ r.records_matched }}</Tag>
                            </Tooltip>
                            <Tooltip :title="fmt(r.started_at)">
                                <span class="muted">{{ fmtRel(r.started_at) }}</span>
                            </Tooltip>
                        </div>
                    </li>
                </ul>
            </Card>
        </template>
    </div>
</template>

<style scoped>
/* Cabecera de bloque: separa «la obra» de «la cuenta» de «la plataforma». */
.block-head { margin: 22px 0 10px; }
.block-head:first-of-type { margin-top: 4px; }
.block-head h2 {
    margin: 0; font-size: 1.05rem; font-weight: 600;
    color: var(--color-text-strong);
}
.block-head p {
    margin: 2px 0 0; font-size: 0.8125rem; color: var(--color-text-muted);
}

/* Welcome card — sin permiso de planes */
.welcome-title { margin: 0 0 6px; font-size: 1.05rem; font-weight: 600; }
.welcome-text { margin: 0; font-size: 0.9375rem; line-height: 1.6; color: var(--color-text); }

/* Rejilla de indicadores */
.widgets-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.widget-card {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px;
    /* 44px de alto minimo de toque: con guantes menos no se acierta. */
    min-height: 76px;
    background: white;
    border: 1px solid var(--color-border-soft);
    border-radius: 6px;
    transition: box-shadow 0.18s ease, transform 0.18s ease;
    text-decoration: none;
    color: inherit;
}
.widget-card--link { cursor: pointer; }
.widget-card--link:hover {
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
    transform: translateY(-2px);
}
.widget-card__icon {
    width: 44px; height: 44px; border-radius: 6px;
    color: white;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem; flex-shrink: 0;
}
.widget-card__body { min-width: 0; }
.widget-card__value {
    font-size: 1.75rem; font-weight: 700; color: var(--color-text-strong);
    line-height: 1.1;
}
.widget-card__label {
    font-size: 0.875rem; color: var(--color-text); margin-top: 2px;
    font-weight: 600;
}
.widget-card__hint {
    font-size: 0.75rem; color: var(--color-text-muted); margin-top: 2px;
}

/* Bloques de listado */
.block-card { border-radius: 6px; margin-bottom: 16px; }
.block-card__link { font-size: 0.8125rem; }

.row-list { list-style: none; margin: 0; padding: 0; }
.row-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border-soft);
}
.row-item:last-child { border-bottom: none; }
.row-item__main { flex: 1; min-width: 0; }
.row-item__tag { margin-left: 8px; }
.row-item__meta {
    display: flex; align-items: center; gap: 10px; flex-shrink: 0;
}
.muted { color: var(--color-text-muted); font-size: 0.8125rem; }

.row-more {
    padding: 10px 16px; font-size: 0.8125rem;
    border-top: 1px solid var(--color-border-soft);
}

/* Fila de plan: marca de estado, titulo, subtitulo y lo que le falta. */
.plan-row { align-items: flex-start; min-height: 56px; }
.plan-row__mark {
    width: 4px; align-self: stretch; border-radius: 2px; flex-shrink: 0;
    min-height: 34px;
}
.plan-row__mark--green   { background: #1D7044; }
.plan-row__mark--orange  { background: #E76500; }
.plan-row__mark--red     { background: #C8281D; }
.plan-row__mark--default { background: #A9B4BE; }

.plan-row__title {
    display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap;
}
.plan-row__title strong { font-size: 0.9375rem; }
.plan-row__time { font-size: 0.8125rem; color: var(--color-text-muted); }
.plan-row__sub {
    margin-top: 2px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.plan-row__missing {
    display: flex; align-items: flex-start; gap: 6px;
    margin-top: 6px;
    font-size: 0.8125rem;
    color: #8a4b00;
    line-height: 1.4;
}
.plan-row__open {
    display: inline-flex; align-items: center; justify-content: center;
    width: 44px; height: 44px;
    border-radius: 6px;
    color: var(--color-text-muted);
}
.plan-row__open:hover { background: var(--color-surface-alt); color: var(--color-primary); }

@media (max-width: 768px) {
    .widgets-grid { grid-template-columns: 1fr; }
    .row-item { flex-wrap: wrap; }
    .row-item__meta { width: 100%; justify-content: flex-end; margin-top: 4px; }
    .plan-row__sub { white-space: normal; }
}
</style>

<style>
html[data-theme="dark"] .widget-card {
    background: var(--color-surface-alt);
    border-color: var(--color-border-strong);
}
html[data-theme="dark"] .plan-row__missing { color: #f0a868; }
</style>
