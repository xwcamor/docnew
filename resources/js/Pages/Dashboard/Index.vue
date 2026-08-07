<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Card, Tag, Empty, Tooltip } from 'ant-design-vue';
import {
    DashboardOutlined, BankOutlined, CrownOutlined, ClockCircleOutlined,
    ThunderboltOutlined, UserOutlined, WarningOutlined,
    CheckCircleFilled, CloseCircleFilled, LoadingOutlined,
    PlusCircleFilled, EditFilled, DeleteFilled, ExportOutlined,
    UndoOutlined, HistoryOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
dayjs.extend(relativeTime);

import AppLayout from '@/Layouts/AppLayout.vue';
import FleetDashboard from '@/Components/Dashboard/FleetDashboard.vue';
import { useI18n } from '@/Plugins/i18n';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const { t } = useI18n();
const page = usePage();
const { formatDateTimeFull } = useDateFormat();

const props = defineProps({
    isSuper:      { type: Boolean, default: false },
    widgets:           { type: Array, default: () => [] },
    recentAutomations: { type: Array, default: () => [] },
    expiringSoon:      { type: Array, default: () => [] },
    // Para la vista simple del non-super: últimas acciones del propio user.
    recentActivity:    { type: Array, default: () => [] },
    // Dashboard de flota del tenant (non-super).
    fleet:         { type: Object, default: null },
    fleetFilters:  { type: Object, default: () => ({ customers: [], types: [] }) },
    fleetActive:   { type: Object, default: () => ({ customer: [], type: [], rating: [] }) },
});

const userName = computed(() => page.props.auth?.user?.name ?? '');
const roles    = computed(() => page.props.auth?.user?.roles ?? []);
const greeting = computed(() => {
    if (roles.value.includes('super')) return t('dashboard.role_super');
    if (roles.value.includes('admin'))       return t('dashboard.role_admin');
    return t('dashboard.role_user');
});

const iconMap = {
    BankOutlined, CrownOutlined, ClockCircleOutlined, ThunderboltOutlined,
    UserOutlined, WarningOutlined,
};
const resolveIcon = (key) => iconMap[key] ?? DashboardOutlined;

const widgetColor = (color) => ({
    blue: '#1677ff', green: '#52c41a', cyan: '#13c2c2',
    orange: '#fa8c16', red: '#f5222d', gold: '#faad14',
    default: '#8c8c8c',
}[color] ?? '#1677ff');

const runStatusIcon = (status) => ({
    success: CheckCircleFilled,
    failed:  CloseCircleFilled,
    running: LoadingOutlined,
}[status] ?? CheckCircleFilled);

const runStatusColor = (status) => ({
    success: '#52c41a', failed: '#f5222d', running: '#1677ff',
}[status] ?? '#8c8c8c');

// Meta por tipo de evento de audit (icono + color + label i18n).
const eventMeta = (event) => {
    switch (event) {
        case 'created':       return { icon: PlusCircleFilled, color: '#1D7044', label: t('global.event_created') };
        case 'updated':       return { icon: EditFilled,        color: '#0A6ED1', label: t('global.event_updated') };
        case 'deleted':       return { icon: DeleteFilled,      color: '#C8281D', label: t('global.event_deleted') };
        case 'force_deleted': return { icon: DeleteFilled,      color: '#7E1810', label: t('global.event_force_deleted') };
        case 'restored':      return { icon: UndoOutlined,      color: '#1D7044', label: t('global.event_restored') };
        case 'exported':      return { icon: ExportOutlined,    color: '#6A6D70', label: t('global.event_exported') };
        case 'export_queued': return { icon: ExportOutlined,    color: '#6A6D70', label: t('global.event_export_queued') };
        default:              return { icon: HistoryOutlined,   color: '#6A6D70', label: event };
    }
};

const fmt    = (d) => formatDateTimeFull(d);
const fmtRel = (d) => d ? dayjs(d).fromNow() : '—';
</script>

<template>
    <Head :title="$t('dashboard.title')" />

    <div class="sap-index dashboard">
        <!-- Header común a ambos roles: saludo + descripción del rol -->
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
        </div>

        <!-- ─── VISTA SUPER: dashboard completo ─────────────────────────── -->
        <template v-if="isSuper">
            <!-- Widgets / KPIs -->
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
                        <div class="widget-card__label">{{ $t('dashboard.widget_' + w.label) }}</div>
                        <div v-if="w.hint" class="widget-card__hint">{{ w.hint }}</div>
                    </div>
                </component>
            </div>

            <!-- Flota cross-tenant (todos los workspaces) + desglose por workspace -->
            <FleetDashboard v-if="fleet" :fleet="fleet" :filters="fleetFilters" :active="fleetActive" class="dashboard__fleet" />

            <!-- Suscripciones por vencer -->
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

            <!-- Automatizaciones recientes -->
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
                                <Tag :bordered="false">{{ r.records_matched }} rec.</Tag>
                            </Tooltip>
                            <Tooltip :title="fmt(r.started_at)">
                                <span class="muted">{{ fmtRel(r.started_at) }}</span>
                            </Tooltip>
                        </div>
                    </li>
                </ul>
            </Card>
        </template>

        <!-- ─── VISTA NON-SUPER: dashboard de flota + actividad reciente ──────── -->
        <template v-else>
            <FleetDashboard v-if="fleet" :fleet="fleet" :filters="fleetFilters" :active="fleetActive" />
        </template>
    </div>
</template>

<style scoped>
/* Full-width: ocupa todo el ancho del shell (no max-width restrictiva). */

/* Welcome card — non-super */
.welcome-card { border-radius: 6px; margin-bottom: 16px; }
.welcome-text {
    margin: 0; font-size: 0.9375rem; line-height: 1.6;
    color: var(--color-text);
}

/* Widgets grid */
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
.widget-card__value {
    font-size: 1.5rem; font-weight: 700; color: var(--color-text-strong);
    line-height: 1.1;
}
.widget-card__label {
    font-size: 0.8125rem; color: var(--color-text); margin-top: 2px;
}
.widget-card__hint {
    font-size: 0.75rem; color: var(--color-text-muted); margin-top: 2px;
}

/* Bloques de listado */
.block-card { border-radius: 6px; margin-bottom: 16px; }

.row-list {
    list-style: none; margin: 0; padding: 0;
}
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

/* Icono pequeño coloreado para cada evento del audit */
.event-icon {
    width: 32px; height: 32px; border-radius: 50%;
    color: white;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.95rem; flex-shrink: 0;
}

@media (max-width: 640px) {
    .row-item { flex-wrap: wrap; }
    .row-item__meta { width: 100%; justify-content: flex-end; margin-top: 4px; }
}
</style>

<style>
html[data-theme="dark"] .widget-card {
    background: var(--color-surface-alt);
    border-color: var(--color-border-strong);
}
</style>
