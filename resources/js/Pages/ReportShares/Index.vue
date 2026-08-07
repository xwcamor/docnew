<script setup>
/**
 * "Envíos de informes" — historial de enlaces compartidos CRUZANDO clientes.
 *
 * El modal de compartir muestra el historial de un solo alcance (un trafo o la
 * flota de un cliente). Esta pantalla responde "¿qué mandé esta semana?" sin
 * entrar cliente por cliente. Ver docs/COMPARTIR-REPORTES.md.
 */
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import {
    Card, Table, Tag, Input, Select, Button, Space, Tooltip, Popconfirm, DatePicker, message,
} from 'ant-design-vue';
import { ShareAltOutlined, CopyOutlined, DeleteOutlined, ReloadOutlined } from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/Plugins/i18n';

const props = defineProps({
    rows:      { type: Object, required: true },
    filters:   { type: Object, default: () => ({}) },
    customers: { type: Array,  default: () => [] },
    tenants:   { type: Array,  default: () => [] },
    isSuper:   { type: Boolean, default: false },
});

const { t } = useI18n();

const f = ref({
    q:          props.filters.q ?? '',
    estado:     props.filters.estado ?? undefined,
    f_customer: props.filters.f_customer ? Number(props.filters.f_customer) : undefined,
    f_tenant:   props.filters.f_tenant ? Number(props.filters.f_tenant) : undefined,
    desde:      props.filters.desde ?? undefined,
    hasta:      props.filters.hasta ?? undefined,
});

let debounce = null;
const aplicar = () => {
    router.get(route('business_management.report_shares_log.index'), f.value, {
        preserveState: true, preserveScroll: true, replace: true,
    });
};
watch(() => f.value.q, () => { clearTimeout(debounce); debounce = setTimeout(aplicar, 350); });
watch(() => [f.value.estado, f.value.f_customer, f.value.f_tenant, f.value.desde, f.value.hasta], aplicar);

const limpiar = () => {
    f.value = { q: '', estado: undefined, f_customer: undefined, f_tenant: undefined, desde: undefined, hasta: undefined };
};

const copiar = async (url) => {
    try { await navigator.clipboard.writeText(url); message.success(t('sharing.copied')); } catch (_) { /* noop */ }
};

const revocar = (row) => {
    router.delete(route('business_management.report_shares_log.revoke', row.id), {
        preserveScroll: true,
        onSuccess: () => message.success(t('sharing.revoked')),
    });
};

// Las fechas llegan YA formateadas en la zona del usuario (App\Support\Tz).
// No se re-convierten acá: hacerlo con la zona del navegador era justamente el
// bug — la misma fecha se veia distinta segun la maquina.
const fecha = (v) => v || '—';

const COLOR = { active: 'success', expired: 'error', revoked: 'default' };

// En movil la columna de acciones NO va fija: al superponerse recortaba el
// nombre del cliente a media palabra.
const esMovil = ref(false);
const medir = () => { esMovil.value = window.innerWidth <= 768; };
onMounted(() => { medir(); window.addEventListener('resize', medir); });
onBeforeUnmount(() => window.removeEventListener('resize', medir));

const cambiarPagina = (pag) => {
    router.get(route('business_management.report_shares_log.index'),
        { ...f.value, page: pag.current },
        { preserveState: true, preserveScroll: true, replace: true });
};
</script>

