<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Card, Tag, Space, Alert, Empty } from 'ant-design-vue';
import { IdcardOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';
import { useI18n } from '@/Plugins/i18n';
import { documentTypeCharsLabel, documentTypeLengthLabel, documentTypeScopeLabel } from './config/length';

defineOptions({ layout: AppLayout });

const props = defineProps({
    documentType:       { type: Object, required: true },
    usages:      { type: Array,  default: () => [] },
    activity:    { type: Array,  default: () => [] },
    recordAudit: { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();
const { t } = useI18n();

const isDeleted = computed(() => !!props.documentType.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

// «de 7 a 8 caracteres». La ficha abre con la sigla arriba y el nombre largo
// debajo: lo que identifica arriba, lo que matiza debajo (docs/UI.md §5).
const lengthLabel = computed(() => documentTypeLengthLabel(t, props.documentType));

// «Solo números». Sin esto la ficha decía cuántos caracteres admite el número
// pero no cuáles, que es lo que impide que entre un celular en un DNI.
const charsLabel = computed(() => documentTypeCharsLabel(t, props.documentType));

// «Persona» o «Empresa». Es lo que explica en qué selector aparece esta fila, y
// por tanto la primera cosa que hay que mirar cuando alguien avisa de que el
// alta de una empresa no le ofrece ningún tipo de documento.
const scopeLabel = computed(() => documentTypeScopeLabel(t, props.documentType));

const fmt = (d) => formatDateTimeFull(d);
</script>

<template>
    <Head :title="documentType.code" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.document_types.index')"
            :title="documentType.code"
            :icon-bg="iconBg"
        >
            <template #icon><IdcardOutlined /></template>
            <template #subtitle>
                <Space :size="6" wrap>
                    <!-- El nombre largo al lado del estado: la sigla sola no
                         dice de qué documento hablamos. -->
                    <span v-if="documentType.name" class="head-name">{{ documentType.name }}</span>
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="documentType.is_active ? 'success' : 'default'" :bordered="false">
                        {{ documentType.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="document_types"
                    route-prefix="business_management"
                    :slug="documentType.slug"
                    :id="documentType.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('document_types.edit')"
                    :can-delete="can('document_types.delete')"
                    :can-see-audit="canSeeAudit"
                    :is-super="isSuper"
                    :lock="documentType.lock"
                    :is-global="documentType.tenant_id === null"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(documentType.deleted_at) }}</div>
                <div v-if="documentType.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ documentType.deleter.name }}
                </div>
                <div v-if="documentType.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ documentType.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="document_types" route-prefix="business_management" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <!-- Abre con lo que se necesita de un vistazo. El id y el slug
                     solo los ve el super: para quien usa la aplicación no
                     significan nada (docs/UI.md §4). -->
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><IdcardOutlined /> {{ $t('global.general_info') }}</template>
                    <div class="spec-grid">
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">ID</span>
                            <span class="spec-cell__value">{{ documentType.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code class="muted">{{ documentType.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('document_types.code') }}</span>
                            <span class="spec-cell__value">{{ documentType.code }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('document_types.scope') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="documentType.scope === 'company' ? 'gold' : 'blue'" :bordered="false">
                                    {{ scopeLabel }}
                                </Tag>
                            </span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('document_types.name') }}</span>
                            <span class="spec-cell__value">{{ documentType.name || '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('document_types.country') }}</span>
                            <span class="spec-cell__value">{{ documentType.country ?? '—' }}</span>
                        </div>
                        <!-- La longitud del número, en una frase y no en dos
                             celdas con un número cada una. Es ayuda al dar de
                             alta a una persona, no condición de búsqueda: el
                             buscador de trabajadores va por coincidencia exacta
                             del número. -->
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('document_types.length') }}</span>
                            <span class="spec-cell__value">{{ lengthLabel }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('document_types.allowed_chars') }}</span>
                            <span class="spec-cell__value">{{ charsLabel }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('document_types.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="documentType.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ documentType.is_active ? $t('global.active') : $t('global.inactive') }}
                                </Tag>
                            </span>
                        </div>
                    </div>
                </Card>

                <!-- Lo que depende de esta fila: la respuesta a «¿por qué no me
                     deja borrarla?», puesta antes de que se pregunte. -->
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title>{{ $t('document_types.usage_list_title') }}</template>
                    <Empty v-if="usages.length === 0" :description="$t('document_types.usage_list_none')" />
                    <ul v-else class="uses-list">
                        <li v-for="u in usages" :key="u.slug" class="uses-list__item">
                            <span class="uses-list__label">{{ u.label }}</span>
                            <Tag :bordered="false" :color="u.is_active ? 'success' : 'default'">
                                {{ u.is_active ? $t('global.active') : $t('global.inactive') }}
                            </Tag>
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
.muted { color: var(--color-text-muted); font-size: 0.8125rem; }
.head-name { color: var(--color-text-muted); font-size: 0.875rem; }
.deleted-alert { margin-bottom: 16px; }
.info-card { margin-bottom: 16px; border-radius: 8px; }

.uses-list { list-style: none; margin: 0; padding: 0; }
.uses-list__item {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    /* 48px: la fila entera es un objetivo de toque cómodo en tablet. */
    min-height: 48px; padding: 10px 4px;
    border-bottom: 1px solid var(--color-border-subtle, #f2f3f5);
}
.uses-list__item:last-child { border-bottom: none; }
.uses-list__label { font-weight: 500; }

@media (max-width: 767px) {
    :deep(.ant-descriptions-item-content) { word-break: break-word; }
}
</style>
