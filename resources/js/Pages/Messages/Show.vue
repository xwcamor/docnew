<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Card, Button, Tag, Space, Descriptions, DescriptionsItem, Statistic, Row, Col, Avatar, Progress,
} from 'ant-design-vue';
import {
    MessageOutlined, EditOutlined, DeleteOutlined, ArrowLeftOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
dayjs.extend(relativeTime);

import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/Plugins/i18n';
import { useDateFormat } from '@/Composables/useDateFormat';

const { formatDateTimeFull } = useDateFormat();

defineOptions({ layout: AppLayout });

const { t } = useI18n();

const props = defineProps({
    message: { type: Object, required: true },
    stats:   { type: Object, required: true },
    replies: { type: Array,  default: () => [] },
});

const audienceColor = computed(() => {
    if (props.message.audience_type === 'global') return 'blue';
    if (props.message.audience_type === 'tenant') return 'cyan';
    if (props.message.audience_type === 'user')   return 'purple';
    return 'default';
});

const audienceLabel = computed(() => {
    if (props.message.audience_type === 'global') return t('messages.audience_global');
    if (props.message.audience_type === 'tenant') return `${t('messages.audience_tenant')}: ${props.message.audience_name ?? '-'}`;
    if (props.message.audience_type === 'user')   return `${t('messages.audience_user')}: ${props.message.audience_name ?? '-'}`;
    return '-';
});

const statusTag = computed(() => {
    if (!props.message.published_at) return { color: 'default', label: t('messages.status_draft') };
    if (props.message.expires_at && dayjs(props.message.expires_at).isBefore(dayjs())) {
        return { color: 'red', label: t('messages.status_expired') };
    }
    return {
        color: props.message.is_active ? 'green' : 'orange',
        label: props.message.is_active ? t('messages.status_published') : t('global.inactive'),
    };
});

// Show.vue usa el formato con segundos (dd-mm-aaaa HH:mm:ss) — mismo estandar
// que el resto de Show.vue del proyecto. dayjs queda solo para .fromNow() y
// chequeos de expiracion (isBefore).
const fmt = (d) => formatDateTimeFull(d);
const fmtRel = (d) => d ? dayjs(d).fromNow() : '-';
</script>

<template>
    <Head :title="message.subject" />

    <div class="message-show">
        <Card>
            <div class="page-header">
                <div class="page-header__title">
                    <Link :href="route('communication.messages.index')">
                        <Button type="text"><template #icon><ArrowLeftOutlined /></template></Button>
                    </Link>
                    <MessageOutlined class="page-header__icon" />
                    <h1>{{ message.subject }}</h1>
                </div>
                <Space>
                    <Link :href="route('communication.messages.edit', message.slug)">
                        <Button>
                            <template #icon><EditOutlined /></template>
                            {{ t('global.edit') }}
                        </Button>
                    </Link>
                    <Link :href="route('communication.messages.delete', message.slug)">
                        <Button danger>
                            <template #icon><DeleteOutlined /></template>
                            {{ t('global.delete') }}
                        </Button>
                    </Link>
                </Space>
            </div>

            <Space :size="6" style="margin-bottom:16px">
                <Tag :color="statusTag.color" :bordered="false">{{ statusTag.label }}</Tag>
                <Tag :color="audienceColor" :bordered="false">{{ audienceLabel }}</Tag>
                <Tag v-if="message.allow_replies" color="purple" :bordered="false">{{ t('messages.allow_replies') }}</Tag>
            </Space>

            <Descriptions bordered size="small" :column="{ xs: 1, md: 2 }" style="margin-bottom: 20px">
                <DescriptionsItem :label="t('messages.created_by')">
                    <span v-if="message.creator">{{ message.creator.name }} ({{ message.creator.email }})</span>
                    <span v-else>-</span>
                </DescriptionsItem>
                <DescriptionsItem :label="t('messages.created_at')">{{ fmt(message.created_at) }}</DescriptionsItem>
                <DescriptionsItem :label="t('messages.published_at')">{{ fmt(message.published_at) }}</DescriptionsItem>
                <DescriptionsItem :label="t('messages.expires_at')">{{ fmt(message.expires_at) }}</DescriptionsItem>
            </Descriptions>

            <Row :gutter="16" style="margin-bottom: 24px">
                <Col :xs="24" :md="8">
                    <Card size="small">
                        <Statistic :title="t('messages.recipients_count')" :value="stats.recipients_count" />
                    </Card>
                </Col>
                <Col :xs="24" :md="8">
                    <Card size="small">
                        <Statistic :title="t('messages.read_count')" :value="stats.read_count" />
                    </Card>
                </Col>
                <Col :xs="24" :md="8">
                    <Card size="small">
                        <div style="font-size:0.85rem;color:#888;margin-bottom:6px">{{ t('messages.read_pct') }}</div>
                        <Progress :percent="stats.read_pct" />
                    </Card>
                </Col>
            </Row>

            <div class="message-body">
                <h3>{{ t('messages.body') }}</h3>
                <div class="message-body__content" v-html="message.body" />
            </div>

            <div v-if="message.allow_replies" class="replies-section">
                <h3>{{ t('messages.replies_count') }} ({{ replies.length }})</h3>
                <p v-if="replies.length === 0" class="muted">{{ t('messages.replies_empty') }}</p>
                <div v-else class="replies-list">
                    <div v-for="r in replies" :key="r.id" class="reply-item">
                        <Avatar :src="r.user?.photo_url || undefined" :style="{ background: '#0A6ED1' }" :size="32">{{ r.user?.name?.charAt(0)?.toUpperCase() }}</Avatar>
                        <div class="reply-item__body">
                            <div class="reply-item__head">
                                <strong>{{ r.user?.name ?? '-' }}</strong>
                                <span class="muted">· {{ fmtRel(r.created_at) }}</span>
                            </div>
                            <div class="reply-item__content" v-html="r.body" />
                        </div>
                    </div>
                </div>
            </div>
        </Card>
    </div>
</template>

<style scoped>
.message-show { padding: 16px; width: 100%; }
.page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; gap:12px; flex-wrap:wrap; }
.page-header__title { display:flex; align-items:center; gap:8px; min-width: 0; flex: 1; }
.page-header__title h1 { font-size:1.2rem; margin:0; word-break: break-word; }
.page-header__icon {
    width: 40px; height: 40px; border-radius: 4px;
    background: color-mix(in srgb, var(--color-primary) 12%, transparent); color: var(--color-primary);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.message-body { margin-top: 12px; word-wrap: break-word; overflow-wrap: break-word; }
.message-body__content :deep(img), .message-body__content :deep(table) { max-width: 100%; height: auto; }
.message-body__content :deep(pre) { white-space: pre-wrap; word-break: break-word; }
.message-body h3 { font-size: 1rem; margin: 8px 0 10px; color:#444; }
.message-body__content {
    border: 1px solid var(--color-border, #d9d9d9);
    border-radius: 6px;
    padding: 14px 16px;
    background: var(--color-surface, #fff);
    line-height: 1.55;
}
.message-body__content :deep(p) { margin: 0 0 0.5em; }
.message-body__content :deep(a) { color: var(--color-primary, #0a6ed1); }
.replies-section { margin-top: 28px; }
.replies-section h3 { font-size: 1rem; margin-bottom: 12px; }
.muted { color:#888; }
.replies-list { display: flex; flex-direction: column; gap: 12px; }
.reply-item { display:flex; gap:12px; align-items:flex-start; padding:10px; border:1px solid var(--color-border-soft, #e5e5e5); border-radius:6px; background: var(--color-surface, #fff); }
.reply-item__body { flex:1; }
.reply-item__head { display:flex; gap:6px; align-items:baseline; margin-bottom:6px; font-size:0.85rem; }
.reply-item__content { line-height: 1.5; }
.reply-item__content :deep(p) { margin: 0 0 0.4em; }

@media (max-width: 767px) {
    .message-show { padding: 8px; }
    .page-header__title h1 { font-size: 1.05rem; }
    .message-body__content { padding: 10px 12px; }
}
</style>