<template>
    <AppLayout>
        <Head :title="$t('sharing.log_title')" />

        <div class="sap-index">
            <!-- Cabecera estandar de indice (mismo patron que Mis solicitudes). -->
            <div class="mi-title" data-tour="module">
                <div class="page-header__title">
                    <div class="page-header__icon"><ShareAltOutlined /></div>
                    <div class="page-header__heading">
                        <h1>{{ $t('sharing.log_title') }}</h1>
                        <p>{{ $t('sharing.log_subtitle') }}</p>
                    </div>
                </div>
            </div>

            <Card :bordered="false">
                <div class="rs-filters">
                    <Input
                        v-model:value="f.q"
                        allow-clear
                        :placeholder="$t('sharing.log_search')"
                        class="rs-filters__search"
                    />
                    <Select
                        v-model:value="f.estado"
                        allow-clear
                        :placeholder="$t('sharing.log_state')"
                        style="min-width:150px"
                        :options="[
                            { value: 'active',  label: $t('sharing.state_active') },
                            { value: 'expired', label: $t('sharing.state_expired') },
                            { value: 'revoked', label: $t('sharing.state_revoked') },
                        ]"
                    />
                    <Select
                        v-model:value="f.f_customer"
                        allow-clear
                        show-search
                        option-filter-prop="label"
                        :placeholder="$t('sharing.log_customer')"
                        style="min-width:200px"
                        :options="customers.map((c) => ({ value: c.id, label: c.name }))"
                    />
                    <Select
                        v-if="isSuper"
                        v-model:value="f.f_tenant"
                        allow-clear
                        :placeholder="$t('sharing.log_workspace')"
                        style="min-width:170px"
                        :options="tenants.map((x) => ({ value: x.id, label: x.name }))"
                    />
                    <DatePicker v-model:value="f.desde" value-format="YYYY-MM-DD" :placeholder="$t('sharing.log_from')" />
                    <DatePicker v-model:value="f.hasta" value-format="YYYY-MM-DD" :placeholder="$t('sharing.log_to')" />
                    <Button size="small" @click="limpiar"><ReloadOutlined /> {{ $t('global.clear') }}</Button>
                </div>

                <Table
                    :data-source="rows.data"
                    row-key="id"
                    size="middle"
                    :scroll="{ x: 'max-content' }"
                    :pagination="{
                        current: rows.current_page, pageSize: rows.per_page, total: rows.total,
                        showSizeChanger: false,
                    }"
                    @change="cambiarPagina"
                >
                    <Table.Column :title="$t('sharing.col_recipient')" data-index="recipient_email">
                        <template #default="{ record }">
                            <div>
                                <strong v-if="record.link_only">{{ $t('sharing.link_only_label') }}</strong>
                                <strong v-else>{{ record.recipient_email }}</strong>
                            </div>
                            <div v-if="record.note" class="rs-note">“{{ record.note }}”</div>
                        </template>
                    </Table.Column>

                    <Table.Column :title="$t('sharing.col_customer')" data-index="customer">
                        <template #default="{ record }">{{ record.customer || '—' }}</template>
                    </Table.Column>

                    <Table.Column :title="$t('sharing.col_scope')">
                        <template #default="{ record }">
                            <span v-if="record.scope_type === 'transformer'">{{ record.target || '—' }}</span>
                            <span v-else-if="record.target">{{ $t('sharing.partial_tag', { n: record.target }) }}</span>
                            <span v-else>{{ $t('sharing.sent_all_fleet') }}</span>
                        </template>
                    </Table.Column>

                    <Table.Column :title="$t('sharing.col_sent')">
                        <template #default="{ record }">{{ fecha(record.created_at) }}</template>
                    </Table.Column>

                    <Table.Column :title="$t('sharing.col_state')">
                        <template #default="{ record }">
                            <Tag :color="COLOR[record.estado]" :bordered="false">
                                {{ $t(`sharing.state_${record.estado}`) }}
                            </Tag>
                            <div class="rs-sub">
                                <template v-if="record.estado === 'revoked'">{{ fecha(record.revoked_at) }}</template>
                                <template v-else>{{ $t('sharing.expires_on', { date: fecha(record.expires_at) }) }}</template>
                            </div>
                        </template>
                    </Table.Column>

                    <Table.Column :title="$t('sharing.col_opens')">
                        <template #default="{ record }">
                            <span v-if="record.open_count > 0">
                                {{ record.open_count }}
                                <div class="rs-sub">{{ fecha(record.last_opened_at) }}</div>
                            </span>
                            <span v-else class="rs-sub">{{ $t('sharing.never_opened') }}</span>
                        </template>
                    </Table.Column>

                    <Table.Column :title="$t('sharing.col_sent_by')">
                        <template #default="{ record }">
                            {{ record.sent_by || '—' }}
                            <div v-if="isSuper && record.tenant" class="rs-sub">{{ record.tenant }}</div>
                        </template>
                    </Table.Column>

                    <Table.Column :title="$t('global.actions')" :fixed="esMovil ? false : 'right'">
                        <template #default="{ record }">
                            <Space>
                                <Tooltip :title="$t('sharing.copy_link')">
                                    <Button size="small" type="text" :disabled="record.estado !== 'active'" @click="copiar(record.url)">
                                        <template #icon><CopyOutlined /></template>
                                    </Button>
                                </Tooltip>
                                <Popconfirm
                                    v-if="record.estado === 'active'"
                                    :title="$t('sharing.revoke_confirm')"
                                    @confirm="revocar(record)"
                                >
                                    <Button size="small" type="text" danger>
                                        <template #icon><DeleteOutlined /></template>
                                    </Button>
                                </Popconfirm>
                            </Space>
                        </template>
                    </Table.Column>
                </Table>
            </Card>
        </div>
    </AppLayout>
</template>

<style scoped>
.rs-filters { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; align-items:center; }
.rs-filters__search { min-width:240px; flex:1 1 240px; }
.rs-note { font-size:0.78rem; font-style:italic; color:var(--color-text-muted,#6A6D70); }
.rs-sub  { font-size:0.75rem; color:var(--color-text-muted,#6A6D70); }
</style>
