<script setup>
import { computed, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Tooltip, Pagination, Empty,
} from 'ant-design-vue';
import {
    DownloadOutlined,
    DeleteOutlined,
    FileExcelOutlined,
    FilePdfOutlined,
    FileWordOutlined,
    FileOutlined,
    LoadingOutlined,
    CloseCircleFilled,
    CheckCircleFilled,
    BellOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';

import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

/**
 * Notifications Index — bandeja unificada de notificaciones del usuario.
 *
 * Hoy contiene "downloads" (archivos exportados listos para bajar). Cuando
 * se sumen tareas/alertas/etc., cada item del array tiene un `kind` que
 * permite renderizar diferente según el tipo (download / task / alert).
 */

const props = defineProps({
    notifications: { type: Object, required: true },
    filters:       { type: Object, required: true },
});

const reload = () => router.reload({ preserveScroll: true });

// ── UI helpers — solo aplican al kind 'download' por ahora ────────────
const fileIcon = (type) => {
    switch (type) {
        case 'excel': return { icon: FileExcelOutlined, color: '#1D7044' };
        case 'pdf':   return { icon: FilePdfOutlined,   color: '#C8281D' };
        case 'word':  return { icon: FileWordOutlined,  color: '#185ABD' };
        default:      return { icon: FileOutlined,      color: '#6A6D70' };
    }
};

const statusTag = (status) => {
    switch (status) {
        case 'processing': return { color: 'processing', label: t('notifications.status_processing'), icon: LoadingOutlined };
        case 'ready':      return { color: 'success',    label: t('notifications.status_ready'),      icon: CheckCircleFilled };
        case 'failed':     return { color: 'error',      label: t('notifications.status_failed'),     icon: CloseCircleFilled };
        case 'expired':    return { color: 'default',    label: t('notifications.status_expired'),    icon: null };
        default:           return { color: 'default',    label: status,                                icon: null };
    }
};

const fmtDate = (d) => d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '—';

const triggerDownload = (n) => {
    if (n.kind !== 'download' || n.status !== 'ready') return;
    window.location.href = route('notifications.download', n.id);
    setTimeout(reload, 800);
};

const dismiss = (n) => {
    router.delete(
        route('notifications.delete', n.id),
        { preserveScroll: true, onFinish: reload },
    );
};

const onPageChange = (page, pageSize) => {
    router.reload({
        only: ['notifications', 'filters'],
        data: { page, per_page: pageSize },
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

// Auto-refresh: el AppLayout polea el shared `inbox` cada 8s mientras haya
// jobs en proceso. Cuando ese contador cambia, refrescamos también esta
// vista para que el usuario vea los status actualizados sin tocar nada.
// Usamos `inbox` (no `notifications`) para evitar colisión con el page-prop.
const sharedInbox = computed(() => usePage().props.inbox ?? null);
watch(
    () => sharedInbox.value?.processing,
    (curr, prev) => {
        if (curr === prev) return;
        reload();
    },
);
</script>

<template>
    <Head :title="$t('notifications.title')" />

    <div class="sap-index">
        <div class="mi-title" data-tour="module">
            <div class="page-header__title">
                <div class="page-header__icon">
                    <BellOutlined />
                </div>
                <div class="page-header__heading">
                    <h1>{{ $t('notifications.title') }}</h1>
                    <p>
                        {{ $t(notifications.total === 1 ? 'notifications.count_one' : 'notifications.count_many', { count: notifications.total }) }}
                        · {{ $t('notifications.auto_delete_hint') }}
                    </p>
                </div>
            </div>
        </div>

        <Card v-if="notifications.data.length > 0" :bodyStyle="{ padding: 0 }" class="notif-card">
            <ul class="notif-list">
                <li
                    v-for="n in notifications.data"
                    :key="n.id"
                    class="notif-item"
                    :class="{ 'notif-item--unread': n.kind === 'download' && n.status === 'ready' && !n.downloaded_at }"
                >
                    <!-- Render por kind. Hoy solo download; mañana sumamos task/alert. -->
                    <template v-if="n.kind === 'download'">
                        <component
                            :is="fileIcon(n.type).icon"
                            class="notif-item__icon"
                            :style="{ color: fileIcon(n.type).color }"
                        />
                        <div class="notif-item__body">
                            <div class="notif-item__name">{{ n.filename }}</div>
                            <div class="notif-item__meta">
                                <Tag :color="statusTag(n.status).color" :bordered="false">
                                    <component :is="statusTag(n.status).icon" v-if="statusTag(n.status).icon" />
                                    {{ statusTag(n.status).label }}
                                </Tag>
                                <span class="notif-item__date">{{ $t('notifications.generated') }}: {{ fmtDate(n.created_at) }}</span>
                                <span v-if="n.downloaded_at" class="notif-item__date">
                                    · {{ $t('notifications.downloaded') }}: {{ fmtDate(n.downloaded_at) }}
                                </span>
                                <span v-if="n.expires_at" class="notif-item__date">
                                    · {{ $t('notifications.expires') }}: {{ fmtDate(n.expires_at) }}
                                </span>
                            </div>
                            <div v-if="n.error_message" class="notif-item__error">
                                {{ n.error_message }}
                            </div>
                        </div>
                        <div class="notif-item__actions">
                            <Tooltip v-if="n.status === 'ready'" :title="$t('notifications.download')">
                                <Button type="primary" @click="triggerDownload(n)">
                                    <DownloadOutlined /> {{ $t('notifications.download') }}
                                </Button>
                            </Tooltip>
                            <Tooltip :title="$t('notifications.dismiss')">
                                <Button danger ghost @click="dismiss(n)">
                                    <DeleteOutlined />
                                </Button>
                            </Tooltip>
                        </div>
                    </template>
                </li>
            </ul>
        </Card>

        <Card v-else class="notif-card notif-card--empty">
            <Empty :description="$t('notifications.empty')">
                <template #image>
                    <BellOutlined style="font-size: 3rem; color: #cbd5e1;" />
                </template>
                <p class="notif-card__hint">
                    {{ $t('notifications.empty_hint') }}
                </p>
            </Empty>
        </Card>

        <div v-if="notifications.total > notifications.per_page" class="notif-pagination">
            <Pagination
                :current="notifications.current_page"
                :pageSize="notifications.per_page"
                :total="notifications.total"
                :pageSizeOptions="['10', '25', '50', '100']"
                show-size-changer
                @change="onPageChange"
                @show-size-change="onPageChange"
            />
        </div>
    </div>
</template>

<style scoped>
.notif-card { border-radius: 6px; }
.notif-card--empty { padding: 32px 16px; text-align: center; }
.notif-card__hint {
    color: #6A6D70;
    font-size: 0.875rem;
    margin: 8px 0 0 0;
}

.notif-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.notif-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    border-bottom: 1px solid #F0F0F0;
    position: relative;
}
.notif-item:last-child { border-bottom: 0; }
.notif-item:hover { background: #F8FAFC; }
.notif-item--unread::before {
    content: "";
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
    background: #0A6ED1;
}

.notif-item__icon {
    font-size: 2rem;
    flex-shrink: 0;
}
.notif-item__body { flex: 1; min-width: 0; }
.notif-item__name {
    font-size: 0.9375rem;
    font-weight: 600;
    color: #32363A;
    margin-bottom: 4px;
    word-break: break-word;
}
.notif-item__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    color: #6A6D70;
}
.notif-item__date { color: #6A6D70; }
.notif-item__error {
    margin-top: 6px;
    font-size: 0.78rem;
    color: #C8281D;
    background: rgba(200, 40, 29, 0.06);
    padding: 6px 10px;
    border-radius: 4px;
}

.notif-item__actions {
    display: inline-flex;
    gap: 6px;
    flex-shrink: 0;
}

.notif-pagination {
    display: flex;
    justify-content: center;
    margin-top: 16px;
}

@media (max-width: 768px) {
    .notif-item {
        flex-wrap: wrap;
        padding: 12px 14px;
    }
    .notif-item__icon { font-size: 1.6rem; }
    .notif-item__actions {
        width: 100%;
        margin-top: 8px;
    }
    .notif-item__actions :deep(.ant-btn) { flex: 1; }
}
</style>

<style>
html[data-theme="dark"] .page-header h1 { color: #e5e6e7; }
html[data-theme="dark"] .page-header p  { color: #a8aaae; }
html[data-theme="dark"] .notif-item { border-bottom-color: #3f4448; }
html[data-theme="dark"] .notif-item:hover { background: #313a44; }
html[data-theme="dark"] .notif-item__name { color: #e5e6e7; }
html[data-theme="dark"] .notif-item__meta,
html[data-theme="dark"] .notif-item__date { color: #a8aaae; }
html[data-theme="dark"] .notif-item--unread::before { background: #4db6e8; }
html[data-theme="dark"] .notif-card__hint { color: #a8aaae; }
</style>
