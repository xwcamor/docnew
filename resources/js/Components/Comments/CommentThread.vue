<script setup>
import { ref, onMounted } from 'vue';
import { Button, Textarea, Popconfirm, message } from 'ant-design-vue';
import { DeleteOutlined, UserOutlined } from '@ant-design/icons-vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from '@/Plugins/i18n';
import { useAuth } from '@/Composables/useAuth';

/**
 * CommentThread — hilo de comentarios de usuario reutilizable. Apunta a cualquier
 * objeto comentable (transformer o muestra) por `type` + `id`, con un `context`
 * para separar hilos sobre el mismo objeto. El texto NO se traduce: se muestra tal
 * cual con su autor y fecha. Ver/crear/borrar se gatean por permiso (comments.*).
 */
const props = defineProps({
    type:     { type: String, required: true },   // transformer | furano | chromatographical | fiqui | fpot
    id:       { type: [Number, String], required: true },
    context:  { type: String, default: null },     // p.ej. 'diag_furanos'
    comments: { type: Array, default: () => [] },
    title:    { type: String, default: '' },
    hint:     { type: String, default: '' },
    // Carga diferida: si true, trae los comentarios al montar (para drawers por
    // muestra, donde no conviene precargarlos todos en el payload).
    lazy:     { type: Boolean, default: false },
});

const emit = defineEmits(['count']);

const { t } = useI18n();
const { can, canSeeAudit } = useAuth();
const page = usePage();
const currentUserId = page.props.auth?.user?.id ?? null;

const items = ref([...props.comments]);
const draft = ref('');
const busy = ref(false);
const loading = ref(false);

// La "Nota del diagnosticador" (hilo sobre el transformer) se gatea con
// diagnosis_notes.create; los comentarios por muestra con comments.create.
const canCreate = props.type === 'transformer'
    ? can('diagnosis_notes.create')
    : can('comments.create');
const canDeleteAny = can('comments.delete');
const canDelete = (c) => canDeleteAny && (c.user_id === currentUserId || canSeeAudit.value);

const syncCount = () => emit('count', items.value.length);

// El popup de confirmación se renderiza en el body para que el drawer no lo
// recorte. (document no es accesible desde el template, por eso vive aquí.)
const popupToBody = () => document.body;

onMounted(async () => {
    if (!props.lazy) return;
    loading.value = true;
    try {
        const { data } = await window.axios.post(route('business_management.comments.index'), {
            type: props.type, id: props.id, context: props.context,
        });
        items.value = data.comments || [];
        syncCount();
    } catch (e) {
        // silencioso: el hilo queda vacío y editable
    } finally {
        loading.value = false;
    }
});

const submit = async () => {
    const body = draft.value.trim();
    if (!body || busy.value) return;
    busy.value = true;
    try {
        const { data } = await window.axios.post(route('business_management.comments.store'), {
            type: props.type, id: props.id, context: props.context, body,
        });
        items.value.push(data);
        draft.value = '';
        syncCount();
        message.success(t('comments.saved'));
    } catch (e) {
        message.error(t('comments.error'));
    } finally {
        busy.value = false;
    }
};

const remove = async (c) => {
    try {
        await window.axios.delete(route('business_management.comments.destroy', c.id));
        items.value = items.value.filter((x) => x.id !== c.id);
        syncCount();
        message.success(t('comments.deleted'));
    } catch (e) {
        message.error(t('comments.error'));
    }
};
</script>

<template>
    <div class="cthread">
        <h4 v-if="title" class="cthread__title">{{ title }}</h4>
        <p v-if="hint" class="cthread__hint">{{ hint }}</p>

        <ul v-if="items.length" class="cthread__list">
            <li v-for="c in items" :key="c.id" class="cmt">
                <span class="cmt__avatar"><UserOutlined /></span>
                <div class="cmt__main">
                    <div class="cmt__meta">
                        <strong>{{ c.author }}</strong>
                        <span v-if="c.country || c.lang" class="cmt__tag">
                            {{ [c.country, c.lang].filter(Boolean).join(' · ') }}
                        </span>
                        <span class="cmt__date">{{ c.created_at }}</span>
                    </div>
                    <p class="cmt__body">{{ c.body }}</p>
                </div>
                <Popconfirm
                    v-if="canDelete(c)"
                    :title="$t('comments.delete_confirm')"
                    :overlay-style="{ maxWidth: '260px' }"
                    :get-popup-container="popupToBody"
                    @confirm="remove(c)"
                >
                    <button class="cmt__del" type="button"><DeleteOutlined /></button>
                </Popconfirm>
            </li>
        </ul>
        <p v-else class="cthread__empty">{{ $t('comments.empty') }}</p>

        <div v-if="canCreate" class="cthread__add">
            <Textarea
                v-model:value="draft"
                :placeholder="$t('comments.placeholder')"
                :auto-size="{ minRows: 2, maxRows: 6 }"
                :maxlength="5000"
                @keydown.ctrl.enter="submit"
            />
            <div class="cthread__actions">
                <span class="cthread__kbd">{{ $t('comments.save_hint') }}</span>
                <Button type="primary" size="small" :loading="busy" :disabled="!draft.trim()" @click="submit">
                    {{ $t('comments.add') }}
                </Button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.cthread { margin-top: 4px; }
.cthread__title { font-size: 0.9rem; margin: 0 0 4px; color: var(--color-text, #32363a); }
.cthread__hint { font-size: 0.8rem; color: var(--color-text-muted, #6A6D70); margin: 0 0 10px; }
.cthread__list { list-style: none; margin: 0 0 12px; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.cmt { display: flex; gap: 10px; align-items: flex-start; }
.cmt__avatar { flex: none; width: 28px; height: 28px; border-radius: 50%; background: var(--color-surface-alt, #f0f2f4); display: inline-flex; align-items: center; justify-content: center; color: #6A6D70; font-size: 0.8rem; }
.cmt__main { flex: 1; min-width: 0; }
.cmt__meta { display: flex; align-items: baseline; gap: 8px; font-size: 0.82rem; }
.cmt__meta strong { color: var(--color-text, #32363a); }
.cmt__date { color: var(--color-text-muted, #9aa0a6); font-size: 0.75rem; font-variant-numeric: tabular-nums; }
.cmt__tag { font-size: 0.68rem; font-weight: 600; letter-spacing: 0.3px; color: #0A6ED1; background: rgba(10, 110, 209, 0.1); border-radius: 4px; padding: 1px 6px; }
.cmt__body { margin: 2px 0 0; font-size: 0.88rem; line-height: 1.5; color: var(--color-text, #32363a); white-space: pre-wrap; word-break: break-word; }
.cmt__del { flex: none; border: none; background: none; cursor: pointer; color: #9aa0a6; padding: 2px 4px; border-radius: 4px; }
.cmt__del:hover { color: #C8281D; background: rgba(200, 40, 29, 0.08); }
.cthread__empty { font-size: 0.83rem; color: var(--color-text-muted, #9aa0a6); margin: 0 0 12px; }
.cthread__actions { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 8px; }
.cthread__kbd { font-size: 0.75rem; color: var(--color-text-muted, #9aa0a6); }
html[data-theme="dark"] .cmt__avatar { background: #2a2f34; }
</style>
