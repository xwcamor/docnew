<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Alert, Button, Popconfirm, Tooltip,
} from 'ant-design-vue';
import { Link, router } from '@inertiajs/vue3';
import { FileOutlined, UnorderedListOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const props = defineProps({
    formTemplate: { type: Object, required: true },
    activity:   { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
    versions:   { type: Array,  default: () => [] },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const publicando = ref(false);
const esBorrador = computed(() => props.formTemplate.status !== 'published');

// Un documento «con campos» y sin ninguno no se puede publicar: el constructor
// lo rechaza. El botón sale deshabilitado y dice por qué, en vez de fallar al
// pulsarlo. La salida está a un clic: «Secciones y campos», aquí al lado.
const noSePuedePublicar = computed(() =>
    esBorrador.value
    && props.formTemplate.kind !== 'upload_only'
    && (props.formTemplate.fields_count ?? 0) === 0,
);

const textoKind = computed(() => `form_templates.kind_${props.formTemplate.kind ?? 'structured'}`);

// `archived` existe en la tabla y no lo pinta nadie: sin esto un documento
// archivado decía «Borrador», que es otra cosa.
const textoEstado = computed(() => `form_templates.status_${props.formTemplate.status ?? 'draft'}`);
const colorEstado = computed(() => ({
    published: 'success',
    draft:     'orange',
    archived:  'default',
}[props.formTemplate.status] ?? 'orange'));

const alternarPublicacion = () => {
    publicando.value = true;
    const accion = esBorrador.value ? 'publish' : 'unpublish';
    router.post(route(`business_management.form_templates.${accion}`, props.formTemplate.slug), {}, {
        preserveScroll: true,
        onFinish: () => { publicando.value = false; },
    });
};

const isDeleted = computed(() => !!props.formTemplate.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// La pestaña «Versiones» sólo cuando hay más de una: con una sola no hay nada
// que comparar y sería una pestaña que repite la ficha.
const hayVersiones = computed(() => props.versions.length > 1);

// El estado de cada versión, con los mismos colores que la cabecera.
const colorDeEstado = (estado) => ({
    published: 'success',
    draft:     'orange',
    archived:  'default',
}[estado] ?? 'orange');

// Wrapper local para mantener call-sites compactos (fmt(...) en templates).
const fmt = (d) => formatDateTimeFull(d);
</script>

<template>
    <Head :title="formTemplate.name" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.form_templates.index')"
            :title="formTemplate.name"
            :icon-bg="iconBg"
        >
            <template #icon><FileOutlined /></template>
            <template #subtitle>
                <Space :size="6" wrap>
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="formTemplate.is_active ? 'success' : 'default'" :bordered="false">
                        {{ formTemplate.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                    <!-- Borrador en naranja, publicado en verde: color Y palabra. -->
                    <Tag :color="colorEstado" :bordered="false">{{ $t(textoEstado) }}</Tag>
                    <Tag color="blue" :bordered="false">{{ $t(textoKind) }}</Tag>
                    <span v-if="formTemplate.code" class="muted">{{ formTemplate.code }}</span>
                </Space>
            </template>
            <template #actions>
                <!-- Publicar es lo que hace usable el formato: la ficha del plan
                     sólo ofrece los publicados. Sin este botón, uno creado desde
                     la pantalla se quedaba en borrador para siempre y ningún
                     plan podía usarlo. -->
                <template v-if="can('form_templates.edit') && !isDeleted">
                    <!-- Definir qué se pregunta en obra. Es lo que hace útil al
                         documento y no había forma de llegar: el motor estaba
                         entero y sin pantalla, así que un documento creado aquí
                         nacía sin campos y no se podía publicar nunca. -->
                    <Tooltip :title="$t('form_templates.structure_open_hint')">
                        <Link :href="route('business_management.form_templates.structure', formTemplate.slug)">
                            <Button>
                                <UnorderedListOutlined />
                                {{ $t('form_templates.structure') }}
                            </Button>
                        </Link>
                    </Tooltip>

                    <!-- Sin campos definidos no se puede publicar: deshabilitado
                         y con el motivo, que un botón que falla al pulsarlo es
                         peor que un botón que no está. -->
                    <Tooltip v-if="noSePuedePublicar" :title="$t('form_templates.publish_blocked_no_fields')">
                        <Button disabled>{{ $t('form_templates.publish') }}</Button>
                    </Tooltip>
                    <Tooltip v-else :title="$t(esBorrador ? 'form_templates.publish_hint' : 'form_templates.unpublish_hint')">
                        <Popconfirm
                            :title="$t(esBorrador ? 'form_templates.publish_confirm' : 'form_templates.unpublish_confirm')"
                            :ok-text="$t(esBorrador ? 'form_templates.publish' : 'form_templates.unpublish')"
                            :cancel-text="$t('global.cancel')"
                            placement="bottomRight"
                            @confirm="alternarPublicacion"
                        >
                            <Button :type="esBorrador ? 'primary' : 'default'" :loading="publicando">
                                {{ $t(esBorrador ? 'form_templates.publish' : 'form_templates.unpublish') }}
                            </Button>
                        </Popconfirm>
                    </Tooltip>
                </template>

                <EntityShowActions
                    module="form_templates"
                    route-prefix="business_management"
                    :slug="formTemplate.slug"
                    :id="formTemplate.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('form_templates.edit')"
                    :can-delete="can('form_templates.delete')"
                    :can-see-audit="canSeeAudit"
                    :is-super="isSuper"
                    :is-global="formTemplate.tenant_id === null"
                    :lock="formTemplate.lock"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(formTemplate.deleted_at) }}</div>
                <div v-if="formTemplate.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ formTemplate.deleter.name }}
                </div>
                <div v-if="formTemplate.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ formTemplate.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="form_templates" route-prefix="business_management" />
            </template>
        </Alert>

        <!-- En una tablet con guantes nadie descubre un tooltip: el motivo por el
             que no se puede publicar se dice en la pantalla, no al pasar por
             encima del botón. -->
        <Alert
            v-if="noSePuedePublicar && !isDeleted"
            type="warning"
            show-icon
            class="deleted-alert"
            :message="$t('form_templates.publish_blocked_no_fields')"
        />
        <Alert
            v-else-if="esBorrador && !isDeleted"
            type="info"
            show-icon
            class="deleted-alert"
            :message="$t('form_templates.publish_hint')"
        />

        <EntityShowTabs
            :show-history="canSeeAudit"
            :history-count="activity.length"
            :show-versions="hayVersiones"
            :versions-count="versions.length"
        >
            <template #general>
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><FileOutlined /> {{ $t('global.general_info') }}</template>
                    <div class="spec-grid">
                        <!-- ID y slug: solo el super (datos técnicos), y van primero. -->
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">ID</span>
                            <span class="spec-cell__value">{{ formTemplate.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code class="muted">{{ formTemplate.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('form_templates.name') }}</span>
                            <span class="spec-cell__value">{{ formTemplate.name }}</span>
                        </div>
                        <div v-if="formTemplate.code" class="spec-cell">
                            <span class="spec-cell__label">{{ $t('form_templates.code') }}</span>
                            <span class="spec-cell__value"><code>{{ formTemplate.code }}</code></span>
                        </div>
                        <!-- El país decide qué catálogos ofrece y qué planes lo
                             pueden usar: se pedía al crear y luego no se veía. -->
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('form_templates.country') }}</span>
                            <span class="spec-cell__value">{{ formTemplate.country_name ?? '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('form_templates.kind') }}</span>
                            <span class="spec-cell__value">{{ $t(textoKind) }}</span>
                        </div>
                        <!-- La versión salía siempre vacía: la ficha leía
                             `sort_order`, columna que no existe en esta tabla. -->
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('form_templates.version') }}</span>
                            <span class="spec-cell__value">{{ formTemplate.version }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('form_templates.status') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="colorEstado" :bordered="false">{{ $t(textoEstado) }}</Tag>
                            </span>
                        </div>
                        <div v-if="formTemplate.published_at" class="spec-cell">
                            <span class="spec-cell__label">{{ $t('form_templates.published_at') }}</span>
                            <span class="spec-cell__value">{{ fmt(formTemplate.published_at) }}</span>
                        </div>
                        <!-- Estado: siempre al final. -->
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('form_templates.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="formTemplate.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ formTemplate.is_active ? $t('global.active') : $t('global.inactive') }}
                                </Tag>
                            </span>
                        </div>
                    </div>
                </Card>
            </template>

            <!-- Todas las versiones del mismo documento, cada una con su
                 camino. Publicar archiva la anterior pero no la esconde: es
                 la evidencia de lo que se firmó con ella, y desde aquí se
                 abre para ver qué se cambió. -->
            <template #versions>
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><FileOutlined /> {{ $t('form_templates.versions_title') }}</template>

                    <p class="versions-hint">{{ $t('form_templates.versions_hint') }}</p>

                    <ul class="versions-list">
                        <li v-for="v in versions" :key="v.slug" class="versions-row" :class="{ 'is-current': v.current }">
                            <span class="versions-row__num">v{{ v.version }}</span>

                            <Tag :color="colorDeEstado(v.status)" :bordered="false">
                                {{ $t(`form_templates.status_${v.status ?? 'draft'}`) }}
                            </Tag>

                            <span class="versions-row__meta">
                                {{ $tc('form_templates.versions_fields', v.fields_count ?? 0) }}
                            </span>

                            <!-- La fecha que identifica la versión: publicada si
                                 lo está, y si no la última vez que se tocó. -->
                            <span class="versions-row__meta">
                                {{ v.published_at ? fmt(v.published_at) : fmt(v.updated_at) }}
                            </span>

                            <span v-if="v.current" class="versions-row__current">{{ $t('form_templates.version_current') }}</span>
                            <Link v-else :href="route('business_management.form_templates.show', v.slug)">
                                {{ $t('form_templates.version_open') }}
                            </Link>
                        </li>
                    </ul>
                </Card>
            </template>

            <template #history>
                <RecordHistory :record-audit="recordAudit" :activity="activity" :can-see-activity="canSeeAudit" />
            </template>
        </EntityShowTabs>
    </div>
</template>

<style scoped>
.show-page { /* fullscreen — sin max-width, ocupa todo el ancho del content */ }
.muted { color: var(--color-text-muted); font-size: 0.8125rem; }
.deleted-alert { margin-bottom: 16px; }
.info-card { margin-bottom: 16px; border-radius: 8px; }

/* La lista de versiones: una fila por versión, la actual marcada. */
.versions-hint { margin: 0 0 12px; color: var(--color-text-muted); }
.versions-list { list-style: none; margin: 0; padding: 0; }
.versions-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 10px 4px;
    border-top: 1px solid var(--color-border);
}
.versions-row.is-current { background: var(--color-surface-alt, rgba(0, 0, 0, 0.02)); }
.versions-row__num { font-weight: 600; min-width: 2.5rem; }
.versions-row__meta { color: var(--color-text-muted); font-size: 0.8125rem; }
.versions-row__current { color: var(--color-text-muted); font-size: 0.8125rem; font-style: italic; }

@media (max-width: 767px) {
    :deep(.ant-descriptions-item-label) {
        width: auto !important;
        min-width: 0 !important;
        white-space: normal !important;
        font-weight: 500;
    }
    :deep(.ant-descriptions-item-content) { word-break: break-word; }
}
</style>
