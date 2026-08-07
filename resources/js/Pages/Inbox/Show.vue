<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import {
    Card, Button, Tag, Space, Avatar, Form, FormItem, Alert,
} from 'ant-design-vue';
import {
    InboxOutlined, ArrowLeftOutlined, SendOutlined, MessageOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
dayjs.extend(relativeTime);

import AppLayout from '@/Layouts/AppLayout.vue';
import RichTextEditor from '@/Components/Common/RichTextEditor.vue';
import { useI18n } from '@/Plugins/i18n';
import { useDateFormat } from '@/Composables/useDateFormat';

const { formatDateTime } = useDateFormat();

defineOptions({ layout: AppLayout });

const { t } = useI18n();

const props = defineProps({
    message:   { type: Object, required: true },
    replies:   { type: Array,  default: () => [] },
    can_reply: { type: Boolean, default: false },
});

const replyForm = useForm({ body: '' });

const submitReply = () => {
    replyForm.post(route('communication.inbox.reply', props.message.slug), {
        onSuccess: () => replyForm.reset('body'),
    });
};

// Formato estandar del proyecto (dd-mm-aaaa HH:mm), respeta el TZ del user.
const fmt = (d) => formatDateTime(d);
const fmtRel = (d) => d ? dayjs(d).fromNow() : '-';

const audienceLabel = computed(() => {
    if (props.message.audience_type === 'global') return t('messages.audience_global');
    if (props.message.audience_type === 'tenant') return t('messages.audience_tenant');
    if (props.message.audience_type === 'user')   return t('messages.audience_user');
    return '-';
});
</script>

<template>
    <Head :title="message.subject" />

    <div class="inbox-show">
        <Card>
            <div class="page-header">
                <div class="page-header__title">
                    <Link :href="route('communication.inbox.index')">
                        <Button type="text"><template #icon><ArrowLeftOutlined /></template></Button>
                    </Link>
                    <InboxOutlined class="page-header__icon" />
                    <h1>{{ message.subject }}</h1>
                </div>
            </div>

            <Space :size="6" style="margin-bottom: 12px">
                <Tag color="blue" :bordered="false">{{ audienceLabel }}</Tag>
                <Tag v-if="message.allow_replies" color="purple" :bordered="false">{{ t('messages.allow_replies') }}</Tag>
            </Space>

            <div class="meta-row">
                <div>
                    <Avatar :src="message.creator?.photo_url || undefined" :style="{ background: '#0A6ED1' }" :size="32">
                        {{ message.creator?.name?.charAt(0)?.toUpperCase() }}
                    </Avatar>
                    <span class="meta-row__name">{{ message.creator?.name ?? '-' }}</span>
                </div>
                <div class="meta-row__date">
                    {{ fmt(message.published_at) }} · {{ fmtRel(message.published_at) }}
                </div>
            </div>

            <div class="message-body" v-html="message.body" />

            <div v-if="can_reply" class="replies-section">
                <h3>{{ t('messages.replies_count') }} ({{ replies.length }})</h3>

                <p v-if="replies.length === 0" class="muted">{{ t('messages.replies_empty') }}</p>
                <div v-else class="replies-list">
                    <div v-for="r in replies" :key="r.id" class="reply-item">
                        <Avatar :src="r.user?.photo_url || undefined" :style="{ background: '#0A6ED1' }" :size="32">
                            {{ r.user?.name?.charAt(0)?.toUpperCase() }}
                        </Avatar>
                        <div class="reply-item__body">
                            <div class="reply-item__head">
                                <strong>{{ r.user?.name ?? '-' }}</strong>
                                <span class="muted">· {{ fmtRel(r.created_at) }}</span>
                            </div>
                            <div class="reply-item__content" v-html="r.body" />
                        </div>
                    </div>
                </div>

                <div class="reply-form">
                    <Form layout="vertical">
                        <FormItem
                            :label="t('messages.reply')"
                            :validate-status="replyForm.errors.body ? 'error' : ''"
                            :help="replyForm.errors.body"
                        >
                            <RichTextEditor v-model="replyForm.body" min-height="140px" />
                        </FormItem>
                        <Button type="primary" :loading="replyForm.processing" @click="submitReply">
                            <template #icon><SendOutlined /></template>
                            {{ t('messages.send_reply') }}
                        </Button>
                    </Form>
                </div>
            </div>

            <Alert
                v-else
                type="info"
                show-icon
                :message="t('messages.replies_not_allowed')"
                style="margin-top: 18px"
            />
        </Card>
    </div>
</template>

<style scoped>
.inbox-show { padding: 16px; width: 100%; }
.page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; gap:12px; flex-wrap:wrap; }
.page-header__title { display:flex; align-items:center; gap:10px; min-width: 0; flex: 1; }
.page-header__title h1 { font-size:1.2rem; margin:0; word-break: break-word; }
.page-header__icon {
    width: 40px; height: 40px; border-radius: 4px;
    background: color-mix(in srgb, var(--color-primary) 12%, transparent); color: var(--color-primary);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.meta-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; padding-bottom:14px; border-bottom: 1px solid var(--color-border-soft, #e5e5e5); gap:8px; flex-wrap:wrap; }
.meta-row__name { margin-left:8px; font-weight:500; }
.meta-row__date { color:#888; font-size:0.85rem; }
.message-body { word-wrap: break-word; overflow-wrap: break-word; }
.message-body :deep(img), .message-body :deep(table) { max-width: 100%; height: auto; }
.message-body :deep(pre) { white-space: pre-wrap; word-break: break-word; }
.message-body {
    line-height: 1.6;
    color: var(--color-text, #32363a);
}
.message-body :deep(p) { margin: 0 0 0.6em; }
.message-body :deep(a) { color: var(--color-primary, #0a6ed1); }
.replies-section { margin-top: 28px; }
.replies-section h3 { font-size: 1rem; margin-bottom: 12px; }
.muted { color:#888; }
.replies-list { display:flex; flex-direction:column; gap:10px; margin-bottom: 18px; }
.reply-item { display:flex; gap:10px; align-items:flex-start; padding:10px; border:1px solid var(--color-border-soft, #e5e5e5); border-radius:6px; background: var(--color-surface, #fff); }
.reply-item__body { flex:1; }
.reply-item__head { display:flex; gap:6px; align-items:baseline; margin-bottom:6px; font-size:0.85rem; }
.reply-item__content { line-height: 1.5; }
.reply-item__content :deep(p) { margin: 0 0 0.4em; }
.reply-form { margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--color-border-soft, #e5e5e5); }

@media (max-width: 767px) {
    .inbox-show { padding: 8px; }
    .page-header__title h1 { font-size: 1.05rem; }
    .meta-row { font-size: 0.85rem; }
}
</style>
