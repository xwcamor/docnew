<script setup>
/**
 * AdvancedFilterDrawer — drawer reusable de filtros avanzados estilo query
 * builder. Recibe un `schema` declarado por el backend (Customer::filterSchema)
 * y genera cláusulas dinámicas { field, op, value } con controles tipados
 * según el `type` de cada field (string/number/boolean/date/enum).
 *
 * Reutilizable en cualquier módulo que exponga su filterSchema. El backend
 * aplica las cláusulas vía FilterApplier (mismo del módulo Automations).
 */
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import {
    Drawer, Select, SelectOption, Input, InputNumber, DatePicker, Button, Space, Tag, Empty,
} from 'ant-design-vue';
import { PlusOutlined, MinusCircleOutlined, FilterOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

const props = defineProps({
    open:        { type: Boolean, required: true },
    /** Array de fields del schema del backend: { key, label, type, operators, options? } */
    schema:      { type: Array,   default: () => [] },
    /** v-model: array actual de cláusulas { field, op, value } */
    modelValue:  { type: Array,   default: () => [] },
    /** Ancho del drawer en desktop. En mobile (<768px) usa 100% siempre. */
    width:       { type: [Number, String], default: 560 },
});

const emit = defineEmits(['update:open', 'update:modelValue', 'apply']);

// ─── Responsive: el drawer ocupa todo el ancho en mobile ────────────────────
// 560px de width fijo se sale del viewport en pantallas <600px y queda con
// scroll horizontal feo. En mobile vamos a 100% (full screen drawer).
const isMobile = ref(false);
const checkMobile = () => { isMobile.value = window.innerWidth < 768; };
onMounted(() => { checkMobile(); window.addEventListener('resize', checkMobile); });
onBeforeUnmount(() => window.removeEventListener('resize', checkMobile));

const effectiveWidth = computed(() => isMobile.value ? '100%' : props.width);

// ─── Estado local: copia editable hasta que el user haga "Aplicar" ──────────
// No mutamos modelValue directo para evitar reload del listado en cada cambio
// de input. Solo cuando el user confirma, hacemos emit.
const draft = ref(JSON.parse(JSON.stringify(props.modelValue ?? [])));
watch(() => props.open, (isOpen) => {
    if (isOpen) {
        // Al abrir, sincronizar con el v-model actual.
        draft.value = JSON.parse(JSON.stringify(props.modelValue ?? []));
    }
});

// ─── Helpers de schema ──────────────────────────────────────────────────────
const fieldOptions = computed(() =>
    (props.schema ?? []).map(f => ({ value: f.key, label: f.label }))
);

const fieldMeta = (key) => (props.schema ?? []).find(f => f.key === key) ?? null;

const allOperators = computed(() => [
    { value: '=',        label: '=' },
    { value: '!=',       label: '≠' },
    { value: '>',        label: '>' },
    { value: '<',        label: '<' },
    { value: '>=',       label: '≥' },
    { value: '<=',       label: '≤' },
    { value: 'contains', label: t('global.contains') },
    { value: 'in',       label: t('global.in_list') },
]);

const operatorsFor = (fieldKey) => {
    const meta = fieldMeta(fieldKey);
    if (!meta?.operators) return allOperators.value;
    return allOperators.value.filter(o => meta.operators.includes(o.value));
};

const defaultValueForType = (meta) => {
    if (!meta) return '';
    if (meta.type === 'boolean')          return true;
    if (meta.type === 'enum')             return meta.options?.[0]?.value ?? '';
    if (meta.type === 'number' || meta.type === 'int') return null;
    if (meta.type === 'date' || meta.type === 'datetime') return null;
    return '';
};

const booleanOptions = computed(() => [
    { value: true,  label: t('global.active') },
    { value: false, label: t('global.inactive') },
]);

// ─── Acciones sobre el draft ────────────────────────────────────────────────
const addClause = () => {
    const first = props.schema?.[0];
    if (!first) return;
    const ops = operatorsFor(first.key);
    draft.value.push({
        field: first.key,
        op:    ops[0]?.value ?? '=',
        value: defaultValueForType(first),
    });
};

const removeClause = (i) => {
    draft.value.splice(i, 1);
};

const onFieldChange = (clause, newField) => {
    clause.field = newField;
    const meta = fieldMeta(newField);
    const ops  = operatorsFor(newField);
    clause.op    = ops[0]?.value ?? '=';
    clause.value = defaultValueForType(meta);
};

const apply = () => {
    // Filtrar clausulas incompletas antes de emitir.
    const cleaned = draft.value.filter(c => c.field && c.op);
    emit('update:modelValue', cleaned);
    emit('apply', cleaned);
    emit('update:open', false);
};

const clearAll = () => {
    draft.value = [];
};

const cancel = () => {
    emit('update:open', false);
};
</script>

<template>
    <Drawer
        :open="open"
        :title="$t('global.advanced_filters')"
        :width="effectiveWidth"
        :placement="isMobile ? 'bottom' : 'right'"
        :height="isMobile ? '90vh' : undefined"
        @update:open="(v) => emit('update:open', v)"
    >
        <p class="adv-filter__hint">{{ $t('global.advanced_filters_hint') }}</p>

        <div v-if="draft.length === 0" class="adv-filter__empty">
            <FilterOutlined style="font-size: 1.8rem; color: #cbd5e1;" />
            <p>{{ $t('global.advanced_filters_empty') }}</p>
        </div>

        <div v-else class="adv-filter__list">
            <div v-for="(clause, i) in draft" :key="i" class="adv-filter__row">
                <Select
                    :value="clause.field"
                    @change="(v) => onFieldChange(clause, v)"
                    :options="fieldOptions"
                    style="flex: 1 1 160px; min-width: 140px"
                />

                <Select
                    v-model:value="clause.op"
                    :options="operatorsFor(clause.field)"
                    style="flex: 0 0 110px"
                />

                <!-- Valor: control tipado según el field.type -->
                <template v-if="fieldMeta(clause.field)?.type === 'boolean'">
                    <Select
                        v-model:value="clause.value"
                        :options="booleanOptions"
                        style="flex: 1 1 120px"
                    />
                </template>

                <template v-else-if="fieldMeta(clause.field)?.type === 'enum'">
                    <Select
                        v-model:value="clause.value"
                        :options="fieldMeta(clause.field).options"
                        show-search
                        option-filter-prop="label"
                        style="flex: 1 1 120px"
                    />
                </template>

                <template v-else-if="['number', 'int'].includes(fieldMeta(clause.field)?.type)">
                    <InputNumber v-model:value="clause.value" style="flex: 1 1 120px; width: 100%" />
                </template>

                <template v-else-if="['date', 'datetime'].includes(fieldMeta(clause.field)?.type)">
                    <DatePicker
                        :value="clause.value ? dayjs(clause.value) : null"
                        @update:value="(d) => clause.value = d ? d.format('YYYY-MM-DD') : null"
                        :format="fieldMeta(clause.field).type === 'datetime' ? 'YYYY-MM-DD HH:mm' : 'YYYY-MM-DD'"
                        :show-time="fieldMeta(clause.field).type === 'datetime'"
                        style="flex: 1 1 140px; width: 100%"
                    />
                </template>

                <template v-else>
                    <Input
                        v-model:value="clause.value"
                        :placeholder="clause.op === 'in' ? $t('global.values_comma_separated') : ''"
                        style="flex: 1 1 120px"
                    />
                </template>

                <Button type="text" danger @click="removeClause(i)" class="adv-filter__remove">
                    <MinusCircleOutlined />
                </Button>
            </div>
        </div>

        <Button @click="addClause" type="dashed" block class="adv-filter__add">
            <PlusOutlined /> {{ $t('global.advanced_filters_add') }}
        </Button>

        <template #footer>
            <div class="adv-filter__footer">
                <Space>
                    <Button v-if="draft.length > 0" danger ghost @click="clearAll">
                        {{ $t('global.clear') }}
                    </Button>
                    <Button @click="cancel">{{ $t('global.cancel') }}</Button>
                    <Button type="primary" @click="apply">
                        {{ $t('global.apply') }}
                    </Button>
                </Space>
            </div>
        </template>
    </Drawer>
</template>

<style scoped>
.adv-filter__hint {
    font-size: 0.8125rem;
    color: var(--color-text-muted, #6a6d70);
    margin: 0 0 16px 0;
}
.adv-filter__empty {
    text-align: center;
    padding: 28px 12px;
    border: 1px dashed var(--color-border, #e1e3e5);
    border-radius: 6px;
    margin-bottom: 12px;
}
.adv-filter__empty p {
    margin: 8px 0 0 0;
    font-size: 0.875rem;
    color: var(--color-text-muted, #6a6d70);
}
.adv-filter__list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 12px;
}
.adv-filter__row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.adv-filter__remove {
    flex-shrink: 0;
    padding: 4px 8px;
}
.adv-filter__add { margin-top: 4px; }
.adv-filter__footer {
    display: flex;
    justify-content: flex-end;
}

@media (max-width: 600px) {
    .adv-filter__row { flex-direction: column; align-items: stretch; }
    .adv-filter__row > :deep(*) { width: 100% !important; }
    .adv-filter__remove { align-self: flex-end; }
}
</style>
