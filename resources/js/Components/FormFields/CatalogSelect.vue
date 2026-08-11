<script setup>
/**
 * Texto libre con sugerencias de catalogo, como la v1.
 *
 * Fue un Select cerrado y estuvo mal: la v1 captura actividad, peligro,
 * riesgo, control y herramienta en un TEXTAREA libre con autocompletado
 * (`.danger-autocomplete` y familia sobre jQuery UI), y guarda lo tecleado,
 * este o no en el catalogo. Los datos lo confirman: de 3 561 peligros
 * distintos escritos en los AST reales, 3 492 NO estan en `ast_dangers` — el
 * catalogo sugiere, no manda. Un select cerrado dejaba ineditable casi todo
 * el historico migrado y no dejaba escribir nada propio.
 *
 * Las sugerencias filtran por subcadena mientras se escribe y elegir una la
 * copia al texto — que sigue siendo texto y se puede retocar despues, que es
 * exactamente lo que el select no permitia. Va en tamaño grande porque el
 * objetivo de toque tiene que ser comodo con guantes.
 */
import { AutoComplete, Textarea } from 'ant-design-vue';

const props = defineProps({
    value:       { type: [String, null], default: null },
    options:     { type: Array, default: () => [] },
    placeholder: { type: String, default: '' },
    readonly:    { type: Boolean, default: false },
});

const emit = defineEmits(['update:value']);

/** Vacio es null, como cuando esto era un select: el espejo de faltantes
 *  (`faltasDe`) y el servidor (`exigirPeligroEntero`) preguntan por hueco. */
const cambiar = (texto) => emit('update:value', (texto ?? '').trim() === '' ? null : texto);

const filtrar = (escrito, opcion) =>
    String(opcion.value).toLowerCase().includes(String(escrito).toLowerCase());
</script>

<template>
    <span v-if="readonly" class="ff-readonly">{{ value || '—' }}</span>

    <AutoComplete
        v-else
        class="ff-libre"
        :value="value ?? ''"
        :options="options.map((o) => ({ value: o }))"
        :filter-option="filtrar"
        @update:value="cambiar"
    >
        <Textarea
            :placeholder="placeholder"
            :auto-size="{ minRows: 1, maxRows: 5 }"
            size="large"
        />
    </AutoComplete>
</template>
