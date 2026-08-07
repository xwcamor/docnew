<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Segmented, Button, Modal, DatePicker, Input, Textarea, Select, Tag, Empty } from 'ant-design-vue';
import {
    PlusOutlined, EditOutlined, DeleteOutlined, CalendarOutlined,
    LeftOutlined, RightOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import { useI18n } from '@/Plugins/i18n';

/**
 * BitacoraSection — bitácora del transformador: comentarios/eventos como línea de
 * tiempo y kanban. Cada evento es puntual (una fecha) o de tramo (rango). El kanban
 * agrupa por estado; se puede mover de columna con las flechas o desde el modal.
 */
const props = defineProps({
    transformerSlug: { type: String, required: true },
    events:          { type: Array,  default: () => [] },
    canEdit:         { type: Boolean, default: false },
});

const { t } = useI18n();

const STATUSES = ['pendiente', 'en_seguimiento', 'resuelto'];
const CATEGORIES = ['observacion', 'mantenimiento', 'alarma', 'ensayo'];
const STATUS_COLOR = { pendiente: '#E9A23B', en_seguimiento: '#0A6ED1', resuelto: '#1D7044' };
const CAT_COLOR = { observacion: 'blue', mantenimiento: 'cyan', alarma: 'red', ensayo: 'purple' };
const statusLabel = (s) => t('bitacora.status_' + s);
const catLabel = (c) => (c ? t('bitacora.cat_' + c) : t('bitacora.cat_none'));

const view = ref('timeline');

const options = computed(() => [
    { label: t('bitacora.timeline_tab'), value: 'timeline' },
    { label: t('bitacora.kanban_tab'), value: 'kanban' },
]);
const statusOptions = computed(() => STATUSES.map((s) => ({ value: s, label: statusLabel(s) })));
const catOptions = computed(() => CATEGORIES.map((c) => ({ value: c, label: catLabel(c) })));

// Línea de tiempo: más reciente arriba.
const timeline = computed(() =>
    [...props.events].sort((a, b) => (a.starts_at < b.starts_at ? 1 : -1)),
);
// Kanban: agrupado por estado, cada columna más reciente arriba.
const board = computed(() =>
    STATUSES.map((s) => ({
        status: s,
        items: timeline.value.filter((e) => e.status === s),
    })),
);

const fmtRange = (e) => (e.ends_at ? `${e.starts_at} → ${e.ends_at}` : e.starts_at);

// ── Modal ──────────────────────────────────────────────────────────────
const blank = () => ({ id: null, title: '', body: '', status: 'pendiente', category: null, starts_at: null, ends_at: null });
const modal = reactive({ open: false, mode: 'create', error: '', saving: false, ...blank() });

const openCreate = () => Object.assign(modal, blank(), { open: true, mode: 'create', error: '', saving: false });
const openEdit = (e) => Object.assign(modal, blank(), {
    open: true, mode: 'edit', error: '', saving: false,
    id: e.id, title: e.title, body: e.body ?? '', status: e.status, category: e.category,
    starts_at: e.starts_at ? dayjs(e.starts_at) : null,
    ends_at: e.ends_at ? dayjs(e.ends_at) : null,
});

const payloadFrom = (m) => ({
    title: m.title,
    body: m.body || null,
    status: m.status,
    category: m.category || null,
    starts_at: m.starts_at ? dayjs(m.starts_at).format('YYYY-MM-DD HH:mm') : null,
    ends_at: m.ends_at ? dayjs(m.ends_at).format('YYYY-MM-DD HH:mm') : null,
});

const submit = () => {
    if (!modal.title.trim()) { modal.error = t('bitacora.title_required'); return; }
    if (!modal.starts_at) { modal.error = t('bitacora.starts_required'); return; }
    modal.error = '';
    modal.saving = true;
    const opts = {
        preserveScroll: true,
        onSuccess: () => { modal.open = false; },
        onError: (e) => { modal.error = Object.values(e)[0] || ''; },
        onFinish: () => { modal.saving = false; },
    };
    if (modal.mode === 'create') {
        router.post(route('business_management.transformers.events.store', props.transformerSlug), payloadFrom(modal), opts);
    } else {
        router.put(route('business_management.transformers.events.update', [props.transformerSlug, modal.id]), payloadFrom(modal), opts);
    }
};

const remove = (e) => {
    Modal.confirm({
        title: t('bitacora.delete_confirm', { title: e.title }),
        okText: t('global.delete'), okType: 'danger', cancelText: t('global.cancel'), maskClosable: false,
        onOk: () => router.delete(
            route('business_management.transformers.events.destroy', [props.transformerSlug, e.id]),
            { preserveScroll: true },
        ),
    });
};

// Mover de columna (kanban) sin abrir el modal.
const move = (e, dir) => {
    const i = STATUSES.indexOf(e.status) + dir;
    if (i < 0 || i >= STATUSES.length) return;
    router.put(
        route('business_management.transformers.events.update', [props.transformerSlug, e.id]),
        payloadFrom({ ...e, status: STATUSES[i], starts_at: e.starts_at, ends_at: e.ends_at }),
        { preserveScroll: true },
    );
};
</script>

<template>
    <div class="bita">
        <div class="bita__head">
            <Segmented v-model:value="view" :options="options" />
            <Button v-if="canEdit" type="primary" @click="openCreate">
                <template #icon><PlusOutlined /></template>{{ $t('bitacora.new_event') }}
            </Button>
        </div>

        <div v-if="!events.length" class="bita__empty">
            <Empty :description="$t('bitacora.no_events')" />
        </div>

        <!-- Línea de tiempo -->
        <div v-else-if="view === 'timeline'" class="tl">
            <div v-for="e in timeline" :key="e.id" class="tl__item">
                <div class="tl__rail">
                    <span class="tl__dot" :style="{ background: STATUS_COLOR[e.status] }"></span>
                </div>
                <div class="tl__card lift">
                    <div class="tl__top">
                        <span class="tl__date"><CalendarOutlined /> {{ fmtRange(e) }}</span>
                        <Tag :color="STATUS_COLOR[e.status]">{{ statusLabel(e.status) }}</Tag>
                        <Tag v-if="e.category" :color="CAT_COLOR[e.category]">{{ catLabel(e.category) }}</Tag>
                        <span v-if="e.ends_at" class="tl__pill">{{ $t('bitacora.range') }}</span>
                    </div>
                    <div class="tl__title">{{ e.title }}</div>
                    <div v-if="e.body" class="tl__body">{{ e.body }}</div>
                    <div v-if="canEdit" class="tl__actions">
                        <button class="ic" @click="openEdit(e)"><EditOutlined /></button>
                        <button class="ic ic--danger" @click="remove(e)"><DeleteOutlined /></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kanban -->
        <div v-else class="kb">
            <div v-for="col in board" :key="col.status" class="kb__col">
                <div class="kb__colhead" :style="{ borderTopColor: STATUS_COLOR[col.status] }">
                    {{ statusLabel(col.status) }} <span class="kb__count">{{ col.items.length }}</span>
                </div>
                <div class="kb__cards">
                    <div v-for="e in col.items" :key="e.id" class="kb__card lift">
                        <div class="kb__cardtop">
                            <span class="kb__date">{{ fmtRange(e) }}</span>
                            <Tag v-if="e.category" :color="CAT_COLOR[e.category]" size="small">{{ catLabel(e.category) }}</Tag>
                        </div>
                        <div class="kb__title">{{ e.title }}</div>
                        <div v-if="e.body" class="kb__body">{{ e.body }}</div>
                        <div v-if="canEdit" class="kb__actions">
                            <button class="ic" :disabled="col.status === STATUSES[0]" @click="move(e, -1)"><LeftOutlined /></button>
                            <button class="ic" :disabled="col.status === STATUSES[STATUSES.length - 1]" @click="move(e, 1)"><RightOutlined /></button>
                            <span class="kb__spacer"></span>
                            <button class="ic" @click="openEdit(e)"><EditOutlined /></button>
                            <button class="ic ic--danger" @click="remove(e)"><DeleteOutlined /></button>
                        </div>
                    </div>
                    <div v-if="!col.items.length" class="kb__hollow">—</div>
                </div>
            </div>
        </div>

        <Modal
            v-model:open="modal.open"
            :title="modal.mode === 'create' ? $t('bitacora.new_event') : $t('bitacora.edit_event')"
            :confirm-loading="modal.saving"
            :ok-text="$t('global.save')" :cancel-text="$t('global.cancel')"
            :mask-closable="false" :keyboard="false" width="560px"
            @ok="submit"
        >
            <div class="eform">
                <label>{{ $t('bitacora.title') }} *</label>
                <Input v-model:value="modal.title" :maxlength="150" />

                <div class="eform__row">
                    <div>
                        <label>{{ $t('bitacora.status') }} *</label>
                        <Select v-model:value="modal.status" :options="statusOptions" style="width: 100%" />
                    </div>
                    <div>
                        <label>{{ $t('bitacora.category') }}</label>
                        <Select v-model:value="modal.category" :options="catOptions" allow-clear style="width: 100%" />
                    </div>
                </div>

                <div class="eform__row">
                    <div>
                        <label>{{ $t('bitacora.starts_at') }} *</label>
                        <DatePicker v-model:value="modal.starts_at" :show-time="{ format: 'HH:mm' }" format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm" style="width: 100%" />
                    </div>
                    <div>
                        <label>{{ $t('bitacora.ends_at') }}</label>
                        <DatePicker v-model:value="modal.ends_at" :show-time="{ format: 'HH:mm' }" format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD HH:mm" style="width: 100%" />
                    </div>
                </div>
                <p class="eform__hint">{{ $t('bitacora.ends_at_hint') }}</p>

                <label>{{ $t('bitacora.body') }}</label>
                <Textarea v-model:value="modal.body" :rows="4" :maxlength="5000" show-count />

                <div v-if="modal.error" class="eform__error">{{ modal.error }}</div>
            </div>
        </Modal>
    </div>
</template>

<style scoped>
.bita__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
.bita__empty { padding: 24px 0; }

/* Timeline */
.tl { position: relative; }
.tl__item { display: flex; gap: 14px; }
.tl__rail { position: relative; width: 14px; display: flex; justify-content: center; }
.tl__rail::before { content: ''; position: absolute; top: 0; bottom: 0; width: 2px; background: var(--color-border, #e2e6ea); }
.tl__dot { width: 12px; height: 12px; border-radius: 50%; margin-top: 6px; z-index: 1; border: 2px solid #fff; }
.tl__card { flex: 1; border: 1px solid var(--color-border, #e2e6ea); border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; position: relative; }
.tl__top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 5px; }
.tl__date { font-size: 0.8rem; color: var(--color-text-muted, #6A6D70); }
.tl__pill { font-size: 0.7rem; background: var(--color-surface-alt, #f0f2f5); border-radius: 10px; padding: 1px 8px; color: #6A6D70; }
.tl__title { font-weight: 700; color: var(--color-text, #32363a); }
.tl__body { font-size: 0.88rem; color: var(--color-text, #4a4f55); margin-top: 4px; white-space: pre-wrap; }
.tl__actions { position: absolute; top: 10px; right: 10px; display: flex; gap: 4px; }

/* Kanban */
.kb { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.kb__col { background: var(--color-surface-alt, #f7f9fb); border-radius: 10px; padding: 8px; }
.kb__colhead { font-size: 0.82rem; font-weight: 700; padding: 6px 8px 8px; border-top: 3px solid; border-radius: 3px 3px 0 0; color: var(--color-text, #32363a); }
.kb__count { color: var(--color-text-muted, #9aa0a6); font-weight: 600; }
.kb__cards { display: flex; flex-direction: column; gap: 8px; min-height: 40px; }
.kb__card { background: var(--color-surface, #fff); border: 1px solid var(--color-border, #e2e6ea); border-radius: 8px; padding: 9px 11px; }
.kb__cardtop { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 3px; }
.kb__date { font-size: 0.74rem; color: var(--color-text-muted, #6A6D70); }
.kb__title { font-weight: 600; font-size: 0.9rem; color: var(--color-text, #32363a); }
.kb__body { font-size: 0.82rem; color: var(--color-text-muted, #6A6D70); margin-top: 3px; max-height: 54px; overflow: hidden; }
.kb__actions { display: flex; align-items: center; gap: 4px; margin-top: 7px; }
.kb__spacer { flex: 1; }
.kb__hollow { text-align: center; color: var(--color-text-muted, #c0c4c8); padding: 10px 0; font-size: 0.8rem; }

.ic { border: none; background: transparent; cursor: pointer; color: #6A6D70; width: 26px; height: 26px; border-radius: 6px; }
.ic:hover { background: rgba(10,110,209,0.1); color: #0A6ED1; }
.ic:disabled { opacity: 0.3; cursor: default; }
.ic--danger:hover { background: rgba(200,40,29,0.1); color: #C8281D; }

.eform label { display: block; font-size: 0.78rem; color: var(--color-text-muted, #6A6D70); margin: 10px 0 4px; }
.eform__row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.eform__hint { font-size: 0.76rem; color: var(--color-text-muted, #9aa0a6); margin: 5px 0 0; }
.eform__error { color: #C8281D; font-size: 0.82rem; margin-top: 10px; }

html[data-theme="dark"] .tl__card, html[data-theme="dark"] .kb__card { border-color: #3f4448; }
html[data-theme="dark"] .kb__col { background: #2c3034; }
html[data-theme="dark"] .kb__card { background: #24282c; }

@media (max-width: 820px) { .kb { grid-template-columns: 1fr; } }
</style>
