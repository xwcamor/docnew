<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Card, Tag, Space, Alert, Button } from 'ant-design-vue';
import { ToolOutlined, WarningOutlined, SaveOutlined, UndoOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EntityShowTabs from '@/Components/Common/EntityShowTabs.vue';
import EntityShowActions from '@/Components/Common/EntityShowActions.vue';
import ViewDeletedButton from '@/Components/Common/ViewDeletedButton.vue';
import RecordHistory from '@/Components/Common/RecordHistory.vue';
import WorkTypeFormatsPicker from '@/Components/WorkTypes/WorkTypeFormatsPicker.vue';
import { useAuth } from '@/Composables/useAuth';
import { useDateFormat } from '@/Composables/useDateFormat';

defineOptions({ layout: AppLayout });

const props = defineProps({
    workType:      { type: Object, required: true },
    // Todos los formatos del país, con su marca. La ficha los edita aquí mismo:
    // mover un interruptor no debería obligar a abrir el formulario entero.
    formTemplates: { type: Array,  default: () => [] },
    // Planes en obra a los que alcanza un cambio en los formatos.
    openPlans:     { type: Number, default: 0 },
    canEditForms:  { type: Boolean, default: false },
    activity:      { type: Array,  default: () => [] },
    recordAudit:   { type: Object, default: null },
});

const { can, isSuper, canSeeAudit } = useAuth();
const { formatDateTimeFull } = useDateFormat();

const isDeleted = computed(() => !!props.workType.deleted_at);
const iconBg = computed(() => isDeleted.value ? 'var(--color-danger)' : 'var(--color-primary)');

const fmt = (d) => formatDateTimeFull(d);

// ── La matriz, editable en la propia ficha ──────────────────────────────────

const seleccionInicial = () => props.formTemplates
    .filter((f) => f.is_selected)
    .map((f) => ({ id: f.id, is_required: f.is_required }));

const form = useForm({ form_templates: seleccionInicial() });

// Referencia contra la que se compara «hay algo sin guardar». Se recalcula al
// llegar la respuesta del servidor: después de guardar, lo guardado es lo nuevo
// original y la barra tiene que desaparecer sola.
const original = ref(JSON.stringify(seleccionInicial()));
watch(() => props.formTemplates, () => {
    form.form_templates = seleccionInicial();
    original.value = JSON.stringify(seleccionInicial());
});

const hayCambios = computed(() => JSON.stringify(form.form_templates) !== original.value);

const exigidos = computed(() => props.formTemplates.filter((f) => f.is_selected));
const obligatorios = computed(() => exigidos.value.filter((f) => f.is_required));

const descartar = () => { form.form_templates = JSON.parse(original.value); };

const guardar = () => {
    form.put(route('business_management.work_types.form_templates.update', props.workType.slug), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="workType.code" />

    <div class="show-page sap-show">
        <SectionHeader
            :back-href="route('business_management.work_types.index')"
            :title="workType.code"
            :icon-bg="iconBg"
        >
            <template #icon><ToolOutlined /></template>
            <template #subtitle>
                <Space :size="6" wrap>
                    <span>{{ workType.country ?? '—' }}</span>
                    <Tag :color="exigidos.length > 0 ? 'geekblue' : 'red'" :bordered="false">
                        {{ $t('work_types.forms_count') }}: {{ exigidos.length }}
                    </Tag>
                    <Tag v-if="obligatorios.length > 0" color="red" :bordered="false">
                        {{ $t('work_types.required') }}: {{ obligatorios.length }}
                    </Tag>
                    <Tag v-if="isDeleted" color="red" :bordered="false">{{ $t('global.deleted') }}</Tag>
                    <Tag v-else :color="workType.is_active ? 'success' : 'default'" :bordered="false">
                        {{ workType.is_active ? $t('global.active') : $t('global.inactive') }}
                    </Tag>
                </Space>
            </template>
            <template #actions>
                <EntityShowActions
                    module="work_types"
                    route-prefix="business_management"
                    :slug="workType.slug"
                    :id="workType.id"
                    :is-deleted="isDeleted"
                    :can-edit="can('work_types.edit')"
                    :can-delete="can('work_types.delete')"
                    :can-see-audit="canSeeAudit"
                    :is-super="isSuper"
                    :lock="workType.lock"
                    :is-global="workType.tenant_id === null"
                />
            </template>
        </SectionHeader>

        <Alert v-if="isDeleted" type="error" show-icon class="deleted-alert">
            <template #message>{{ $t('global.record_is_deleted') }}</template>
            <template #description>
                <div><strong>{{ $t('global.deleted_at') }}:</strong> {{ fmt(workType.deleted_at) }}</div>
                <div v-if="workType.deleter">
                    <strong>{{ $t('global.deleted_by') }}:</strong> {{ workType.deleter.name }}
                </div>
                <div v-if="workType.deleted_description">
                    <strong>{{ $t('global.delete_description') }}:</strong> {{ workType.deleted_description }}
                </div>
            </template>
            <template v-if="isSuper" #action>
                <ViewDeletedButton module="work_types" route-prefix="business_management" />
            </template>
        </Alert>

        <EntityShowTabs :show-history="canSeeAudit" :history-count="activity.length">
            <template #general>
                <!-- Los formatos van PRIMERO: es a lo que se viene a esta ficha.
                     Lo técnico (id, slug, fechas) vive en la vista larga. -->
                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title>{{ $t('work_types.forms_title') }}</template>

                    <Alert v-if="openPlans > 0" type="info" show-icon class="mb-3">
                        <template #message>{{ $t('work_types.open_plans') }}: {{ openPlans }}</template>
                        <template #description>{{ $tc('work_types.open_plans_warning', openPlans) }}</template>
                    </Alert>
                    <Alert v-else type="info" show-icon class="mb-3" :message="$t('work_types.open_plans_none')" />

                    <WorkTypeFormatsPicker
                        v-model="form.form_templates"
                        :templates="formTemplates"
                        :disabled="!canEditForms || isDeleted"
                    />

                    <p v-if="form.errors.form_templates" class="field-error">{{ form.errors.form_templates }}</p>

                    <!-- La barra sólo aparece cuando hay algo que guardar: una
                         barra siempre visible se vuelve parte del decorado. -->
                    <div v-if="canEditForms && !isDeleted && hayCambios" class="forms-actions">
                        <Alert
                            v-if="openPlans > 0"
                            type="warning"
                            show-icon
                            class="forms-actions__warn"
                        >
                            <template #icon><WarningOutlined /></template>
                            <template #message>{{ $tc('work_types.open_plans_warning', openPlans) }}</template>
                        </Alert>
                        <Space wrap>
                            <Button size="large" :disabled="form.processing" @click="descartar">
                                <UndoOutlined /> {{ $t('work_types.forms_discard') }}
                            </Button>
                            <Button type="primary" size="large" :loading="form.processing" @click="guardar">
                                <SaveOutlined /> {{ $t('work_types.forms_save') }}
                            </Button>
                        </Space>
                    </div>
                </Card>

                <Card :bodyStyle="{ padding: 18 }" class="info-card">
                    <template #title><ToolOutlined /> {{ $t('global.general_info') }}</template>
                    <div class="spec-grid">
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">ID</span>
                            <span class="spec-cell__value">{{ workType.id }}</span>
                        </div>
                        <div v-if="isSuper" class="spec-cell">
                            <span class="spec-cell__label">Slug</span>
                            <span class="spec-cell__value"><code class="muted">{{ workType.slug }}</code></span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_types.code') }}</span>
                            <span class="spec-cell__value">{{ workType.code }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_types.country') }}</span>
                            <span class="spec-cell__value">{{ workType.country ?? '—' }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_types.open_plans') }}</span>
                            <span class="spec-cell__value">{{ openPlans }}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell__label">{{ $t('work_types.is_active') }}</span>
                            <span class="spec-cell__value">
                                <Tag :color="workType.is_active ? 'success' : 'default'" :bordered="false">
                                    {{ workType.is_active ? $t('global.active') : $t('global.inactive') }}
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
.muted { color: var(--color-text-muted); font-size: 0.8125rem; }
.deleted-alert { margin-bottom: 16px; }
.info-card { margin-bottom: 16px; border-radius: 8px; }
.mb-3 { margin-bottom: 12px; }
.field-error { color: var(--color-danger); font-size: 0.8rem; margin: 8px 0 0 0; }

.forms-actions {
    display: flex; align-items: center; justify-content: flex-end;
    gap: 12px; flex-wrap: wrap;
    margin-top: 16px; padding-top: 14px;
    border-top: 1px solid var(--color-border-subtle, #f2f3f5);
}
.forms-actions__warn { flex: 1 1 320px; }
/* 44px de objetivo de toque: se pulsa en tablet, con guantes. */
.forms-actions :deep(.ant-btn) { min-height: 44px; }

@media (max-width: 767px) {
    .forms-actions { flex-direction: column; align-items: stretch; }
    .forms-actions :deep(.ant-space) { width: 100%; justify-content: flex-end; }
}
</style>
