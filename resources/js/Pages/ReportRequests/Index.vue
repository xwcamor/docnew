<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Tabs, TabPane, Card, Button, Tag, Empty, Modal, Progress, Pagination, message } from 'ant-design-vue';
import {
    SolutionOutlined, FilePdfOutlined, ClusterOutlined, ThunderboltOutlined,
    DownloadOutlined, RedoOutlined, ShareAltOutlined,
} from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/Plugins/i18n';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const props = defineProps({
    requests: { type: Object, default: () => ({ data: [] }) },  // paginador: { data, current_page, last_page, per_page, total }
    counts:   { type: Object, default: () => ({ review: 0, approved: 0, rejected: 0 }) },
    tab:      { type: String, default: 'review' },
});

const { t } = useI18n();
const { formatDateTimeFull } = useDateFormat();

const submitting = ref(false);
const activeTab = ref(props.tab);
watch(() => props.tab, (v) => { activeTab.value = v; });

// El server manda SOLO la página de la pestaña activa.
const rowsFor = (key) => (props.tab === key ? props.requests.data : []);

const goTab = (key) => {
    if (key === props.tab) return;
    router.get(route('report_requests.index'), { tab: key }, { preserveScroll: true, preserveState: true });
};
const goPage = (page) => {
    router.get(route('report_requests.index'), { tab: props.tab, page }, { preserveScroll: true, preserveState: true });
};

const pct = (p) => (p.total ? Math.round((p.approved / p.total) * 100) : 0);

// Etiqueta de un transformador: "serie · tag" si hay ambos; si falta uno, el que exista.
const trafoLabel = (tr) => (tr.serial && tr.tag) ? `${tr.serial} · ${tr.tag}` : (tr.serial || tr.tag || '—');

// Descarga el PDF emitido de un informe aprobado.
const download = (instanceId) => {
    window.open(route('report_requests.download', instanceId), '_blank', 'noopener');
};

// Reenvía una solicitud rechazada a aprobación.
const resubmit = (row) => {
    Modal.confirm({
        title: t('approvals.mr_resubmit'),
        icon: null,
        content: t('approvals.mr_confirm_resubmit'),
        okText: t('approvals.mr_resubmit'),
        cancelText: t('global.cancel'),
        onOk: () => {
            submitting.value = true;
            return new Promise((resolve) => {
                router.post(route('report_requests.resubmit', row.id), {}, {
                    preserveScroll: true,
                    onSuccess: () => message.success(t('approvals.resubmit_ok')),
                    onFinish: () => { submitting.value = false; resolve(); },
                });
            });
        },
    });
};
</script>

