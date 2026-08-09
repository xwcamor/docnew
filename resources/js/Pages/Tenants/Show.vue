<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Space, Alert, Avatar,
    Tabs, TabPane, Table, Modal, Input, Checkbox, CheckboxGroup, InputNumber,
    Popconfirm, Empty, Tooltip,
} from 'ant-design-vue';
import {
    BankOutlined, KeyOutlined, PlusOutlined, CopyOutlined,
    CheckOutlined, UserOutlined, HistoryOutlined, FileTextOutlined,
    CrownOutlined,
} from '@ant-design/icons-vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import BackLink from '@/Components/Common/BackLink.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import SubscriptionsTab from '@/Components/Tenants/SubscriptionsTab.vue';
import { useI18n } from '@/Plugins/i18n';
import { useDateFormat } from '@/Composables/useDateFormat';

const { t } = useI18n();
const { formatDateTime, formatDateTimeFull } = useDateFormat();

defineOptions({ layout: AppLayout });

const props = defineProps({
    tenant:   { type: Object, required: true },
    users:    { type: Array,  default: () => [] },
    tokens:   { type: Array,  default: () => [] },
    activity: { type: Array,  default: () => [] },
    currentPlan:          { type: String, default: 'free' },
    activeSubscription:   { type: Object, default: null },
    subscriptionsHistory: { type: Array,  default: () => [] },
    availablePlans:       { type: Array,  default: () => [] },
    plansComparison:      { type: Array,  default: () => [] },
    // Si el plan vigente NO incluye api_access, los tokens existentes en BD
    // siguen ahí pero el middleware los bloquea con 402. La UI muestra un
    // banner "dormido" en el tab API Keys para que el admin lo entienda sin
    // tener que adivinar por qué los integradores externos ven 402.
    hasApiAccess:         { type: Boolean, default: false },
    recordAudit:          { type: Object, default: null },
});

const page = usePage();
const isSuper = computed(() => page.props.auth?.user?.roles?.includes('super') ?? false);
const canSeeAudit = computed(() => {
    const r = page.props.auth?.user?.roles ?? [];
    return r.includes('super') || r.includes('admin');
});

const isDeleted = computed(() => !!props.tenant.deleted_at);
// Wrappers locales para mantener call-sites compactos (fmt/fmtShort en templates).
const fmt = (d) => formatDateTimeFull(d);
const fmtShort = (d) => formatDateTime(d);

// Color del tag por rol — admin destacado, user neutral, custom roles tenant-scoped cyan.
const roleTagColor = (roleName) => {
    if (roleName === 'admin') return 'purple';
    if (roleName === 'user')  return 'default';
    return 'cyan'; // custom roles (Soporte, Editor, Visitante, etc.)
};

// Usuarios humanos vivos / tope del plan. Un tope enorme (plan sin límite) se
// muestra sin el "de N": decir "3 de 9223372036854775807" no informa nada.
const usersLabel = computed(() => {
    const used = props.tenant.users_count ?? props.users.length;
    const max  = props.tenant.max_users ?? 0;
    return (max > 0 && max < 100000) ? `${used} / ${max}` : String(used);
});
const usersAtLimit = computed(() => {
    const used = props.tenant.users_count ?? props.users.length;
    const max  = props.tenant.max_users ?? 0;
    return max > 0 && max < 100000 && used >= max;
});

// El backend devuelve `logo_url` ya completo + cache-busted (?v=updated_at).
// Sin esto el browser cachea el logo viejo despues de un upload.
const logoUrl = computed(() => props.tenant.logo_url ?? null);

// Permisos que se pueden marcar en un token. Sólo los que EXISTEN en
// `routes/api.php`: marcar uno que no gatea ninguna ruta da un token que
// promete algo que el sistema no hace. Hoy la única API expuesta es Clientes;
// al publicar otra, se agrega aquí y en la ruta a la vez.
//
// Se quitaron `transformers:read`, `transformers:write` y `lab:write`: son
// restos de la integración con el laboratorio de transformadores, purgada del
// producto (docs/PURGA.md). No hay endpoint detrás de ninguno de los tres.
const availableAbilities = computed(() => [
    { value: 'customers:read',   label: t('tenants.ability_customers_read') },
    { value: 'customers:write',  label: t('tenants.ability_customers_write') },
    { value: 'customers:delete', label: t('tenants.ability_customers_delete') },
]);

