<script setup>
/**
 * Select sobre un catalogo del sistema anterior.
 *
 * Los catalogos del AST son largos de verdad (127 actividades, 83 peligros, 51
 * controles): sin buscador dentro del select no se puede usar en obra. Va en
 * tamaño grande porque el objetivo de toque tiene que ser comodo con guantes.
 */
import { computed } from 'vue';
import { Select } from 'ant-design-vue';

const props = defineProps({
    value:       { type: [String, null], default: null },
    options:     { type: Array, default: () => [] },
    placeholder: { type: String, default: '' },
    readonly:    { type: Boolean, default: false },
});

const emit = defineEmits(['update:value']);

const opciones = computed(() => props.options.map((o) => ({ value: o, label: o })));
</script>

<template>
    <span v-if="readonly" class="ff-readonly">{{ value || '—' }}</span>

    <Select
        v-else
        class="ff-select"
        :value="value"
        :options="opciones"
        :placeholder="placeholder"
        size="large"
        show-search
        allow-clear
        option-filter-prop="label"
        @update:value="emit('update:value', $event ?? null)"
    />
</template>
