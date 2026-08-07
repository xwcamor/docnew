<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Alert,
} from 'ant-design-vue';
import {
    SettingOutlined, EyeInvisibleOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
dayjs.extend(relativeTime);

import AppLayout from '@/Layouts/AppLayout.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const props = defineProps({
    setting:  { type: Object, required: true },
    activity: { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.setting.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);
const lastUpdatedRel = computed(() => props.setting.updated_at ? dayjs(props.setting.updated_at).fromNow() : null);

// Format del value según el type — secret se enmascara siempre.
const displayValue = computed(() => {
    if (props.setting.is_secret) return '••••••••';
    const t = props.setting.type;
    const v = props.setting.value;
    if (v === null || v === undefined || v === '') return '—';
    if (t === 'bool' || t === 'boolean') return v ? 'true' : 'false';
    if (t === 'json' && typeof v !== 'string') return JSON.stringify(v, null, 2);
    return String(v);
});

const typeColor = computed(() => {
    switch (props.setting.type) {
        case 'int':
        case 'integer': return 'geekblue';
        case 'bool':
        case 'boolean': return 'purple';
        case 'json':    return 'orange';
        default:        return 'default';
    }
});
</script>

<template>
    <Head :title="setting.name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('system_management.settings.index')"
            :title="setting.name"
            :icon-bg="iconBg"
        >
            <template #icon><SettingOutlined /></template>
            <template #subtitle>
                <Space :size="6" class="show-page__meta">
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="setting.is_active ? 'success' : 'default'" :bordered="false">
                        {{ setting.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                    <span v-if="lastUpdatedRel" class="page-header__rel">
                        · {{ $t('global.updated_at') }} {{ lastUpdatedRel }}
                    </span>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="settings"
                    :slug="setting.slug"
                    :id="setting.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('settings.edit')"
                    :can-delete="can('settings.delete')"
                    :can-see-audit="canSeeAudit"
                />
            </template>
        </SectionHeader>

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
                    <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(setting.deleted_at) }}</div>
                    <div v-if="setting.deleter">
                        <strong>{{ $t('global.deleted_by') }}:</strong> {{ setting.deleter.name }} ({{ setting.deleter.email }})
                    </div>
                    <div v-if="setting.deleted_description" class="deleted-reason">
                        <strong>{{ $t('global.delete_description') }}:</strong> {{ setting.deleted_description }}
                    </div>
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="settings" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <Card :title="$t('global.general_info')" :bodyStyle="{ padding: 18 }" class="info-card">
                    <div class="spec-grid">
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">ID</span>
                            <span class="spec-cell__value">{{ setting.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code>{{ setting.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('settings.name') }}</span>
                            <span class="spec-cell__value">{{ setting.name }}</span>
                        </div>
                        <div v-if="setting.key" class="spec-cell">
                            <span class="spec-cell__label">Key</span>
                            <span class="spec-cell__value"><code>{{ setting.key }}</code></span>
                        </div>
                        <div v-if="setting.group" class="spec-cell">
                            <span class="spec-cell__label">Group</span>
                            <span class="spec-cell__value"><Tag :bordered="false">{{ setting.group }}</Tag></span>
                        </div>
                        <div v-if="setting.type" class="spec-cell">
                            <span class="spec-cell__label">Type</span>
                            <span class="spec-cell__value"><Tag :color="typeColor" :bordered="false">{{ setting.type }}</Tag></span>
                        </div>
                        <div class="spec-cell spec-cell--wide">
                            <span class="spec-cell__label">Value</span>
                            <span class="spec-cell__value">
                                <Space :size="6">
                                    <EyeInvisibleOutlined v-if="setting.is_secret" />
                                    <code class="value-pre">{{ displayValue }}</code>
                                </Space>
                            </span>
                        </div>
                        <div v-if="setting.description" class="spec-cell spec-cell--wide">
                            <span class="spec-cell__label">{{ $t('global.description') || 'Descripción' }}</span>
                            <span class="spec-cell__value">{{ setting.description }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('settings.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="setting.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ setting.is_active ? $t('global.active') : $t('global.inactive') }}
                                </Tag>
                            </span>
                        </div>
                    </div>
                </Card>
            </template>

            <template #history>
                <RecordHistory :record-audit="recordAudit" :activity="activity" :can-see-activity="canSeeAudit" />
            </template>
        </EntityShowTabs>
    </div>
</template>

<style scoped>
.show-page__meta { margin-top: 4px; }
.page-header__id,
.page-header__rel {
    font-size: 0.8125rem;
    color: var(--color-text-muted);
}
.deleted-alert { margin-bottom: 16px; }
.deleted-info { display: flex; flex-direction: column; gap: 4px; font-size: 0.875rem; }
.deleted-reason { margin-top: 6px; padding-top: 6px; border-top: 1px dashed rgba(0,0,0,0.1); }
.info-card { margin-bottom: 16px; border-radius: 6px; }
.muted { color: var(--color-text-muted); font-size: 0.8125rem; margin-left: 4px; }
.activity-card__title { display: inline-flex; align-items: center; gap: 6px; }
.value-pre {
    display: inline-block;
    max-width: 100%;
    white-space: pre-wrap;
    word-break: break-word;
    line-height: 1.4;
}
</style>
