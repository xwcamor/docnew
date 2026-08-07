<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Descriptions, DescriptionsItem, Alert, Button, Tooltip,
} from 'ant-design-vue';
import { SolutionOutlined, EnvironmentOutlined, AppstoreOutlined, ClusterOutlined, ThunderboltOutlined, BarChartOutlined, TeamOutlined, ApartmentOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import ShareModal from '@/Components/Sharing/ShareModal.vue';
import CustomerHierarchyTree from '@/Components/Customers/CustomerHierarchyTree.vue';
import CustomerOrgChart from '@/Components/Customers/CustomerOrgChart.vue';
import CustomerStructureTable from '@/Components/Customers/CustomerStructureTable.vue';
import CustomerCards from '@/Components/Customers/CustomerCards.vue';
import { Segmented } from 'ant-design-vue';
import { ref, watch } from 'vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const props = defineProps({
    customer: { type: Object, required: true },
    activity:   { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
    hierarchy:  { type: Object, default: () => ({ nodes: [], totals: { locations: 0, areas: 0, substations: 0, transformers: 0 } }) },
    // Usuario acotado a su cartera asignada: solo-lectura en Clientes.
    isCustomerRestricted: { type: Boolean, default: false },
});

// Escritura sobre el cliente: además del permiso, NO debe estar restringido.
const canEditCustomer   = computed(() => can('customers.edit')   && !props.isCustomerRestricted);
const canDeleteCustomer = computed(() => can('customers.delete') && !props.isCustomerRestricted);

const totals = computed(() => props.hierarchy?.totals ?? { locations: 0, areas: 0, substations: 0, transformers: 0 });

// Conteo animado de los totales (rAF). OJO: es un watch (no onMounted) porque
// al crear/borrar nodos de la jerarquía Inertia refresca los props SIN
// remontar el componente — con onMounted los números quedaban congelados y
// había que refrescar la página. Re-anima desde el valor visible actual.
const animTotals = ref({ locations: 0, areas: 0, substations: 0, transformers: 0 });
watch(totals, (to) => {
    const from = { ...animTotals.value };
    const start = performance.now();
    const dur = 700;
    const tick = (now) => {
        const p = Math.min(1, (now - start) / dur);
        const e = 1 - Math.pow(1 - p, 3); // easeOutCubic
        animTotals.value = {
            locations:    Math.round(from.locations    + (to.locations    - from.locations) * e),
            areas:        Math.round(from.areas        + (to.areas        - from.areas) * e),
            substations:  Math.round(from.substations  + (to.substations  - from.substations) * e),
            transformers: Math.round(from.transformers + (to.transformers - from.transformers) * e),
        };
        if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
}, { immediate: true });

// Tarjetas de totales (data-driven para entrada escalonada).
const totalCards = computed(() => [
    { key: 'locations',    icon: EnvironmentOutlined,  label: 'customers.locations' },
    { key: 'areas',        icon: AppstoreOutlined,     label: 'customers.areas' },
    { key: 'substations',  icon: ClusterOutlined,      label: 'customers.substations' },
    { key: 'transformers', icon: ThunderboltOutlined,  label: 'customers.transformers', accent: true },
]);

// Vista de la estructura: árbol editable o organigrama (solo lectura).
const view = ref('tree');

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.customer.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);
</script>