// ─── Create token modal ────────────────────────────────────────────────────
const tokenModalOpen = ref(false);
const newTokenValue  = ref(null);   // shown ONCE after creation
const justCopied     = ref(false);

const tokenForm = useForm({
    name: '',
    abilities: [],
    expires_in_days: null,
});

const openCreateTokenModal = () => {
    tokenForm.reset();
    tokenForm.clearErrors();
    newTokenValue.value = null;
    tokenModalOpen.value = true;
};

const submitToken = () => {
    tokenForm.post(route('system_management.tenants.tokens.create', props.tenant.slug), {
        preserveScroll: true,
        onSuccess: () => {
            // The plain-text token comes flashed via session as `newToken` — read from page props.
            newTokenValue.value = page.props.flash?.newToken ?? null;
        },
    });
};

const closeTokenModal = () => {
    tokenModalOpen.value = false;
    newTokenValue.value = null;
    tokenForm.reset();
};

const copyTokenToClipboard = async () => {
    if (!newTokenValue.value) return;
    try {
        await navigator.clipboard.writeText(newTokenValue.value);
        justCopied.value = true;
        setTimeout(() => { justCopied.value = false; }, 1800);
    } catch (e) {}
};

// Watch flash to catch newToken even when arriving from a redirect.
watch(() => page.props.flash, (flash) => {
    if (flash?.newToken && tokenModalOpen.value) {
        newTokenValue.value = flash.newToken;
    }
}, { deep: true });

// ─── Revoke token ──────────────────────────────────────────────────────────
const revokeToken = (tokenId) => {
    router.delete(
        route('system_management.tenants.tokens.revoke', { tenant: props.tenant.slug, tokenId }),
        { preserveScroll: true },
    );
};

// ─── Token row helpers ─────────────────────────────────────────────────────
const tokenAbilitiesLabel = (abilities) => {
    if (!abilities || abilities.length === 0) return '—';
    if (abilities.length === 1 && abilities[0] === '*') return t('tenants.token_abilities_all');
    if (abilities.length <= 3) return abilities.join(', ');
    return `${abilities.slice(0, 2).join(', ')} +${abilities.length - 2}`;
};
</script>

