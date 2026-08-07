<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Alert,
} from 'ant-design-vue';
import { UserOutlined } from '@ant-design/icons-vue';
import UserAvatar from '@/Components/Common/UserAvatar.vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const { formatDateTimeFull } = useDateFormat();

const props = defineProps({
    user:     { type: Object, required: true },
    activity: { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
});

const page = usePage();
const can = (perm) => {
    const u = page.props.auth?.user;
    if (!u) return false;
    if (u.roles?.includes('super')) return true;
    return u.permissions?.includes(perm) ?? false;
};
const isSuper = computed(() => page.props.auth?.user?.roles?.includes('super') ?? false);
const canSeeAudit = computed(() => {
    const r = page.props.auth?.user?.roles ?? [];
    return r.includes('super') || r.includes('admin');
});

const isDeleted = computed(() => !!props.user.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);
</script>

<template>
    <Head :title="user.name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('user_management.users.index')"
            :title="user.name"
            :icon-bg="iconBg"
        >
            <template #icon><UserOutlined /></template>
            <template #subtitle>
                <Space :size="6" class="show-page__meta">
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="user.is_active ? 'success' : 'default'" :bordered="false">
                        {{ user.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="users"
                    :slug="user.slug"
                    :id="user.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('users.edit')"
                    :can-delete="can('users.delete')"
                    :can-see-audit="canSeeAudit"
                    route-prefix="user_management"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div class="deleted-info">
                    <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(user.deleted_at) }}</div>
                    <div v-if="user.deleter">
                        <strong>{{ $t('global.deleted_by') }}:</strong> {{ user.deleter.name }} ({{ user.deleter.email }})
                    </div>
                    <div v-if="user.deleted_description" class="deleted-reason">
                        <strong>{{ $t('global.delete_description') }}:</strong> {{ user.deleted_description }}
                    </div>
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="users" route-prefix="user_management" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <!-- Tab 1 — Detalles: solo datos del dominio -->
            <template #general>
                <Card :bodyStyle="{ padding: 0 }" class="info-card">
                    <template #title><UserOutlined /> {{ $t('global.general_info') }}</template>
                    <div class="user-hero">
                        <UserAvatar
                            :photo="user.photo_url"
                            :name="user.name"
                            :size="56"
                            class="user-hero__avatar"
                            :class="{ 'user-hero__avatar--deleted': isDeleted }"
                        />
                        <div>
                            <h2>{{ user.name }}</h2>
                            <p class="user-hero__email">{{ user.email }}</p>
                        </div>
                    </div>
                    <div class="spec-pad">
                        <div class="spec-grid">
                            <div v-if="isSuper" class="spec-cell">
                                <span class="spec-cell__label">ID</span>
                                <span class="spec-cell__value">{{ user.id }}</span>
                            </div>
                            <div v-if="isSuper && user.slug" class="spec-cell">
                                <span class="spec-cell__label">Slug</span>
                                <span class="spec-cell__value"><code class="muted">{{ user.slug }}</code></span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('users.name') }}</span>
                                <span class="spec-cell__value">{{ user.name }}</span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('users.email') }}</span>
                                <span class="spec-cell__value">{{ user.email }}</span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('users.role') }}</span>
                                <span class="spec-cell__value">
                                    <Tag v-if="user.role === 'admin'" color="purple" :bordered="false">{{ user.role }}</Tag>
                                    <template v-else-if="user.role">{{ user.role }}</template>
                                    <template v-else>—</template>
                                </span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('profile.country') }}</span>
                                <span class="spec-cell__value">{{ user.country?.name || '—' }}</span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('locales.singular') }}</span>
                                <span class="spec-cell__value">{{ user.locale ? (user.locale.name || user.locale.code) : '—' }}</span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('profile.timezone') }}</span>
                                <span class="spec-cell__value">{{ user.timezone || '—' }}</span>
                            </div>
                            <div class="spec-cell">
                                <span class="spec-cell__label">{{ $t('users.is_active') }}</span>
                                <span class="spec-cell__value">
                                    <Tag :color="user.is_active ? 'success' : 'default'" :bordered="false">
                                        {{ user.is_active ? $t('global.active') : $t('global.inactive') }}
                                    </Tag>
                                </span>
                            </div>
                        </div>
                    </div>
                </Card>
            </template>

            <!-- Tab 2 — Historial: metadata del registro + timeline -->
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

.user-hero {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--color-border-soft);
    min-width: 0;
}
.user-hero > div {
    min-width: 0;
    flex: 1 1 auto;
}
.user-hero__avatar { flex-shrink: 0; border: 2px solid var(--color-primary); }
.user-hero__avatar--deleted { border-color: var(--color-danger); }
.user-hero h2 {
    font-size: 1.1rem; font-weight: 600; margin: 0; color: var(--color-text);
    word-break: break-word; overflow-wrap: anywhere;
}
.user-hero__email {
    font-size: 0.8125rem; color: var(--color-text-muted); margin: 2px 0 0 0;
    word-break: break-word; overflow-wrap: anywhere;
}

@media (max-width: 767px) {
    .user-hero { padding: 12px 14px; gap: 10px; }
    .user-hero h2 { font-size: 1rem; }
    .user-hero__email { word-break: break-word; }
}
</style>
