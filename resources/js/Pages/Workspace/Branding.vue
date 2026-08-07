<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Card, Input, Textarea, Button, Form, FormItem, Alert, Select, SelectOption, Tag, Switch } from 'ant-design-vue';
import { BankOutlined, PlusOutlined, DeleteOutlined, ArrowUpOutlined, ArrowDownOutlined, CameraOutlined, ShopOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

const props = defineProps({
    workspace: { type: Object, required: true },
    signers:   { type: Array, default: () => [] },
    users:     { type: Array, default: () => [] },
});

const form = useForm({
    address:           props.workspace.address ?? '',
    report_disclaimer: props.workspace.report_disclaimer ?? '',
    require_report_approval: props.workspace.require_report_approval ?? false,
    notify_approval_by_email: props.workspace.notify_approval_by_email ?? false,
    // Flujo de firmas: N slots con cargo (Supervisor, Auditor, …), en orden.
    signers: props.signers.map((s) => ({ user_id: s.user_id, name: s.name ?? '', title: s.title, relation: s.relation ?? 'approved' })),
});

// Estado por slot (calculado en el server para los existentes; para filas
// nuevas/cambiadas se muestra al guardar).
const statusOf = (i) => props.signers[i] && props.signers[i].user_id === form.signers[i]?.user_id
    ? props.signers[i].status : null;
const STATUS_TAG = {
    ready:        { color: 'success' },
    no_autosign:  { color: 'warning' },
    no_signature: { color: 'warning' },
    external:     { color: 'default' },
};

const RELATIONS = ['prepared', 'reviewed', 'approved', 'authorized', 'verified', 'endorsed'];
const addSigner = () => { if (form.signers.length < 8) form.signers.push({ user_id: null, name: '', title: '', relation: 'approved' }); };
const removeSigner = (i) => form.signers.splice(i, 1);
const moveSigner = (i, dir) => {
    const j = i + dir;
    if (j < 0 || j >= form.signers.length) return;
    [form.signers[i], form.signers[j]] = [form.signers[j], form.signers[i]];
};

const submit = () => form.put(route('workspace.update'), { preserveScroll: true });

// ── Logo del workspace: clic en el logo/título → file picker → sube. ──
const logoInput = ref(null);
const logoVersion = ref(0);
const logoUrl = ref(props.workspace.logo_url);
const onLogoPicked = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    router.post(route('workspace.logo.update'), { logo: file }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            logoVersion.value = Date.now();
            if (logoUrl.value) logoUrl.value = logoUrl.value.split('?')[0] + '?v=' + logoVersion.value;
            else router.reload({ only: ['workspace'] });
        },
        onFinish: () => { if (e.target) e.target.value = ''; },
    });
};
</script>

