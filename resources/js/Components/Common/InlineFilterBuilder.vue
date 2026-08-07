<script setup>
/**
 * InlineFilterBuilder — filtros tipo Airtable/Linear: filas { field, op, value }
 * que viven INLINE bajo el buscador (no en un drawer). Cada fila tiene editor
 * tipado según el schema del backend (enum→select, number→InputNumber, date→
 * DatePicker, enum+in→multiselect). Aplica en vivo: emite `change` con las
 * cláusulas COMPLETAS cada vez que el usuario edita; el padre debouncea + recarga.
 *
 * v-model = array de cláusulas (incluye las incompletas que se están editando).
 */
import { computed } from 'vue';
import { Select, Input, InputNumber, DatePicker, Button, Tooltip } from 'ant-design-vue';
import { PlusOutlined, MinusCircleOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

const props = defineProps({
    schema:     { type: Array, default: () => [] },
    modelValue: { type: Array, default: () => [] },
    // Conector POR CLÁUSULA (estilo RENATI): cada fila (menos la 1ª) lleva su
    // propio 'conj' ('and'|'or') guardado en la cláusula. Opt-in con
    // showConjunction (default off → otros módulos quedan en AND plano).
    showConjunction: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'change']);

const rows = computed(() => props.modelValue ?? []);

const fieldOptions = computed(() => (props.schema ?? []).map(f => ({ value: f.key, label: f.label })));
const fieldMeta = (key) => (props.schema ?? []).find(f => f.key === key) ?? null;

const ALL_OPS = computed(() => ([
    { value: '=',        label: '=' },
    { value: '!=',       label: '≠' },
    { value: '>',        label: '>' },
    { value: '<',        label: '<' },
    { value: '>=',       label: '≥' },
    { value: '<=',       label: '≤' },
    { value: 'contains', label: t('global.contains') },
    { value: 'in',       label: t('global.in_list') },
]));
const operatorsFor = (key) => {
    const meta = fieldMeta(key);
    if (!meta?.operators) return ALL_OPS.value;
    return ALL_OPS.value.filter(o => meta.operators.includes(o.value));
};

const defaultValue = (meta, op) => {
    if (op === 'in') return [];
    if (!meta) return '';
    if (meta.type === 'enum' || meta.type === 'boolean') return undefined;
    if (meta.type === 'number' || meta.type === 'int') return null;
    if (meta.type === 'date' || meta.type === 'datetime') return null;
    return '';
};

// Qué editor de valor mostrar para una cláusula.
const valueKind = (clause) => {
    const meta = fieldMeta(clause.field);
    if (!meta) return 'text';
    if (clause.op === 'in') return meta.type === 'enum' ? 'multiselect' : 'tags';
    if (meta.type === 'enum') return 'select';
    if (meta.type === 'boolean') return 'boolean';
    if (meta.type === 'number' || meta.type === 'int') return 'number';
    if (meta.type === 'date' || meta.type === 'datetime') return 'date';
    return 'text';
};

// Una cláusula está "completa" (lista para enviar al server) si tiene valor real.
const isComplete = (c) => {
    if (!c.field || !c.op) return false;
    if (Array.isArray(c.value)) return c.value.length > 0;
    return c.value !== '' && c.value !== null && c.value !== undefined;
};

const emitChange = (next) => {
    emit('update:modelValue', next);
    emit('change', next.filter(isComplete));
};

const addRow = () => {
    const first = props.schema?.[0];
    if (!first) return;
    const op = operatorsFor(first.key)[0]?.value ?? '=';
    // No emite change (fila nueva incompleta), solo actualiza el v-model.
    emit('update:modelValue', [...rows.value, { field: first.key, op, value: defaultValue(first, op) }]);
};

const removeRow = (i) => {
    const next = rows.value.slice();
    next.splice(i, 1);
    emitChange(next);
};

const setField = (i, field) => {
    const op = operatorsFor(field)[0]?.value ?? '=';
    const next = rows.value.slice();
    next[i] = { field, op, value: defaultValue(fieldMeta(field), op) };
    emitChange(next);
};
const setOp = (i, op) => {
    const next = rows.value.slice();
    const cur = next[i];
    const switchedInOut = (cur.op === 'in') !== (op === 'in');
    next[i] = { ...cur, op, value: switchedInOut ? defaultValue(fieldMeta(cur.field), op) : cur.value };
    emitChange(next);
};
const setValue = (i, value) => {
    const next = rows.value.slice();
    next[i] = { ...next[i], value };
    emitChange(next);
};
// Conector de la fila i (i>0): se guarda EN la cláusula como 'conj'.
const setRowConj = (i, conj) => {
    const next = rows.value.slice();
    next[i] = { ...next[i], conj: conj === 'or' ? 'or' : 'and' };
    emitChange(next);
};

// Permite al padre agregar una fila de filtro programáticamente (ej. al abrir
// el panel de filtros con el icono → arranca con un filtro activado).
defineExpose({ addRow });
</script>

<template>
    <div class="ifb">
        <!-- Ayuda del conector: solo cuando hay >1 condición (el Y/O recién
             aparece en la 2ª fila). Una vez, arriba, muteada. -->
        <p v-if="showConjunction && rows.length > 1" class="ifb__hint">
            {{ $t('global.filter_conj_hint') }}
        </p>
        <div
            v-for="(clause, i) in rows"
            :key="i"
            class="ifb__row"
        >
            <!-- Conector estilo RENATI: la 1ª fila dice "Donde"; cada fila
                 siguiente tiene su PROPIO dropdown Y/O (independiente, guardado en
                 la cláusula). Solo con showConjunction. -->
            <template v-if="showConjunction">
                <span v-if="i === 0" class="ifb__conn-lead">{{ $t('global.filter_where') }}</span>
                <Select
                    v-else
                    :value="clause.conj === 'or' ? 'or' : 'and'"
                    @change="(v) => setRowConj(i, v)"
                    :options="[
                        { value: 'and', label: $t('global.filter_and') },
                        { value: 'or',  label: $t('global.filter_or') },
                    ]"
                    size="small"
                    class="ifb__conn"
                />
            </template>

            <Select
                :value="clause.field"
                @change="(v) => setField(i, v)"
                :options="fieldOptions"
                show-search
                option-filter-prop="label"
                class="ifb__field"
            />
            <Select
                :value="clause.op"
                @change="(v) => setOp(i, v)"
                :options="operatorsFor(clause.field)"
                class="ifb__op"
            />

            <Select
                v-if="valueKind(clause) === 'select'"
                :value="clause.value"
                @change="(v) => setValue(i, v)"
                :options="fieldMeta(clause.field)?.options"
                show-search
                option-filter-prop="label"
                :placeholder="$t('global.select')"
                class="ifb__val"
            />
            <Select
                v-else-if="valueKind(clause) === 'multiselect'"
                :value="clause.value"
                @change="(v) => setValue(i, v)"
                :options="fieldMeta(clause.field)?.options"
                mode="multiple"
                show-search
                option-filter-prop="label"
                :max-tag-count="3"
                :placeholder="$t('global.select')"
                class="ifb__val"
            />
            <Select
                v-else-if="valueKind(clause) === 'boolean'"
                :value="clause.value"
                @change="(v) => setValue(i, v)"
                :options="[
                    { value: true,  label: $t('global.active') },
                    { value: false, label: $t('global.inactive') },
                ]"
                :placeholder="$t('global.select')"
                class="ifb__val"
            />
            <InputNumber
                v-else-if="valueKind(clause) === 'number'"
                :value="clause.value"
                @update:value="(v) => setValue(i, v)"
                class="ifb__val"
            />
            <DatePicker
                v-else-if="valueKind(clause) === 'date'"
                :value="clause.value ? dayjs(clause.value) : null"
                @update:value="(d) => setValue(i, d ? d.format('YYYY-MM-DD') : null)"
                class="ifb__val"
            />
            <Select
                v-else-if="valueKind(clause) === 'tags'"
                :value="clause.value"
                @change="(v) => setValue(i, v)"
                mode="tags"
                :placeholder="$t('global.values_comma_separated')"
                :open="false"
                class="ifb__val"
            />
            <Input
                v-else
                :value="clause.value"
                @update:value="(v) => setValue(i, v)"
                allow-clear
                class="ifb__val"
            />

            <Tooltip :title="$t('global.delete')">
                <Button type="text" danger class="ifb__remove" @click="removeRow(i)">
                    <MinusCircleOutlined />
                </Button>
            </Tooltip>
        </div>

        <div class="ifb__addrow">
            <!-- Sin Tooltip: el botón ya está etiquetado y el tooltip (placement
                 right, texto largo) se superponía a "Guardar filtro" y bloqueaba
                 el click en pantallas chicas. -->
            <Button type="dashed" class="ifb__add" @click="addRow">
                <PlusOutlined /> {{ $t('global.advanced_filters_add') }}
            </Button>
            <!-- Acciones extra junto a "Agregar filtro" (ej. Limpiar filtros). -->
            <slot name="actions" />
        </div>
    </div>
