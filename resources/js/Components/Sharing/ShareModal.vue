<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';
import {
    Modal, Button, Form, FormItem, Input, Select, Divider, Tag, Popconfirm, Dropdown, Menu, MenuItem, message, Empty, Alert, Tooltip,
    RadioGroup, RadioButton,
} from 'ant-design-vue';
import {
    ShareAltOutlined, CopyOutlined, ReloadOutlined, FieldTimeOutlined, DeleteOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import { usePlanFeatures } from '@/Composables/usePlanFeatures';

/**
 * ShareModal — botón + panel para compartir y GESTIONAR enlaces de un diagnóstico
 * (un trafo o una flota): crear, listar, reenviar, extender y revocar. Todo por
 * JSON (axios). Ver docs/COMPARTIR-REPORTES.md.
 */
const props = defineProps({
    scopeType: { type: String, required: true }, // 'transformer' | 'fleet'
    scopeId:   { type: [Number, String], required: true },
    label:     { type: String, default: '' },
    // Opcional: función async que captura los gráficos del trafo (echarts → PNG)
    // para cachearlos al compartir y que el portal reuse las imágenes reales.
    chartProvider: { type: Function, default: null },
    // Uso desde la selección múltiple del índice: el padre abre el modal y ya
    // trae elegidos los trafos, así que no se dibuja el botón propio.
    hideTrigger: { type: Boolean, default: false },
    openExternal: { type: Boolean, default: false },
    preselected: { type: Array, default: null },   // ids; null = toda la flota
    // Etiquetas de los preseleccionados ([{id,label}]). Vienen del indice para
    // no tener que cargar la flota entera solo para mostrar 2 nombres.
    preselectedOptions: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:openExternal']);

const { t } = useI18n();
const page = usePage();
const { canUse } = usePlanFeatures();

const enabled = computed(() => canUse('report_sharing'));
const title = computed(() => (props.scopeType === 'fleet' ? t('sharing.modal_title_fleet') : t('sharing.modal_title')));
// Workspace con aprobación exigida: compartir manda a aprobación (no crea enlace
// al toque). Al aprobarse, el sistema envía el enlace solo al destinatario.
const requiresApproval = computed(() => !!page.props.approvals?.requires_approval);

const open = ref(false);
const loading = ref(false);
const busy = ref(false);
const shares = ref([]);
const transformers = ref([]); // opciones de flota (solo scope fleet)
const selected = ref([]);     // trafos elegidos (default: todos)
const form = ref({ recipient_email: '', note: '', expiration_days: '30', locale: page.props.locale || 'es', delivery: 'email' });
const withRevoked = ref(false);   // los revocados se piden aparte
const listTotal = ref(0);
const listLimit = ref(0);
const loadingTx = ref(false);     // Select de flota: se carga al abrirlo

// Ancho del modal: comodo en escritorio (dos columnas) y practicamente a
// pantalla completa en movil, donde las columnas se apilan.
const ANCHO_ANGOSTO = 768;
const esAngosto = ref(false);
const medirAncho = () => { esAngosto.value = window.innerWidth <= ANCHO_ANGOSTO; };
onMounted(() => { medirAncho(); window.addEventListener('resize', medirAncho); });
onBeforeUnmount(() => window.removeEventListener('resize', medirAncho));
const anchoModal = computed(() => (esAngosto.value ? 'calc(100vw - 16px)' : 760));
const fleetCount = ref(0);        // total real de la flota del cliente

// Con aprobacion exigida el enlace lo manda el sistema al aprobarse, asi
// que hace falta destinatario: el modo "solo enlace" queda deshabilitado.
const canSubmit = computed(() => (form.value.delivery === 'link' && !requiresApproval.value) || !!form.value.recipient_email);

const isFleet = computed(() => props.scopeType === 'fleet');
// Contra el TOTAL de la flota, no contra las opciones cargadas: con
// preseleccion solo se cargan las elegidas y comparar contra esas daria
// siempre "todas" -> se compartiria la flota entera.
const allSelected = computed(() => isFleet.value && fleetCount.value > 0 && selected.value.length >= fleetCount.value);

// Ya vienen formateadas en la zona del usuario desde el backend (App\Support\Tz):
// convertirlas de nuevo con la zona del navegador daba horas distintas.
const fmtDate = (v) => v || null;
const fmtDateTime = (v) => v || null;

// Lista de enlaces: acotada, con "ver más". La lista viene ordenada del más
// reciente al más viejo, así que los visibles son los últimos enviados.
const SHARES_VISIBLE = 4;
const showAllShares = ref(false);
const shareQuery = ref('');
const filteredShares = computed(() => {
    const q = shareQuery.value.trim().toLowerCase();
    if (!q) return shares.value;
    return shares.value.filter((s) => (s.recipient_email || t('sharing.link_only_label')).toLowerCase().includes(q));
});
const visibleShares = computed(() => (showAllShares.value ? filteredShares.value : filteredShares.value.slice(0, SHARES_VISIBLE)));

// Lista de lo enviado: se muestran los primeros 6 y el resto se despliega.
const expandedSent = ref([]);
const toggleSent = (id) => {
    expandedSent.value = expandedSent.value.includes(id)
        ? expandedSent.value.filter((x) => x !== id)
        : [...expandedSent.value, id];
};
// El backend manda hasta 20 etiquetas; partial_count trae el TOTAL real, asi
// que los "+N mas" se cuentan sobre el total y no sobre lo que llego.
const sentTotal = (s) => s.partial_count ?? (s.sent_labels ?? []).length;
const sentHidden = (s) => sentTotal(s) - (s.sent_labels ?? []).length;
const sentPreview = (s) => {
    const all = s.sent_labels ?? [];
    if (!expandedSent.value.includes(s.id)) return all.slice(0, 6).join(', ');
    // Desplegado: lo que llego, y si el enlace tenia mas se dice cuantos faltan.
    const resto = sentHidden(s);
    return all.join(', ') + (resto > 0 ? t('sharing.sent_and_more', { n: resto }) : '');
};

const fetchShares = async () => {
    loading.value = true;
    try {
        // with_transformers solo la primera vez y si NO hay preseleccion: las
        // opciones del Select son la flota entera y casi nunca se abre.
        const needTx = isFleet.value && !(props.preselected ?? []).length;
        const { data } = await window.axios.get(route('business_management.report_shares.index'), {
            params: {
                scope_type: props.scopeType,
                scope_id: props.scopeId,
                with_revoked: withRevoked.value ? 1 : 0,
                with_transformers: needTx ? 1 : 0,
            },
        });
        shares.value = data.shares;
        listTotal.value = data.total ?? data.shares.length;
        if (data.fleet_count != null) fleetCount.value = data.fleet_count;
        listLimit.value = data.limit ?? 0;
        if (needTx) {
            transformers.value = data.transformers ?? [];
            selected.value = transformers.value.map((x) => x.id);
        } else {
            // Con preseleccion no se piden las opciones: se arman con lo elegido
            // en el indice y las demas llegan si el usuario abre el Select.
            selected.value = [...(props.preselected ?? [])];
            if (!transformers.value.length) {
                const dados = new Map((props.preselectedOptions ?? []).map((o) => [o.id, o.label]));
                transformers.value = selected.value.map((id) => ({ id, label: dados.get(id) ?? String(id) }));
            }
        }
    } catch (_) { /* noop */ } finally { loading.value = false; }
};

// Campos que son POR enlace: si no se limpian, el proximo enlace hereda en
// silencio la nota (y el destinatario) del anterior.
const limpiarCampos = () => {
    form.value.recipient_email = '';
    form.value.note = '';
};

const createShare = async () => {
    if (!canSubmit.value || busy.value) return;
    busy.value = true;
    try {
        // Captura los gráficos reales del trafo (solo scope transformer) para que
        // el portal reuse las mismas imágenes que el informe logueado.
        let images = null;
        if (props.scopeType === 'transformer' && props.chartProvider) {
            try { images = await props.chartProvider(); } catch (_) { images = null; }
        }
        const { data } = await window.axios.post(route('business_management.report_shares.store'), {
            scope_type: props.scopeType,
            transformer_id: props.scopeType === 'transformer' ? props.scopeId : null,
            customer_id: props.scopeType === 'fleet' ? props.scopeId : null,
            // Flota parcial: si no están todos, manda el subconjunto; sino null = todos.
            transformer_ids: isFleet.value && !allSelected.value ? selected.value : null,
            images,
            ...form.value,
            recipient_email: form.value.delivery === 'link' ? null : form.value.recipient_email,
        });
        // Workspace con aprobación exigida: no se creó enlace, se envió a
        // aprobación. Al aprobarse, el sistema manda el enlace solo al cliente.
        if (data.approval) {
            limpiarCampos();
            message.success(data.message);
            return;
        }
        shares.value.unshift(data.share);
        limpiarCampos();
        if (data.share.link_only) {
            await copyLink(data.share.url);
            message.success(t('sharing.created_link_only'));
        } else {
            message.success(t('sharing.created_short', { email: data.share.recipient_email }));
        }
    } catch (e) {
        message.error(e?.response?.data?.message || t('sharing.error'));
    } finally { busy.value = false; }
};

// Trae la flota completa al abrir el Select (antes venia siempre, aunque el
// Select no se abriera nunca).
const loadTransformers = async () => {
    if (loadingTx.value || !isFleet.value) return;
    loadingTx.value = true;
    try {
        const { data } = await window.axios.get(route('business_management.report_shares.index'), {
            params: { scope_type: props.scopeType, scope_id: props.scopeId, with_transformers: 1 },
        });
        if (data.transformers?.length) transformers.value = data.transformers;
    } catch (_) { /* noop */ } finally { loadingTx.value = false; }
};
const onSelectOpen = (abierto) => {
    // Las etiquetas provisorias son el id: si siguen asi, faltan los nombres.
    // Si solo estan las preseleccionadas, faltan las demas de la flota.
    if (abierto && transformers.value.length < fleetCount.value) loadTransformers();
};

const copyLink = async (url) => {
    try { await navigator.clipboard.writeText(url); message.success(t('sharing.copied')); } catch (_) { /* noop */ }
};
const resend = async (s) => {
    busy.value = true;
    try { await window.axios.post(route('business_management.report_shares.resend', s.id)); message.success(t('sharing.resent')); }
    finally { busy.value = false; }
};
const extend = async (s, days) => {
    busy.value = true;
    try {
        const { data } = await window.axios.post(route('business_management.report_shares.extend', s.id), { days });
        Object.assign(s, data.share);
        message.success(t('sharing.extended'));
    } finally { busy.value = false; }
};
const revoke = async (s) => {
    busy.value = true;
    try {
        await window.axios.delete(route('business_management.report_shares.revoke', s.id));
        if (withRevoked.value) {
            s.revoked_at = new Date().toISOString();   // queda a la vista, marcado
        } else {
            shares.value = shares.value.filter((x) => x.id !== s.id);
            listTotal.value = Math.max(0, listTotal.value - 1);
        }
        message.success(t('sharing.revoked'));
    } finally { busy.value = false; }
};

watch(open, (v) => {
    if (v) { limpiarCampos(); form.value.delivery = 'email'; showAllShares.value = false; shareQuery.value = ''; withRevoked.value = false; fetchShares(); }
    if (props.hideTrigger) { emit('update:openExternal', v); }
});
// Apertura controlada por el padre (selección múltiple del índice).
watch(() => props.openExternal, (v) => { if (v !== open.value) open.value = v; }, { immediate: true });
</script>

<template>
    <Tooltip v-if="enabled && !hideTrigger" :title="label || (scopeType === 'fleet' ? $t('sharing.share_fleet') : $t('sharing.share'))">
        <Button @click="open = true">
            <template #icon><ShareAltOutlined /></template><span class="btn-txt">{{ label || (scopeType === 'fleet' ? $t('sharing.share_fleet') : $t('sharing.share')) }}</span>
        </Button>
    </Tooltip>

    <Modal v-model:open="open" :title="title" :footer="null" :width="anchoModal" :style="{ top: esAngosto ? '8px' : '32px' }" destroy-on-close>

        <!-- Nuevo enlace -->
        <span class="sh-h">{{ $t('sharing.new_link') }}</span>
        <p class="sh-muted sh-intro">{{ form.delivery === 'link' ? $t('sharing.modal_intro_link') : $t('sharing.modal_intro') }}</p>
        <Alert v-if="requiresApproval" type="info" show-icon style="margin-bottom:12px;"
            :message="$t('sharing.approval_required')" />
        <Form layout="vertical" @submit.prevent="createShare">
            <!-- Dos columnas en escritorio; en <=768px la grilla colapsa a una
                 sola y todo queda vertical (ver .sh-grid en los estilos). -->
            <div class="sh-grid">
            <FormItem v-if="isFleet && transformers.length" class="sh-span2" :label="$t('sharing.field_transformers')" :extra="$t('sharing.field_transformers_hint')">
                <Select
                    v-model:value="selected"
                    mode="multiple"
                    :max-tag-count="3"
                    :options="transformers.map((x) => ({ value: x.id, label: x.label }))"
                    :placeholder="$t('sharing.all_transformers')"
                    :loading="loadingTx"
                    @dropdown-visible-change="onSelectOpen"
                />
            </FormItem>
            <FormItem :label="$t('sharing.field_delivery')" :class="form.delivery === 'link' ? 'sh-span2' : ''">
                <RadioGroup v-model:value="form.delivery" button-style="solid" size="small">
                    <RadioButton value="email">{{ $t('sharing.delivery_email') }}</RadioButton>
                    <RadioButton value="link" :disabled="requiresApproval">{{ $t('sharing.delivery_link') }}</RadioButton>
                </RadioGroup>
            </FormItem>
            <FormItem v-if="form.delivery === 'email'" :label="$t('sharing.field_email')" required>
                <Input v-model:value="form.recipient_email" type="email" placeholder="cliente@empresa.com" />
            </FormItem>
            <!-- Sin correo no hay codigo de acceso: el enlace ES la credencial.
                 Se dice claro para que sea una decision, no una sorpresa. -->
            <Alert v-else class="sh-span2" type="warning" show-icon style="margin-bottom:12px;"
                :message="$t('sharing.delivery_link_warning')" />
            <FormItem class="sh-span2" :label="$t('sharing.field_note')" :extra="$t('sharing.field_note_hint')">
                <Input v-model:value="form.note" :maxlength="250" :placeholder="$t('sharing.note_placeholder')" />
            </FormItem>
                <FormItem :label="$t('sharing.field_expiration')">
                    <Select v-model:value="form.expiration_days" :options="[
                        { value: '1', label: $t('sharing.exp_1') },
                        { value: '7', label: $t('sharing.exp_7') },
                        { value: '30', label: $t('sharing.exp_30') },
                        { value: '90', label: $t('sharing.exp_90') },
                    ]" />
                </FormItem>
                <FormItem :label="$t('sharing.field_language')">
                    <Select v-model:value="form.locale" :options="[
                        { value: 'es', label: $t('sharing.lang_es') },
                        { value: 'en', label: $t('sharing.lang_en') },
                    ]" />
                </FormItem>
            </div>
            <Button type="primary" block :loading="busy" :disabled="!canSubmit" @click="createShare">
                {{ requiresApproval ? $t('sharing.submit_approval') : (form.delivery === 'link' ? $t('sharing.submit_link') : $t('sharing.submit')) }}
            </Button>
        </Form>
        <Divider style="margin:14px 0;" />

        <!-- Enlaces activos -->
        <div class="sh-section">
            <div class="sh-listhead">
                <span class="sh-h">{{ $t('sharing.active_links') }}</span>
                <span v-if="shares.length" class="sh-count">{{ shares.length }}</span>
                <!-- Los revocados existen y se auditan: tienen que poder verse. -->
                <a class="sh-toggle" @click.prevent="withRevoked = !withRevoked; fetchShares()">
                    {{ withRevoked ? $t('sharing.hide_revoked') : $t('sharing.show_revoked') }}
                </a>
            </div>
            <!-- Con muchos enlaces, buscar por destinatario es más rápido que
                 desplegar la lista entera. -->
            <Input
                v-if="shares.length > SHARES_VISIBLE"
                v-model:value="shareQuery"
                size="small"
                allow-clear
                :placeholder="$t('sharing.filter_links')"
                style="margin-bottom:8px;"
            />
            <div v-if="loading" class="sh-muted">…</div>
            <Empty v-else-if="!shares.length" :image="false" :description="$t('sharing.no_links')" />
            <Empty v-else-if="!filteredShares.length" :image="false" :description="$t('sharing.no_links_match')" />
            <ul v-else class="sh-list">
                <li v-for="s in visibleShares" :key="s.id" class="sh-item" :class="{ 'sh-item--revoked': s.revoked_at }">
                    <div class="sh-item__main">
                        <strong v-if="s.link_only">{{ $t('sharing.link_only_label') }}</strong>
                        <strong v-else>{{ s.recipient_email }}</strong>
                        <Tag v-if="s.revoked_at" color="default" :bordered="false">{{ $t('sharing.revoked_tag') }}</Tag>
                        <Tag v-else-if="s.expired" color="error" :bordered="false">{{ $t('sharing.expired_tag') }}</Tag>
                        <Tag v-if="!s.revoked_at && !s.expired" color="success" :bordered="false">{{ $t('sharing.expires_on', { date: fmtDate(s.expires_at) }) }}</Tag>
                        <Tag v-if="s.partial_count" :bordered="false">{{ $t('sharing.partial_tag', { n: s.partial_count }) }}</Tag>
                    </div>
                    <div class="sh-item__meta">
                        <span v-if="s.created_at">{{ $t('sharing.sent_on', { date: fmtDateTime(s.created_at) }) }} · </span>
                        {{ s.open_count > 0 ? $t('sharing.opened_count', { n: s.open_count, date: fmtDate(s.last_opened_at) }) : $t('sharing.never_opened') }}
                        <span v-if="s.revoked_at"> · {{ $t('sharing.revoked_on', { date: fmtDateTime(s.revoked_at) }) }}</span>
                    </div>
                    <div v-if="s.note" class="sh-item__note">“{{ s.note }}”</div>
                    <!-- Qué se envió: la lista exacta si fue una selección, o
                         "toda la flota" si no se acotó. -->
                    <div v-if="scopeType === 'fleet'" class="sh-item__sent">
                        <template v-if="s.sent_labels && s.sent_labels.length">
                            <strong>{{ $t('sharing.sent_what') }}</strong>
                            <span>{{ sentPreview(s) }}</span>
                            <a v-if="sentTotal(s) > 6" @click.prevent="toggleSent(s.id)">
                                {{ expandedSent.includes(s.id) ? $t('sharing.sent_less') : $t('sharing.sent_more', { n: sentTotal(s) - 6 }) }}
                            </a>
                        </template>
                        <template v-else>
                            <strong>{{ $t('sharing.sent_what') }}</strong>
                            <span>{{ $t('sharing.sent_all_fleet') }}</span>
                        </template>
                    </div>
                    <div v-if="!s.revoked_at" class="sh-item__acts">
                        <Button size="small" type="text" :title="$t('sharing.copy_link')" @click="copyLink(s.url)"><template #icon><CopyOutlined /></template></Button>
                        <Button v-if="!s.link_only" size="small" type="text" :title="$t('sharing.a_resend')" :disabled="busy" @click="resend(s)"><template #icon><ReloadOutlined /></template></Button>
                        <Dropdown :trigger="['click']">
                            <Button size="small" type="text" :title="$t('sharing.a_extend')" :disabled="busy"><template #icon><FieldTimeOutlined /></template></Button>
                            <template #overlay>
                                <Menu @click="({ key }) => extend(s, key)">
                                    <MenuItem key="1">{{ $t('sharing.exp_1') }}</MenuItem>
                                    <MenuItem key="7">{{ $t('sharing.exp_7') }}</MenuItem>
                                    <MenuItem key="30">{{ $t('sharing.exp_30') }}</MenuItem>
                                    <MenuItem key="90">{{ $t('sharing.exp_90') }}</MenuItem>
                                </Menu>
                            </template>
                        </Dropdown>
                        <Popconfirm :title="$t('sharing.revoke_confirm')" @confirm="revoke(s)">
                            <Button size="small" type="text" danger :title="$t('sharing.a_revoke')" :disabled="busy"><template #icon><DeleteOutlined /></template></Button>
                        </Popconfirm>
                    </div>
                </li>
            </ul>
            <!-- Con muchos enlaces el modal se hacía interminable: se muestran
                 los últimos y el resto se despliega. -->
            <p v-if="listLimit && listTotal > listLimit" class="sh-trunc">
                {{ $t('sharing.list_truncated', { shown: listLimit, total: listTotal }) }}
            </p>
            <a v-if="filteredShares.length > SHARES_VISIBLE" class="sh-more" @click.prevent="showAllShares = !showAllShares">
                {{ showAllShares ? $t('sharing.links_less') : $t('sharing.links_more', { n: filteredShares.length - SHARES_VISIBLE }) }}
            </a>
        </div>

    </Modal>
</template>

<style scoped>
.sh-h { display:block; font-size:0.7rem; text-transform:uppercase; letter-spacing:0.4px; color:var(--color-text-muted,#6A6D70); font-weight:600; margin-bottom:8px; }
.sh-muted { color:var(--color-text-muted,#9aa0a6); font-size:0.82rem; }
.sh-intro { margin:0 0 12px; }
.sh-list { list-style:none; margin:0; padding:0; max-height:340px; overflow-y:auto; }
.sh-more { display:inline-block; margin-top:8px; font-size:0.82rem; }
.sh-listhead { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
.sh-toggle { margin-left:auto; font-size:0.78rem; }
.sh-item--revoked { opacity:0.55; }
.sh-item__note { font-size:0.78rem; font-style:italic; color:var(--color-text-muted,#6A6D70); margin-top:2px; }
.sh-trunc { font-size:0.76rem; color:var(--color-text-muted,#6A6D70); margin:8px 0 0; }
.sh-count { font-size:0.72rem; font-weight:600; padding:1px 7px; border-radius:9px;
    background:var(--color-fill-tertiary,#f0f0f0); color:var(--color-text-muted,#6A6D70); }
.sh-item__sent { font-size:0.78rem; color:var(--color-text-muted,#6A6D70); margin-top:2px; line-height:1.35; }
.sh-item__sent strong { color:var(--color-text,#32363a); font-weight:600; }
.sh-item__sent a { margin-left:6px; }
.sh-item { padding:8px 0; border-bottom:1px solid var(--color-border,#eef0f2); }
.sh-item__main { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.sh-item__meta { font-size:0.76rem; color:var(--color-text-muted,#9aa0a6); margin:2px 0 4px; }
.sh-item__acts { display:flex; gap:2px; }
.sh-row { display:flex; gap:12px; }
.sh-grow { flex:1; }

/* Formulario a dos columnas en escritorio. En pantallas chicas colapsa a una
   sola: los campos quedan uno debajo del otro en vez de apretados al costado. */
.sh-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; align-items:start; }
.sh-grid > .sh-span2 { grid-column:1 / -1; }
@media (max-width: 768px) {
    .sh-grid { grid-template-columns:1fr; gap:0; }
    .sh-grid > .sh-span2 { grid-column:auto; }
}
</style>
