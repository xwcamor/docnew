<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Collapse, CollapsePanel, Empty, Alert,
} from 'ant-design-vue';
import {
    IdcardOutlined, SafetyCertificateOutlined, UserOutlined,
} from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

// Humaniza el módulo (reutiliza el sidebar) y la acción del permiso.
const moduleLabel = (mod) => {
    const fromSidebar = t(`sidebar.${mod}`);
    if (fromSidebar !== `sidebar.${mod}`) return fromSidebar;
    const fromRoles = t(`roles.perm_module.${mod}`);
    return fromRoles !== `roles.perm_module.${mod}` ? fromRoles : mod;
};
const actionLabel = (action) => {
    const label = t(`roles.perm_action.${action}`);
    return label !== `roles.perm_action.${action}` ? label : action;
};

const props = defineProps({
    role:         { type: Object, required: true },
    permissions:  { type: Array,  default: () => [] },
    activity:     { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
    isSuper: { type: Boolean, default: false },
});

const { canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.role.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);

// Agrupar permissions por modulo para mostrar en Collapse.
const groupedPermissions = computed(() => {
    const map = {};
    for (const p of props.permissions) {
        const mod = p.module ?? 'other';
        if (!map[mod]) map[mod] = [];
        map[mod].push(p);
    }
    return Object.entries(map)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([module, perms]) => ({ module, perms }));
});

// Badge de tipo de rol — system / global / tenant-scoped.
const roleScopeTag = computed(() => {
    if (props.role.is_system) return { color: 'purple', label: 'roles.tag_system' };
    if (props.role.tenant_id === null) return { color: 'orange', label: 'roles.tag_global' };
    return { color: 'blue', label: null }; // muestra tenant_name directo
});
</script>

<template>
    <Head :title="role.name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('user_management.roles.index')"
            :title="role.name"
            :icon-bg="iconBg"
        >
            <template #icon><IdcardOutlined /></template>
            <template #subtitle>
                <Space :size="6" class="show-page__meta">
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="role.is_active ? 'success' : 'default'" :bordered="false">
                        {{ role.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                    <!-- El tag de tipo (system/global) lo ve todos; el del NOMBRE del
                         tenant solo super (roleScopeTag.label === null = tenant-scoped). -->
                    <Tag v-if="roleScopeTag.label || isSuper" :color="roleScopeTag.color" :bordered="false">
                        {{ roleScopeTag.label ? $t(roleScopeTag.label) : role.tenant_name }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="roles"
                    :slug="role.slug"
                    :id="role.id"
                    :is-deleted="isDeleted"
                    :can-edit="!role.is_system"
                    :can-delete="!role.is_system"
                    :can-see-audit="canSeeAudit"
                    route-prefix="user_management"
                    edit-protected-key="roles.protected"
                    :is-super="isSuper"
                    :is-global="role.tenant_id === null"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div class="deleted-info">
                    <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(role.deleted_at) }}</div>
                    <div v-if="role.deleter">
                        <strong>{{ $t('global.deleted_by') }}:</strong> {{ role.deleter.name }} ({{ role.deleter.email }})
                    </div>
                    <div v-if="role.deleted_description" class="deleted-reason">
                        <strong>{{ $t('global.delete_description') }}:</strong> {{ role.deleted_description }}
                    </div>
                </div>
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <!-- Tab 1 — Detalles: SOLO datos del dominio. -->
            <template #general>
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><IdcardOutlined /> {{ $t('global.general_info') }}</template>
                    <div class="spec-grid">
                        <div v-if="isSuper && role.slug" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code class="muted">{{ role.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('roles.name') }}</span>
                            <span class="spec-cell__value"><strong>{{ role.name }}</strong></span>
                        </div>
                        <div class="spec-cell spec-cell--wide">
                            <span class="spec-cell__label">{{ $t('roles.description') }}</span>
                            <span class="spec-cell__value">{{ role.description || '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('roles.scope') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="roleScopeTag.color" :bordered="false">
                                    {{ roleScopeTag.label ? $t(roleScopeTag.label) : role.tenant_name }}
                                </Tag>
                            </span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('roles.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="role.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ role.is_active ? $t('global.active') : $t('global.inactive') }}
                                </Tag>
                            </span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('roles.users_count') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="role.users_count > 0 ? 'green' : 'default'" :bordered="false">
                                    <UserOutlined /> {{ role.users_count }}
                                </Tag>
                            </span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('roles.permissions_count') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="role.permissions_count > 0 ? 'cyan' : 'default'" :bordered="false">
                                    <SafetyCertificateOutlined /> {{ role.permissions_count }}
                                </Tag>
                            </span>
                        </div>
                    </div>
                </Card>

                <!-- Permissions asignados al rol — agrupados por modulo -->
                <Card :bodyStyle="{ padding: 16 }" class="info-card">
                    <template #title>
                        <Space>
                            <SafetyCertificateOutlined />
                            <span>{{ $t('roles.permissions') }}</span>
                            <Tag :bordered="false">{{ role.permissions_count }}</Tag>
                        </Space>
                    </template>

                    <Empty v-if="permissions.length === 0" :description="$t('roles.no_permissions')" />

                    <Collapse v-else ghost>
                        <CollapsePanel v-for="g in groupedPermissions" :key="g.module">
                            <template #header>
                                <Space>
                                    <strong>{{ moduleLabel(g.module) }}</strong>
                                    <Tag color="cyan" :bordered="false">{{ g.perms.length }}</Tag>
                                </Space>
                            </template>
                            <div class="perm-grid">
                                <Tag v-for="p in g.perms" :key="p.id" color="cyan" :bordered="false" :title="p.name">
                                    {{ actionLabel(p.action) }}
                                </Tag>
                            </div>
                        </CollapsePanel>
                    </Collapse>
                </Card>
            </template>

            <!-- Tab 2 — Historial: metadata del registro + timeline. Gated por canSeeAudit. -->
            <template #history>
                <RecordHistory :record-audit="recordAudit" :activity="activity" :can-see-activity="canSeeAudit" />
            </template>
        </EntityShowTabs>
    </div>
</template>

<style scoped>
.show-page { /* sin width: el bleed de .sap-show necesita ancho auto */ }
.show-page__meta { margin-top: 4px; }
.page-header__id,
.page-header__rel { font-size: 0.8125rem; color: var(--color-text-muted); }
.deleted-alert { margin-bottom: 16px; }
.deleted-info { display: flex; flex-direction: column; gap: 4px; font-size: 0.875rem; }
.deleted-reason { margin-top: 6px; padding-top: 6px; border-top: 1px dashed rgba(0,0,0,0.1); }
.info-card { margin-bottom: 16px; border-radius: 6px; }
.muted { color: var(--color-text-muted); font-size: 0.8125rem; margin-left: 4px; }
.perm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; padding: 8px 0; }
</style>
