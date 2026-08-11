<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Form, FormItem, Input, Switch, Space, Alert, Row, Col,
} from 'ant-design-vue';
import { GlobalOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    region: { type: Object, default: null },  // null = create, object = edit
});

const isEdit = computed(() => !!props.region);

const form = useForm({
    name:      props.region?.name ?? '',
    is_active: props.region?.is_active ?? true,
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('system_management.regions.update', props.region.slug));
    } else {
        form.post(route('system_management.regions.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('regions.singular') : $t('regions.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('system_management.regions.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('regions.record') : $t('regions.new')"
            :subtitle="isEdit ? region.name : $t('regions.form_create_hint')"
        >
            <template #icon><GlobalOutlined /></template>
        </SectionHeader>

        <!-- Form card -->
        <!-- `.form-body`, no una Card: la barra del pie sangra hasta los bordes con
             los `--bar-bleed-*` que app.css declara para `.form-body`; metida en una
             tarjeta salia 28px corta por lado y despegada 24px del fondo, el
             «descuadrado» del alta de usuario (docs/UI.md §8). -->
        <div class="form-body">
            <Form layout="horizontal" :label-col="{ xs: 24, sm: 8, md: 6 }" :wrapper-col="{ xs: 24, sm: 16, md: 13 }" label-align="right" :colon="true" @submit.prevent="submit">

                <!-- General error banner -->
                <Alert
                    v-if="form.hasErrors && Object.keys(form.errors).length > 0"
                    type="error"
                    show-icon
                    :message="$t('global.fix_marked_fields')"
                    class="mb-4"
                />

                <h2 class="form-section-title">{{ $t('global.general_data') }}</h2>

                <!-- `form-grid` es obligatorio para que las columnas se respeten:
                     app.css aplana con !important toda fila que no la lleve, y el
                     formulario se pintaba apilado aunque el template dijera dos
                     columnas. El corte es `lg` (992) a proposito — en tablet
                     vertical se apila. -->
                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="16">
                        <FormItem
                            :label-col="{ xs: 24, sm: 8 }"
                            :wrapper-col="{ xs: 24, sm: 16 }"
                            :label="$t('regions.name')"
                            :tooltip="$t('regions.name_help')"
                            required
                            :validate-status="form.errors.name ? 'error' : ''"
                            :help="form.errors.name"
                        >
                            <Input
                                v-model:value="form.name"
                                :placeholder="$t('regions.name_placeholder')"
                                size="large"
                                :maxlength="255"
                                showCount
                                autofocus
                            />
                        </FormItem>
                    </Col>

                    <Col v-if="isEdit" :xs="24" :lg="8">
                        <FormItem
                            :label-col="{ xs: 24, sm: 10 }"
                            :wrapper-col="{ xs: 24, sm: 14 }"
                            :label="$t('regions.is_active')"
                            :tooltip="$t('regions.is_active_help')"
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

                <!-- Footer actions -->
                <FormFooter
                    :cancel-href="route('system_management.regions.index')"
                    :is-edit="isEdit"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </div>
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
</style>