</template>

<style scoped>
.ifb { display: flex; flex-direction: column; gap: 8px; }
.ifb__hint { font-size: 0.75rem; color: var(--color-text-muted, #6A6D70); margin: 0 0 2px; }
/* Conector estilo Airtable: "Donde" en la 1ª fila, dropdown Y/O en las siguientes. */
.ifb__conn { flex: 0 0 78px; }
.ifb__conn-lead { flex: 0 0 78px; font-size: 0.8rem; color: var(--color-text-muted, #6A6D70); padding-left: 4px; }
/* Filas compactas y alineadas a la izquierda: no estirar los inputs a todo
   el ancho (eso hacia que el panel se viera vacio/pesado). */
.ifb__row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ifb__field { flex: 0 0 200px; min-width: 160px; }
.ifb__op    { flex: 0 0 130px; }
.ifb__val   { flex: 1 1 360px; min-width: 220px; }
.ifb__remove { flex-shrink: 0; }
.ifb__add { flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px; }
.ifb__addrow { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; align-self: flex-start; margin-top: 2px; }

@media (max-width: 720px) {
    .ifb__row { flex-direction: column; align-items: stretch; }
    /* Incluir .ifb__conn: si no, su `flex: 0 0 78px` se interpreta como ALTURA
       al apilar en columna y descuadra el select (la flecha quedaba afuera). */
    .ifb__field, .ifb__op, .ifb__val, .ifb__conn { flex: 0 0 auto; width: 100%; }
    .ifb__conn-lead { flex: 0 0 auto; }
    .ifb__remove { align-self: flex-end; }
}
</style>