<template>
    <Head :title="tenant.name" />

    <div class="show-page sap-show">
        <!-- Header -->
        <div class="page-header">
            <div class="page-header__title">
                <BackLink :href="route('system_management.tenants.index')" />
                <Avatar
                    :src="logoUrl"
                    :size="48"
                    shape="square"
                    class="page-header__avatar"
                    :class="{ 'page-header__avatar--deleted': isDeleted }"
                >
                    <template v-if="!logoUrl" #icon><BankOutlined /></template>
                </Avatar>
                <div>
                    <h1>{{ tenant.name }}</h1>
                    <Space :size="6">
                        <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                        <Tag v-else :color="tenant.is_active ? 'success' : 'default'" :bordered="false">
                            {{ tenant.is_active ? $t('global.active') : $t('global.inactive') }}
                        </Tag>
                    </Space>
                </div>
            </div>

            <Space wrap>
                <EntityShowActions
                    module="tenants"
                    :slug="tenant.slug"
                    :id="tenant.id"
                    :is-deleted="isDeleted"
                    :can-edit="!isDeleted"
                    :can-delete="!isDeleted"
                    :can-see-audit="canSeeAudit"
                />
            </Space>
        </div>

        <Alert
            v-if="isDeleted"
            type="error"
            show-icon
            class="deleted-alert"
        >
            <template #message>
                <span v-html="$t('global.record_is_deleted')" />
            </template>
            <template #description>
                <div class="deleted-info">
                    <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(tenant.deleted_at) }}</div>
                    <div v-if="tenant.deleter">
                        <strong>{{ $t('global.deleted_by') }}:</strong> {{ tenant.deleter.name }} ({{ tenant.deleter.email }})
                    </div>
                    <div v-if="tenant.deleted_description" class="deleted-reason">
                        <strong>{{ $t('global.delete_description') }}:</strong> {{ tenant.deleted_description }}
                    </div>
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="tenants" />
            </template>
        </Alert>

        <!-- Tabs -->
        <Card class="tabs-card" :bodyStyle="{ padding: '0 16px' }">
            <Tabs default-active-key="info">
                <!-- TAB: Detalles — SOLO campos del dominio (mismo patrón que EntityShowTabs) -->
                <TabPane key="info">
                    <template #tab>
                        <span><FileTextOutlined /> {{ $t('global.details') }}</span>
                    </template>

                    <Card :title="$t('global.general_info')" :bodyStyle="{ padding: 18 }" class="info-card">
                        <div class="spec-grid">
                            <div v-if="isSuper" class="spec-cell">
                                <span class="spec-cell__label">ID</span>
                                <span class="spec-cell__value">{{ tenant.id }}</span>
                            </div>
                            <div v-if="isSuper" class="spec-cell">
                                <span class="spec-cell__label">Slug</span>
                                <span class="spec-cell__value"><code>{{ tenant.slug }}</code></span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('tenants.name') }}</span>
                                <span class="spec-cell__value">{{ tenant.name }}</span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('tenants.is_active') }}</span>
                                <span class="spec-cell__value">
                                    <Tag :color="tenant.is_active ? 'success' : 'default'" :bordered="false">
                                        {{ tenant.is_active ? $t('global.active') : $t('global.inactive') }}
                                    </Tag>
                                </span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('tenants.plan') }}</span>
                                <span class="spec-cell__value">
                                    <Tag
                                        :color="tenant.plan === 'enterprise' ? 'gold' : (tenant.plan === 'pro' ? 'blue' : 'default')"
                                        :bordered="false"
                                    >
                                        {{ (tenant.plan || 'free').toUpperCase() }}
                                    </Tag>
                                </span>
                            </div>
                            <!-- Cuántos de cuántos: el dato que dice si el
                                 workspace puede seguir dando de alta gente. -->
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('tenants.users_count') }}</span>
                                <span class="spec-cell__value">
                                    <Tag :color="usersAtLimit ? 'warning' : 'default'" :bordered="false">
                                        {{ usersLabel }}
                                    </Tag>
                                </span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('tenants.timezone') }}</span>
                                <span class="spec-cell__value">{{ tenant.timezone || '—' }}</span>
                            </div>
                            <div class="spec-cell spec-cell--wide">
                                <span class="spec-cell__label">{{ $t('tenants.form_address_label') }}</span>
                                <span class="spec-cell__value">{{ tenant.address || '—' }}</span>
                            </div>
                            <div class="spec-cell spec-cell--wide">
                                <span class="spec-cell__label">{{ $t('tenants.system_user_email') }}</span>
                                <span class="spec-cell__value">
                                    <code class="text-mini">{{ tenant.system_user_email ?? '—' }}</code>
                                    <p class="hint">{{ $t('tenants.system_user_hint') }}</p>
                                </span>
                            </div>
                        </div>
                    </Card>
                </TabPane>

                <!-- TAB: Subscription (gestión de planes) -->
                <TabPane key="subscription">
                    <template #tab>
                        <span><CrownOutlined /> {{ $t('subscriptions.tab_title') }}</span>
                    </template>

                    <SubscriptionsTab
                        :tenant-slug="tenant.slug"
                        :current-plan="currentPlan"
                        :active-subscription="activeSubscription"
                        :subscriptions-history="subscriptionsHistory"
                        :available-plans="availablePlans"
                        :plans-comparison="plansComparison"
                    />
                </TabPane>

                <!-- TAB: Users -->
                <TabPane key="users">
                    <template #tab>
                        <span><UserOutlined /> {{ $t('tenants.tab_users') }} ({{ users.length }})</span>
                    </template>

                    <div class="tab-content">
                        <Empty v-if="users.length === 0" :description="$t('tenants.no_human_users')" />
                        <Table
                            v-else
                            :data-source="users"
                            row-key="id"
                            :pagination="false"
                            size="small"
                        >
                            <Table.Column title="ID" data-index="id" :width="80" />
                            <Table.Column :title="$t('tenants.name')" data-index="name" />
                            <Table.Column :title="$t('tenants.admin_email')" data-index="email" />
                            <Table.Column :title="$t('roles.singular')" :width="150">
                                <template #default="{ record }">
                                    <Tag v-if="record.role" :color="roleTagColor(record.role)" :bordered="false">
                                        {{ record.role }}
                                    </Tag>
                                    <span v-else class="muted">—</span>
                                </template>
                            </Table.Column>
                            <Table.Column :title="$t('tenants.is_active')" :width="120">
                                <template #default="{ record }">
                                    <Tag :color="record.is_active ? 'success' : 'error'" :bordered="false">
                                        {{ record.is_active ? $t('global.active') : $t('global.inactive') }}
                                    </Tag>
                                </template>
                            </Table.Column>
                            <Table.Column :title="$t('tenants.col_created')" :width="180">
                                <template #default="{ record }">
                                    {{ fmtShort(record.created_at) }}
                                </template>
                            </Table.Column>
                        </Table>
                    </div>
                </TabPane>

                <!-- TAB: Historial / Audit log (mismo label que EntityShowTabs) -->
                <TabPane v-if="canSeeAudit" key="activity">
                    <template #tab>
                        <span><HistoryOutlined /> {{ $t('global.history') }} ({{ activity.length }})</span>
                    </template>
                    <div class="tab-content">
                        <RecordHistory :record-audit="recordAudit" :activity="activity" :can-see-activity="canSeeAudit" />
                    </div>
                </TabPane>

                <!-- TAB: API Keys -->
                <TabPane key="api-keys">
                    <template #tab>
                        <span><KeyOutlined /> {{ $t('tenants.tab_api_keys') }} ({{ tokens.length }})</span>
                    </template>

                    <div class="tab-content">
                        <!-- Banner cuando el plan vigente NO incluye api_access:
                             los tokens existen pero quedan dormidos hasta upgrade. -->
                        <Alert
                            v-if="!hasApiAccess && tokens.length > 0"
                            type="warning"
                            show-icon
                            :message="$t('tenants.api_dormant_title')"
                            :description="$t('tenants.api_dormant_desc')"
                            class="mb-3"
                            style="margin-bottom: 14px;"
                        />
                        <Alert
                            v-else-if="!hasApiAccess"
                            type="info"
                            show-icon
                            :message="$t('tenants.api_locked_title')"
                            :description="$t('tenants.api_locked_desc')"
                            class="mb-3"
                            style="margin-bottom: 14px;"
                        />

                        <div class="api-tab-header">
                            <p class="hint" style="margin: 0;">
                                {{ $t('tenants.tokens_hint', { auth: 'Authorization: Bearer <token>' }) }}
                            </p>
                            <Tooltip :title="hasApiAccess ? $t('tenants.generate_token_hint') : $t('tenants.api_locked_title')">
                                <Button type="primary" :disabled="!hasApiAccess" @click="openCreateTokenModal">
                                    <PlusOutlined /> {{ $t('tenants.generate_token_btn') }}
                                </Button>
                            </Tooltip>
                        </div>

                        <Empty
                            v-if="tokens.length === 0"
                            :description="$t('tenants.no_tokens')"
                            style="padding: 40px 16px"
                        />
                        <Table
                            v-else
                            :data-source="tokens"
                            row-key="id"
                            :pagination="false"
                            size="small"
                            class="tokens-table"
                        >
                            <Table.Column :title="$t('tenants.col_token_name')" data-index="name">
                                <template #default="{ record }">
                                    <span>{{ record.name }}</span>
                                    <Tag v-if="!hasApiAccess" color="warning" :bordered="false" style="margin-left: 8px;">
                                        {{ $t('tenants.token_dormant_badge') }}
                                    </Tag>
                                </template>
                            </Table.Column>
                            <Table.Column :title="$t('tenants.col_permissions')">
                                <template #default="{ record }">
                                    <span class="abilities-cell">{{ tokenAbilitiesLabel(record.abilities) }}</span>
                                </template>
                            </Table.Column>
                            <Table.Column :title="$t('tenants.col_last_used')" :width="180">
                                <template #default="{ record }">
                                    {{ record.last_used_at ? fmtShort(record.last_used_at) : $t('tenants.never') }}
                                </template>
                            </Table.Column>
                            <Table.Column :title="$t('tenants.col_created')" :width="180">
                                <template #default="{ record }">
                                    {{ fmtShort(record.created_at) }}
                                </template>
                            </Table.Column>
                            <Table.Column :title="$t('tenants.col_expires')" :width="140">
                                <template #default="{ record }">
                                    {{ record.expires_at ? fmtShort(record.expires_at) : $t('tenants.no_expiration') }}
                                </template>
                            </Table.Column>
                            <Table.Column title="" :width="120" :fixed="'right'">
                                <template #default="{ record }">
                                    <Popconfirm
                                        :title="$t('tenants.revoke_confirm_title')"
                                        :description="$t('tenants.revoke_confirm_desc')"
                                        :ok-text="$t('tenants.revoke')"
                                        :cancel-text="$t('global.cancel')"
                                        :ok-button-props="{ danger: true }"
                                        @confirm="revokeToken(record.id)"
                                    >
                                        <Tooltip :title="$t('tenants.revoke_hint')">
                                            <Button size="small" type="text" danger>
                                                {{ $t('tenants.revoke') }}
                                            </Button>
                                        </Tooltip>
                                    </Popconfirm>
                                </template>
                            </Table.Column>
                        </Table>
                    </div>
                </TabPane>
            </Tabs>
        </Card>

        <!-- Create token modal — 2 estados: form OR token created -->
        <Modal
            v-model:open="tokenModalOpen"
            :title="newTokenValue ? $t('tenants.token_modal_title_done') : $t('tenants.token_modal_title_form')"
            :footer="null"
            :mask-closable="false"
            :closable="!tokenForm.processing"
            @cancel="closeTokenModal"
        >
            <!-- ESTADO 1: form -->
            <template v-if="!newTokenValue">
                <Alert
                    v-if="tokenForm.errors.name || tokenForm.errors.abilities || tokenForm.errors.expires_in_days"
                    type="error"
                    :message="$t('global.fix_marked_fields')"
                    show-icon
                    class="mb-3"
                />

                <label class="field-label">{{ $t('tenants.token_name_label') }} <span class="required">*</span></label>
                <Input
                    v-model:value="tokenForm.name"
                    :placeholder="$t('tenants.token_name_placeholder')"
                    :status="tokenForm.errors.name ? 'error' : ''"
                    size="large"
                />
                <p v-if="tokenForm.errors.name" class="field-error">{{ tokenForm.errors.name }}</p>

                <label class="field-label" style="margin-top: 14px">
                    {{ $t('tenants.token_abilities_label') }}
                </label>
                <p class="hint">{{ $t('tenants.token_abilities_hint') }}</p>
                <CheckboxGroup
                    v-model:value="tokenForm.abilities"
                    :options="availableAbilities"
                    class="abilities-group"
                />

                <label class="field-label" style="margin-top: 14px">
                    {{ $t('tenants.token_expires_label') }} <span class="muted">— {{ $t('tenants.token_expires_optional') }}</span>
                </label>
                <InputNumber
                    v-model:value="tokenForm.expires_in_days"
                    :min="1"
                    :max="3650"
                    :placeholder="$t('tenants.token_expires_placeholder')"
                    style="width: 100%"
                />
                <p class="hint">{{ $t('tenants.token_expires_hint') }}</p>

                <div class="modal-footer">
                    <Tooltip :title="$t('global.cancel_hint')">
                        <Button @click="closeTokenModal" :disabled="tokenForm.processing">{{ $t('global.cancel') }}</Button>
                    </Tooltip>
                    <Tooltip :title="$t('tenants.token_submit_hint')">
                        <Button type="primary" :loading="tokenForm.processing" @click="submitToken">
                            {{ $t('tenants.token_submit') }}
                        </Button>
                    </Tooltip>
                </div>
            </template>

            <!-- ESTADO 2: token recién creado, mostrar UNA SOLA VEZ -->
            <template v-else>
                <Alert type="warning" show-icon class="mb-3">
                    <template #message>
                        {{ $t('tenants.token_copy_warning_title') }}
                    </template>
                    <template #description>
                        {{ $t('tenants.token_copy_warning_desc') }}
                    </template>
                </Alert>

                <div class="token-display">
                    <code>{{ newTokenValue }}</code>
                </div>

                <div class="modal-footer">
                    <Button @click="copyTokenToClipboard" :type="justCopied ? 'default' : 'primary'">
                        <CheckOutlined v-if="justCopied" /> <CopyOutlined v-else />
                        {{ justCopied ? $t('tenants.token_copied') : $t('tenants.token_copy') }}
                    </Button>
                    <Button type="primary" @click="closeTokenModal">{{ $t('tenants.token_done') }}</Button>
                </div>
            </template>
        </Modal>
    </div>
