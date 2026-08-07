<script setup>
import { computed, ref } from 'vue';
import { Empty, Tag, Tooltip, Button } from 'ant-design-vue';
import {
    PlusCircleFilled, EditFilled, DeleteFilled,
    UndoOutlined, ExportOutlined, EyeFilled, ClockCircleOutlined,
    ApartmentOutlined, TableOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import 'dayjs/locale/es';
import { useI18n } from '@/Plugins/i18n';
import { useDateFormat } from '@/Composables/useDateFormat';

dayjs.extend(relativeTime);
dayjs.locale('es');

const { t } = useI18n();
const { formatDateTimeFull } = useDateFormat();

/**
 * ActivityTimeline — feed cronológico de cambios (audit_log) de un registro.
 *
 * Compartido por el tab "Cambios" de todos los módulos. Muestra:
 *   1. Auditoría del registro (creación, autor, última actualización).
 *   2. Actividad reciente con filtros rápidos (Todos / Creación / Ediciones /
 *      Eliminaciones) que filtran el timeline al instante.
 *   3. Timeline con diff de campos para las ediciones.
 *   4. Pie de resumen.
 *
 * Recibe los audit_logs del registro como `activity` (orden DESC: más reciente
 * primero). Mejorar este componente mejora la vista en TODOS los módulos.
 */
const props = defineProps({
    activity: { type: Array, required: true },
    // Creación REAL del registro (created_at + autor). El audit log puede no
    // tener un evento 'created' (datos de seed/migración), pero el registro
    // existe → la creación se sintetiza para que SIEMPRE figure en el historial.
    recordCreatedAt: { type: String, default: null },
    recordCreatedBy: { type: String, default: null },
    // "Ver más": el padre carga más entradas del historial (paginado server-side).
    hasMore: { type: Boolean, default: false },
    loadingMore: { type: Boolean, default: false },
});
defineEmits(['load-more']);

// Feed efectivo: si no hay evento 'created' y conocemos la creación real del
// registro, se agrega una entrada sintética (la más vieja). Es lógica, no
// invento: el registro demostrablemente se creó en esa fecha.
const feed = computed(() => {
    const base = props.activity ?? [];
    if (props.recordCreatedAt && !base.some(a => a.event === 'created')) {
        return [...base, {
            id: '__created__',
            event: 'created',
            user: props.recordCreatedBy ? { name: props.recordCreatedBy } : null,
            created_at: props.recordCreatedAt,
            subject: null, changes: null, old_values: null, new_values: null,
        }];
    }
    return base;
});

// ── Filtros rápidos ───────────────────────────────────────────────────
const FILTER_GROUPS = {
    all:       null,
    creations: ['created'],
    edits:     ['updated'],
    deletions: ['deleted', 'force_deleted'],
};

const activeFilter = ref('all');
const viewMode = ref('tree'); // 'tree' (timeline) | 'table'

const counts = computed(() => ({
    all:       feed.value.length,
    creations: feed.value.filter(a => a.event === 'created').length,
    edits:     feed.value.filter(a => a.event === 'updated').length,
    deletions: feed.value.filter(a => ['deleted', 'force_deleted'].includes(a.event)).length,
}));

const filters = computed(() => [
    { key: 'all',       label: t('global.filter_all'),       count: counts.value.all },
    { key: 'creations', label: t('global.filter_creations'), count: counts.value.creations },
    { key: 'edits',     label: t('global.filter_edits'),     count: counts.value.edits },
    { key: 'deletions', label: t('global.filter_deletions'), count: counts.value.deletions },
]);

const filteredActivity = computed(() => {
    const group = FILTER_GROUPS[activeFilter.value];
    if (!group) return feed.value;
    return feed.value.filter(a => group.includes(a.event));
});

// ── Helpers de presentación ───────────────────────────────────────────
const eventMeta = (event) => {
    switch (event) {
        case 'created':       return { icon: PlusCircleFilled, color: '#1D7044', label: t('global.event_created') };
        case 'updated':       return { icon: EditFilled,        color: '#0A6ED1', label: t('global.event_updated') };
        case 'deleted':       return { icon: DeleteFilled,      color: '#C8281D', label: t('global.event_deleted') };
        case 'force_deleted': return { icon: DeleteFilled,      color: '#7E1810', label: t('global.event_force_deleted') };
        case 'restored':      return { icon: UndoOutlined,      color: '#1D7044', label: t('global.event_restored') };
        case 'exported':      return { icon: ExportOutlined,    color: '#6A6D70', label: t('global.event_exported') };
        case 'export_queued': return { icon: ExportOutlined,    color: '#6A6D70', label: t('global.event_export_queued') };
        default:              return { icon: EyeFilled,         color: '#6A6D70', label: event };
    }
};

const fmtRelative = (iso) => (iso ? dayjs(iso).fromNow() : '—');
const fmtAbsolute = (iso) => formatDateTimeFull(iso);

const diffFields = (log) => {
    if (log.event !== 'updated') return [];
    const old = log.old_values ?? {};
    const next = log.new_values ?? {};
    const fields = new Set([...Object.keys(old), ...Object.keys(next)]);
    return [...fields]
        .filter(k => !['updated_at', 'created_at'].includes(k))
        .map(k => ({ field: k, before: formatValue(old[k]), after: formatValue(next[k]) }));
};

const formatValue = (v) => {
    if (v === null || v === undefined) return '—';
    if (v === true)  return t('global.yes');
    if (v === false) return t('global.no');
    return String(v);
};

// Filas de cambios: si el server las mandó humanizadas (`changes`: etiqueta
// traducida + FK resueltas), se usan; si no, fallback al diff crudo con el
// nombre de columna prettificado. Mantiene compat con módulos no enriquecidos.
const prettifyField = (k) => k.charAt(0).toUpperCase() + k.slice(1).replace(/_/g, ' ');
const changeRows = (log) => {
    if (Array.isArray(log.changes)) return log.changes;
    return diffFields(log).map(d => ({ label: prettifyField(d.field), before: d.before, after: d.after }));
};
</script>

<template>
    <div class="entity-changes">
        <!-- ── Actividad reciente + filtros rápidos ────────────────── -->
        <!-- La tarjeta "Auditoría del registro" la aporta cada página Show
             (Descriptions) arriba de este componente; aquí solo el feed. -->
        <div class="changes-feed">
            <div class="changes-toolbar">
                <div class="filter-chips" role="tablist">
                    <button
                        v-for="f in filters"
                        :key="f.key"
                        type="button"
                        class="chip"
                        :class="{ 'chip--active': activeFilter === f.key }"
                        @click="activeFilter = f.key"
                    >
                        {{ f.label }}
                        <span class="chip__count">{{ f.count }}</span>
                    </button>
                </div>

                <div class="view-toggle" role="tablist">
                    <button type="button" class="vbtn" :class="{ 'vbtn--active': viewMode === 'tree' }"
                            :title="$t('global.view_tree')" @click="viewMode = 'tree'">
                        <ApartmentOutlined /> <span class="vbtn__lbl">{{ $t('global.view_tree') }}</span>
                    </button>
                    <button type="button" class="vbtn" :class="{ 'vbtn--active': viewMode === 'table' }"
                            :title="$t('global.view_table')" @click="viewMode = 'table'">
                        <TableOutlined /> <span class="vbtn__lbl">{{ $t('global.view_table') }}</span>
                    </button>
                </div>
            </div>

            <div v-if="filteredActivity.length === 0" class="activity-empty">
                <Empty :description="$t('global.no_activity')" />
            </div>

            <!-- ── Vista tabla ── -->
            <div v-else-if="viewMode === 'table'" class="activity-table-wrap">
                <table class="activity-table">
                    <thead>
                        <tr>
                            <th>{{ $t('global.act_date') }}</th>
                            <th>{{ $t('global.act_user') }}</th>
                            <th>{{ $t('global.act_action') }}</th>
                            <th>{{ $t('global.act_detail') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in filteredActivity" :key="log.id">
                            <td class="at-nowrap">
                                <Tooltip :title="fmtAbsolute(log.created_at)">
                                    <span>{{ fmtRelative(log.created_at) }}</span>
                                </Tooltip>
                            </td>
                            <td>{{ log.user?.name ?? $t('global.system_actor') }}</td>
                            <td class="at-nowrap">
                                <span class="at-dot" :style="{ background: eventMeta(log.event).color }"></span>
                                {{ eventMeta(log.event).label }}
                                <span v-if="log.subject" class="at-subject">· {{ log.subject }}</span>
                            </td>
                            <td>
                                <template v-if="changeRows(log).length">
                                    <div v-for="(d, i) in changeRows(log)" :key="i" class="at-change">
                                        <b>{{ d.label }}:</b> {{ d.before }} → {{ d.after }}
                                    </div>
                                </template>
                                <span v-else class="changes-footer__muted">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ── Vista árbol (timeline) ── -->
            <ul v-else class="activity-timeline">
                <li v-for="log in filteredActivity" :key="log.id" class="activity-item">
                    <div class="activity-item__icon" :style="{ background: eventMeta(log.event).color }">
                        <component :is="eventMeta(log.event).icon" />
                    </div>
                    <div class="activity-item__body">
                        <div class="activity-item__head">
                            <span class="activity-item__actor">{{ log.user?.name ?? $t('global.system_actor') }}</span>
                            <span class="activity-item__action">{{ eventMeta(log.event).label.toLowerCase() }}</span>
                            <span v-if="log.subject" class="activity-item__subject">{{ log.subject }}</span>
                            <Tooltip :title="fmtAbsolute(log.created_at)">
                                <span class="activity-item__time">
                                    <ClockCircleOutlined /> {{ fmtRelative(log.created_at) }}
                                </span>
                            </Tooltip>
                        </div>

                        <div v-if="changeRows(log).length > 0" class="activity-diff">
                            <div v-for="(d, i) in changeRows(log)" :key="i" class="activity-diff__row">
                                <span class="activity-diff__field">{{ d.label }}:</span>
                                <span class="activity-diff__before">{{ d.before }}</span>
                                <span class="activity-diff__arrow">→</span>
                                <span class="activity-diff__after">{{ d.after }}</span>
                            </div>
                        </div>

                        <div v-else-if="log.event === 'exported' && log.new_values?.format" class="activity-meta">
                            <Tag color="default" :bordered="false">{{ log.new_values.format.toUpperCase() }}</Tag>
                            <span v-if="log.new_values.scope" class="activity-meta__hint">
                                alcance: {{ log.new_values.scope }}
                            </span>
                        </div>
                    </div>
                </li>
            </ul>

            <div v-if="hasMore" class="activity-more">
                <Button size="small" :loading="loadingMore" @click="$emit('load-more')">
                    {{ $t('global.show_more') }}
                </Button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.entity-changes {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.activity-more { display: flex; justify-content: center; padding: 8px 0 2px; }

.changes-card {
    background: var(--color-surface, #fff);
    border: 1px solid var(--color-border, #e5e7eb);
    border-radius: 8px;
    padding: 18px 20px;
}
.changes-card__title {
    margin: 0 0 14px 0;
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--color-text, #1f2937);
}

/* ── Auditoría del registro ── */
.audit-grid { margin: 0; display: flex; flex-direction: column; }
.audit-grid__row {
    display: grid;
    grid-template-columns: 180px 1fr;
    gap: 12px;
    padding: 12px 0;
    align-items: center;
}
.audit-grid__row + .audit-grid__row { border-top: 1px solid var(--color-border, #f0f0f0); }
.audit-grid dt { color: #6A6D70; font-size: 0.875rem; }
.audit-grid dd { margin: 0; color: #32363A; font-size: 0.9rem; }
.audit-grid__actor { color: var(--color-primary, #0A6ED1); font-weight: 500; }

/* ── Filtros rápidos (chips) ── */
.changes-toolbar {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 12px; flex-wrap: wrap; margin-bottom: 18px;
}
.filter-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
/* Toggle Árbol/Tabla */
.view-toggle {
    display: inline-flex; border: 1px solid var(--color-border, #e5e7eb);
    border-radius: 8px; overflow: hidden; flex-shrink: 0; background: var(--color-surface, #fff);
}
.vbtn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border: none; background: transparent; cursor: pointer;
    color: var(--color-text-muted, #6A6D70); font-size: 0.82rem; font-weight: 500;
    transition: background 0.15s ease, color 0.15s ease;
}
.vbtn + .vbtn { border-left: 1px solid var(--color-border, #e5e7eb); }
.vbtn:hover { color: var(--color-primary, #0A6ED1); }
.vbtn--active { background: var(--color-primary, #0A6ED1); color: #fff; }
.vbtn--active:hover { color: #fff; }

/* Vista tabla */
.activity-table-wrap { overflow-x: auto; }
.activity-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.activity-table th {
    text-align: left; font-weight: 600; color: var(--color-text-muted, #6A6D70);
    font-size: 0.72rem; letter-spacing: 0.04em; text-transform: uppercase;
    padding: 8px 12px; border-bottom: 1px solid var(--color-border, #e5e7eb);
}
.activity-table td {
    padding: 10px 12px; border-bottom: 1px solid var(--color-border, #eef0f2);
    vertical-align: top; color: var(--color-text, #32363A);
}
.activity-table tr:hover td { background: var(--color-surface-alt, #f7f8f9); }
.at-nowrap { white-space: nowrap; }
.at-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
.at-subject { color: var(--color-text-muted, #6A6D70); }
.at-change { line-height: 1.5; }
.at-change b { font-weight: 600; }
html[data-theme="dark"] .view-toggle { background: transparent; border-color: #33373b; }
html[data-theme="dark"] .vbtn + .vbtn { border-color: #33373b; }
html[data-theme="dark"] .activity-table tr:hover td { background: rgba(255,255,255,0.04); }
.chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 5px 14px;
    border-radius: 999px;
    border: 1px solid var(--color-border, #e5e7eb);
    background: var(--color-surface, #fff);
    color: #475569;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s ease;
}
/* Solo los chips NO activos cambian a azul en hover: el activo ya tiene fondo
   azul, así que pintarle el texto de azul lo ocultaba (bug). */
.chip:not(.chip--active):hover { border-color: var(--color-primary, #0A6ED1); color: var(--color-primary, #0A6ED1); }
.chip--active:hover { filter: brightness(1.06); }
.chip--active {
    background: var(--color-primary, #0A6ED1);
    border-color: var(--color-primary, #0A6ED1);
    color: #fff;
}
.chip__count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.06);
    font-size: 0.75rem;
    font-weight: 600;
}
.chip--active .chip__count { background: rgba(255, 255, 255, 0.25); color: #fff; }

/* ── Timeline ── */
.activity-empty { padding: 24px 16px; }
.activity-timeline { list-style: none; margin: 0; padding: 0; position: relative; }
.activity-timeline::before {
    content: '';
    position: absolute;
    left: 14px; top: 0; bottom: 0;
    width: 1px; background: #E5E5E5;
}
.activity-item { position: relative; display: flex; align-items: flex-start; gap: 14px; padding: 8px 0; }
.activity-item:not(:last-child) { padding-bottom: 16px; }
.activity-item__icon {
    flex-shrink: 0; width: 30px; height: 30px; border-radius: 50%;
    background: #6A6D70; color: #fff;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.85rem; z-index: 1;
}
.activity-item__body { flex: 1; min-width: 0; padding-top: 4px; }
.activity-item__head {
    display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px;
    font-size: 0.875rem; color: #32363A; line-height: 1.4;
}
.activity-item__actor { font-weight: 600; color: #1f2937; }
.activity-item__action { color: #6A6D70; }
.activity-item__subject { color: var(--color-text, #32363A); font-weight: 500; }
.activity-item__time { margin-left: auto; font-size: 0.78rem; color: #6A6D70; display: inline-flex; align-items: center; gap: 4px; }
.activity-item__time :deep(.anticon) { font-size: 0.7rem; }

.activity-diff {
    margin-top: 8px; padding: 8px 12px;
    background: #F8FAFC; border-left: 2px solid #0A6ED1; border-radius: 0 4px 4px 0;
    font-size: 0.8125rem;
}
.activity-diff__row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; color: #32363A; line-height: 1.5; }
.activity-diff__row + .activity-diff__row { margin-top: 2px; }
.activity-diff__field { font-weight: 600; color: #475569; text-transform: capitalize; }
.activity-diff__before {
    background: rgba(200, 40, 29, 0.08); color: #C8281D; padding: 1px 6px; border-radius: 3px;
    font-family: ui-monospace, SFMono-Regular, monospace; font-size: 0.78rem; text-decoration: line-through;
}
.activity-diff__arrow { color: #6A6D70; font-weight: 600; }
.activity-diff__after {
    background: rgba(29, 112, 68, 0.10); color: #1D7044; padding: 1px 6px; border-radius: 3px;
    font-family: ui-monospace, SFMono-Regular, monospace; font-size: 0.78rem; font-weight: 600;
}
.activity-meta { margin-top: 6px; display: inline-flex; align-items: center; gap: 8px; font-size: 0.78rem; }
.activity-meta__hint { color: #6A6D70; }

/* Texto tenue reutilizado en la celda "Detalle" de la vista tabla. */
.changes-footer__muted { color: #9aa0a6; }
</style>

<style>
/* Dark mode */
html[data-theme="dark"] .changes-card {
    background: #2c3034;
    border-color: #3f4448;
}
html[data-theme="dark"] .changes-card__title { color: #e5e6e7; }
html[data-theme="dark"] .audit-grid__row + .audit-grid__row { border-top-color: #3f4448; }
html[data-theme="dark"] .audit-grid dt { color: #a8aaae; }
html[data-theme="dark"] .audit-grid dd { color: #e5e6e7; }
html[data-theme="dark"] .chip {
    background: #23272b; border-color: #3f4448; color: #cbd5e1;
}
html[data-theme="dark"] .chip__count { background: rgba(255,255,255,0.08); }
html[data-theme="dark"] .activity-timeline::before { background: #3f4448; }
html[data-theme="dark"] .activity-item__head { color: #e5e6e7; }
html[data-theme="dark"] .activity-item__actor { color: #e5e6e7; }
html[data-theme="dark"] .activity-item__action,
html[data-theme="dark"] .activity-item__time { color: #a8aaae; }
html[data-theme="dark"] .activity-diff { background: #23272b; border-left-color: #4db6e8; }
html[data-theme="dark"] .activity-diff__row { color: #e5e6e7; }
html[data-theme="dark"] .activity-diff__field { color: #cbd5e1; }
</style>
