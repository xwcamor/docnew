<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    Card, Tag, Space, Alert, Button, Popconfirm, Tooltip,
} from 'ant-design-vue';
import { router } from '@inertiajs/vue3';
import { TagsOutlined } from '@ant-design/icons-vue';

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
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const publicando = ref(false);
const esBorrador = computed(() => props.formTemplate.status !== 'published');

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
            <template #icon><TagsOutlined /></template>
            <template #subtitle>
                <Space :size="6">
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="formTemplate.is_active ? 'success' : 'default'" :bordered="false">
                        {{ formTemplate.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                    <Tag :color="esBorrador ? 'default' : 'success'" :bordered="false">
                        {{ $t(esBorrador ? 'form_templates.status_draft' : 'form_templates.status_published') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <!-- Publicar es lo que hace usable el formato: la ficha del plan
                     sólo ofrece los publicados. Sin este botón, uno creado desde
                     la pantalla se quedaba en borrador para siempre y ningún
                     plan podía usarlo. -->
                <Tooltip v-if="can('form_templates.edit') && !isDeleted" :title="$t(esBorrador ? 'form_templates.publish_hint' : 'form_templates.unpublish_hint')">
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

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><TagsOutlined /> {{ $t('global.general_info') }}</template>
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