<template>
    <Head :title="customer.name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.customers.index')"
            :title="customer.name"
            :icon-bg="iconBg"
        >
            <template #icon><TeamOutlined /></template>
            <template #subtitle>
                <Space :size="6">
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="customer.is_active ? 'success' : 'default'" :bordered="false">
                        {{ customer.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <Space :size="8">
                    <Tooltip v-if="!isDeleted && can('transformers.view') && totals.transformers > 0" :title="$t('comparison.compare_fleet_help')">
                        <Link :href="route('business_management.comparison.patrones', { customer: customer.id })">
                            <Button><template #icon><BarChartOutlined /></template><span class="btn-txt">{{ $t('comparison.compare_fleet') }}</span></Button>
                        </Link>
                    </Tooltip>
                    <ShareModal v-if="!isDeleted" scope-type="fleet" :scope-id="customer.id" />
                    <EntityShowActions
                        module="customers"
                        route-prefix="business_management"
                        :slug="customer.slug"
                        :id="customer.id"
                        :is-deleted="isDeleted"
                        :can-edit="canEditCustomer"
                        :can-delete="canDeleteCustomer"
                        :can-see-audit="canSeeAudit"
                    :is-super="isSuper"
                    :is-global="customer.tenant_id === null"
                    :lock="customer.lock"
                    />
                </Space>
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(customer.deleted_at) }}</div>
                <div v-if="customer.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ customer.deleter.name }}
                </div>
                <div v-if="customer.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ customer.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="customers" route-prefix="business_management" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <!-- Información general: mismo patrón que la ficha de Usuario
                     (card con hero + grilla de specs). -->
                <Card :bodyStyle="{ padding: 0 }" class="info-card">
                    <template #title><TeamOutlined /> {{ $t('global.general_info') }}</template>
                    <div class="cust-hero">
                        <div class="cust-hero__logo">
                            <img v-if="customer.logo_url" :src="customer.logo_url" :alt="customer.name" />
                            <SolutionOutlined v-else class="cust-hero__ph" />
                        </div>
                        <div class="cust-hero__body">
                            <h2 class="cust-hero__name">{{ customer.name }}</h2>
                            <p v-if="customer.address" class="cust-hero__addr">
                                <EnvironmentOutlined /> {{ customer.address }}
                            </p>
                        </div>
                    </div>
                    <div class="spec-pad">
                        <div class="spec-grid">
                            <div v-if="isSuper" class="spec-cell">
                                <span class="spec-cell__label">ID</span>
                                <span class="spec-cell__value">{{ customer.id }}</span>
                            </div>
                            <div v-if="isSuper && customer.slug" class="spec-cell">
                                <span class="spec-cell__label">Slug</span>
                                <span class="spec-cell__value"><code class="muted">{{ customer.slug }}</code></span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('customers.name') }}</span>
                                <span class="spec-cell__value">{{ customer.name }}</span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('customers.cod') }}</span>
                                <span class="spec-cell__value"><code v-if="customer.cod">{{ customer.cod }}</code><template v-else>—</template></span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('customers.country') }}</span>
                                <span class="spec-cell__value">{{ customer.country ? customer.country.iso_code + ' · ' + customer.country.name : '—' }}</span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('customers.address') }}</span>
                                <span class="spec-cell__value">{{ customer.address || '—' }}</span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('customers.is_active') }}</span>
                                <span class="spec-cell__value">
                                    <Tag :color="customer.is_active ? 'success' : 'default'" :bordered="false">
                                        {{ customer.is_active ? $t('global.active') : $t('global.inactive') }}
                                    </Tag>
                                </span>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Totales por nivel (con conteo animado y entrada escalonada) -->
                <div class="cust-totals">
                    <div
                        v-for="(c, i) in totalCards"
                        :key="c.key"
                        class="tot animate-in"
                        :class="{ 'tot--accent': c.accent }"
                        :style="{ animationDelay: (i * 70) + 'ms' }"
                    >
                        <component :is="c.icon" class="tot__icon" />
                        <b>{{ animTotals[c.key] }}</b>
                        <span>{{ $t(c.label) }}</span>
                    </div>
                </div>

                <!-- Estructura: árbol editable u organigrama (toggle) -->
                <Card class="info-card">
                    <template #title><ApartmentOutlined /> {{ $t('customers.structure') }}</template>
                    <template #extra>
                        <Segmented
                            v-model:value="view"
                            :options="[
                                { value: 'tree', label: $t('customers.view_tree') },
                                { value: 'chart', label: $t('customers.view_chart') },
                                { value: 'table', label: $t('customers.view_table') },
                                { value: 'cards', label: $t('customers.view_cards') },
                            ]"
                        />
                    </template>
                    <Transition name="view-fade" mode="out-in">
                        <CustomerHierarchyTree
                            v-if="view === 'tree'"
                            :customer="{ id: customer.id, slug: customer.slug }"
                            :hierarchy="hierarchy"
                            :can-edit="canEditCustomer && (isSuper || customer.tenant_id !== null)"
                        />
                        <CustomerOrgChart v-else-if="view === 'chart'" :customer-name="customer.name" :nodes="hierarchy.nodes" />
                        <CustomerStructureTable v-else-if="view === 'table'" :nodes="hierarchy.nodes" />
                        <CustomerCards v-else :nodes="hierarchy.nodes" />
                    </Transition>
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

