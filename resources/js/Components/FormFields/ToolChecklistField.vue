<script setup>
/**
 * Checklist por herramienta (IHM).
 *
 * Igual que el EPP en el papel: una columna por punto de inspeccion y una fila
 * por herramienta. Aqui la herramienta la añade el usuario —no viene del plan—
 * y cada una es una tarjeta plegable con sus puntos debajo, con el mismo indice
 * de arriba que el EPP: los tres campos compuestos son la misma anatomia y no
 * pueden volver a divergir (docs/UI.md §4-bis).
 *
 * El nombre de la herramienta baja al cuerpo de la tarjeta y deja de vivir en
 * la cabecera. La cabecera entera pasa a ser el boton de plegado, y un
 * desplegable dentro de un boton no se puede usar; ademas asi la cabecera dice
 * lo mismo en los tres campos: numero, titulo, avance y estado.
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
import {
    ArrowRightOutlined, CheckOutlined, DeleteOutlined, DownOutlined, PlusOutlined, RightOutlined,
} from '@ant-design/icons-vue';
import AnswerToggle from './AnswerToggle.vue';
import CatalogSelect from './CatalogSelect.vue';
import ExtraFields from './ExtraFields.vue';
import RowNavigator from './RowNavigator.vue';
import { usePlegado } from './plegado';
import {
    catalogo, estadoChecklist, filaConforme, respondidos, respuestaPositiva, textoEstado,
} from './respuestas';
import { useI18n } from '@/Plugins/i18n';

const props = defineProps({
    field:    { type: Object, required: true },
    value:    { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['update:value']);

const { t } = useI18n();

const config = computed(() => props.field?.config ?? {});
const herramientas = computed(() => catalogo(config.value, 'tools'));
const puntos = computed(() => catalogo(config.value, 'items'));
const respuestas = computed(() => catalogo(config.value, 'answers'));
const extras = computed(() => catalogo(config.value, 'extra'));

const filas = computed(() => (Array.isArray(props.value) ? props.value : []));

const { todas, idFila, estaAbierta, abierta, abrir, alternar, alternarTodo, cerrar } =
    usePlegado(`ihm-${props.field?.id ?? 'x'}`);

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

/** La herramienta recien añadida se abre sola: hay que escribir su nombre. */
function agregar() {
    publicar([...filas.value, filaVacia()]);
    abrir(filas.value.length);
}

/**
 * Al quitar una fila se pliega todo.
 *
 * La fila abierta se guarda por posicion, y al borrar la 2 de cuatro lo que
 * antes era la 3 pasa a ser la 2: sin esto se quedaria abierta una herramienta
 * distinta de la que se acaba de borrar, justo donde estaba el boton de
 * borrar. Plegar es lo unico que no engaña.
 */
function quitar(indice) {
    emit('update:value', filas.value.filter((_, i) => i !== indice));
    cerrar();
}

const avance = (fila) => `${respondidos(fila.items)}/${(fila.items ?? []).length}`;

const estados = computed(() => filas.value.map((fila) => estadoChecklist(fila.items ?? [])));

/** Sin nombre todavia, la herramienta se llama por su numero. */
const titulo = (fila, i) => fila.tool || t('field_work.progress.row', { n: i + 1 });

/** Ver la nota de PersonChecklistField: `$t` no se puede pasar como argumento. */
const textos = computed(() => estados.value.map((e) => textoEstado(t, e)));

const resumenFilas = computed(() => filas.value.map((fila, i) => ({
    key: i,
    label: titulo(fila, i),
    state: estados.value[i].clave,
    stateText: textos.value[i],
})));

const hechos = computed(() => estados.value.reduce((n, e) => n + e.hechos, 0));
const totales = computed(() => estados.value.reduce((n, e) => n + e.total, 0));
const completas = computed(() => estados.value.filter((e) => e.total > 0 && e.faltan === 0).length);

