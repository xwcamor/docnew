<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    Card, Form, FormItem, Input, InputNumber, Switch, Select, SelectOption,
    Row, Col, Divider, Button, Space, Tag, TimePicker, DatePicker, Alert, Tooltip,
} from 'ant-design-vue';
import { ThunderboltOutlined, PlusOutlined, MinusCircleOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import FormFooter from '@/Components/Common/FormFooter.vue';
import AutomationsTemplateGallery from '@/Components/Automations/AutomationsTemplateGallery.vue';
import AutomationPreviewModal from '@/Components/Automations/AutomationPreviewModal.vue';
import {
    InfoCircleOutlined, EyeOutlined, UsergroupAddOutlined, TeamOutlined,
} from '@ant-design/icons-vue';
import { Modal as AntModal } from 'ant-design-vue';
import { useI18n } from '@/Plugins/i18n';
import { useAuth } from '@/Composables/useAuth';

defineOptions({ layout: AppLayout });

const { t } = useI18n();

const props = defineProps({
    automation: { type: Object, default: null },
    catalog:    { type: Object, required: true },
});

const isEdit = computed(() => !!props.automation);
const { isSuper } = useAuth();

// Opciones de workspace para el selector. Solo el backend las llena cuando
// el user actual es super; para admin viene array vacio y el selector no
// se renderiza (su tenant se autoasigna server-side).
const tenantOptions = computed(() => props.catalog.tenant_options ?? []);

// ─── State del form ────────────────────────────────────────────────────────
const form = useForm({
    tenant_id:      props.automation?.tenant_id ?? null,
    name:           props.automation?.name ?? '',
    description:    props.automation?.description ?? '',
    is_active:      props.automation?.is_active ?? true,
    trigger_type:   'schedule',
    trigger_config: props.automation?.trigger_config ?? { kind: 'daily', time: '09:00' },
    data_source:    props.automation?.data_source ?? null,
    data_filter:    props.automation?.data_filter ?? { where: [], limit: 100 },
    action_type:    props.automation?.action_type ?? 'email',
    action_config:  props.automation?.action_config ?? defaultActionConfig('email'),
});

// Default config según el action elegido. Cambiar de email a in-app
// reinicializa el shape para que no queden campos vacíos pegados.
function defaultActionConfig(type) {
    if (type === 'email') {
        return { to: [], subject: '', body: '' };
    }
    if (type === 'in_app_notification') {
        return { recipients: 'tenant_admins', user_ids: [], title: '', body: '' };
    }
    return {};
}

// ─── Workspace users (picker de destinatarios) ────────────────────────────
// Catalogo viene del backend. Email picker usa email; specific_users usa id.
const workspaceUsers = computed(() => props.catalog.workspace_users ?? []);
const userEmailOptions = computed(() =>
    workspaceUsers.value
        .filter(u => u.email)
        .map(u => ({ value: u.email, label: `${u.name} (${u.email})` }))
);
const userIdOptions = computed(() =>
    workspaceUsers.value.map(u => ({ value: u.id, label: `${u.name} (${u.email ?? '—'})` }))
);

// ─── Template variables (chips clickeables) ───────────────────────────────
const templateVariables = computed(() => props.catalog.template_variables ?? []);

// El in-app es un recordatorio corto, no un resumen — `{list}` no tiene
// sentido ahi (no podemos pintar una lista de 5 nombres en 1 linea con
// elipsis). Solo lo dejamos disponible en email donde el detalle cabe.
const inAppVariables = computed(() =>
    templateVariables.value.filter(v => v.key !== '{list}')
);

// Refs a los textareas para insertar variables en la posicion del cursor.
const emailBodyRef    = ref(null);
const emailSubjectRef = ref(null);
const inAppBodyRef    = ref(null);
const inAppTitleRef   = ref(null);

/**
 * Inserta `text` en la posicion actual del cursor de `targetRef`.
 * Si el textarea no esta enfocado, agrega al final del valor.
 * `model` es la funcion getter/setter sobre form.action_config.{field}.
 */
function insertAtCursor(targetRef, currentValue, setValue, text) {
    const el = targetRef.value?.$el?.querySelector?.('textarea, input')
            ?? targetRef.value?.input
            ?? targetRef.value;

    const value = currentValue ?? '';
    let next;
    let nextCursor;

    if (el && typeof el.selectionStart === 'number') {
        const start = el.selectionStart;
        const end   = el.selectionEnd;
        next       = value.slice(0, start) + text + value.slice(end);
        nextCursor = start + text.length;
    } else {
        next       = value + (value && !value.endsWith(' ') ? ' ' : '') + text;
        nextCursor = next.length;
    }

    setValue(next);
    nextTick(() => {
        if (el && typeof el.setSelectionRange === 'function') {
            el.focus();
            el.setSelectionRange(nextCursor, nextCursor);
        }
    });
}

// Cuando el usuario cambia el action_type MANUALMENTE, reseteamos action_config
// al shape correcto. Pero NO cuando el cambio viene de aplicar una plantilla:
// la plantilla ya trae su propio action_config (con title/body) y este watcher,
// que corre async tras el Object.assign, lo pisaría con defaults vacíos
// (bug: "al cambiar de plantilla se borra el título y el mensaje").
const applyingTemplate = ref(false);
watch(() => form.action_type, (newType) => {
    if (applyingTemplate.value) return;
    form.action_config = defaultActionConfig(newType);
});

// ─── Trigger UI (form-friendly) ───────────────────────────────────────────
// Mostramos un TimePicker para la hora — guardamos como string HH:mm en
// trigger_config.time para serializar limpio.
const timeValue = computed({
    get: () => {
        const t = form.trigger_config.time;
        return t ? dayjs(t, 'HH:mm') : null;
    },
    set: (v) => {
        form.trigger_config = { ...form.trigger_config, time: v ? v.format('HH:mm') : '09:00' };
    },
});

const onKindChange = (kind) => {
    const next = { kind };
    if (kind === 'cron') next.expression = form.trigger_config.expression ?? '0 9 * * *';
    else {
        next.time = form.trigger_config.time ?? '09:00';
        if (kind === 'weekly')  next.day = form.trigger_config.day ?? 1;
        if (kind === 'monthly') next.day = form.trigger_config.day ?? 1;
    }
    form.trigger_config = next;
};

// ─── Filtros del data source ──────────────────────────────────────────────
// El catálogo del backend trae los campos por data_source con type + operators
// permitidos + options (para enums). El frontend renderiza el control de
// "value" según el type, así el usuario no adivina el formato.
const sourceFields = computed(() => {
    const s = props.catalog.data_sources.find(s => s.key === form.data_source);
    return s?.fields ?? [];
});

// Busca el meta del field actual de una cláusula. Devuelve null si la source
// cambió y el field ya no existe.
const fieldMeta = (fieldKey) => sourceFields.value.find(f => f.key === fieldKey) ?? null;

// Operadores soportados por todos los fields, con label y símbolo. Usamos
// "label" porque "contains" o "in" no son obvios como símbolo.
const allOperators = computed(() => [
    { value: '=',        label: '=' },
    { value: '!=',       label: '!=' },
    { value: '>',        label: '>' },
    { value: '<',        label: '<' },
    { value: '>=',       label: '>=' },
    { value: '<=',       label: '<=' },
    { value: 'contains', label: t('automations.op_contains') },
    { value: 'in',       label: t('automations.op_in') },
]);

// Operadores válidos para un field específico (intersección con su declaracion).
const operatorsFor = (fieldKey) => {
    const meta = fieldMeta(fieldKey);
    if (!meta?.operators) return allOperators.value;
    return allOperators.value.filter(o => meta.operators.includes(o.value));
};

// Valor inicial al agregar una cláusula o cambiar de field. Si el type es
// boolean, default true; date, hoy; enum, primera opción; resto, string vacío.
const defaultValueForType = (meta) => {
    if (!meta) return '';
    if (meta.type === 'boolean')          return true;
    if (meta.type === 'enum')             return meta.options?.[0]?.value ?? '';
    if (meta.type === 'number' || meta.type === 'int') return null;
    if (meta.type === 'date' || meta.type === 'datetime') return null;
    return '';
};

const addClause = () => {
    if (!form.data_filter.where) form.data_filter.where = [];
    const firstField = sourceFields.value[0];
    if (!firstField) return;
    const ops = operatorsFor(firstField.key);
    form.data_filter.where.push({
        field: firstField.key,
        op:    ops[0]?.value ?? '=',
        value: defaultValueForType(firstField),
    });
};

// Al cambiar el field de una cláusula, resetear operator + value para que
// queden consistentes con el nuevo type.
const onFieldChange = (clause, newField) => {
    clause.field = newField;
    const meta = fieldMeta(newField);
    const ops  = operatorsFor(newField);
    clause.op    = ops[0]?.value ?? '=';
    clause.value = defaultValueForType(meta);
};

const removeClause = (i) => {
    form.data_filter.where.splice(i, 1);
};

// Opciones para boolean (Sí / No).
const booleanOptions = computed(() => [
    { value: true,  label: t('global.active')   ?? 'Sí' },
    { value: false, label: t('global.inactive') ?? 'No' },
]);

// ─── Recipients picker para in_app_notification ───────────────────────────
const recipientsOptions = [
    { value: 'tenant_admins',  label: t('automations.action_in_app_recipients_admins') },
    { value: 'specific_users', label: t('automations.action_in_app_recipients_specific') },
];

// ─── Submit ───────────────────────────────────────────────────────────────
const submit = () => {
    if (isEdit.value) {
        form.put(route('automation_management.automations.update', props.automation.id));
    } else {
        form.post(route('automation_management.automations.store'));
    }
};

// Aplicar una plantilla = pisar todos los campos del form con sus values.
// Solo se ofrece en modo CREATE (la gallery no se renderiza en edit).
const applyTemplate = (config) => {
    // Suprime el watcher de action_type mientras aplicamos: el template ya
    // trae action_config completo (incl. title/body). Se libera tras el flush.
    applyingTemplate.value = true;
    Object.assign(form, JSON.parse(JSON.stringify(config)));
    nextTick(() => { applyingTemplate.value = false; });
};

// ─── Helpers de seleccion masiva ───────────────────────────────────────────
// Botones tipo "Agregar todos los admins" arriba de los selects para evitar
// click manual en cada user cuando la intencion es "todos".
const addAllAdminsAsEmails = () => {
    const adminEmails = workspaceUsers.value
        .filter(u => u.is_admin && u.email)
        .map(u => u.email);
    form.action_config.to = Array.from(new Set([...(form.action_config.to ?? []), ...adminEmails]));
};
const addAllUsersAsEmails = () => {
    const allEmails = workspaceUsers.value
        .filter(u => u.email)
        .map(u => u.email);
    form.action_config.to = Array.from(new Set([...(form.action_config.to ?? []), ...allEmails]));
};
const clearEmailRecipients = () => {
    form.action_config.to = [];
};

const addAllAdminsAsUserIds = () => {
    const adminIds = workspaceUsers.value.filter(u => u.is_admin).map(u => u.id);
    form.action_config.user_ids = Array.from(new Set([...(form.action_config.user_ids ?? []), ...adminIds]));
};
const addAllUserIds = () => {
    const allIds = workspaceUsers.value.map(u => u.id);
    form.action_config.user_ids = Array.from(new Set([...(form.action_config.user_ids ?? []), ...allIds]));
};
const clearUserIds = () => {
    form.action_config.user_ids = [];
};

// ─── Preview del mensaje (renderiza el body con variables ejemplo) ─────────
const previewOpen = ref(false);
const openPreview = () => { previewOpen.value = true; };

const daysOfWeek = [
    { value: 1, label: 'Lunes' },
    { value: 2, label: 'Martes' },
    { value: 3, label: 'Miércoles' },
    { value: 4, label: 'Jueves' },
    { value: 5, label: 'Viernes' },
    { value: 6, label: 'Sábado' },
    { value: 0, label: 'Domingo' },
];
</script>

<template>
    <Head :title="isEdit ? $t('global.edit') + ' — ' + $t('automations.singular') : $t('automations.new')" />

    <div class="sap-form">
        <SectionHeader
            :back-href="route('automation_management.automations.index')"
            :title="isEdit ? $t('global.edit') + ' ' + $t('automations.record') : $t('automations.new')"
            :subtitle="isEdit ? form.name : $t('automations.index_subtitle')"
        >
            <template #icon><ThunderboltOutlined /></template>
        </SectionHeader>

        <!-- Galeria de plantillas pre-armadas — solo al crear -->
        <AutomationsTemplateGallery
            v-if="!isEdit"
            :catalog="catalog"
            @apply="applyTemplate"
        />

        <Card :bodyStyle="{ padding: '24px 28px' }">
            <Form layout="horizontal" :label-col="{ xs: 24, sm: 8, md: 6 }" :wrapper-col="{ xs: 24, sm: 16, md: 13 }" label-align="right" :colon="true" @submit.prevent="submit">

                <!-- ── Identidad ─────────────────────────────────────────── -->
                <Divider orientation="left" plain>
                    <strong>{{ $t('automations.section_identity') }}</strong>
                    <Tooltip :title="$t('automations.section_identity_help')">
                        <InfoCircleOutlined class="section-help" />
                    </Tooltip>
                </Divider>
                <Row :gutter="[20, 0]">
                    <!-- Selector de workspace: solo super lo ve. Admin tiene
                         tenant fijo y se autoasigna server-side. -->
                    <Col v-if="isSuper" :xs="24" :md="10">
                        <FormItem :label="$t('automations.workspace')" :tooltip="$t('automations.workspace_help')" required
                                  :validate-status="form.errors.tenant_id ? 'error' : ''"
                                  :help="form.errors.tenant_id || $t('automations.workspace_hint')">
                            <Select v-model:value="form.tenant_id" size="large"
                                    :options="tenantOptions"
                                    :placeholder="$t('automations.workspace_placeholder')"
                                    show-search
                                    :filter-option="(input, option) => (option.label ?? '').toLowerCase().includes(input.toLowerCase())" />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="isSuper ? 8 : 14">
                        <FormItem :label="$t('automations.name')" :tooltip="$t('automations.name_help')" required
                                  :validate-status="form.errors.name ? 'error' : ''"
                                  :help="form.errors.name">
                            <Input v-model:value="form.name" size="large" :maxlength="150"
                                   :placeholder="$t('automations.name_placeholder')" />
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="6">
                        <FormItem :label="$t('automations.is_active')"
                                  :tooltip="$t('automations.is_active_help')"
                                  :help="$t('automations.is_active_hint')">
                            <Switch v-model:checked="form.is_active" />
                        </FormItem>
                    </Col>
                    <Col :xs="24">
                        <FormItem :label="$t('automations.description')"
                                  :tooltip="$t('automations.description_help')"
                                  :validate-status="form.errors.description ? 'error' : ''"
                                  :help="form.errors.description">
                            <Input.TextArea v-model:value="form.description" :rows="2" :maxlength="1000"
                                            :placeholder="$t('automations.description_placeholder')" />
                        </FormItem>
                    </Col>
                </Row>

                <!-- ── Trigger ───────────────────────────────────────────── -->
                <Divider orientation="left" plain>
                    <strong>{{ $t('automations.section_trigger') }}</strong>
                    <Tooltip :title="$t('automations.section_trigger_help')">
                        <InfoCircleOutlined class="section-help" />
                    </Tooltip>
                </Divider>
                <Row :gutter="[20, 0]">
                    <Col :xs="24" :md="8">
                        <FormItem :label="$t('automations.trigger_kind')" :tooltip="$t('automations.trigger_kind_help')">
                            <Select :value="form.trigger_config.kind" size="large" @change="onKindChange" :placeholder="$t('automations.trigger_kind_placeholder')">
                                <SelectOption value="daily">{{ $t('automations.trigger_kind_daily') }}</SelectOption>
                                <SelectOption value="weekly">{{ $t('automations.trigger_kind_weekly') }}</SelectOption>
                                <SelectOption value="monthly">{{ $t('automations.trigger_kind_monthly') }}</SelectOption>
                                <SelectOption value="cron">{{ $t('automations.trigger_kind_cron') }}</SelectOption>
                            </Select>
                        </FormItem>
                    </Col>

                    <Col :xs="24" :md="8" v-if="form.trigger_config.kind !== 'cron'">
                        <FormItem :label="$t('automations.trigger_time')" :tooltip="$t('automations.trigger_time_help')">
                            <TimePicker v-model:value="timeValue" format="HH:mm" size="large" style="width: 100%" :placeholder="$t('automations.trigger_time_placeholder')" />
                        </FormItem>
                    </Col>

                    <Col :xs="24" :md="8" v-if="form.trigger_config.kind === 'weekly'">
                        <FormItem :label="$t('automations.trigger_day_of_week')" :tooltip="$t('automations.trigger_day_of_week_help')">
                            <Select v-model:value="form.trigger_config.day" size="large" :placeholder="$t('automations.trigger_day_of_week_placeholder')">
                                <SelectOption v-for="d in daysOfWeek" :key="d.value" :value="d.value">{{ d.label }}</SelectOption>
                            </Select>
                        </FormItem>
                    </Col>

                    <Col :xs="24" :md="8" v-if="form.trigger_config.kind === 'monthly'">
                        <FormItem :label="$t('automations.trigger_day_of_month')" :tooltip="$t('automations.trigger_day_of_month_help')">
                            <InputNumber v-model:value="form.trigger_config.day" :min="1" :max="31" size="large" style="width: 100%" :placeholder="$t('automations.trigger_day_of_month_placeholder')" />
                        </FormItem>
                    </Col>

                    <Col :xs="24" v-if="form.trigger_config.kind === 'cron'">
                        <FormItem :label="$t('automations.trigger_cron_expression')"
                                  :tooltip="$t('automations.trigger_cron_expression_help')"
                                  :help="$t('automations.trigger_cron_hint')">
                            <Input v-model:value="form.trigger_config.expression" size="large" placeholder="0 9 * * 1" />
                        </FormItem>
                    </Col>
                </Row>

                <!-- ── Data source + filtros ─────────────────────────────── -->
                <Divider orientation="left" plain>
                    <strong>{{ $t('automations.section_data') }}</strong>
                    <Tooltip :title="$t('automations.section_data_help')">
                        <InfoCircleOutlined class="section-help" />
                    </Tooltip>
                </Divider>
                <Row :gutter="[20, 0]">
                    <Col :xs="24" :md="10">
                        <FormItem :label="$t('automations.data_source')" :tooltip="$t('automations.data_source_help')">
                            <Select v-model:value="form.data_source" size="large" allow-clear
                                    :placeholder="$t('automations.data_source_none')">
                                <SelectOption v-for="s in catalog.data_sources" :key="s.key" :value="s.key">
                                    {{ s.label }}
                                </SelectOption>
                            </Select>
                        </FormItem>
                    </Col>
                    <Col :xs="24" :md="6" v-if="form.data_source">
                        <FormItem :label="$t('automations.data_filter_limit')" :tooltip="$t('automations.data_filter_limit_help')">
                            <InputNumber v-model:value="form.data_filter.limit" :min="1" :max="1000" size="large" style="width: 100%" :placeholder="$t('automations.data_filter_limit_placeholder')" />
                        </FormItem>
                    </Col>
                </Row>

                <div v-if="form.data_source">
                    <p class="hint">{{ $t('automations.data_filter_intro') }}</p>
                    <div v-for="(clause, i) in (form.data_filter.where ?? [])" :key="i" class="filter-row">
                        <!-- Campo: lista del data source -->
                        <Select
                            :value="clause.field"
                            @change="(v) => onFieldChange(clause, v)"
                            style="width: 200px"
                        >
                            <SelectOption v-for="f in sourceFields" :key="f.key" :value="f.key">{{ f.label }}</SelectOption>
                        </Select>

                        <!-- Operador: filtrado a los soportados por el field -->
                        <Select v-model:value="clause.op" style="width: 140px">
                            <SelectOption v-for="o in operatorsFor(clause.field)" :key="o.value" :value="o.value">
                                {{ o.label }}
                            </SelectOption>
                        </Select>

                        <!-- Valor: control tipado según el field.type -->
                        <template v-if="fieldMeta(clause.field)?.type === 'boolean'">
                            <Select
                                v-model:value="clause.value"
                                :options="booleanOptions"
                                style="flex: 1; min-width: 120px"
                            />
                        </template>

                        <template v-else-if="fieldMeta(clause.field)?.type === 'enum'">
                            <Select
                                v-model:value="clause.value"
                                :options="fieldMeta(clause.field).options"
                                show-search
                                option-filter-prop="label"
                                allow-clear
                                style="flex: 1; min-width: 120px"
                            />
                        </template>

                        <template v-else-if="fieldMeta(clause.field)?.type === 'number' || fieldMeta(clause.field)?.type === 'int'">
                            <InputNumber v-model:value="clause.value" style="flex: 1; min-width: 120px; width: 100%" />
                        </template>

                        <template v-else-if="fieldMeta(clause.field)?.type === 'date' || fieldMeta(clause.field)?.type === 'datetime'">
                            <DatePicker
                                :value="clause.value ? dayjs(clause.value) : null"
                                @update:value="(d) => clause.value = d ? d.format('YYYY-MM-DD') : null"
                                :format="fieldMeta(clause.field).type === 'datetime' ? 'YYYY-MM-DD HH:mm' : 'YYYY-MM-DD'"
                                :show-time="fieldMeta(clause.field).type === 'datetime'"
                                style="flex: 1; min-width: 140px; width: 100%"
                            />
                        </template>

                        <template v-else>
                            <!-- string: Input. Cuando op='in', el usuario ingresa lista separada por comas. -->
                            <Input
                                v-model:value="clause.value"
                                :placeholder="clause.op === 'in' ? $t('automations.value_in_placeholder') : ''"
                                style="flex: 1"
                            />
                        </template>

                        <Button type="text" danger @click="removeClause(i)">
                            <MinusCircleOutlined />
                        </Button>
                    </div>
                    <Button @click="addClause">
                        <PlusOutlined /> {{ $t('automations.data_filter_add') }}
                    </Button>
                </div>

                <!-- ── Action ────────────────────────────────────────────── -->
                <Divider orientation="left" plain>
                    <strong>{{ $t('automations.section_action') }}</strong>
                    <Tooltip :title="$t('automations.section_action_help')">
                        <InfoCircleOutlined class="section-help" />
                    </Tooltip>
                </Divider>
                <Row :gutter="[20, 0]">
                    <Col :xs="24" :md="10">
                        <FormItem :label="$t('automations.action_type')" :tooltip="$t('automations.action_type_help')" required>
                            <Select v-model:value="form.action_type" size="large" :placeholder="$t('automations.action_type_placeholder')">
                                <SelectOption v-for="a in catalog.actions" :key="a.key" :value="a.key">
                                    {{ a.label }}
                                </SelectOption>
                            </Select>
                        </FormItem>
                    </Col>
                </Row>

                <!-- Email config -->
                <template v-if="form.action_type === 'email'">
                    <Row :gutter="[20, 0]">
                        <Col :xs="24" :md="14">
                            <FormItem :label="$t('automations.action_email_to')" :tooltip="$t('automations.action_email_to_help')" required
                                      :help="$t('automations.action_email_to_hint')">
                                <div class="bulk-helpers">
                                    <Button size="small" @click="addAllAdminsAsEmails">
                                        <UsergroupAddOutlined /> {{ $t('automations.add_all_admins') }}
                                    </Button>
                                    <Button size="small" @click="addAllUsersAsEmails">
                                        <TeamOutlined /> {{ $t('automations.add_all_users') }}
                                    </Button>
                                    <Button v-if="(form.action_config.to ?? []).length" size="small" type="text" danger @click="clearEmailRecipients">
                                        {{ $t('automations.clear_recipients') }}
                                    </Button>
                                </div>
                                <Select
                                    v-model:value="form.action_config.to"
                                    mode="tags"
                                    size="large"
                                    :options="userEmailOptions"
                                    :placeholder="$t('automations.action_email_to_placeholder')"
                                    :token-separators="[',', ' ', ';', '\n']"
                                    style="width: 100%"
                                />
                            </FormItem>
                        </Col>
                        <Col :xs="24" :md="10">
                            <FormItem :label="$t('automations.action_email_subject')" :tooltip="$t('automations.action_email_subject_help')" required>
                                <Input ref="emailSubjectRef" v-model:value="form.action_config.subject"
                                       size="large" :maxlength="200"
                                       :placeholder="$t('automations.action_email_subject_placeholder')" />
                            </FormItem>
                        </Col>
                        <Col :xs="24">
                            <FormItem :label="$t('automations.action_email_body')" :tooltip="$t('automations.action_email_body_help')" required>
                                <div class="template-vars">
                                    <span class="template-vars__label">{{ $t('automations.template_variables_label') }}:</span>
                                    <Tooltip v-for="v in templateVariables" :key="v.key" :title="v.description">
                                        <Tag class="template-vars__chip" color="blue" :bordered="false"
                                             @click="insertAtCursor(emailBodyRef, form.action_config.body, val => form.action_config.body = val, v.key)">
                                            <code>{{ v.key }}</code>
                                            <span class="template-vars__chip-label">— {{ v.label }}</span>
                                        </Tag>
                                    </Tooltip>
                                </div>
                                <Input.TextArea ref="emailBodyRef" v-model:value="form.action_config.body" :rows="6"
                                    :placeholder="$t('automations.action_email_body_placeholder')" />
                                <Button @click="openPreview" type="dashed" size="small" style="margin-top: 10px">
                                    <EyeOutlined /> {{ $t('automations.preview_button') }}
                                </Button>
                            </FormItem>
                        </Col>
                    </Row>
                </template>

                <!-- In-app notification config -->
                <template v-if="form.action_type === 'in_app_notification'">
                    <Row :gutter="[20, 0]">
                        <Col :xs="24" :md="10">
                            <FormItem :label="$t('automations.action_in_app_recipients')" :tooltip="$t('automations.action_in_app_recipients_help')" required>
                                <Select v-model:value="form.action_config.recipients" size="large" :placeholder="$t('automations.action_in_app_recipients_placeholder')">
                                    <SelectOption v-for="r in recipientsOptions" :key="r.value" :value="r.value">
                                        {{ r.label }}
                                    </SelectOption>
                                </Select>
                            </FormItem>
                        </Col>
                        <Col :xs="24" :md="14">
                            <FormItem :label="$t('automations.action_in_app_title')" :tooltip="$t('automations.action_in_app_title_help')" required>
                                <Input ref="inAppTitleRef" v-model:value="form.action_config.title"
                                       size="large" :maxlength="200"
                                       :placeholder="$t('automations.action_in_app_title_placeholder')" />
                            </FormItem>
                        </Col>
                        <Col v-if="form.action_config.recipients === 'specific_users'" :xs="24">
                            <FormItem :label="$t('automations.action_in_app_specific_users')" :tooltip="$t('automations.action_in_app_specific_users_help')" required
                                      :help="$t('automations.action_in_app_specific_users_hint')">
                                <div class="bulk-helpers">
                                    <Button size="small" @click="addAllAdminsAsUserIds">
                                        <UsergroupAddOutlined /> {{ $t('automations.add_all_admins') }}
                                    </Button>
                                    <Button size="small" @click="addAllUserIds">
                                        <TeamOutlined /> {{ $t('automations.add_all_users') }}
                                    </Button>
                                    <Button v-if="(form.action_config.user_ids ?? []).length" size="small" type="text" danger @click="clearUserIds">
                                        {{ $t('automations.clear_recipients') }}
                                    </Button>
                                </div>
                                <Select
                                    v-model:value="form.action_config.user_ids"
                                    mode="multiple"
                                    size="large"
                                    :options="userIdOptions"
                                    :placeholder="$t('automations.action_in_app_specific_users_placeholder')"
                                    :filter-option="(input, option) => (option.label ?? '').toLowerCase().includes(input.toLowerCase())"
                                    style="width: 100%"
                                />
                            </FormItem>
                        </Col>
                        <Col :xs="24">
                            <FormItem :label="$t('automations.action_in_app_body')" :tooltip="$t('automations.action_in_app_body_help')" required>
                                <div class="template-vars">
                                    <span class="template-vars__label">{{ $t('automations.template_variables_label') }}:</span>
                                    <Tooltip v-for="v in inAppVariables" :key="v.key" :title="v.description">
                                        <Tag class="template-vars__chip" color="blue" :bordered="false"
                                             @click="insertAtCursor(inAppBodyRef, form.action_config.body, val => form.action_config.body = val, v.key)">
                                            <code>{{ v.key }}</code>
                                            <span class="template-vars__chip-label">— {{ v.label }}</span>
                                        </Tag>
                                    </Tooltip>
                                </div>
                                <p class="hint">{{ $t('automations.in_app_body_hint') }}</p>
                                <Input.TextArea ref="inAppBodyRef" v-model:value="form.action_config.body" :rows="4"
                                                :placeholder="$t('automations.action_in_app_body_placeholder')" />
                                <Button @click="openPreview" type="dashed" size="small" style="margin-top: 10px">
                                    <EyeOutlined /> {{ $t('automations.preview_button') }}
                                </Button>
                            </FormItem>
                        </Col>
                    </Row>
                </template>

                <FormFooter
                    :cancel-href="route('automation_management.automations.index')"
                    :processing="form.processing"
                    floating
                />
            </Form>
        </Card>

        <AutomationPreviewModal v-model:open="previewOpen" :form="form" :catalog="catalog" />
    </div>
</template>

<style scoped>
.hint { font-size: 0.8125rem; color: var(--color-text-muted); margin: 4px 0 12px 0; }
.section-help {
    margin-left: 8px;
    color: var(--color-text-muted, #8a8d90);
    font-size: 14px;
    cursor: help;
    transition: color 0.15s ease;
}
.section-help:hover { color: var(--color-primary, #0A6ED1); }

.bulk-helpers {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 8px;
}
.template-vars {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px 8px;
    margin-bottom: 8px;
    padding: 8px 10px;
    background: var(--color-surface-alt, #f6f7f9);
    border-radius: 6px;
}
.template-vars__label {
    font-size: 0.75rem;
    color: var(--color-text-muted, #6a6d70);
    font-weight: 500;
    margin-right: 4px;
}
.template-vars__chip {
    cursor: pointer;
    user-select: none;
    transition: transform 0.1s ease;
    margin: 0;
}
.template-vars__chip:hover { transform: translateY(-1px); }
.template-vars__chip code {
    font-family: ui-monospace, 'SF Mono', Consolas, monospace;
    font-size: 0.8125rem;
    background: transparent;
    padding: 0;
}
.template-vars__chip-label {
    font-size: 0.75rem;
    color: var(--color-text-muted, #6a6d70);
    margin-left: 4px;
}
@media (max-width: 640px) {
    .template-vars__chip-label { display: none; }
}
.filter-row {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.filter-row > :deep(.ant-select),
.filter-row > :deep(.ant-input) { min-width: 0; }

@media (max-width: 767px) {
    /* Stack vertical: Select field + Select op + Input value + Button. Cada
       uno full-width — selects con width inline 200px/120px caian fuera. */
    .filter-row { flex-direction: column; align-items: stretch; }
    .filter-row > :deep(.ant-select) { width: 100% !important; }
    .filter-row > :deep(.ant-input)  { width: 100% !important; flex: 0 0 auto; }
}
</style>
