<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Form, FormItem, Input, InputNumber, Switch, Space, Alert, Tag } from 'ant-design-vue';
import { SafetyCertificateOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    approverRole:  { type: Object, default: null },
    nextSortOrder: { type: Number, default: 1 },
});

const isEdit = computed(() => !!props.approverRole);

// Los tres que trae el sistema conservan su código pase lo que pase: las
// reglas sembradas y la migración de datos los nombran así.
const codeLocked = computed(() => !!props.approverRole?.is_system);

const form = useForm({
    code:       props.approverRole?.code ?? '',
    name_es:    props.approverRole?.name_es ?? '',
    name_en:    props.approverRole?.name_en ?? '',
    sort_order: props.approverRole?.sort_order ?? props.nextSortOrder,
    is_active:  props.approverRole?.is_active ?? true,
});

// El código es una clave, no una etiqueta: se corrige mientras se escribe en
// vez de rechazar «Jefe de Obra» y dejar al usuario adivinando el formato.
const normalizeCode = (raw) => (raw ?? '')
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');

watch(() => form.code, (v) => {
    const limpio = normalizeCode(v);
    if (limpio !== v) form.code = limpio;
});

// Sugerir el código a partir del nombre en español mientras nadie lo haya
// tocado a mano: al crear, casi siempre es lo que se quería escribir.
const codeTouched = ref(isEdit.value);
watch(() => form.name_es, (v) => {
    if (!codeTouched.value) form.code = normalizeCode(v);
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('business_management.approver_roles.update', props.approverRole.slug));
    } else {
        form.post(route('business_management.approver_roles.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('approver_roles.edit_title') : $t('approver_roles.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.approver_roles.index')"
            :title="isEdit ? $t('approver_roles.edit_title') : $t('approver_roles.new')"
            :subtitle="isEdit ? approverRole.name_es : $t('approver_roles.create_subtitle')"
        >
            <template #icon><SafetyCertificateOutlined /></template>
        </SectionHeader>

        <div class="form-body">
            <Form
                layout="horizontal"
                :label-col="{ xs: 24, sm: 8, md: 6 }"
                :wrapper-col="{ xs: 24, sm: 16, md: 13 }"
                label-align="right"
                :colon="true"
                @submit.prevent="submit"
            >
                <Alert
                    v-if="form.hasErrors && Object.keys(form.errors).length > 0"
                    type="error"
                    show-icon
                    :message="$t('global.fix_marked_fields')"
                    class="mb-4"
                />

                <Alert
                    v-if="codeLocked"
                    type="info"
                    show-icon
                    :message="$t('approver_roles.system_hint')"
                    class="mb-4"
                />

                <h2 class="form-section-title">{{ $t('global.general_data') }}</h2>

                <!-- Código, nombre y orden, en ese orden y uno por línea.
                     No se pide el nombre en inglés: lo que se traduce es la
                     aplicación, no lo que escribe el cliente. Un rol se llama
                     «Supervisor Autorizante» y así se llama en obra, se mire la
                     pantalla en el idioma que se mire. -->
                <FormItem
                    :label="$t('approver_roles.code')"
                    :tooltip="$t('approver_roles.code_help')"
                    required
                    :validate-status="form.errors.code ? 'error' : ''"
                    :help="form.errors.code"
                >
                    <Input
                        v-model:value="form.code"
                        size="large"
                        :maxlength="30"
                        :disabled="codeLocked"
                        :autofocus="!codeLocked"
                        :placeholder="$t('approver_roles.code_placeholder')"
                        class="code-input"
                        @input="codeTouched = true"
                    />
                </FormItem>

                <FormItem
                    :label="$t('approver_roles.name_es')"
                    :tooltip="$t('approver_roles.name_es_help')"
                    required
                    :validate-status="form.errors.name_es ? 'error' : ''"
                    :help="form.errors.name_es"
                >
                    <Input
                        v-model:value="form.name_es"
                        size="large"
                        :maxlength="60"
                        show-count
                        :autofocus="codeLocked"
                    />
                </FormItem>

                <FormItem
                    :label="$t('approver_roles.sort_order')"
                    :tooltip="$t('approver_roles.sort_order_help')"
                    :validate-status="form.errors.sort_order ? 'error' : ''"
                    :help="form.errors.sort_order"
                >
                    <InputNumber v-model:value="form.sort_order" size="large" :min="1" :max="9999" style="width: 160px" />
                </FormItem>

                <FormItem
                    v-if="isEdit"
                    :label="$t('approver_roles.is_active')"
                    :tooltip="$t('approver_roles.is_active_help')"
                    :validate-status="form.errors.is_active ? 'error' : ''"
                    :help="form.errors.is_active"
                >
                    <Space>
                        <Switch v-model:checked="form.is_active" />
                        <span class="state-label">
                            {{ form.is_active ? $t('global.active') : $t('global.inactive') }}
                        </span>
                        <Tag v-if="isEdit && approverRole.rules_count > 0" color="blue" :bordered="false">
                            {{ $t('approver_roles.rules_count') }}: {{ approverRole.rules_count }}
                        </Tag>
                    </Space>
                </FormItem>

                <FormFooter
                    :cancel-href="route('business_management.approver_roles.index')"
                    :is-edit="isEdit"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </div>
    </div>
</template>

<style scoped>
.state-label { font-size: 0.875rem; color: var(--color-text); font-weight: 500; }
.code-input :deep(input) { font-family: ui-monospace, Consolas, monospace; }
.mb-4 { margin-bottom: 16px; }
</style>