<template>
    <Head :title="t('approvals.my_requests_title')" />

    <div class="sap-index myreq">
        <div class="mi-title" data-tour="module">
            <div class="page-header__title">
                <div class="page-header__icon"><SolutionOutlined /></div>
                <div class="page-header__heading">
                    <h1>{{ t('approvals.my_requests_title') }}</h1>
                    <p>{{ t('approvals.my_requests_subtitle') }}</p>
                </div>
            </div>
        </div>

        <Tabs v-model:activeKey="activeTab" @change="goTab">
            <!-- En revisión -->
            <TabPane key="review" :tab="`${t('approvals.mr_tab_review')} (${counts.review})`">
                <Empty v-if="!rowsFor('review').length" :description="t('approvals.mr_empty_review')" style="padding: 48px 16px" />
                <div v-else class="cards">
                    <Card v-for="row in rowsFor('review')" :key="row.id" class="rcard" :bodyStyle="{ padding: '16px 18px' }">
                        <div class="rcard__top">
                            <div class="rcard__title">
                                <component :is="row.scope === 'fleet' ? ClusterOutlined : ThunderboltOutlined" />
                                <strong>{{ row.label || (row.customer || '—') }}</strong>
                                <Tag color="processing" :bordered="false">{{ t('approvals.mr_status_in_review') }}</Tag>
                            </div>
                            <div class="rcard__meta">
                                {{ t('approvals.mr_sent_on') }}: {{ row.created_at ? formatDateTimeFull(row.created_at) : '—' }}
                            </div>
                        </div>

                        <div v-if="row.customer" class="rcard__client">{{ t('approvals.mr_customer') }}: {{ row.customer }}</div>

                        <div v-if="row.transformers?.length" class="rcard__trafos">
                            <Tag v-for="tr in row.transformers" :key="tr.instance_id" :bordered="false">{{ trafoLabel(tr) }}</Tag>
                        </div>

                        <div class="rcard__progress">
                            <Progress :percent="pct(row.progress)" size="small" :show-info="false" style="max-width:160px" />
                            <span class="rcard__pmuted">{{ t('approvals.progress', { approved: row.progress.approved, total: row.progress.total }) }}</span>
                        </div>

                        <div class="rcard__signers">
                            <span class="rcard__tlabel">{{ t('approvals.mr_signers') }}:</span>
                            <Tag v-for="(s, i) in row.signers" :key="i"
                                 :color="s.status === 'approved' ? 'success' : (s.status === 'rejected' ? 'error' : 'default')"
                                 :bordered="false">
                                {{ s.title || s.relation }}
                            </Tag>
                        </div>

                        <div v-if="row.recipient" class="rcard__recipient">
                            {{ t('approvals.mr_recipient') }}: {{ row.recipient }}
                        </div>
                    </Card>
                </div>
            </TabPane>

            <!-- Aprobadas -->
            <TabPane key="approved" :tab="`${t('approvals.mr_tab_approved')} (${counts.approved})`">
                <Empty v-if="!rowsFor('approved').length" :description="t('approvals.mr_empty_approved')" style="padding: 48px 16px" />
                <div v-else class="cards">
                    <Card v-for="row in rowsFor('approved')" :key="row.id" class="rcard" :bodyStyle="{ padding: '16px 18px' }">
                        <div class="rcard__top">
                            <div class="rcard__title">
                                <component :is="row.scope === 'fleet' ? ClusterOutlined : ThunderboltOutlined" />
                                <strong>{{ row.label || (row.customer || '—') }}</strong>
                                <Tag color="success" :bordered="false">{{ t('approvals.mr_status_approved') }}</Tag>
                                <Tag v-if="row.shared" color="blue" :bordered="false"><ShareAltOutlined /> {{ t('approvals.mr_shared_badge') }}</Tag>
                            </div>
                            <div class="rcard__meta">
                                {{ t('approvals.mr_issued_on') }}: {{ row.issued_at ? formatDateTimeFull(row.issued_at) : '—' }}
                            </div>
                        </div>

                        <div v-if="row.customer" class="rcard__client">{{ t('approvals.mr_customer') }}: {{ row.customer }}</div>

                        <div class="rcard__trafos">
                            <Button v-for="tr in row.transformers" :key="tr.instance_id" size="small" type="link" @click="download(tr.instance_id)">
                                <FilePdfOutlined /> {{ trafoLabel(tr) }}
                            </Button>
                        </div>
                    </Card>
                </div>
            </TabPane>

            <!-- Rechazadas -->
            <TabPane key="rejected" :tab="`${t('approvals.mr_tab_rejected')} (${counts.rejected})`">
                <Empty v-if="!rowsFor('rejected').length" :description="t('approvals.mr_empty_rejected')" style="padding: 48px 16px" />
                <div v-else class="cards">
                    <Card v-for="row in rowsFor('rejected')" :key="row.id" class="rcard" :bodyStyle="{ padding: '16px 18px' }">
                        <div class="rcard__top">
                            <div class="rcard__title">
                                <component :is="row.scope === 'fleet' ? ClusterOutlined : ThunderboltOutlined" />
                                <strong>{{ row.label || (row.customer || '—') }}</strong>
                                <Tag color="error" :bordered="false">{{ t('approvals.mr_status_rejected') }}</Tag>
                            </div>
                            <div class="rcard__meta">
                                {{ t('approvals.mr_sent_on') }}: {{ row.created_at ? formatDateTimeFull(row.created_at) : '—' }}
                            </div>
                        </div>

                        <div v-if="row.customer" class="rcard__client">{{ t('approvals.mr_customer') }}: {{ row.customer }}</div>

                        <div v-if="row.transformers?.length" class="rcard__trafos">
                            <Tag v-for="tr in row.transformers" :key="tr.instance_id" :bordered="false">{{ trafoLabel(tr) }}</Tag>
                        </div>

                        <div v-if="row.reject_reason" class="rcard__reason">
                            <strong>{{ t('approvals.reject_reason_label') }}<template v-if="row.rejected_by"> ({{ row.rejected_by }})</template>:</strong>
                            {{ row.reject_reason }}
                        </div>

                        <div class="rcard__actions">
                            <Button type="primary" :loading="submitting" @click="resubmit(row)">
                                <RedoOutlined /> {{ t('approvals.mr_resubmit') }}
                            </Button>
                        </div>
                    </Card>
                </div>
            </TabPane>
        </Tabs>

        <div v-if="requests.last_page > 1" class="myreq__pager">
            <Pagination :current="requests.current_page" :total="requests.total" :page-size="requests.per_page"
                        :show-size-changer="false" @change="goPage" />
        </div>
    </div>
</template>

<style scoped>
.cards { display: flex; flex-direction: column; gap: 12px; }
.myreq__pager { margin: 16px 0 0; display: flex; justify-content: center; }
.rcard__top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.rcard__title { display: flex; align-items: center; gap: 8px; font-size: 1rem; flex-wrap: wrap; }
.rcard__meta { font-size: 0.8rem; color: var(--color-text-muted); }
.rcard__progress { display: flex; align-items: center; gap: 10px; margin: 10px 0 0; }
.rcard__pmuted { font-size: 0.8rem; color: var(--color-text-muted); }
.rcard__signers { margin: 10px 0 0; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.rcard__tlabel { font-size: 0.8rem; color: var(--color-text-muted); margin-right: 4px; }
.rcard__trafos { margin: 8px 0 0; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.rcard__client { margin: 6px 0 0; font-size: 0.85rem; color: var(--color-text); }
.rcard__recipient { margin: 8px 0 0; font-size: 0.82rem; color: var(--color-text-muted); }
.rcard__reason { margin: 10px 0 0; font-size: 0.85rem; color: var(--color-text); background: var(--color-surface-alt, #f6f7f9); padding: 8px 10px; border-radius: 4px; }
.rcard__actions { margin: 14px 0 0; display: flex; justify-content: flex-end; gap: 8px; }
</style>
