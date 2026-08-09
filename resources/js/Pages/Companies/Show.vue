<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Alert,
} from 'ant-design-vue';
import { BankOutlined, TeamOutlined, SolutionOutlined } from '@ant-design/icons-vue';

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
    company: { type: Object, required: true },
    activity:   { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.company.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);
</script>

<template>
    <Head :title="company.name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.companies.index')"
            :title="company.name"
            :icon-bg="iconBg"
        >
            <template #icon><BankOutlined /></template>
            <template #subtitle>
                <Space :size="6" wrap>
                    <span v-if="company.complete_name" class="cab-razon">{{ company.complete_name }}</span>
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="company.is_active ? 'success' : 'default'" :bordered="false">
                        {{ company.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="companies"
                    route-prefix="business_management"
                    :slug="company.slug"
                    :id="company.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('companies.edit')"
                    :can-delete="can('companies.delete')"
                    :can-see-audit="canSeeAudit"
                    :is-super="isSuper"
                    :is-global="company.tenant_id === null"
                    :lock="company.lock"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(company.deleted_at) }}</div>
                <div v-if="company.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ company.deleter.name }}
                </div>
                <div v-if="company.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ company.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="companies" route-prefix="business_management" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <!-- Los dos numeros por los que se abre la ficha de una
                     contratista: cuanta gente tiene y cuantos planes ha hecho.
                     Antes eran dos cajas mas entre ocho, del mismo tamaño que el
                     slug, y no llevaban a ninguna parte. Ahora son la entrada al
                     listado ya filtrado por esta empresa. -->
                <div class="ficha-cifras">
                    <Link
                        class="cifra"
                        :href="route('business_management.people.index', { company_id: [company.id] })"
                    >
                        <span class="cifra__num">{{ company.people_count ?? 0 }}</span>
                        <span class="cifra__lbl"><TeamOutlined /> {{ $t('companies.people_count') }}</span>
                    </Link>
                    <Link
                        class="cifra"
                        :href="route('business_management.work_plans.index', { company_id: [company.id] })"
                    >
                        <span class="cifra__num">{{ company.work_plans_count ?? 0 }}</span>
                        <span class="cifra__lbl"><SolutionOutlined /> {{ $t('companies.plans_count') }}</span>
                    </Link>
                </div>

                <Card :bodyStyle="{ padding: 0 }" class="info-card">
                    <template #title><BankOutlined /> {{ $t('global.general_info') }}</template>

                    <!-- Filas etiqueta/valor en vez de ocho cajas iguales: el RUC
                         y la razon social son lo que identifica a la empresa y hay
                         que poder leerlos de un vistazo. El nombre y el estado NO
                         se repiten aqui: ya estan en la cabecera. -->
                    <dl class="ficha-datos">
                        <div class="ficha-datos__fila">
                            <dt>{{ $t('companies.num_doc') }}</dt>
                            <dd><code class="ruc">{{ company.num_doc || '—' }}</code></dd>
                        </div>
                        <div class="ficha-datos__fila">
                            <dt>{{ $t('companies.country') }}</dt>
                            <dd>{{ company.country?.name || '—' }}</dd>
                        </div>
                        <div class="ficha-datos__fila">
                            <dt>{{ $t('global.created_at') }}</dt>
                            <dd>
                                {{ fmt(company.created_at) }}
                                <span v-if="company.creator" class="muted">· {{ company.creator.name }}</span>
                            </dd>
                        </div>
                        <div class="ficha-datos__fila">
                            <dt>{{ $t('global.updated_at') }}</dt>
                            <dd>{{ fmt(company.updated_at) }}</dd>
                        </div>
                    </dl>

                    <!-- Tecnico: el ID y el slug no son datos de la empresa, son
                         de la base. Al pie y en gris, no compitiendo con el RUC. -->
                    <p v-if="isSuper" class="ficha-tecnico">
                        ID {{ company.id }} · <code>{{ company.slug }}</code>
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
.show-page { /* fullscreen — sin max-width, ocupa todo el ancho del content */ }
.muted { color: var(--color-text-muted); font-size: 0.8125rem; }
.cab-razon { color: var(--color-text-muted); font-size: 0.875rem; }
.deleted-alert { margin-bottom: 16px; }
.info-card { margin-bottom: 16px; border-radius: 8px; }

/* ── Las dos cifras de cabecera ────────────────────────────────────────────
   Son enlaces al listado ya filtrado: desde la ficha de una contratista lo
   siguiente que se quiere es «enseñame su gente» o «enseñame sus planes». */
.ficha-cifras { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
.ficha-cifras .cifra { flex: 0 1 200px; }
.cifra {
    display: flex; flex-direction: column; gap: 2px;
    padding: 14px 18px; border-radius: 8px;
    background: var(--color-surface, #fff);
    border: 1px solid var(--color-border-soft, #eceff2);
    color: inherit; transition: border-color .12s ease, box-shadow .12s ease;
}
.cifra:hover { border-color: var(--color-primary, #0A6ED1); box-shadow: 0 1px 6px rgba(10,110,209,.12); }
.cifra__num { font-size: 1.75rem; font-weight: 600; line-height: 1.1; color: var(--color-primary, #0A6ED1); }
.cifra__lbl { display: inline-flex; align-items: center; gap: 6px; font-size: .8125rem; color: var(--color-text-muted); }

/* ── Datos: filas, no cajas ──────────────────────────────────────────────── */
.ficha-datos { margin: 0; }
.ficha-datos__fila {
    display: grid; grid-template-columns: 200px 1fr; gap: 16px;
    padding: 12px 18px; border-bottom: 1px solid var(--color-border-soft, #f0f2f5);
}
.ficha-datos__fila:last-child { border-bottom: 0; }
.ficha-datos dt { color: var(--color-text-muted); font-size: .8125rem; }
.ficha-datos dd { margin: 0; color: var(--color-text); }
.ruc { font-size: 1rem; letter-spacing: .04em; }
.ficha-tecnico {
    margin: 0; padding: 10px 18px;
    border-top: 1px solid var(--color-border-soft, #f0f2f5);
    color: var(--color-text-muted); font-size: .75rem;
}

@media (max-width: 767px) {
    .ficha-cifras .cifra { flex: 1 1 100%; }
    /* A una columna: la etiqueta encima del valor, que 200px de etiqueta en un
       movil dejan el valor en un canal de dos palabras. */
    .ficha-datos__fila { grid-template-columns: 1fr; gap: 2px; }
}

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
