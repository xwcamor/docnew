<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, Switch, Space, Alert, Row, Col, Tag, Divider,
} from 'ant-design-vue';
import { GlobalOutlined, KeyOutlined, SafetyCertificateOutlined, BlockOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    system_module: { type: Object, default: null },  // null = create, object = edit
});

const isEdit = computed(() => !!props.system_module);

const form = useForm({
    name:      props.system_module?.name ?? '',
    is_active: props.system_module?.is_active ?? true,
});

// Auto-derivar permission_key del nombre (mirror del backend setNameAttribute).
// "Mi Modulo Genial" → "mi_modulos_geniales"... no exactamente, la lógica del
// backend es: PascalCase singular → snake_case plural. Lo aproximamos para preview:
const previewPermissionKey = computed(() => {
    if (isEdit.value) return props.system_module.permission_key ?? '';
    const n = (form.name ?? '').trim();
    if (!n) return '';
    // Aproximación visual — la versión real la calcula el backend.
    const snake = n.replace(/([a-z0-9])([A-Z])/g, '$1_$2').replace(/[\s-]+/g, '_').toLowerCase();
    // pluralización naive (s al final si no termina en s)
    return snake.endsWith('s') ? snake : snake + 's';
});

const ACTIONS = ['view', 'show', 'create', 'edit', 'delete', 'export'];

const previewPermissions = computed(() => {
    const key = previewPermissionKey.value;
    return key ? ACTIONS.map(a => `${key}.${a}`) : [];
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('system_management.system_modules.update', props.system_module.slug));
    } else {
        form.post(route('system_management.system_modules.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('system_modules.singular') : $t('system_modules.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('system_management.system_modules.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('system_modules.record') : $t('system_modules.new')"
            :subtitle="isEdit ? system_module.name : $t('system_modules.form_create_hint')"
        >
            <template #icon><BlockOutlined /></template>
        </SectionHeader>

        <!-- Form card -->
        <Card class="form-card" :bodyStyle="{ padding: '24px 28px' }">
            <Form layout="horizontal" :label-col="{ xs: 24, sm: 8, md: 6 }" :wrapper-col="{ xs: 24, sm: 16, md: 13 }" label-align="right" :colon="true" @submit.prevent="submit">

                <!-- General error banner -->
                <Alert
                    v-if="form.hasErrors && Object.keys(form.errors).length > 0"
                    type="error"
                    show-icon
                    :message="$t('global.fix_marked_fields')"
                    class="mb-4"
                />

                <!-- Layout grid: col-12 (24 en Ant) en mobile, distribuye en
                     desktop. Patrón a clonar para módulos con más campos: cada
                     FormItem en su <Col> con span responsive (xs=24 stack,
                     md=12 dos columnas, lg=8 tres columnas, etc.). -->
                <h2 class="form-section-title">{{ $t('global.general_data') }}</h2>

                <Row :gutter="[20, 0]">
                    <Col :xs="24" :md="isEdit ? 16 : 24">
                        <FormItem
                            :label="$t('system_modules.name')"
                            :tooltip="$t('system_modules.name_help')"
                            required
                            :validate-status="form.errors.name ? 'error' : ''"
                            :help="form.errors.name"
                        >
                            <Input
                                v-model:value="form.name"
                                :placeholder="$t('system_modules.name_placeholder')"
                                size="large"
                                :maxlength="255"
                                showCount
                                autofocus
                            />
                        </FormItem>
                    </Col>

                    <Col v-if="isEdit" :xs="24" :md="8">
                        <FormItem
                            :label="$t('system_modules.is_active')"
                            :tooltip="$t('system_modules.is_active_help')"
                            :validate-status="form.errors.is_active ? 'error' : ''"
                            :help="form.errors.is_active"
                        >
                            <Space>
                                <Switch v-model:checked="form.is_active" />
                                <span class="state-label">
                                    {{ form.is_active ? $t('global.active') : $t('global.inactive') }}
                                </span>
                            </Space>
                        </FormItem>
                    </Col>
                </Row>

                <!-- Preview: permission_key + permissions generadas -->
                <div v-if="previewPermissionKey" class="meta-section">
                    <Divider orientation="left">
                        <Space><KeyOutlined /> <span>{{ $t('system_modules.generated_section_title') }}</span></Space>
                    </Divider>

                    <FormItem :label="$t('system_modules.permission_key_preview_label')">
                        <Input :value="previewPermissionKey" readonly size="large">
                            <template #prefix><KeyOutlined /></template>
                        </Input>
                    </FormItem>

                    <FormItem :label="$t('system_modules.permissions_preview_label')">
                        <Space wrap :size="[6, 6]">
                            <Tag v-for="p in previewPermissions" :key="p" color="cyan" :bordered="false">
                                <SafetyCertificateOutlined /> {{ p }}
                            </Tag>
                        </Space>
                        <p class="hint">{{ $t('system_modules.permissions_preview_hint') }}</p>
                    </FormItem>
                </div>

                <!-- Footer actions -->
                <FormFooter
                    :cancel-href="route('system_management.system_modules.index')"
                    :is-edit="isEdit"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </Card>
    </div>
</template>

<style scoped>
.form-card { border-radius: 6px; }
.state-label {
    font-size: 0.875rem;
    color: var(--color-text);
    font-weight: 500;
}
.mb-4 { margin-bottom: 16px; }
.meta-section { margin-top: 8px; }
.hint { font-size: 0.8125rem; color: var(--color-text-muted); margin: 6px 0 0 0; line-height: 1.4; }
</style>
