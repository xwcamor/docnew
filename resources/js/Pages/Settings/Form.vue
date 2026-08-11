<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Form, FormItem, Input, InputNumber, Switch, Select, SelectOption,
    Space, Alert, Row, Col, Tag, Button,
} from 'ant-design-vue';
import { SettingOutlined, LockOutlined, EyeOutlined } from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    setting: { type: Object, default: null },
    // Márgenes con sentido por clave (Setting::VALUE_LIMITS) y claves que hoy
    // no lee nadie (Setting::UNUSED_KEYS). Vienen del backend para que la
    // pantalla y la validación no puedan decir cosas distintas.
    valueLimits: { type: Object, default: () => ({}) },
    unusedKeys:  { type: Array,  default: () => [] },
});

const isEdit = computed(() => !!props.setting);

const TYPES = ['string', 'int', 'bool', 'json'];

const form = useForm({
    key:         props.setting?.key ?? '',
    name:        props.setting?.name ?? '',
    type:        props.setting?.type ?? 'string',
    value:       props.setting?.value ?? '',
    group:       props.setting?.group ?? '',
    description: props.setting?.description ?? '',
    is_secret:   props.setting?.is_secret ?? false,
    is_active:   props.setting?.is_active ?? true,
});

// Si es secret en edit: ocultamos el value al cargar la página para no
// exponer credentials. El usuario clickea "Revelar" para editarlo.
const secretRevealed = ref(!isEdit.value || !props.setting?.is_secret);
const revealSecret = () => { secretRevealed.value = true; };

// Márgenes del ajuste que se está editando, si es uno de los que el sistema
// lee de verdad. Un `docufiz.num_doc_minimum` en 0 convertiría el buscador de
// personas en un volcado del padrón: el campo no deja llegar ahí.
const fmtLimit = (n) => Number.isInteger(n) ? String(n) : String(n).replace('.', ',');
const limits = computed(() => props.valueLimits[form.key] ?? null);
const isUnused = computed(() => props.unusedKeys.includes(form.key));
const limitHint = computed(() => limits.value
    ? { min: fmtLimit(limits.value.min), max: fmtLimit(limits.value.max) }
    : null);

const jsonError = ref('');
const validateJson = (raw) => {
    if (!raw || raw.trim() === '') { jsonError.value = ''; return true; }
    try { JSON.parse(raw); jsonError.value = ''; return true; }
    catch (e) { jsonError.value = e.message; return false; }
};

watch(() => [form.value, form.type], ([v, t]) => {
    if (t === 'json') validateJson(v); else jsonError.value = '';
});

watch(() => form.type, (next, prev) => {
    if (prev === next) return;
    if (next === 'bool') {
        const cur = String(form.value).toLowerCase();
        form.value = (cur === '1' || cur === 'true') ? 'true' : 'false';
    }
});

const valuePlaceholder = computed(() => {
    switch (form.type) {
        case 'int':  return 'settings.value_placeholder_int';
        case 'json': return 'settings.value_placeholder_json';
        default:     return 'settings.value_placeholder_string';
    }
});

const valueHelp = computed(() => {
    if (form.errors.value) return form.errors.value;
    if (form.type === 'json' && jsonError.value) return jsonError.value;
    switch (form.type) {
        case 'bool': return 'settings.value_help_bool';
        case 'int':  return 'settings.value_help_int';
        case 'json': return 'settings.value_help_json';
        default:     return '';
    }
});

