<script setup>
/**
 * Modal de preview del mensaje renderizado de una automatizacion.
 * Sustituye {count}, {list}, {date}, {automation} por valores ejemplo
 * para que el user vea cómo va a quedar antes de guardar.
 *
 * Acepta el form completo y decide qué renderizar segun action_type.
 */
import { computed } from 'vue';
import { Modal, Tag, Button } from 'ant-design-vue';
import { MailOutlined, BellOutlined, CloseOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

const props = defineProps({
    open:    { type: Boolean, required: true },
    form:    { type: Object,  required: true },
    catalog: { type: Object,  default: () => ({}) },
});

// Datos del remitente que viene del backend (lee config('mail.from.*')).
// El user no tiene que saber nada de .env — solo ve "de quién" sale el email.
const mailFromAddress = computed(() => props.catalog?.mail_from?.address ?? null);
const mailFromName    = computed(() => props.catalog?.mail_from?.name    ?? null);

const emit = defineEmits(['update:open']);

const sampleVars = computed(() => ({
    count:      4,
    list:       '- Acme S.A.\n- Globex Corp\n- Iniciativa Norte\n- TecnoMax',
    date:       new Date().toISOString().slice(0, 10),
    automation: props.form.name || t('automations.preview_placeholder_name'),
}));

const interpolate = (text) => {
    if (!text) return '';
    return String(text).replace(/\{(\w+)\}/g, (m, k) => sampleVars.value[k] ?? m);
};

const isEmail = computed(() => props.form.action_type === 'email');

const renderedSubject = computed(() => interpolate(props.form.action_config?.subject ?? ''));
const renderedTitle   = computed(() => interpolate(props.form.action_config?.title ?? ''));
const renderedBody    = computed(() => interpolate(props.form.action_config?.body  ?? ''));

const recipients = computed(() => {
    if (isEmail.value) {
        const to = props.form.action_config?.to ?? [];
        return to.length
            ? to.join(', ')
            : t('automations.preview_no_recipients_email');
    }
    const mode = props.form.action_config?.recipients;
    if (mode === 'tenant_admins') return t('automations.action_in_app_recipients_admins');
    const ids = props.form.action_config?.user_ids ?? [];
    return ids.length
        ? t('automations.preview_specific_users_count', { n: ids.length })
        : t('automations.preview_no_recipients_users');
});

const whenLabel = computed(() => {
    const c = props.form.trigger_config ?? {};
    const time = c.time ?? '—';
    const dayNames = {
        0: t('global.days.sun'), 1: t('global.days.mon'), 2: t('global.days.tue'),
        3: t('global.days.wed'), 4: t('global.days.thu'), 5: t('global.days.fri'),
        6: t('global.days.sat'),
    };
    if (c.kind === 'daily')   return t('automations.preview_when_daily',   { time });
    if (c.kind === 'weekly')  return t('automations.preview_when_weekly',  { day: dayNames[c.day] ?? '—', time });
    if (c.kind === 'monthly') return t('automations.preview_when_monthly', { day: c.day ?? 1, time });
    if (c.kind === 'cron')    return t('automations.preview_when_cron',    { expr: c.expression ?? '—' });
    return '—';
});
</script>

<template>
    <Modal
        :open="open"
        @update:open="(v) => emit('update:open', v)"
        :title="$t('automations.preview_title')"
        :width="700"
        :footer="null"
    >
        <div class="prv">
            <p class="prv__hint">{{ $t('automations.preview_hint') }}</p>

            <div class="prv__meta">
                <div class="prv__meta-row">
                    <strong>{{ $t('automations.preview_when') }}:</strong>
                    <span>{{ whenLabel }}</span>
                </div>
                <div class="prv__meta-row">
                    <strong>{{ $t('automations.preview_channel') }}:</strong>
                    <Tag :color="isEmail ? 'blue' : 'orange'" :bordered="false">
                        <component :is="isEmail ? MailOutlined : BellOutlined" />
                        {{ isEmail ? $t('automations.action_email') : $t('automations.action_in_app') }}
                    </Tag>
                </div>
                <div class="prv__meta-row">
                    <strong>{{ $t('automations.preview_recipients') }}:</strong>
                    <span class="prv__recipients">{{ recipients }}</span>
                </div>
            </div>

            <!-- Email-like envelope -->
            <div v-if="isEmail" class="prv__envelope">
                <div class="prv__envelope-from">
                    <span class="prv__label">{{ $t('automations.preview_from') }}:</span>
                    <span v-if="mailFromAddress">
                        <template v-if="mailFromName">{{ mailFromName }} &lt;{{ mailFromAddress }}&gt;</template>
                        <template v-else>{{ mailFromAddress }}</template>
                    </span>
                    <span v-else class="prv__from-missing">
                        {{ $t('automations.preview_from_not_configured') }}
                    </span>
                </div>
                <div class="prv__envelope-subject">
                    <span class="prv__label">{{ $t('automations.preview_subject') }}:</span>
                    <strong>{{ renderedSubject || $t('automations.preview_empty_subject') }}</strong>
                </div>
                <pre class="prv__body">{{ renderedBody || $t('automations.preview_empty_body') }}</pre>
            </div>

            <!-- In-app notification card (mimics bell dropdown) -->
            <div v-else class="prv__bell">
                <div class="prv__bell-icon">
                    <BellOutlined />
                </div>
                <div class="prv__bell-content">
                    <strong>{{ renderedTitle || $t('automations.preview_empty_subject') }}</strong>
                    <pre class="prv__body prv__body--bell">{{ renderedBody || $t('automations.preview_empty_body') }}</pre>
                    <span class="prv__bell-time">{{ $t('automations.preview_when_now') }}</span>
                </div>
            </div>

            <div class="prv__legend">
                <strong>{{ $t('automations.preview_legend_title') }}:</strong>
                <ul>
                    <li><code>{{ '{count}' }}</code> → <em>4</em></li>
                    <li><code>{{ '{list}' }}</code> → <em>Acme S.A., Globex Corp, Iniciativa Norte, TecnoMax</em></li>
                    <li><code>{{ '{date}' }}</code> → <em>{{ sampleVars.date }}</em></li>
                    <li><code>{{ '{automation}' }}</code> → <em>{{ sampleVars.automation }}</em></li>
                </ul>
                <p class="prv__legend-note">{{ $t('automations.preview_legend_note') }}</p>
            </div>
        </div>

        <div class="prv__actions">
            <Button @click="emit('update:open', false)">
                <CloseOutlined /> {{ $t('global.close') }}
            </Button>
        </div>
    </Modal>
</template>

<style scoped>
.prv__hint {
    font-size: 0.8125rem;
    color: var(--color-text-muted, #6a6d70);
    margin: 0 0 14px 0;
}
.prv__meta {
    background: var(--color-surface-alt, #f6f7f9);
    border-radius: 6px;
    padding: 12px 14px;
    margin-bottom: 16px;
}
.prv__meta-row {
    display: flex;
    align-items: baseline;
    gap: 10px;
    font-size: 0.875rem;
    line-height: 1.7;
    flex-wrap: wrap;
}
.prv__meta-row strong {
    min-width: 110px;
    color: var(--color-text-muted, #6a6d70);
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.7rem;
    letter-spacing: 0.04em;
}
.prv__recipients { word-break: break-all; flex: 1; min-width: 0; }

/* Email envelope */
.prv__envelope {
    border: 1px solid var(--color-border, #e1e3e5);
    border-radius: 8px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.prv__envelope-from,
.prv__envelope-subject {
    padding: 10px 14px;
    border-bottom: 1px solid var(--color-border, #e1e3e5);
    display: flex;
    gap: 10px;
    font-size: 0.875rem;
    flex-wrap: wrap;
}
.prv__label {
    color: var(--color-text-muted, #6a6d70);
    font-weight: 500;
    min-width: 60px;
}
.prv__from-missing {
    color: #d4380d;
    font-style: italic;
    font-size: 0.8125rem;
}
.prv__body {
    margin: 0;
    padding: 16px;
    background: #fff;
    font-family: inherit;
    font-size: 0.9rem;
    white-space: pre-wrap;
    word-break: break-word;
    line-height: 1.55;
    color: var(--color-text, #2f3438);
    min-height: 80px;
}

/* In-app bell card */
.prv__bell {
    display: flex;
    gap: 12px;
    padding: 12px 14px;
    border: 1px solid var(--color-border, #e1e3e5);
    border-left: 4px solid #fa8c16;
    border-radius: 6px;
    background: #fffbf5;
}
.prv__bell-icon {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #fa8c16;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
}
.prv__bell-content { flex: 1; min-width: 0; }
.prv__bell-content strong { display: block; margin-bottom: 4px; font-size: 0.9rem; }
.prv__body--bell {
    background: transparent;
    padding: 0;
    min-height: 0;
    font-size: 0.8125rem;
}
.prv__bell-time {
    display: block;
    margin-top: 6px;
    font-size: 0.75rem;
    color: var(--color-text-muted, #888);
}

/* Legend */
.prv__legend {
    margin-top: 18px;
    padding: 12px 14px;
    background: #f0f5ff;
    border: 1px solid #adc6ff;
    border-radius: 6px;
    font-size: 0.8125rem;
}
.prv__legend strong { display: block; margin-bottom: 6px; }
.prv__legend ul { margin: 0; padding-left: 18px; }
.prv__legend li { line-height: 1.7; }
.prv__legend code {
    font-family: ui-monospace, 'SF Mono', Consolas, monospace;
    background: #fff;
    padding: 1px 6px;
    border-radius: 3px;
    border: 1px solid #d6e4ff;
}
.prv__legend em {
    color: var(--color-text-muted, #555);
    font-style: normal;
}
.prv__legend-note {
    margin: 8px 0 0 0;
    color: var(--color-text-muted, #6a6d70);
    font-style: italic;
}

.prv__actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid var(--color-border, #e1e3e5);
}
</style>
