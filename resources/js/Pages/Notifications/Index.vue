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
    NotificationOutlined,
    MailOutlined,
    SafetyCertificateOutlined,
    MessageOutlined,
    ThunderboltOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';

import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

defineOptions({ layout: AppLayout });

/**
 * Notifications Index — bandeja unificada de notificaciones del usuario.
 *
 * Dos tipos, distinguidos por `kind`:
 *   · `download` — un archivo exportado, listo para bajar.
 *   · `app`      — un aviso del sistema (seguridad, automatización, respuesta
 *                  a un mensaje). Son los mismos que enseña la campana.
 *
 * Las dos fuentes tienen que estar aquí: la campana lleva a esta página con
 * "Ver todas", y una página que solo enseñara los archivos dejaría al usuario
 * con un contador que no cuadra con lo que ve.
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

// ── UI helpers — kind 'app' (avisos del sistema) ──────────────────────
// Un icono por concepto, los MISMOS que usa la campana del AppLayout.
const appIcon = (n) => {
    if (n.type === 'automation') return n.channel === 'email' ? MailOutlined : ThunderboltOutlined;
    const map = {
        security:      SafetyCertificateOutlined,
        message_reply: MessageOutlined,
        plan_change:   NotificationOutlined,
    };
    return map[n.type] ?? BellOutlined;
};
const appColor = (n) => {
    if (n.type === 'automation') return n.channel === 'email' ? '#0A6ED1' : '#B45309';
    const map = {
        security:      '#C8281D',
        message_reply: '#0A6ED1',
        plan_change:   '#0A6ED1',
    };
    return map[n.type] ?? '#0A6ED1';
};

const fmtDate = (d) => d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '—';

const triggerDownload = (n) => {
    if (n.kind !== 'download' || n.status !== 'ready') return;
    window.location.href = route('notifications.download', n.id);
    setTimeout(reload, 800);
};

const markRead = (n) => {
    if (n.kind !== 'app' || n.status !== 'unread') return;
    router.post(route('notifications.app.read', n.raw_id), {}, {
        preserveScroll: true,
        onFinish: reload,
    });
};

const markAllRead = () => {
    router.post(route('notifications.app.read_all'), {}, {
        preserveScroll: true,
        onFinish: reload,
    });
};

const unreadAppCount = computed(
    () => (props.notifications.data ?? []).filter((n) => n.kind === 'app' && n.status === 'unread').length,
);

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
            <div v-if="unreadAppCount > 0" class="mi-title__actions">
                <Button @click="markAllRead">
                    <CheckCircleFilled /> {{ $t('notifications.mark_all_read') }}
                </Button>
            </div>
        </div>

        <Card v-if="notifications.data.length > 0" :bodyStyle="{ padding: 0 }" class="notif-card">
            <ul class="notif-list">
                <li
                    v-for="n in notifications.data"
                    :key="n.id"
                    class="notif-item"
                    :class="{ 'notif-item--unread':
                        (n.kind === 'download' && n.status === 'ready' && !n.downloaded_at)
                        || (n.kind === 'app' && n.status === 'unread') }"
                >
                    <!-- Render por kind: download (archivo) | app (aviso del sistema). -->
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

                    <!-- ── Aviso del sistema (seguridad, automatización, respuesta) ── -->
                    <template v-else-if="n.kind === 'app'">
                        <component
                            :is="appIcon(n)"
                            class="notif-item__icon"
                            :style="{ color: appColor(n) }"
                        />
                        <div class="notif-item__body">
                            <div class="notif-item__name">{{ n.title }}</div>
                            <div v-if="n.body" class="notif-item__text">{{ n.body }}</div>
                            <div class="notif-item__meta">
                                <!-- Color Y palabra: al sol el color solo no se lee (UI.md §5). -->
                                <Tag :color="n.status === 'unread' ? 'processing' : 'default'" :bordered="false">
                                    {{ n.status === 'unread' ? $t('notifications.status_unread') : $t('notifications.status_read') }}
                                </Tag>
                                <span class="notif-item__date">{{ $t('notifications.received') }}: {{ fmtDate(n.created_at) }}</span>
                            </div>
                        </div>
                        <div class="notif-item__actions">
                            <Button v-if="n.status === 'unread'" @click="markRead(n)">
                                {{ $t('notifications.mark_read') }}
                            </Button>
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
    color: var(--color-text-muted);
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
    border-bottom: 1px solid var(--color-border-soft);
    position: relative;
}
.notif-item:last-child { border-bottom: 0; }
.notif-item:hover { background: var(--color-surface-hover); }
.notif-item--unread::before {
    content: "";
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
    background: var(--color-primary);
}

.notif-item__icon {
    font-size: 2rem;
    flex-shrink: 0;
}
.notif-item__body { flex: 1; min-width: 0; }
.notif-item__name {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--color-text);
    margin-bottom: 4px;
    word-break: break-word;
}
.notif-item__text {
    font-size: 0.85rem;
    color: var(--color-text-muted);
    margin-bottom: 5px;
    line-height: 1.45;
    word-break: break-word;
}
.notif-item__meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    color: var(--color-text-muted);
}
.notif-item__date { color: var(--color-text-muted); }
.notif-item__error {
    margin-top: 6px;
    font-size: 0.78rem;
    color: var(--state-bad-text);
    background: var(--state-bad-bg);
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

<!-- Ajustes de tema oscuro (sin scope).
     Queda una sola regla. Las demas repetian en oscuro colores que arriba ya
     son tokens, y dos de ellas —las de `.page-header`— ni siquiera pintaban
     nada: la cabecera de esta pantalla es `.mi-title`, que ya viene resuelta
     desde app.css. -->
<style>
/* La marca de «sin leer» es lo unico que no sale del token tal cual: el azul
   principal no se aclara en oscuro (cada esquema lo mantiene), y sobre la
   tarjeta oscura una raya de 3px en ese azul no se ve. El acento si. */
html[data-theme="dark"] .notif-item--unread::before { background: var(--color-primary-accent); }
</style>
