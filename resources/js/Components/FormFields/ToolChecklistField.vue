<script setup>
/**
 * Checklist por herramienta (IHM).
 *
 * Igual que el EPP en el papel: una columna por punto de inspeccion y una fila
 * por herramienta. Aqui la herramienta la añade el usuario —no viene del plan—
 * y cada una es una tarjeta con sus puntos debajo.
 *
 * FORMA DEL VALOR que emite (una respuesta por herramienta, con su `row`):
 *
 *   {
 *     tool,
 *     items: [{ item, answer }, ...],
 *     conforme: bool,
 *     ...config.extra
 *   }
 *
 * Es una lista no vacia, que es lo que exige validarValor() para este tipo.
 */
import { computed } from 'vue';
import { Button } from 'ant-design-vue';
import { CheckOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons-vue';
import AnswerToggle from './AnswerToggle.vue';
import CatalogSelect from './CatalogSelect.vue';
import ExtraFields from './ExtraFields.vue';
import { catalogo, filaConforme, respondidos, respuestaPositiva } from './respuestas';

const props = defineProps({
    field:    { type: Object, required: true },
    value:    { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['update:value']);

const config = computed(() => props.field?.config ?? {});
const herramientas = computed(() => catalogo(config.value, 'tools'));
const puntos = computed(() => catalogo(config.value, 'items'));
const respuestas = computed(() => catalogo(config.value, 'answers'));
const extras = computed(() => catalogo(config.value, 'extra'));

const filas = computed(() => (Array.isArray(props.value) ? props.value : []));

function filaVacia() {
    return {
        tool: null,
        items: puntos.value.map((item) => ({ item, answer: null })),
        conforme: true,
    };
}

function publicar(nuevas) {
    emit('update:value', nuevas.map((fila) => ({
        ...fila,
        conforme: filaConforme(fila.items),
    })));
}

function responder(indice, item, respuesta) {
    publicar(filas.value.map((fila, i) => (i !== indice ? fila : {
        ...fila,
        items: (fila.items ?? []).map((x) => (x.item === item ? { ...x, answer: respuesta } : x)),
    })));
}

function marcarTodo(indice) {
    const positiva = respuestaPositiva(respuestas.value);

    publicar(filas.value.map((fila, i) => (i !== indice ? fila : {
        ...fila,
        items: (fila.items ?? []).map((x) => ({ ...x, answer: positiva })),
    })));
}

function cambiar(indice, clave, valor) {
    publicar(filas.value.map((fila, i) => (i !== indice ? fila : { ...fila, [clave]: valor })));
}

function agregar() {
    publicar([...filas.value, filaVacia()]);
}

function quitar(indice) {
    emit('update:value', filas.value.filter((_, i) => i !== indice));
}

const avance = (fila) => `${respondidos(fila.items)}/${(fila.items ?? []).length}`;
</script>

<template>
    <div class="ff-field">
        <p v-if="!filas.length" class="ff-empty">{{ $t('field_work.tool_checklist.empty') }}</p>

        <article v-for="(fila, i) in filas" :key="i" class="ff-row">
            <header class="ff-row__head">
                <span class="ff-row__num">{{ i + 1 }}</span>

                <span class="ff-row__title ff-row__title--field">
                    <CatalogSelect
                        :value="fila.tool" :options="herramientas" :readonly="readonly"
                        :placeholder="$t('field_work.tool_checklist.tool')"
                        @update:value="cambiar(i, 'tool', $event)" />
                </span>

                <span class="ff-count" :class="{ 'is-done': respondidos(fila.items) === (fila.items ?? []).length }">
                    {{ avance(fila) }}
                </span>

                <Button v-if="!readonly" size="large" class="ff-row__all" @click="marcarTodo(i)">
                    <template #icon><CheckOutlined /></template>
                    {{ $t('field_work.mark_all') }}
                </Button>

                <Button
                    v-if="!readonly"
                    class="ff-row__del"
                    danger
                    size="large"
                    :title="$t('field_work.tool_checklist.remove_tool')"
                    :aria-label="$t('field_work.tool_checklist.remove_tool')"
                    @click="quitar(i)"
                >
                    <template #icon><DeleteOutlined /></template>
                </Button>
            </header>

            <ul class="ff-items">
                <li v-for="x in (fila.items ?? [])" :key="x.item" class="ff-item">
                    <span class="ff-item__name">{{ x.item }}</span>
                    <AnswerToggle
                        :value="x.answer" :answers="respuestas" :readonly="readonly" :label="x.item"
                        @update:value="responder(i, x.item, $event)" />
                </li>
            </ul>

            <section v-if="extras.length && !fila.conforme" class="ff-correction">
                <h4 class="ff-correction__title">{{ $t('field_work.correction_title') }}</h4>
                <ExtraFields
                    :keys="extras" :values="fila" :readonly="readonly"
                    @change="(clave, valor) => cambiar(i, clave, valor)" />
            </section>
        </article>

        <Button v-if="!readonly" class="ff-add" size="large" block @click="agregar">
            <template #icon><PlusOutlined /></template>
            {{ $t('field_work.tool_checklist.add_tool') }}
        </Button>
    </div>
</template>