/* ── Entrada animada (GPU-friendly, una sola vez) ── */
@keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
.animate-in { animation: fadeInUp 0.45s cubic-bezier(0.22, 1, 0.36, 1) both; }
@media (prefers-reduced-motion: reduce) { .animate-in { animation: none; } }

/* ── Banner de identidad (estilo SAP Fiori: claro, acento azul sutil) ── */
/* Hero embebido en la card de Información general (patrón de la ficha de
   Usuario): cabecera con logo + nombre + dirección y divisor inferior. */
.cust-hero {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--color-border-soft);
    min-width: 0;
}
.cust-hero__logo {
    width: 68px; height: 68px; border-radius: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; overflow: hidden;
    background: var(--color-surface-alt, #f4f6f8); border: 1px solid var(--color-border, #e5e7eb);
}
.cust-hero__logo img { width: 100%; height: 100%; object-fit: cover; }
.cust-hero__ph { font-size: 1.9rem; color: var(--color-primary, #0A6ED1); opacity: 0.55; }
.cust-hero__body { min-width: 0; }
.cust-hero__name { font-size: 1.1rem; font-weight: 600; margin: 0; line-height: 1.2; color: var(--color-text, #32363A); word-break: break-word; overflow-wrap: anywhere; }
.cust-hero__addr {
    margin: 2px 0 0 0;
    display: flex;
    align-items: flex-start;
    gap: 6px;
    font-size: 0.8125rem;
    color: var(--color-text-muted, #6A6D70);
    line-height: 1.35;
    word-break: break-word;
    overflow-wrap: anywhere;
}
.cust-hero__addr .anticon { margin-top: 3px; flex-shrink: 0; }

html[data-theme="dark"] .cust-hero { border-color: #3f4448; }
html[data-theme="dark"] .cust-hero__name { color: #e5e6e7; }
html[data-theme="dark"] .cust-hero__logo { background: #23272b; border-color: #3f4448; }

/* Transición al cambiar de vista de estructura */
.view-fade-enter-active, .view-fade-leave-active { transition: opacity 0.18s ease, transform 0.18s ease; }
.view-fade-enter-from { opacity: 0; transform: translateY(6px); }
.view-fade-leave-to { opacity: 0; transform: translateY(-6px); }

/* ── Totales ── */
.cust-totals { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
.tot {
    flex: 1; min-width: 120px;
    display: flex; flex-direction: column; align-items: center; gap: 2px;
    padding: 16px 10px; border-radius: 12px;
    background: var(--color-surface, #fff); border: 1px solid var(--color-border, #e5e7eb);
    transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
}
.tot:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
.tot__icon { font-size: 1.1rem; color: var(--color-primary, #0A6ED1); margin-bottom: 4px; }
.tot b { font-size: 1.7rem; font-weight: 700; color: var(--color-text, #1f2937); line-height: 1; }
.tot span { font-size: 0.8rem; color: var(--color-text-muted, #6A6D70); }
.tot--accent { border-color: #E9A23B; background: linear-gradient(180deg, rgba(233,162,59,0.07), transparent); }
.tot--accent .tot__icon { color: #E9A23B; }
.tot--accent b { color: #B9791F; }
.tot--accent:hover { box-shadow: 0 8px 20px rgba(233,162,59,0.20); }

html[data-theme="dark"] .tot { background: #2c3034; border-color: #3f4448; }
html[data-theme="dark"] .tot b { color: #e5e6e7; }

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