function siguientePendiente(indice) {
    const n = filas.value.length;

    for (let salto = 1; salto < n; salto++) {
        const j = (indice + salto) % n;

        if (estados.value[j].faltan > 0) return j;
    }

    return null;
}
</script>

<template>
    <div class="ff-field">
        <p v-if="!filas.length" class="ff-empty">{{ $t('field_work.tool_checklist.empty') }}</p>

        <RowNavigator
            v-if="filas.length > 1"
            :rows="resumenFilas"
            :active="todas ? null : abierta"
            :summary="$t('field_work.progress.tools_done', { done: completas, total: filas.length })"
            :detail="$t('field_work.progress.checks', { done: hechos, total: totales })"
            :ratio="totales ? hechos / totales : 0"
            :all-open="todas"
            :label="$t('field_work.progress.index_tools')"
            @select="abrir"
            @toggle-all="alternarTodo"
        />

        <article
            v-for="(fila, i) in filas"
            :id="idFila(i)"
            :key="i"
            class="ff-row"
            :class="{ 'is-open': estaAbierta(i) }"
        >
            <button
                type="button"
                class="ff-row__head ff-row__head--toggle"
                :aria-expanded="estaAbierta(i)"
                :aria-controls="`${idFila(i)}-cuerpo`"
                @click="alternar(i)"
            >
                <component :is="estaAbierta(i) ? DownOutlined : RightOutlined" class="ff-row__chev" />

                <span class="ff-row__num">{{ i + 1 }}</span>

                <span class="ff-row__title">{{ titulo(fila, i) }}</span>

                <span class="ff-count" :class="{ 'is-done': estados[i].clave === 'ok' }">{{ avance(fila) }}</span>

                <span class="ff-state" :class="`is-${estados[i].clave}`">{{ textos[i] }}</span>
            </button>

            <div v-if="estaAbierta(i)" :id="`${idFila(i)}-cuerpo`">
                <div class="ff-row__body">
                    <div class="ff-cell ff-cell--wide">
                        <label class="ff-label">{{ $t('field_work.tool_checklist.tool') }}</label>
                        <CatalogSelect
                            :value="fila.tool" :options="herramientas" :readonly="readonly"
                            :placeholder="$t('field_work.tool_checklist.tool')"
                            @update:value="cambiar(i, 'tool', $event)" />
                    </div>
                </div>

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

                <!-- Igual que en el EPP: el salto al siguiente se ofrece, nunca
                     se hace solo. Ver el comentario largo de PersonChecklistField. -->
                <footer v-if="!readonly" class="ff-row__foot">
                    <Button size="large" class="ff-row__all" @click="marcarTodo(i)">
                        <template #icon><CheckOutlined /></template>
                        {{ $t('field_work.mark_all') }}
                    </Button>

                    <Button
                        class="ff-row__del"
                        danger
                        size="large"
                        :title="$t('field_work.tool_checklist.remove_tool')"
                        @click="quitar(i)"
                    >
                        <template #icon><DeleteOutlined /></template>
                        {{ $t('field_work.tool_checklist.remove_tool') }}
                    </Button>

                    <Button
                        v-if="siguientePendiente(i) !== null"
                        size="large"
                        class="ff-row__next"
                        :type="estados[i].faltan === 0 ? 'primary' : 'default'"
                        @click="abrir(siguientePendiente(i))"
                    >
                        {{ $t('field_work.progress.next', {
                            name: titulo(filas[siguientePendiente(i)], siguientePendiente(i)),
                        }) }}
                        <!-- Detras del texto, como en el EPP: ver el comentario allí. -->
                        <ArrowRightOutlined class="ff-row__next-icon" />
                    </Button>
                </footer>
            </div>
        </article>

        <Button v-if="!readonly" class="ff-add" size="large" block @click="agregar">
            <template #icon><PlusOutlined /></template>
            {{ $t('field_work.tool_checklist.add_tool') }}
        </Button>
    </div>
</template>
