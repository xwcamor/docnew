<script setup>
/**
 * RecordHistory — contenido ESTÁNDAR del tab "Historial" de todo módulo.
 *
 *   1. "Auditoría del registro": grilla 2×2 (creado/actualizado, fecha + autor
 *      con avatar). La ve CUALQUIER usuario que pueda abrir el Show.
 *   2. "Actividad reciente": feed completo (árbol/tabla). SOLO super/admin
 *      (canSeeActivity).
 *
 * Uso en cada Show.vue, dentro del slot #history de EntityShowTabs:
 *   <RecordHistory :record-audit="recordAudit" :activity="activity"
 *                  :can-see-activity="canSeeAudit" />
 */
import { computed } from 'vue';
import { Card } from 'ant-design-vue';
import { AuditOutlined, HistoryOutlined } from '@ant-design/icons-vue';
import ActivityTimeline from './ActivityTimeline.vue';
import { useI18n } from '@/Plugins/i18n';
import { useDateFormat } from '@/Composables/useDateFormat';

const props = defineProps({
    // { created_at, created_by, updated_at, updated_by } — del backend (BuildsRecordAudit).
    recordAudit:    { type: Object, default: null },
    activity:       { type: Array,  default: () => [] },
    canSeeActivity: { type: Boolean, default: false },
    hasMore:        { type: Boolean, default: false },
    loadingMore:    { type: Boolean, default: false },
});
defineEmits(['load-more']);

const { t } = useI18n();
const { formatDateTimeFull } = useDateFormat();
const fmt = (d) => formatDateTimeFull(d);

// Sin actor humano (seed/migración o proceso del sistema) → "Sistema".
const systemActor = computed(() => t('global.system_actor'));
const createdBy = computed(() => props.recordAudit?.created_by || systemActor.value);
const updatedBy = computed(() => props.recordAudit?.updated_by || systemActor.value);
const updatedAt = computed(() => props.recordAudit?.updated_at);
const isRealActor = (name) => !!name && name !== systemActor.value;

// Avatar de iniciales (color estable por nombre); solo para personas.
const initials = (name) => (name || '').split(/\s+/).filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase();
const avaStyle = (name) => {
    let h = 0;
    for (const c of (name || '')) h = (h * 31 + c.charCodeAt(0)) % 360;
    return { background: `hsl(${h} 42% 45%)` };
};
</script>

<template>
    <Card :bodyStyle="{ padding: 18 }" class="info-card rh-card">
        <template #title><AuditOutlined /> {{ $t('global.record_audit') }}</template>
        <div class="audit-grid">
            <div class="audit-cell">
                <span class="audit-cell__label">{{ $t('global.created_at') }}</span>
                <span class="audit-cell__value">{{ fmt(recordAudit?.created_at) }}</span>
            </div>
            <div class="audit-cell">
                <span class="audit-cell__label">{{ $t('global.created_by') }}</span>
                <span class="audit-cell__value" :class="{ 'is-system': !isRealActor(createdBy) }">
                    <span v-if="isRealActor(createdBy)" class="audit-ava" :style="avaStyle(createdBy)">{{ initials(createdBy) }}</span>
                    {{ createdBy }}
                </span>
            </div>
            <div class="audit-cell">
                <span class="audit-cell__label">{{ $t('global.updated_at') }}</span>
                <span class="audit-cell__value">{{ fmt(updatedAt) }}</span>
            </div>
            <div class="audit-cell">
                <span class="audit-cell__label">{{ $t('global.updated_by') }}</span>
                <span class="audit-cell__value" :class="{ 'is-system': !isRealActor(updatedBy) }">
                    <span v-if="isRealActor(updatedBy)" class="audit-ava" :style="avaStyle(updatedBy)">{{ initials(updatedBy) }}</span>
                    {{ updatedBy }}
                </span>
            </div>
        </div>
    </Card>

    <Card v-if="canSeeActivity" :bodyStyle="{ padding: 16 }" class="info-card rh-card">
        <template #title><HistoryOutlined /> {{ $t('global.recent_activity') }}</template>
        <ActivityTimeline
            :activity="activity"
            :record-created-at="recordAudit?.created_at"
            :record-created-by="recordAudit?.created_by"
            :has-more="hasMore"
            :loading-more="loadingMore"
            @load-more="$emit('load-more')"
        />
    </Card>
</template>

<style scoped>
.rh-card { margin-bottom: 16px; border-radius: 8px; }
.audit-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.audit-cell {
    display: flex; flex-direction: column; gap: 4px;
    padding: 12px 14px; border-radius: 8px;
    background: var(--color-surface-alt, #f5f6f7);
    border: 1px solid var(--color-border, #e5e5e5);
}
.audit-cell__label {
    font-size: 0.72rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
    color: var(--color-text-muted, #6A6D70);
}
.audit-cell__value { font-size: 0.95rem; color: var(--color-text, #32363A); display: inline-flex; align-items: center; }
.audit-cell__value.is-system { color: var(--color-text-muted, #6A6D70); font-style: italic; }
.audit-ava {
    display: inline-flex; align-items: center; justify-content: center;
    width: 20px; height: 20px; border-radius: 50%; margin-right: 7px;
    color: #fff; font-size: 0.62rem; font-weight: 700; line-height: 1; flex-shrink: 0;
}
@media (max-width: 640px) { .audit-grid { grid-template-columns: 1fr; } }
</style>
