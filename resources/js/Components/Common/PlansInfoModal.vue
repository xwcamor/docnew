<script setup>
/**
 * PlansInfoModal — comparativa de planes 100% data-driven.
 *
 * Lee los planes públicos+activos del prop `plans` (que viene del controller →
 * Plan::publicComparisonData()). Cualquier plan nuevo que el super cree
 * desde el módulo Plans aparece automáticamente sin tocar código ni i18n.
 *
 * No hay cards hardcoded ni taglines traducidas por plan: el `tagline` viene
 * de DB tal cual y se renderiza junto al header del plan en la tabla.
 */
import { computed } from 'vue';
import { Modal, Empty } from 'ant-design-vue';
import {
    CheckCircleOutlined, CloseCircleOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import { resolveIconComponent, resolveColor } from '@/Utils/planAppearance';

const { t } = useI18n();

const props = defineProps({
    open:  { type: Boolean, default: false },
    plans: { type: Array,   default: () => [] },
});

defineEmits(['update:open']);

const fmtLimit = (n) => n < 0 ? '∞' : Number(n).toLocaleString();
const fmtMoney = (n, c) => n > 0 ? `${c || 'USD'} ${Number(n).toFixed(2)}` : '—';

// Feature keys + labels — mismo orden que el Form del módulo Plans. Las
// labels SÍ son traducibles porque describen funcionalidades genéricas
// ("Exportar CSV") que no dependen del plan.
const featureRows = computed(() => [
    { key: 'export_csv',              labelKey: 'plans.feature_exportCsv' },
    { key: 'export_excel',            labelKey: 'plans.feature_exportExcel' },
    { key: 'export_pdf',              labelKey: 'plans.feature_exportPdf' },
    { key: 'export_word',             labelKey: 'plans.feature_exportWord' },
    { key: 'audit_log_view',          labelKey: 'plans.feature_auditLogView' },
    { key: 'saved_views',             labelKey: 'plans.feature_savedViews' },
    { key: 'bulk_operations',         labelKey: 'plans.feature_bulkOperations' },
    { key: 'api_access',              labelKey: 'plans.feature_apiAccess' },
    { key: 'branded_exports',         labelKey: 'plans.feature_brandedExports' },
    { key: 'scheduled_exports',       labelKey: 'plans.feature_scheduledExports' },
    { key: 'export_webhook_delivery', labelKey: 'plans.feature_exportWebhookDelivery' },
    { key: 'export_email_delivery',   labelKey: 'plans.feature_exportEmailDelivery' },
    { key: 'extended_retention',      labelKey: 'plans.feature_extendedRetention' },
    { key: 'higher_export_rate_limit',labelKey: 'plans.feature_higherExportRateLimit' },
]);

// support_level no es booleano — se renderiza como texto en su propia fila.
const supportLabel = (level) => {
    const map = {
        community: t('plans.support_community'),
        email:     t('plans.support_email'),
        priority:  t('plans.support_priority'),
    };
    return map[level] || map.community;
};

// El color de fondo del header de columna viene del campo `color` del plan
// (editable desde el módulo Plans). Si no hay color guardado, queda neutro.
const planColumnStyle = (plan) => {
    const c = resolveColor(plan.color);
    const tints = {
        blue:    '#E6F1FB',
        cyan:    '#E0F7F7',
        green:   '#EBF7EB',
        gold:    '#FFF7E0',
        orange:  '#FFF1E0',
        red:     '#FFE6E6',
        purple:  '#F0E6F7',
        magenta: '#FBE6F0',
        default: 'var(--color-surface-alt)',
    };
    return { background: tints[c] || tints.default };
};
const iconFor = (plan) => resolveIconComponent(plan.icon);
</script>

<template>
    <Modal
        :open="open"
        :title="$t('plans.modal_title')"
        :footer="null"
        :width="980"
        @cancel="$emit('update:open', false)"
        destroy-on-close
    >
        <p class="hint">{{ $t('plans.modal_intro') }}</p>

        <Empty v-if="plans.length === 0" :description="$t('plans.no_public_plans')" />

        <table v-else class="plans-table">
            <thead>
                <tr>
                    <th>{{ $t('plans.feature') }}</th>
                    <th
                        v-for="plan in plans"
                        :key="plan.slug"
                        class="col-plan"
                        :style="planColumnStyle(plan)"
                    >
                        <div class="plan-header">
                            <!-- Slot del icono SIEMPRE reservado (con o sin icono) para
                                 que todos los headers tengan el mismo alto y los nombres
                                 queden alineados horizontalmente entre planes. -->
                            <div class="plan-header__icon-slot">
                                <component :is="iconFor(plan)" v-if="iconFor(plan)" class="plan-header__icon" />
                            </div>
                            <div class="plan-header__name">{{ plan.name.toUpperCase() }}</div>
                        </div>
                    </th>
                </tr>
            </thead>
            <tbody>
                <!-- Límites numéricos -->
                <tr>
                    <td class="feature-cell">{{ $t('plans.feature_users') }}</td>
                    <td v-for="plan in plans" :key="plan.slug" class="value-cell">
                        <strong>{{ fmtLimit(plan.max_users) }}</strong>
                    </td>
                </tr>
                <tr>
                    <td class="feature-cell">{{ $t('plans.feature_records') }}</td>
                    <td v-for="plan in plans" :key="plan.slug" class="value-cell">
                        <strong>{{ fmtLimit(plan.max_records_per_module) }}</strong>
                    </td>
                </tr>
                <tr>
                    <td class="feature-cell">{{ $t('plans.feature_export_rate') }}</td>
                    <td v-for="plan in plans" :key="plan.slug" class="value-cell">
                        <strong>{{ plan.export_rate_limit }}/min</strong>
                    </td>
                </tr>

                <!-- Features booleanas -->
                <tr v-for="row in featureRows" :key="row.key">
                    <td class="feature-cell">{{ $t(row.labelKey) }}</td>
                    <td v-for="plan in plans" :key="plan.slug" class="value-cell">
                        <CheckCircleOutlined v-if="plan.features?.[row.key]" class="icon-yes" />
                        <CloseCircleOutlined v-else class="icon-no" />
                    </td>
                </tr>

                <!-- Nivel de soporte — texto, no booleano -->
                <tr>
                    <td class="feature-cell">{{ $t('plans.support_level') }}</td>
                    <td v-for="plan in plans" :key="plan.slug" class="value-cell">
                        <strong>{{ supportLabel(plan.support_level) }}</strong>
                    </td>
                </tr>

                <!-- Precios -->
                <tr class="price-row">
                    <td class="feature-cell">{{ $t('plans.price_monthly') }}</td>
                    <td v-for="plan in plans" :key="plan.slug" class="value-cell">
                        <strong>{{ fmtMoney(plan.price_monthly, plan.currency) }}</strong>
                    </td>
                </tr>
                <tr class="price-row">
                    <td class="feature-cell">{{ $t('plans.price_yearly') }}</td>
                    <td v-for="plan in plans" :key="plan.slug" class="value-cell">
                        <strong>{{ fmtMoney(plan.price_yearly, plan.currency) }}</strong>
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="footnote">{{ $t('plans.modal_footnote') }}</p>
    </Modal>
</template>

<style scoped>
.hint { color: var(--color-text-muted); margin-bottom: 18px; line-height: 1.5; }

.plans-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}
.plans-table th {
    text-align: left;
    padding: 12px;
    background: var(--color-surface-alt);
    font-weight: 600;
    color: var(--color-text-strong);
    border-bottom: 2px solid var(--color-border-soft);
    vertical-align: middle;
}
.plans-table th.col-plan {
    text-align: center;
    min-width: 130px;
}

.plan-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
}
/* Slot reservado del icono — siempre la misma altura, con o sin icono.
   Garantiza que los nombres de planes queden alineados entre columnas. */
.plan-header__icon-slot {
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.plan-header__icon {
    font-size: 18px;
}
.plan-header__name {
    font-size: 0.9375rem;
    font-weight: 700;
    letter-spacing: 0.5px;
}

.plans-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--color-border-soft);
}
.feature-cell { color: var(--color-text); }
.value-cell {
    text-align: center;
    font-size: 0.875rem;
}
.icon-yes { color: #52c41a; font-size: 18px; }
.icon-no  { color: #d9d9d9; font-size: 18px; }

.price-row td { background: var(--color-surface-alt); }
.price-row .feature-cell { font-weight: 600; }

.footnote {
    margin-top: 20px;
    font-size: 0.8125rem;
    color: var(--color-text-muted);
    text-align: center;
    font-style: italic;
}

@media (max-width: 768px) {
    .plans-table { font-size: 0.8125rem; }
    .plans-table th, .plans-table td { padding: 8px 6px; }
}
@media (max-width: 480px) {
    .plans-table { font-size: 0.75rem; }
}
</style>
