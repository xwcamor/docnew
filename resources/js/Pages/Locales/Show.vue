<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Alert,
} from 'ant-design-vue';
import { GlobalOutlined, ReadOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
dayjs.extend(relativeTime);

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const props = defineProps({
    locale:   { type: Object, required: true },
    activity: { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.locale.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);
const lastUpdatedRel = computed(() => props.locale.updated_at ? dayjs(props.locale.updated_at).fromNow() : null);
</script>

<template>
    <Head :title="locale.name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('system_management.locales.index')"
            :title="locale.name"
            :icon-bg="iconBg"
        >
            <template #icon><ReadOutlined /></template>
            <template #subtitle>
                <Space :size="6" class="show-page__meta">
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="locale.is_active ? 'success' : 'default'" :bordered="false">
                        {{ locale.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                    <span v-if="lastUpdatedRel" class="page-header__rel">
                        · {{ $t('global.updated_at') }} {{ lastUpdatedRel }}
                    </span>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="locales"
                    :slug="locale.slug"
                    :id="locale.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('locales.edit')"
                    :can-delete="can('locales.delete')"
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
                    <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(locale.deleted_at) }}</div>
                    <div v-if="locale.deleter">
                        <strong>{{ $t('global.deleted_by') }}:</strong> {{ locale.deleter.name }} ({{ locale.deleter.email }})
                    </div>
                    <div v-if="locale.deleted_description" class="deleted-reason">
                        <strong>{{ $t('global.delete_description') }}:</strong> {{ locale.deleted_description }}
                    </div>
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="locales" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <Card :title="$t('global.general_info')" :bodyStyle="{ padding: 18 }" class="info-card">
                    <div class="spec-grid">
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">ID</span>
                            <span class="spec-cell__value">{{ locale.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code>{{ locale.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('locales.name') }}</span>
                            <span class="spec-cell__value">{{ locale.name }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('locales.code') }}</span>
                            <span class="spec-cell__value"><code>{{ locale.code }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('locales.language') }}</span>
                            <span class="spec-cell__value">
                                <template v-if="locale.language">
                                    {{ locale.language.name }}
                                    <code class="ml-1">({{ locale.language.iso_code }})</code>
                                </template>
                                <template v-else>—</template>
                            </span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('locales.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="locale.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ locale.is_active ? $t('global.active') : $t('global.inactive') }}
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
.ml-1 { margin-left: 4px; }
</style>