const submit = () => {
    if (form.type === 'json' && !validateJson(form.value)) return;
    if (isEdit.value) {
        form.put(route('system_management.settings.update', props.setting.slug));
    } else {
        form.post(route('system_management.settings.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('settings.singular') : $t('settings.new')" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('system_management.settings.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('settings.record') : $t('settings.new')"
            :subtitle="isEdit ? setting.name : $t('settings.form_create_hint')"
        >
            <template #icon><SettingOutlined /></template>
        </SectionHeader>

        <!-- `.form-body`, no una Card: la barra del pie sangra hasta los bordes con
             los `--bar-bleed-*` que app.css declara para `.form-body`; metida en una
             tarjeta salia 28px corta por lado y despegada 24px del fondo, el
             «descuadrado» del alta de usuario (docs/UI.md §8). -->
        <div class="form-body">
            <Form layout="horizontal" :label-col="{ xs: 24, sm: 8, md: 6 }" :wrapper-col="{ xs: 24, sm: 16, md: 13 }" label-align="right" :colon="true" @submit.prevent="submit">

                <Alert
                    v-if="form.hasErrors && Object.keys(form.errors).length > 0"
                    type="error"
                    show-icon
                    :message="$t('global.fix_marked_fields')"
                    class="mb-4"
                />

                <!-- Ajuste heredado que hoy no lee nadie: se avisa antes de
                     que alguien pierda el rato afinando un número inerte. -->
                <Alert
                    v-if="isUnused"
                    type="warning"
                    show-icon
                    :message="$t('settings.unused_badge')"
                    :description="$t('settings.unused_warning')"
                    class="mb-4"
                />

                <h2 class="form-section-title">{{ $t('global.general_data') }}</h2>


                <!-- `form-grid`: sin esa clase, app.css aplana toda columna a
                     ancho completo y este formulario, escrito en dos y tres
                     columnas, salía como una tira vertical. -->
                <Row :gutter="[20, 0]" class="form-grid">
                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label="$t('settings.key')"
                            :tooltip="$t('settings.key_help')"
                            required
                            :validate-status="form.errors.key ? 'error' : ''"
                            :help="form.errors.key || $t(isEdit ? 'settings.key_locked_help' : 'settings.key_help')"
                        >
                            <Input
                                v-model:value="form.key"
                                :placeholder="$t('settings.key_placeholder')"
                                size="large"
                                :maxlength="100"
                                :disabled="isEdit"
                            >
                                <template v-if="isEdit" #prefix><LockOutlined /></template>
                            </Input>
                        </FormItem>
                    </Col>

                    <Col :xs="24" :lg="12">
                        <FormItem
                            :label="$t('settings.name')"
                            :tooltip="$t('settings.name_help')"
                            required
                            :validate-status="form.errors.name ? 'error' : ''"
                            :help="form.errors.name"
                        >
                            <Input
                                v-model:value="form.name"
                                :placeholder="$t('settings.name_placeholder')"
                                size="large"
                                :maxlength="255"
                                showCount
                                autofocus
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :lg="8">
                        <FormItem
                            :label="$t('settings.type')"
                            :tooltip="$t('settings.type_help')"
                            required
                            :validate-status="form.errors.type ? 'error' : ''"
                            :help="form.errors.type"
                        >
                            <Select
                                v-model:value="form.type"
                                size="large"
                                :placeholder="$t('settings.type_placeholder')"
                            >
                                <SelectOption v-for="t in TYPES" :key="t" :value="t">
                                    <Tag :bordered="false" color="cyan" style="margin-right: 6px;">{{ t }}</Tag>
                                </SelectOption>
                            </Select>
                        </FormItem>
                    </Col>

                    <Col :xs="24" :lg="8">
                        <FormItem
                            :label="$t('settings.group')"
                            :tooltip="$t('settings.group_help')"
                            :validate-status="form.errors.group ? 'error' : ''"
                            :help="form.errors.group || $t('settings.group_help')"
                        >
                            <Input
                                v-model:value="form.group"
                                :placeholder="$t('settings.group_placeholder')"
                                size="large"
                                :maxlength="60"
                            />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :lg="8">
                        <FormItem
                            :label="$t('settings.is_secret')"
                            :tooltip="$t('settings.is_secret_help')"
                            :validate-status="form.errors.is_secret ? 'error' : ''"
                            :help="$t('settings.is_secret_hint')"
                        >
                            <Space>
                                <Switch v-model:checked="form.is_secret" />
                                <span class="state-label">
                                    {{ form.is_secret ? $t('global.yes') : $t('global.no') }}
                                </span>
                            </Space>
                        </FormItem>
                    </Col>

                    <Col :xs="24">
                        <FormItem
                            :label="$t('settings.value')"
                            :tooltip="$t('settings.value_help')"
                            :validate-status="(form.errors.value || (form.type === 'json' && jsonError)) ? 'error' : ''"
                            :help="valueHelp ? (valueHelp.startsWith('settings.') ? $t(valueHelp) : valueHelp) : (limitHint ? $t('settings.value_range_hint', limitHint) : '')"
                        >
                            <div v-if="!secretRevealed" class="value-hidden">
                                <code class="value-masked"><LockOutlined /> {{ $t('settings.secret_masked') }}</code>
                                <Button size="small" @click="revealSecret">
                                    <EyeOutlined /> {{ $t('global.edit') }}
                                </Button>
                                <span class="hint-inline">{{ $t('settings.value_reveal_hint') }}</span>
                            </div>

                            <template v-else>
                                <Switch
                                    v-if="form.type === 'bool'"
                                    :checked="form.value === 'true'"
                                    @update:checked="(v) => form.value = v ? 'true' : 'false'"
                                />

                                <!-- min/max salen de Setting::VALUE_LIMITS: son
                                     los mismos números que valida el servidor. -->
                                <InputNumber
                                    v-else-if="form.type === 'int'"
                                    :value="form.value === '' || form.value === null ? null : Number(form.value)"
                                    @update:value="(v) => form.value = v === null ? '' : String(v)"
                                    size="large"
                                    :min="limits ? limits.min : undefined"
                                    :max="limits ? limits.max : undefined"
                                    :placeholder="$t(valuePlaceholder)"
                                    style="width: 100%"
                                />

                                <Input.TextArea
                                    v-else-if="form.type === 'json'"
                                    v-model:value="form.value"
                                    :placeholder="$t(valuePlaceholder)"
                                    :rows="6"
                                    :auto-size="{ minRows: 4, maxRows: 16 }"
                                    class="json-textarea"
                                />

                                <Input
                                    v-else
                                    v-model:value="form.value"
                                    :placeholder="$t(valuePlaceholder)"
                                    size="large"
                                    :maxlength="2000"
                                />
                            </template>
                        </FormItem>
                    </Col>

                    <Col :xs="24">
                        <FormItem
                            :label="$t('settings.description')"
                            :tooltip="$t('settings.description_help')"
                            :validate-status="form.errors.description ? 'error' : ''"
                            :help="form.errors.description"
                        >
                            <Input.TextArea
                                v-model:value="form.description"
                                :placeholder="$t('settings.description_placeholder')"
                                :rows="3"
                                :maxlength="2000"
                                showCount
                            />
                        </FormItem>
                    </Col>

                    <Col v-if="isEdit" :xs="24" :lg="8">
                        <FormItem
                            :label="$t('settings.is_active')"
                            :tooltip="$t('settings.is_active_help')"
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

                <FormFooter
                    :cancel-href="route('system_management.settings.index')"
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
.json-textarea :deep(textarea) {
    font-family: ui-monospace, 'SF Mono', Consolas, 'Liberation Mono', monospace;
    font-size: 0.875rem;
}
.value-hidden {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.value-masked {
    font-family: ui-monospace, 'SF Mono', Consolas, monospace;
    font-size: 0.875rem;
    color: var(--color-text-muted);
    background: var(--color-surface-alt);
    padding: 6px 10px;
    border-radius: 4px;
}
.hint-inline {
    font-size: 0.8125rem;
    color: var(--color-text-muted);
}
</style>