<template>
    <Head :title="t('tenants.workspace_title')" />

    <div class="form-page sap-form">
        <SectionHeader
            :title="workspace.name"
            :subtitle="t('tenants.workspace_subtitle')"
        >
            <template #icon><ShopOutlined /></template>
        </SectionHeader>

        <input ref="logoInput" type="file" accept="image/png,image/jpeg,image/webp" style="display:none" @change="onLogoPicked">

        <Card class="form-card" :bodyStyle="{ padding: '24px 28px' }">
            <Alert
                v-if="form.recentlySuccessful"
                type="success"
                show-icon
                class="ws-saved"
                :message="t('global.updated_success')"
            />

            <Form layout="vertical" @submit.prevent="submit">
                <h2 class="form-section-title">{{ t('global.general_data') }}</h2>
                <!-- ── Logo de la empresa: sección explícita (no solo el ícono) ── -->
                <FormItem :label="t('tenants.logo_label')">
                    <div class="ws-logo-row">
                        <div class="ws-logo-box" @click="logoInput?.click()">
                            <img v-if="logoUrl" :src="logoUrl" class="ws-logo-box__img" alt="">
                            <BankOutlined v-else class="ws-logo-box__ph" />
                        </div>
                        <div class="ws-logo-meta">
                            <Button @click="logoInput?.click()">
                                <CameraOutlined /> {{ logoUrl ? t('tenants.logo_change') : t('tenants.logo_upload') }}
                            </Button>
                            <p class="ws-hint">{{ t('tenants.logo_help') }}</p>
                        </div>
                    </div>
                </FormItem>

                <FormItem
                    :label="t('tenants.form_address_label')"
                    :tooltip="t('tenants.form_address_help')"
                    :validate-status="form.errors.address ? 'error' : ''"
                    :help="form.errors.address"
                >
                    <Input v-model:value="form.address" :maxlength="255" showCount />
                </FormItem>

                <FormItem
                    :label="t('tenants.form_disclaimer_label')"
                    :tooltip="t('tenants.form_disclaimer_help')"
                    :validate-status="form.errors.report_disclaimer ? 'error' : ''"
                    :help="form.errors.report_disclaimer"
                >
                    <Textarea v-model:value="form.report_disclaimer" :rows="4" :maxlength="2000" showCount />
                </FormItem>

                <!-- ── Exigir aprobación de informes (etapa 2 de firmas) ──── -->
                <FormItem :label="t('tenants.require_approval_label')" :tooltip="t('tenants.require_approval_help')">
                    <div class="ws-toggle">
                        <Switch v-model:checked="form.require_report_approval" />
                        <span class="ws-hint">{{ t('tenants.require_approval_help') }}</span>
                    </div>
                    <!-- Canal del aviso: solo cuando el flujo está activo -->
                    <div v-if="form.require_report_approval" class="ws-toggle ws-subtoggle">
                        <Switch v-model:checked="form.notify_approval_by_email" />
                        <span class="ws-hint">{{ t('tenants.notify_email_help') }}</span>
                    </div>
                </FormItem>

                <!-- ── Flujo de firmas: N slots con cargo ─────────────────── -->
                <FormItem :label="t('tenants.signers_label')" :tooltip="t('tenants.signers_help')">
                    <p class="ws-hint">{{ t('tenants.signers_help') }}</p>

                    <div v-for="(s, i) in form.signers" :key="i" class="signer-row">
                        <span class="signer-row__n">{{ i + 1 }}</span>
                        <Select
                            v-model:value="s.relation"
                            class="signer-row__relation"
                            :placeholder="t('approvals.relation.approved')"
                        >
                            <SelectOption v-for="r in RELATIONS" :key="r" :value="r">{{ t('approvals.relation.' + r) }}</SelectOption>
                        </Select>
                        <Input
                            v-model:value="s.title"
                            class="signer-row__title"
                            :placeholder="t('tenants.signer_title_placeholder')"
                            :maxlength="120"
                            :status="form.errors[`signers.${i}.title`] ? 'error' : ''"
                        />
                        <Select
                            v-model:value="s.user_id"
                            class="signer-row__user"
                            allow-clear show-search option-filter-prop="children"
                            :placeholder="t('tenants.signer_user_placeholder')"
                            :status="form.errors[`signers.${i}.user_id`] ? 'error' : ''"
                        >
                            <SelectOption v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</SelectOption>
                        </Select>
                        <Input
                            v-if="!s.user_id"
                            v-model:value="s.name"
                            class="signer-row__name"
                            :placeholder="t('tenants.signer_external_placeholder')"
                            :maxlength="120"
                            :status="form.errors[`signers.${i}.name`] ? 'error' : ''"
                        />
                        <Tag v-else-if="statusOf(i)" :color="STATUS_TAG[statusOf(i)].color" class="signer-row__status">
                            {{ t('tenants.signer_status_' + statusOf(i)) }}
                        </Tag>
                        <span class="signer-row__actions">
                            <Button size="small" type="text" :disabled="i === 0" @click="moveSigner(i, -1)"><ArrowUpOutlined /></Button>
                            <Button size="small" type="text" :disabled="i === form.signers.length - 1" @click="moveSigner(i, 1)"><ArrowDownOutlined /></Button>
                            <Button size="small" type="text" danger @click="removeSigner(i)"><DeleteOutlined /></Button>
                        </span>
                    </div>

                    <Button type="dashed" block :disabled="form.signers.length >= 8" @click="addSigner">
                        <PlusOutlined /> {{ t('tenants.signers_add') }}
                    </Button>
                </FormItem>

                <FormFooter
                    :cancel-href="route('workspace.edit')"
                    :is-edit="true"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </Card>
    </div>
</template>

<style scoped>
.form-card { border-radius: 6px; }
.ws-saved { margin-bottom: 16px; }
.ws-hint { color: var(--color-text-muted, #6A6D70); font-size: 0.8rem; margin: 0 0 10px; }
.ws-toggle { display: flex; align-items: flex-start; gap: 12px; }
.ws-toggle .ws-hint { margin: 0; flex: 1; }
.ws-subtoggle { margin-top: 10px; padding-left: 16px; border-left: 2px solid var(--color-border-soft, #eceff2); }
.ws-logo-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.ws-logo-box {
    width: 92px; height: 92px; flex-shrink: 0;
    border: 1px dashed var(--color-border, #d4d8dd); border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; background: var(--color-surface-alt, #f6f8fa); overflow: hidden;
    transition: border-color .15s ease;
}
.ws-logo-box:hover { border-color: #0A6ED1; }
.ws-logo-box__img { max-width: 84px; max-height: 84px; object-fit: contain; }
.ws-logo-box__ph { font-size: 30px; color: var(--color-text-muted, #9aa0a6); }
.ws-logo-meta { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.ws-logo-meta .ws-hint { margin: 0; max-width: 420px; }
.signer-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.signer-row__n { width: 18px; text-align: right; color: var(--color-text-muted, #9aa0a6); font-size: 0.82rem; }
.signer-row__relation { flex: 0 0 150px; }
.signer-row__title { flex: 0 0 190px; }
.signer-row__user { flex: 0 0 220px; }
.signer-row__name { flex: 1; }
.signer-row__status { margin: 0; }
.signer-row__actions { margin-left: auto; white-space: nowrap; }
@media (max-width: 768px) {
    .signer-row { flex-wrap: wrap; }
    .signer-row__relation, .signer-row__title, .signer-row__user, .signer-row__name { flex: 1 1 100%; }
}
</style>
