<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Card, Button, Tag, Empty, Pagination, Avatar, Tabs, TabPane,
} from 'ant-design-vue';
import {
    InboxOutlined, CheckSquareOutlined, MessageOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
dayjs.extend(relativeTime);

import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const { t } = useI18n();

const props = defineProps({
    messages: { type: Object, required: true },
    filters:  { type: Object, required: true },
});

// Tab activo derivado de los filtros del server (single-source-of-truth).
// "all" | "unread" | "repliable" — mutuamente exclusivos como tabs.
const activeTab = computed(() => {
    if (props.filters.only_unread)    return 'unread';
    if (props.filters.only_repliable) return 'repliable';
    return 'all';
});

const switchTab = (key) => {
    router.get(route('communication.inbox.index'), {
        only_unread:    key === 'unread'    ? 1 : undefined,
        only_repliable: key === 'repliable' ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true });
};

const markAllRead = () => {
    router.post(route('communication.inbox.mark_all_read'), {}, {
        preserveScroll: true,
    });
};

const onPage = (page) => {
    router.get(route('communication.inbox.index'), {
        ...props.filters,
        page,
    }, { preserveState: true, preserveScroll: true });
};

const list = computed(() => props.messages.data ?? []);
const meta = computed(() => ({
    current_page: props.messages.current_page ?? 1,
    per_page:     props.messages.per_page ?? 15,
    total:        props.messages.total ?? 0,
}));

const audienceTag = (type) => {
    if (type === 'global') return { color: 'blue',   label: t('messages.audience_global') };
    if (type === 'tenant') return { color: 'cyan',   label: t('messages.audience_tenant') };
    if (type === 'user')   return { color: 'purple', label: t('messages.audience_user') };
    return { color: 'default', label: type };
};

const fmtRel = (d) => d ? dayjs(d).fromNow() : '-';
</script>

<template>
    <Head :title="t('messages.inbox')" />

    <div class="sap-index inbox-index">
        <div class="mi-title" data-tour="module">
            <div class="page-header__title">
                <div class="page-header__icon"><InboxOutlined /></div>
                <div class="page-header__heading">
                    <h1>{{ t('messages.inbox') }}</h1>
                </div>
            </div>
            <div class="mi-title__actions">
                <Button @click="markAllRead">
                    <template #icon><CheckSquareOutlined /></template>
                    {{ t('messages.mark_all_read') }}
                </Button>
            </div>
        </div>

        <Card>
            <Tabs :active-key="activeTab" @change="switchTab" class="inbox-tabs">
                <TabPane key="all"       :tab="t('messages.tab_all')" />
                <TabPane key="unread"    :tab="t('messages.only_unread')" />
                <TabPane key="repliable" :tab="t('messages.only_repliable')" />
            </Tabs>

            <div v-if="list.length === 0" class="empty-state">
                <Empty :description="t('messages.inbox_empty_title')">
                    <p style="color:#888">{{ t('messages.inbox_empty_hint') }}</p>
                </Empty>
            </div>

            <ul v-else class="msg-list">
                <li v-for="m in list" :key="m.id" class="msg-item" :class="{ 'msg-item--unread': !m.read_at }">
                    <Link :href="route('communication.inbox.show', m.slug)" class="msg-item__link">
                        <Avatar :src="m.creator?.photo_url || undefined" :style="{ background: '#0A6ED1' }" :size="40">
                            <template v-if="!m.creator?.photo_url">
                                <span v-if="m.creator?.name">{{ m.creator.name.charAt(0).toUpperCase() }}</span>
                                <MessageOutlined v-else />
                            </template>
                        </Avatar>
                        <div class="msg-item__body">
                            <div class="msg-item__head">
                                <strong>{{ m.subject }}</strong>
                                <Tag v-if="!m.read_at" color="red" :bordered="false">{{ t('messages.badge_new') }}</Tag>
                                <Tag :color="audienceTag(m.audience_type).color" :bordered="false">
                                    {{ audienceTag(m.audience_type).label }}
                                </Tag>
                                <Tag v-if="m.allow_replies" color="purple" :bordered="false">{{ t('messages.reply') }}</Tag>
                            </div>
                            <div class="msg-item__snippet">{{ m.snippet }}</div>
                            <div class="msg-item__meta">
                                <span v-if="m.creator">· {{ m.creator.name }}</span>
                                <span>· {{ fmtRel(m.published_at) }}</span>
                            </div>
                        </div>
                    </Link>
                </li>
            </ul>

            <div v-if="meta.total > meta.per_page" class="pagination-row">
                <Pagination
                    :current="meta.current_page"
                    :total="meta.total"
                    :page-size="meta.per_page"
                    :show-size-changer="false"
                    @change="onPage"
                />
            </div>
        </Card>
    </div>
</template>

<style scoped>
.empty-state { padding: 40px 0; }
.msg-list { list-style: none; padding: 0; margin: 0; display:flex; flex-direction:column; gap:8px; }
.msg-item {
    border: 1px solid var(--color-border-soft, #e5e5e5);
    border-radius: 6px;
    background: var(--color-surface, #fff);
    transition: border-color 0.12s, background 0.12s;
}
.msg-item:hover { border-color: var(--color-primary, #0a6ed1); }
.msg-item--unread { background: var(--color-brand-soft, #e6f1fb); }
.msg-item__link { display:flex; gap:12px; padding:12px 14px; align-items:flex-start; color: inherit; text-decoration: none; }
.msg-item__body { flex:1; min-width:0; }
.msg-item__head { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-bottom:4px; word-break: break-word; }
.msg-item__snippet {
    color:#555; font-size:0.9rem; margin-bottom:4px; line-height:1.4;
    display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2;
    overflow: hidden; word-break: break-word;
}
.msg-item__meta { font-size:0.8rem; color:#888; display:flex; gap:4px; flex-wrap:wrap; }
.pagination-row { display:flex; justify-content:flex-end; padding-top:14px; }

@media (max-width: 767px) {
    .msg-item__link { padding: 10px 12px; gap: 10px; }
}
</style>
