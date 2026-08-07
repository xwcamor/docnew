<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Tabs, TabPane, Card, Button, Tag, Empty, Modal, Input, Progress, Pagination, message } from 'ant-design-vue';
import { CheckCircleOutlined, CloseCircleOutlined, FilePdfOutlined, AuditOutlined, ClusterOutlined, ThunderboltOutlined } from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/Plugins/i18n';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const props = defineProps({
    rows:   { type: Object, default: () => ({ data: [] }) },  // paginador de la pestaña activa
    counts: { type: Object, default: () => ({ pending: 0, done: 0 }) },
    tab:    { type: String, default: 'pending' },
});

const { t } = useI18n();
const { formatDateTimeFull } = useDateFormat();

const submitting = ref(false);
const activeTab = ref(props.tab);
watch(() => props.tab, (v) => { activeTab.value = v; });

// El server manda SOLO la página de la pestaña activa.
const rowsFor = (key) => (props.tab === key ? props.rows.data : []);
const goTab = (key) => {
    if (key === props.tab) return;
    router.get(route('approvals.index'), { tab: key }, { preserveScroll: true, preserveState: true });
};
const goPage = (page) => {
    router.get(route('approvals.index'), { tab: props.tab, page }, { preserveScroll: true, preserveState: true });
};

// Etiqueta de un transformador: "serie · tag" si hay ambos; si falta uno, el que exista.
const trafoLabel = (tr) => (tr.serial && tr.tag) ? `${tr.serial} · ${tr.tag}` : (tr.serial || tr.tag || '—');

// Abre el borrador (EN REVISIÓN, sin firmas) en una pestaña nueva.
const viewDraft = (instanceId) => {
    window.open(route('approvals.draft', instanceId), '_blank', 'noopener');
};

const approve = (row) => {
    Modal.confirm({
        title: t('approvals.approve'),
        icon: null,
        content: row.label || '',
        okText: t('approvals.approve'),
        cancelText: t('global.cancel'),
        onOk: () => {
            submitting.value = true;
            return new Promise((resolve) => {
                router.post(route('approvals.approve', row.approval_id), {}, {
                    preserveScroll: true,
                    onSuccess: () => message.success(t('approvals.approved_ok')),
                    onFinish: () => { submitting.value = false; resolve(); },
                });
            });
        },
    });
};

// ── Rechazo con motivo ──
const rejectOpen = ref(false);
const rejectRow = ref(null);
const rejectReason = ref('');
const openReject = (row) => { rejectRow.value = row; rejectReason.value = ''; rejectOpen.value = true; };
const submitReject = () => {
    if ((rejectReason.value || '').trim().length < 5) return;
    submitting.value = true;
    router.post(route('approvals.reject', rejectRow.value.approval_id), { reason: rejectReason.value }, {
        preserveScroll: true,
        onSuccess: () => { message.success(t('approvals.rejected_ok')); rejectOpen.value = false; },
        onFinish: () => { submitting.value = false; },
    });
};
</script>

