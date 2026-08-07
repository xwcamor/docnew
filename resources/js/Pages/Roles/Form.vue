<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Button, Alert, Select, Row, Col,
    Collapse, CollapsePanel, Checkbox, Tag, Space,
} from 'ant-design-vue';
import { IdcardOutlined } from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

// Nombre humanizado del módulo: reutiliza el sidebar (Clientes, Transformadores…);
// para los que no están ahí (comments) cae a roles.perm_module; último recurso: el key.
const moduleLabel = (mod) => {
    const fromSidebar = t(`sidebar.${mod}`);
    if (fromSidebar !== `sidebar.${mod}`) return fromSidebar;
    const fromRoles = t(`roles.perm_module.${mod}`);
    return fromRoles !== `roles.perm_module.${mod}` ? fromRoles : mod;
};

// Acción humanizada (Ver listado, Crear, Editar…); fallback al key crudo.
const actionLabel = (action) => {
    const label = t(`roles.perm_action.${action}`);
    return label !== `roles.perm_action.${action}` ? label : action;
};

const props = defineProps({
    role:                  { type: Object, default: null },
    assignablePermissions: { type: Array, default: () => [] },
    tenantOptions:         { type: Array, default: () => [] },
});

const isEdit = computed(() => !!props.role);

const form = useForm({
    name:        props.role?.name        ?? '',
    description: props.role?.description ?? '',
    tenant_id:   props.role?.tenant_id   ?? null,
    permissions: props.role?.permission_ids ?? [],
});

// Agrupar permisos por module (patients, doctors, users, roles…).
const grouped = computed(() => {
    const map = {};
    for (const p of props.assignablePermissions) {
        const mod = p.module ?? 'other';
        if (!map[mod]) map[mod] = [];
        map[mod].push(p);
    }
    return Object.entries(map)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([module, perms]) => ({ module, perms }));
});

const toggleModule = (perms, select) => {
    const ids = perms.map(p => p.id);
    if (select) {
        form.permissions = [...new Set([...form.permissions, ...ids])];
    } else {
        form.permissions = form.permissions.filter(id => !ids.includes(id));
    }
};

const moduleSelectedCount = (perms) =>
    perms.filter(p => form.permissions.includes(p.id)).length;

const submit = () => {
    if (isEdit.value) {
        form.put(route('user_management.roles.update', props.role.slug));
    } else {
        form.post(route('user_management.roles.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('roles.singular') : $t('roles.new')" />
    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('user_management.roles.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('roles.record') : $t('roles.new')"
            :subtitle="isEdit ? role.name : $t('roles.form_create_hint')"
        >
            <template #icon><IdcardOutlined /></template>
        </SectionHeader>

        <Card :bodyStyle="{ padding: 24 }">
            <Form layout="horizontal" :label-col="{ xs: 24, sm: 8, md: 6 }" :wrapper-col="{ xs: 24, sm: 16, md: 13 }" label-align="right" :colon="true" @submit.prevent="submit">
                <Alert
                    v-if="form.hasErrors && Object.keys(form.errors).length > 0"
                    type="error" show-icon
                    :message="$t('global.fix_marked_fields')"
                    class="mb-4"
                />

                <h2 class="form-section-title">{{ $t('global.general_data') }}</h2>


                <Row :gutter="[20, 0]">
                    <Col :xs="24" :md="16">
                        <FormItem
                            :label="$t('roles.name')"
                            :tooltip="$t('roles.name_help')"
                            required
                            :validate-status="form.errors.name ? 'error' : ''"
                            :help="form.errors.name"
                        >
                            <Input v-model:value="form.name" size="large" :maxlength="120" :placeholder="$t('roles.name_placeholder')" />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="8">
                        <FormItem
                            :label="$t('roles.description')"
                            :tooltip="$t('roles.description_help')"
                            required
                            :validate-status="form.errors.description ? 'error' : ''"
                            :help="form.errors.description"
                        >
                            <Input v-model:value="form.description" size="large" :maxlength="255" :placeholder="$t('roles.description_placeholder')" />
                        </FormItem>
                    </Col>
                </Row>

                <!-- ════ Sección: Workspace ════ (solo super; al crear elige el tenant) -->
                <section v-if="tenantOptions.length" class="form-section">
                    <h2 class="form-section-title form-section-title--spaced">{{ $t('tenants.singular') }}</h2>
                    <FormItem
                        :label="$t('roles.tenant')"
                        :tooltip="$t('tenants.assign_hint')"
                        :validate-status="form.errors.tenant_id ? 'error' : ''"
                        :help="form.errors.tenant_id ?? $t('tenants.assign_hint')"
                    >
                        <Select
                            v-model:value="form.tenant_id"
                            :options="tenantOptions"
                            :placeholder="$t('tenants.select_placeholder')"
                            allow-clear
                            show-search
                            option-filter-prop="label"
                            size="large"
                            :disabled="isEdit"
                        />
                    </FormItem>
                </section>

                <div class="permissions-block">
                    <h3 class="permissions-title">
                        {{ $t('roles.permissions') }}
                        <Tag :bordered="false" color="cyan">{{ form.permissions.length }}</Tag>
                    </h3>
                    <p class="hint">{{ $t('roles.permissions_hint') }}</p>
                    <p v-if="form.errors.permissions" class="perm-error">{{ form.errors.permissions }}</p>

                    <Collapse v-if="grouped.length" ghost>
                        <CollapsePanel v-for="g in grouped" :key="g.module">
                            <template #header>
                                <Space>
                                    <strong>{{ moduleLabel(g.module) }}</strong>
                                    <Tag :color="moduleSelectedCount(g.perms) > 0 ? 'cyan' : 'default'" :bordered="false">
                                        {{ moduleSelectedCount(g.perms) }} / {{ g.perms.length }}
                                    </Tag>
                                </Space>
                            </template>
                            <template #extra>
                                <Space @click.stop>
                                    <Button size="small" @click="toggleModule(g.perms, true)">Todos</Button>
                                    <Button size="small" @click="toggleModule(g.perms, false)">Ninguno</Button>
                                </Space>
                            </template>
                            <div class="perm-grid">
                                <Checkbox
                                    v-for="p in g.perms"
                                    :key="p.id"
                                    :checked="form.permissions.includes(p.id)"
                                    :title="p.name"
                                    @change="(e) => {
                                        if (e.target.checked) form.permissions = [...form.permissions, p.id];
                                        else form.permissions = form.permissions.filter(id => id !== p.id);
                                    }"
                                >
                                    {{ actionLabel(p.action) }}
                                </Checkbox>
                            </div>
                        </CollapsePanel>
                    </Collapse>
                    <Alert v-else type="info" :message="$t('roles.no_permissions_available')" show-icon />
                </div>

                <FormFooter
                    :cancel-href="route('user_management.roles.index')"
                    :is-edit="isEdit"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </Card>
    </div>
</template>

<style scoped>
.permissions-block { margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--color-border); }
.permissions-title { font-size: 1rem; font-weight: 600; margin: 0 0 4px 0; }
.hint { font-size: 0.8125rem; color: var(--color-text-muted); margin: 0 0 14px 0; }
.perm-error { font-size: 0.8125rem; color: #dc2626; font-weight: 500; margin: 0 0 12px 0; }
.perm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; padding: 8px 0; }
.mb-4 { margin-bottom: 16px; }
</style>
