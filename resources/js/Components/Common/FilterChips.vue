<script setup>
import { computed } from 'vue';
import { Tag, Button } from 'ant-design-vue';
import { ClearOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';

/**
 * FilterChips — barra inline de filtros activos con X para quitar uno o todos.
 *
 * Pensado para vivir SOBRE el listado de resultados (estilo SAP Fiori),
 * separado del FilterBar que tiene los inputs. Esto deja siempre visible
 * "qué está limitando los resultados" sin tener que abrir un drawer ni
 * mirar adentro del FilterBar.
 *
 * Usage:
 *   <FilterChips
 *       :fields="filterFields"
 *       v-model="filters"
 *   />
 *
 * Recibe la misma `fields` array que FilterBar (mismo contrato) y emite
 * `update:modelValue` cuando se borra uno o todos los chips.
 *
 * Si no hay filtros activos, no renderiza nada (auto-hides).
 */

const props = defineProps({
    fields:     { type: Array,  required: true },
    modelValue: { type: Object, required: true },
    /** Mostrar el botón "Limpiar todo" al final si hay >= 2 chips activos. */
    clearAll:   { type: Boolean, default: true },
});
const emit = defineEmits(['update:modelValue']);

// ─── Helpers ──────────────────────────────────────────────────────────────
const isFieldActive = (field) => {
    const v = props.modelValue[field.key];
    if (v === undefined || v === null || v === '') return false;
    if (Array.isArray(v)) return v.length > 0;
    // Un switch apagado (false) es el default, NO un filtro activo. Sin esto
    // aparecía un chip "Solo favoritos: false" aunque el usuario no filtrara.
    if (field.type === 'switch' && v === false) return false;
    return true;
};

const activeFields = computed(() => props.fields.filter(f => isFieldActive(f)));

const emptyValueFor = (field) => {
    switch (field.type) {
        case 'tags':
        case 'multiselect':
            return [];
        case 'date_range':
        case 'number_range':
            return null;
        default:
            return undefined;
    }
};

const clearField = (field) => {
    const next = { ...props.modelValue, [field.key]: emptyValueFor(field) };
    emit('update:modelValue', next);
};

const clearAllFields = () => {
    const next = { ...props.modelValue };
    props.fields.forEach(f => { next[f.key] = emptyValueFor(f); });
    emit('update:modelValue', next);
};

const chipLabel = (field) => {
    const v = props.modelValue[field.key];
    switch (field.type) {
        case 'tags':
        case 'multiselect': {
            if (!Array.isArray(v) || v.length === 0) return '';
            const labels = field.type === 'multiselect'
                ? v.map(val => field.options?.find(o => o.value === val)?.label ?? val)
                : v;
            if (labels.length <= 2) return labels.join(', ');
            return `${labels[0]} +${labels.length - 1}`;
        }
        case 'select': {
            const opt = field.options?.find(o => o.value === v);
            return opt?.label ?? String(v);
        }
        case 'date_range': {
            if (!v || v.length !== 2) return '';
            const fmt = (d) => dayjs.isDayjs(d) ? d.format('DD/MM/YY') : dayjs(d).format('DD/MM/YY');
            return `${fmt(v[0])} – ${fmt(v[1])}`;
        }
        case 'date':
            return dayjs.isDayjs(v) ? v.format('DD/MM/YY') : dayjs(v).format('DD/MM/YY');
        case 'number_range':
            if (!v || v.length !== 2) return '';
            return `${v[0] ?? '∞'} – ${v[1] ?? '∞'}`;
        default:
            return String(v);
    }
};
</script>

<template>
    <div v-if="activeFields.length > 0" class="filter-chips-bar">
        <span class="filter-chips-bar__label">{{ $t('global.filters_active') }}:</span>
        <Tag
            v-for="field in activeFields"
            :key="field.key"
            :bordered="false"
            color="processing"
            class="filter-chips-bar__chip"
            closable
            @close.prevent="clearField(field)"
        >
            <strong>{{ field.label }}:</strong>
            <span class="filter-chips-bar__value">{{ chipLabel(field) }}</span>
        </Tag>
        <Button
            v-if="clearAll && activeFields.length >= 2"
            size="small"
            type="link"
            class="filter-chips-bar__clear-all"
            @click="clearAllFields"
        >
            <ClearOutlined /> {{ $t('global.clear_all') }}
        </Button>
    </div>
</template>

<style scoped>
.filter-chips-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 8px;
    padding: 10px 14px;
    margin: 0 0 12px 0;
    background: #F0F6FB;
    border: 1px solid #B5D7F4;
    border-radius: 6px;
}
.filter-chips-bar__label {
    font-size: 0.78rem;
    color: #0A6ED1;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-right: 4px;
}
.filter-chips-bar__chip {
    font-size: 0.8rem;
    padding: 4px 10px;
    margin: 0;
}
.filter-chips-bar__value {
    margin-left: 6px;
    font-weight: 600;
}
.filter-chips-bar__clear-all {
    margin-left: auto;
    font-size: 0.8rem;
    padding: 0 6px;
    height: 26px;
}
</style>

<style>
/* Dark mode (no scoped: el Tag de Ant porta sus propios estilos) */
html[data-theme="dark"] .filter-chips-bar {
    background: rgba(77, 182, 232, 0.08);
    border-color: rgba(77, 182, 232, 0.30);
}
html[data-theme="dark"] .filter-chips-bar__label {
    color: #4db6e8;
}
</style>