<template>
    <Head :title="t('approvals.title')" />

    <div class="sap-index approvals">
        <div class="mi-title" data-tour="module">
            <div class="page-header__title">
                <div class="page-header__icon"><AuditOutlined /></div>
                <div class="page-header__heading">
                    <h1>{{ t('approvals.title') }}</h1>
                    <p>{{ t('approvals.subtitle') }}</p>
                </div>
            </div>
        </div>

        <Tabs v-model:activeKey="activeTab" @change="goTab">
            <TabPane key="pending" :tab="`${t('approvals.tab_pending')} (${counts.pending})`">
                <Empty v-if="!rowsFor('pending').length" :description="t('approvals.empty')" style="padding: 48px 16px" />
                <div v-else class="cards">
                    <Card v-for="row in rowsFor('pending')" :key="row.approval_id" class="rcard" :bodyStyle="{ padding: '16px 18px' }">
                        <div class="rcard__top">
                            <div class="rcard__title">
                                <component :is="row.scope === 'fleet' ? ClusterOutlined : ThunderboltOutlined" />
                                <strong>{{ row.label }}</strong>
                                <Tag :bordered="false">{{ row.scope === 'fleet' ? t('approvals.fleet_one') : t('approvals.col_serial') }}</Tag>
                            </div>
                            <div class="rcard__meta">
                                {{ t('approvals.col_preparer') }}: {{ row.requester || '—' }} ·
                                {{ row.created_at ? formatDateTimeFull(row.created_at) : '—' }}
                            </div>
                        </div>

                        <div v-if="row.customer" class="rcard__client">{{ t('approvals.mr_customer') }}: {{ row.customer }}</div>

                        <div class="rcard__role">
                            {{ t('approvals.your_relation') }}: <Tag color="blue" :bordered="false">{{ row.relation }}<template v-if="row.title"> — {{ row.title }}</template></Tag>
                        </div>

                        <div class="rcard__progress">
                            <Progress :percent="row.progress.total ? Math.round(row.progress.approved / row.progress.total * 100) : 0" size="small" :show-info="false" style="max-width:160px" />
                            <span class="rcard__pmuted">{{ t('approvals.progress', { approved: row.progress.approved, total: row.progress.total }) }}</span>
                        </div>

                        <div class="rcard__trafos">
                            <span class="rcard__tlabel">{{ t('approvals.transformers') }}:</span>
                            <Button v-for="tr in row.transformers" :key="tr.instance_id" size="small" type="link" @click="viewDraft(tr.instance_id)">
                                <FilePdfOutlined /> {{ trafoLabel(tr) }}
                            </Button>
                        </div>

                        <div class="rcard__actions">
                            <Button danger @click="openReject(row)"><CloseCircleOutlined /> {{ t('approvals.reject') }}</Button>
                            <Button type="primary" :loading="submitting" @click="approve(row)"><CheckCircleOutlined /> {{ t('approvals.approve') }}</Button>
                        </div>
                    </Card>
                </div>
            </TabPane>

            <TabPane key="done" :tab="`${t('approvals.tab_done')} (${counts.done})`">
                <Empty v-if="!rowsFor('done').length" :description="t('approvals.empty_done')" style="padding: 48px 16px" />
                <div v-else class="cards">
                    <Card v-for="row in rowsFor('done')" :key="row.approval_id" class="rcard rcard--done" :bodyStyle="{ padding: '14px 18px' }">
                        <div class="rcard__top">
                            <div class="rcard__title">
                                <component :is="row.scope === 'fleet' ? ClusterOutlined : ThunderboltOutlined" />
                                <strong>{{ row.label }}</strong>
                                <Tag :bordered="false" :color="row.status === 'approved' ? 'success' : 'error'">
                                    {{ row.status === 'approved' ? t('approvals.approved_badge') : t('approvals.rejected_badge') }}
                                </Tag>
                            </div>
                            <div class="rcard__meta">
                                {{ t('approvals.col_preparer') }}: {{ row.requester || '—' }} ·
                                {{ row.acted_at ? formatDateTimeFull(row.acted_at) : '—' }}
                            </div>
                        </div>

                        <div v-if="row.customer" class="rcard__client">{{ t('approvals.mr_customer') }}: {{ row.customer }}</div>

                        <div v-if="row.transformers?.length" class="rcard__trafos">
                            <Tag v-for="tr in row.transformers" :key="tr.instance_id" :bordered="false">{{ trafoLabel(tr) }}</Tag>
                        </div>

                        <div v-if="row.reason" class="rcard__reason">{{ t('approvals.reject_reason') }}: {{ row.reason }}</div>
                    </Card>
                </div>
            </TabPane>
        </Tabs>

        <div v-if="rows.last_page > 1" class="approvals__pager">
            <Pagination :current="rows.current_page" :total="rows.total" :page-size="rows.per_page"
                        :show-size-changer="false" @change="goPage" />
        </div>

        <!-- Modal de rechazo -->
        <Modal v-model:open="rejectOpen" :title="t('approvals.reject_title')"
               :ok-text="t('approvals.reject')" :ok-button-props="{ danger: true, disabled: (rejectReason || '').trim().length < 5 }"
               :confirm-loading="submitting" @ok="submitReject">
            <p class="reject-hint">{{ t('approvals.reject_hint') }}</p>
            <Input.TextArea v-model:value="rejectReason" :rows="4" :maxlength="500"
                            :placeholder="t('approvals.reject_reason')" />
        </Modal>
    </div>
</template>

<style scoped>
.cards { display: flex; flex-direction: column; gap: 12px; }
.approvals__pager { margin: 16px 0 0; display: flex; justify-content: center; }
.rcard__top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.rcard__title { display: flex; align-items: center; gap: 8px; font-size: 1rem; }
.rcard__meta { font-size: 0.8rem; color: var(--color-text-muted); }
.rcard__client { margin: 6px 0 0; font-size: 0.85rem; color: var(--color-text); }
.rcard__role { margin: 10px 0 0; font-size: 0.875rem; }
.rcard__progress { display: flex; align-items: center; gap: 10px; margin: 8px 0 0; }
.rcard__pmuted { font-size: 0.8rem; color: var(--color-text-muted); }
.rcard__trafos { margin: 10px 0 0; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.rcard__tlabel { font-size: 0.8rem; color: var(--color-text-muted); margin-right: 4px; }
.rcard__actions { margin: 14px 0 0; display: flex; justify-content: flex-end; gap: 8px; }
.rcard__reason { margin: 8px 0 0; font-size: 0.85rem; color: var(--color-text-muted); }
.rcard--done { opacity: 0.92; }
.reject-hint { color: var(--color-text-muted); margin: 0 0 10px; font-size: 0.875rem; }
</style>