</template>

<style scoped>
.show-page { /* sin width: el bleed de .sap-show necesita ancho auto */ }

.page-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 16px; margin-bottom: 16px; flex-wrap: wrap;
}
/* Franja blanca full-bleed del título (esta ficha usa .page-header propio, no
   SectionHeader, así que la franja de .sap-show se aplica aquí a mano). */
.sap-show.show-page .page-header {
    background: var(--color-surface, #fff);
    margin: -24px -24px 20px;
    padding: 16px 24px;
    align-items: center;
}
@media (max-width: 768px) {
    .sap-show.show-page .page-header { margin: -16px -16px 16px; padding: 14px 16px; }
}
.page-header__title { display: flex; align-items: flex-start; gap: 14px; }
.back-link {
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; border-radius: 4px; color: #6A6D70;
    transition: background 0.12s ease, color 0.12s ease; margin-top: 2px;
}
.back-link:hover { background: #f1f5f9; color: #0A6ED1; }
.page-header__avatar { flex-shrink: 0; border: 2px solid #0A6ED1; }
.page-header__avatar--deleted { border-color: #BB0000; }
.page-header h1 {
    font-size: 1.5rem; font-weight: 400; margin: 0 0 4px 0; color: #32363A; line-height: 1.2;
}
.page-header__id { font-size: 0.8125rem; color: #6A6D70; }

.deleted-alert { margin-bottom: 16px; }
.deleted-info { display: flex; flex-direction: column; gap: 4px; font-size: 0.875rem; }
.deleted-reason { margin-top: 6px; padding-top: 6px; border-top: 1px dashed rgba(0,0,0,0.1); }

.tabs-card { border-radius: 6px; }
.tab-content { padding: 16px 0; }
.info-card { margin-bottom: 16px; border-radius: 6px; }
.info-card + .info-card { margin-top: 0; }

.api-tab-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 16px; margin-bottom: 16px; flex-wrap: wrap;
}
.tokens-table :deep(.ant-table-cell) { font-size: 0.8125rem; }
.abilities-cell { font-family: ui-monospace, monospace; font-size: 0.78rem; color: #475569; }

.muted { color: #6A6D70; font-size: 0.8125rem; margin-left: 4px; }
.text-mini { font-size: 0.78rem; color: #475569; word-break: break-all; }
.hint { font-size: 0.75rem; color: #6A6D70; margin: 4px 0 0 0; line-height: 1.4; }

/* Modal form */
.field-label {
    display: block; font-size: 0.8125rem; font-weight: 500;
    color: #475569; margin-bottom: 6px;
}
.field-error { color: #dc2626; font-size: 0.8rem; font-weight: 500; margin: 4px 0 0 0; }
.required { color: #ff4d4f; }
.abilities-group :deep(.ant-checkbox-group-item) {
    display: flex !important; margin-right: 0 !important; margin-bottom: 6px;
}

.modal-footer {
    display: flex; justify-content: flex-end; gap: 8px;
    margin-top: 18px; padding-top: 16px; border-top: 1px solid #E5E5E5;
}

/* Token display */
.token-display {
    background: #1e293b; color: #e2e8f0;
    padding: 14px 16px; border-radius: 6px;
    font-family: ui-monospace, "SF Mono", Menlo, monospace;
    font-size: 0.85rem; word-break: break-all; line-height: 1.4;
    user-select: all;
}

.mb-3 { margin-bottom: 12px; }
.mt-3 { margin-top: 12px; }

@media (max-width: 768px) {
    .page-header { flex-direction: column; align-items: stretch; }
    .page-header h1 { font-size: 1.2rem; }
    .api-tab-header { flex-direction: column; align-items: stretch; }
}

/* Tabs compactos en mobile — 4 tabs entran sin scroll horizontal */
@media (max-width: 640px) {
    .tabs-card :deep(.ant-tabs-tab) {
        padding: 8px 10px !important;
        font-size: 0.8125rem;
    }
    .tabs-card :deep(.ant-tabs-tab + .ant-tabs-tab) {
        margin-left: 6px !important;
    }
    .tabs-card :deep(.ant-tabs-tab .anticon) {
        margin-right: 4px;
        font-size: 0.95em;
    }
}

/* En el card hay que limitar overflow del CONTENIDO de los tabs.
   La nav bar (.ant-tabs-nav) sigue con scroll horizontal nativo de Antd
   como fallback si los tabs no caben — el user hace swipe lateral. */
.tabs-card :deep(.ant-tabs-content-holder) { overflow-x: hidden; }
</style>

<style>
html[data-theme="dark"] .page-header h1 { color: #e5e6e7; }
html[data-theme="dark"] .page-header__id { color: #a8aaae; }
html[data-theme="dark"] .back-link:hover { background: #313a44; }
html[data-theme="dark"] .muted { color: #a8aaae; }
html[data-theme="dark"] .modal-footer { border-top-color: #3f4448; }
</style>
