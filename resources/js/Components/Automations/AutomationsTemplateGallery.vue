<script setup>
/**
 * Galeria de plantillas pre-armadas que aparece al crear una automatizacion.
 * Cada plantilla rellena el form completo con valores listos para guardar
 * (o ajustar). Sin esto el usuario nuevo se queda mirando un form en blanco
 * sin saber por donde empezar.
 *
 * Las plantillas son ejemplos pedagogicos — el usuario las clona, las edita
 * con sus datos reales y guarda. No se persisten como "templates" en BD.
 */
import { computed, ref } from 'vue';
import { Card, Button, Tag, Tooltip, Modal, Space } from 'ant-design-vue';
import {
    ClockCircleOutlined, MailOutlined, BellOutlined,
    DollarOutlined, BulbOutlined, RightOutlined, ThunderboltOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

const props = defineProps({
    catalog: { type: Object, required: true },
});

const emit = defineEmits(['apply']);

const previewOpen = ref(false);
const previewTpl  = ref(null);

// Keys de data sources que el user actual tiene en su catalog. Las plantillas
// que dependen de una key no autorizada se filtran (ej. subscriptions oculto
// para admin). El catalog ya viene filtrado del backend por rol.
const availableSources = computed(() =>
    (props.catalog?.data_sources ?? []).map(s => s.key)
);

const allTemplates = computed(() => [
    {
        key: 'daily_customers_summary',
        requires: 'customers',
        icon: MailOutlined,
        color: 'blue',
        title: t('automations.tpl_daily_customers_title'),
        useCase: t('automations.tpl_daily_customers_use'),
        when:   t('automations.tpl_daily_customers_when'),
        where:  t('automations.tpl_daily_customers_where'),
        config: {
            name:           t('automations.tpl_daily_customers_title'),
            description:    t('automations.tpl_daily_customers_use'),
            is_active:      true,
            trigger_type:   'schedule',
            trigger_config: { kind: 'daily', time: '09:00' },
            data_source:    'customers',
            data_filter:    { where: [{ field: 'is_active', op: '=', value: true }], limit: 100 },
            action_type:    'email',
            action_config:  {
                to:      [],
                subject: t('automations.tpl_daily_customers_subject'),
                body:    t('automations.tpl_daily_customers_body'),
            },
        },
    },
    {
        key: 'inactive_customers_alert',
        requires: 'customers',
        icon: BellOutlined,
        color: 'orange',
        title: t('automations.tpl_inactive_title'),
        useCase: t('automations.tpl_inactive_use'),
        when:   t('automations.tpl_inactive_when'),
        where:  t('automations.tpl_inactive_where'),
        config: {
            name:           t('automations.tpl_inactive_title'),
            description:    t('automations.tpl_inactive_use'),
            is_active:      true,
            trigger_type:   'schedule',
            trigger_config: { kind: 'weekly', day: 1, time: '08:00' },
            data_source:    'customers',
            data_filter:    { where: [{ field: 'is_active', op: '=', value: false }], limit: 50 },
            action_type:    'in_app_notification',
            action_config:  {
                recipients: 'tenant_admins',
                user_ids:   [],
                title:      t('automations.tpl_inactive_subject'),
                body:       t('automations.tpl_inactive_body'),
            },
        },
    },
    {
        key: 'subscriptions_expiring_month',
        requires: 'subscriptions',
        icon: DollarOutlined,
        color: 'purple',
        title: t('automations.tpl_subs_title'),
        useCase: t('automations.tpl_subs_use'),
        when:   t('automations.tpl_subs_when'),
        where:  t('automations.tpl_subs_where'),
        config: {
            name:           t('automations.tpl_subs_title'),
            description:    t('automations.tpl_subs_use'),
            is_active:      true,
            trigger_type:   'schedule',
            trigger_config: { kind: 'monthly', day: 1, time: '08:00' },
            data_source:    'subscriptions',
            data_filter:    { where: [{ field: 'status', op: '=', value: 'active' }], limit: 100 },
            action_type:    'email',
            action_config:  {
                to:      [],
                subject: t('automations.tpl_subs_subject'),
                body:    t('automations.tpl_subs_body'),
            },
        },
    },
    {
        key: 'transformers_by_customer',
        requires: 'transformers',
        icon: ThunderboltOutlined,
        color: 'cyan',
        title: t('automations.tpl_tr_cust_title'),
        useCase: t('automations.tpl_tr_cust_use'),
        when:   t('automations.tpl_tr_cust_when'),
        where:  t('automations.tpl_tr_cust_where'),
        config: {
            name:           t('automations.tpl_tr_cust_title'),
            description:    t('automations.tpl_tr_cust_use'),
            is_active:      true,
            trigger_type:   'schedule',
            trigger_config: { kind: 'weekly', day: 1, time: '08:00' },
            data_source:    'transformers',
            data_filter:    { where: [{ field: 'customer_id', op: '=', value: '' }], limit: 100 },
            action_type:    'in_app_notification',
            action_config:  {
                recipients: 'tenant_admins',
                user_ids:   [],
                title:      t('automations.tpl_tr_cust_subject'),
                body:       t('automations.tpl_tr_cust_body'),
            },
        },
    },
    {
        key: 'transformers_by_customer_state',
        requires: 'transformers',
        icon: ThunderboltOutlined,
        color: 'green',
        title: t('automations.tpl_tr_state_title'),
        useCase: t('automations.tpl_tr_state_use'),
        when:   t('automations.tpl_tr_state_when'),
        where:  t('automations.tpl_tr_state_where'),
        config: {
            name:           t('automations.tpl_tr_state_title'),
            description:    t('automations.tpl_tr_state_use'),
            is_active:      true,
            trigger_type:   'schedule',
            trigger_config: { kind: 'weekly', day: 1, time: '08:00' },
            data_source:    'transformers',
            data_filter:    { where: [
                { field: 'customer_id',   op: '=',  value: '' },
                { field: 'health_rating', op: '<=', value: 1 },
            ], limit: 100 },
            action_type:    'in_app_notification',
            action_config:  {
                recipients: 'tenant_admins',
                user_ids:   [],
                title:      t('automations.tpl_tr_state_subject'),
                body:       t('automations.tpl_tr_state_body'),
            },
        },
    },
    {
        key: 'transformers_by_customer_state_index',
        requires: 'transformers',
        icon: ThunderboltOutlined,
        color: 'orange',
        title: t('automations.tpl_tr_idx_title'),
        useCase: t('automations.tpl_tr_idx_use'),
        when:   t('automations.tpl_tr_idx_when'),
        where:  t('automations.tpl_tr_idx_where'),
        config: {
            name:           t('automations.tpl_tr_idx_title'),
            description:    t('automations.tpl_tr_idx_use'),
            is_active:      true,
            trigger_type:   'schedule',
            trigger_config: { kind: 'weekly', day: 1, time: '08:00' },
            data_source:    'transformers',
            data_filter:    { where: [
                { field: 'customer_id',   op: '=',  value: '' },
                { field: 'health_rating', op: '<=', value: 1 },
                { field: 'health_index',  op: '<',  value: 50 },
            ], limit: 100 },
            action_type:    'in_app_notification',
            action_config:  {
                recipients: 'tenant_admins',
                user_ids:   [],
                title:      t('automations.tpl_tr_idx_subject'),
                body:       t('automations.tpl_tr_idx_body'),
            },
        },
    },
]);

const templates = computed(() =>
    allTemplates.value.filter(tpl => availableSources.value.includes(tpl.requires))
);

const openPreview = (tpl) => {
    previewTpl.value  = tpl;
    previewOpen.value = true;
};

const applyTemplate = (tpl) => {
    emit('apply', tpl.config);
    previewOpen.value = false;
};
</script>

<template>
    <Card class="tpl-gallery" :bodyStyle="{ padding: '16px 20px' }">
        <div class="tpl-gallery__header">
            <BulbOutlined class="tpl-gallery__icon" />
            <div>
                <h3>{{ $t('automations.tpl_gallery_title') }}</h3>
                <p>{{ $t('automations.tpl_gallery_hint') }}</p>
            </div>
        </div>

        <div class="tpl-gallery__grid">
            <div
                v-for="tpl in templates"
                :key="tpl.key"
                class="tpl-card"
                @click="openPreview(tpl)"
            >
                <div class="tpl-card__icon" :class="`tpl-card__icon--${tpl.color}`">
                    <component :is="tpl.icon" />
                </div>
                <div class="tpl-card__body">
                    <h4>{{ tpl.title }}</h4>
                    <p>{{ tpl.useCase }}</p>
                    <Space :size="6" wrap>
                        <Tag color="default" :bordered="false">
                            <ClockCircleOutlined /> {{ tpl.when }}
                        </Tag>
                        <Tag :color="tpl.color" :bordered="false">
                            <component :is="tpl.icon" /> {{ tpl.where }}
                        </Tag>
                    </Space>
                </div>
                <RightOutlined class="tpl-card__chevron" />
            </div>
        </div>

        <!-- Modal de preview con detalles + boton "Usar esta plantilla" -->
        <Modal
            :open="previewOpen"
            @update:open="(v) => previewOpen = v"
            :title="previewTpl?.title"
            :width="640"
            :footer="null"
        >
            <template v-if="previewTpl">
                <div class="tpl-preview">
                    <div class="tpl-preview__row">
                        <strong>{{ $t('automations.tpl_preview_use') }}:</strong>
                        <p>{{ previewTpl.useCase }}</p>
                    </div>
                    <div class="tpl-preview__row">
                        <strong>{{ $t('automations.tpl_preview_when') }}:</strong>
                        <p>{{ previewTpl.when }}</p>
                    </div>
                    <div class="tpl-preview__row">
                        <strong>{{ $t('automations.tpl_preview_where') }}:</strong>
                        <p>{{ previewTpl.where }}</p>
                    </div>
                    <div class="tpl-preview__row">
                        <strong>{{ $t('automations.tpl_preview_message') }}:</strong>
                        <div class="tpl-preview__message">
                            <p v-if="previewTpl.config.action_type === 'email'" class="tpl-preview__subject">
                                <MailOutlined /> {{ previewTpl.config.action_config.subject }}
                            </p>
                            <p v-else class="tpl-preview__subject">
                                <BellOutlined /> {{ previewTpl.config.action_config.title }}
                            </p>
                            <pre>{{ previewTpl.config.action_config.body }}</pre>
                        </div>
                    </div>
                    <div class="tpl-preview__hint">
                        {{ $t('automations.tpl_preview_after_apply_hint') }}
                    </div>
                </div>
                <div class="tpl-preview__actions">
                    <Button @click="previewOpen = false">{{ $t('global.cancel') }}</Button>
                    <Button type="primary" @click="applyTemplate(previewTpl)">
                        {{ $t('automations.tpl_apply') }}
                    </Button>
                </div>
            </template>
        </Modal>
    </Card>
</template>

<style scoped>
.tpl-gallery { margin-bottom: 20px; border-radius: 6px; }
.tpl-gallery__header {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 16px;
}
.tpl-gallery__icon {
    font-size: 24px;
    color: var(--color-primary, #0A6ED1);
    margin-top: 4px;
}
.tpl-gallery__header h3 {
    margin: 0 0 4px 0;
    font-size: 1rem;
    font-weight: 600;
}
.tpl-gallery__header p {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--color-text-muted, #6a6d70);
}

.tpl-gallery__grid {
    display: grid;
    /* `minmax(0, 1fr)` evita que el contenido fuerce columnas mas anchas que
       el contenedor (causa de palabras saliendose en pantallas chicas). */
    grid-template-columns: repeat(auto-fill, minmax(min(280px, 100%), 1fr));
    gap: 12px;
}

.tpl-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border: 1px solid var(--color-border, #e1e3e5);
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
    background: var(--color-surface, #fff);
    min-width: 0;
    overflow: hidden;
}
.tpl-card:hover {
    border-color: var(--color-primary, #0A6ED1);
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(10, 110, 209, 0.08);
}
.tpl-card__icon {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 18px;
}
.tpl-card__icon--blue   { background: #1677ff; }
.tpl-card__icon--orange { background: #fa8c16; }
.tpl-card__icon--green  { background: #52c41a; }
.tpl-card__icon--purple { background: #722ed1; }
.tpl-card__icon--cyan   { background: #13c2c2; }

.tpl-card__body {
    flex: 1;
    min-width: 0;
    overflow: hidden;
}
.tpl-card__body h4 {
    margin: 0 0 4px 0;
    font-size: 0.9375rem;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    word-break: break-word;
    /* Limita a 2 lineas y mete elipsis si el titulo es muy largo */
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    line-clamp: 2;
}
.tpl-card__body p {
    margin: 0 0 6px 0;
    font-size: 0.8125rem;
    color: var(--color-text-muted, #6a6d70);
    line-height: 1.35;
    word-break: break-word;
    overflow-wrap: break-word;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    line-clamp: 3;
    overflow: hidden;
}
/* Tags dentro de las cards: que se rompan en multiple lineas y no
   se salgan del contenedor cuando el texto es largo */
.tpl-card__body :deep(.ant-tag) {
    margin: 0;
    max-width: 100%;
    white-space: normal;
    word-break: break-word;
    line-height: 1.3;
}
.tpl-card__chevron {
    color: var(--color-text-muted, #888);
    font-size: 14px;
    flex-shrink: 0;
}

/* Preview modal */
.tpl-preview__row { margin-bottom: 14px; }
.tpl-preview__row strong {
    display: block;
    margin-bottom: 4px;
    font-size: 0.8125rem;
    color: var(--color-text-muted, #6a6d70);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.tpl-preview__row p { margin: 0; font-size: 0.9375rem; }
.tpl-preview__message {
    background: var(--color-surface-alt, #f6f7f9);
    padding: 12px 14px;
    border-radius: 6px;
    border-left: 3px solid var(--color-primary, #0A6ED1);
}
.tpl-preview__subject {
    font-weight: 600;
    margin: 0 0 8px 0;
    font-size: 0.9rem;
}
.tpl-preview__message pre {
    margin: 0;
    font-family: inherit;
    font-size: 0.875rem;
    white-space: pre-wrap;
    color: var(--color-text, #2f3438);
}
.tpl-preview__hint {
    margin-top: 16px;
    padding: 10px 12px;
    background: #fffbe6;
    border: 1px solid #ffe58f;
    border-radius: 4px;
    font-size: 0.8125rem;
    color: #614700;
}
.tpl-preview__actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border, #e1e3e5);
}

@media (max-width: 600px) {
    .tpl-gallery__grid { grid-template-columns: 1fr; }
}
</style>
