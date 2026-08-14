<script setup>
/**
 * Las respuestas posibles de un checklist, cada una con SU TONO.
 *
 * POR QUÉ EXISTE ESTE CONTROL Y NO VALE `StringListEditor`
 * ---------------------------------------------------------
 * Con la lista de textos a secas, el sistema tenía que adivinar cuál de las
 * respuestas significa «esto está mal», y lo adivinaba mirando si el texto
 * empieza por «no». Con Conforme/No conforme y Cumple/No cumple cuela; con el
 * catálogo de otra empresa se rompe, y siempre hacia el lado peligroso:
 *
 *   - «Rechazado», «Malo», «Deficiente», «Fail» salían clasificados CONFORMES —y
 *     como ninguna respuesta contaba como negativa, la pastilla de la tablet ni
 *     siquiera tenía a dónde ir para registrar el fallo.
 *   - «Normal» empieza por «no» y se contaba como no conformidad.
 *
 * De ese tono cuelgan el contador de observaciones que firma el supervisor, los
 * símbolos del PDF, el color de la casilla, los campos de medida de corrección y
 * el relleno de «no aplica» al cerrar. Eso no lo puede decidir una adivinanza en
 * castellano, así que aquí se pide.
 *
 * QUÉ SE GUARDA
 * -------------
 * La forma corta sigue siendo válida y es la que tienen los cuatro formatos
 * migrados y las 14 000 entregas:
 *
 *     ["Conforme", "No conforme", "No aplica"]
 *
 * En cuanto una respuesta declara su tono, esa entrada pasa a la forma larga y
 * las demás se quedan como estaban:
 *
 *     ["Conforme", {"value": "Rechazado", "tone": "bad"}]
 *
 * SE GUARDA LO MÍNIMO A PROPÓSITO. La alternativa era convertirlo todo a objetos
 * al primer guardado, y eso reescribiría la config de los cuatro formatos de
 * producción sin que nadie lo pidiera, en un módulo donde cada cambio es una
 * versión nueva del documento.
 *
 * EL VALOR NO SE TOCA AL TRADUCIR. Es la clave que se guarda en cada respuesta y
 * la que casa con lo ya llenado; lo que cambia con el idioma es el rótulo. Por
 * eso el recuadro del valor y el del rótulo son dos, y no uno.
 */
import { nextTick, ref } from 'vue';
import { Button, Input, Select, Tooltip } from 'ant-design-vue';
import {
    ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, PlusOutlined,
    TranslationOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    disabled:   { type: Boolean, default: false },
    /** Idiomas en los que se puede escribir el rótulo. Vienen de Inertia. */
    locales:    { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();

const entradas = ref(null);

/** Qué filas tienen abierta la fila de idiomas. Por índice. */
const traduciendo = ref(new Set());

/**
 * Los tres tonos, con la palabra que los explica.
 *
 * No se escriben las respuestas («Conforme») sino lo que el tono SIGNIFICA
 * («cuenta como conformidad»), porque cada formato llama distinto a lo mismo y
 * lo que hay que elegir aquí es el significado, no la palabra.
 */
const TONOS = [
    { value: 'ok',  label: () => t('form_templates.answer_tone_ok') },
    { value: 'bad', label: () => t('form_templates.answer_tone_bad') },
    { value: 'na',  label: () => t('form_templates.answer_tone_na') },
];

/** Una entrada cruda —cadena u objeto— leída siempre igual. */
function leer(entrada) {
    if (entrada === null || entrada === undefined) return { value: '', label: null, tone: null };

    if (typeof entrada !== 'object') return { value: String(entrada), label: null, tone: null };

    return {
        value: entrada.value === undefined || entrada.value === null ? '' : String(entrada.value),
        label: entrada.label && typeof entrada.label === 'object' ? { ...entrada.label } : null,
        tone:  entrada.tone ?? null,
    };
}

/**
 * Y de vuelta a la forma mínima: cadena si no hay nada que añadir.
 *
 * Es lo que impide que abrir el editor y guardar sin tocar nada convierta los
 * cuatro formatos de producción a la forma larga.
 */
function escribir({ value, label, tone }) {
    const traducciones = Object.fromEntries(
        Object.entries(label ?? {}).filter(([, texto]) => String(texto ?? '').trim() !== ''),
    );

    if (! tone && ! Object.keys(traducciones).length) return value;

    const entrada = { value };

    if (Object.keys(traducciones).length) entrada.label = traducciones;
    if (tone) entrada.tone = tone;

    return entrada;
}

const filas = () => props.modelValue.map(leer);

function emitir(lista) {
    emit('update:modelValue', lista.map(escribir));
}

function cambiar(i, parche) {
    const lista = filas();
    lista[i] = { ...lista[i], ...parche };
    emitir(lista);
}

function rotular(i, idioma, texto) {
    const lista = filas();
    lista[i] = { ...lista[i], label: { ...(lista[i].label ?? {}), [idioma]: texto } };
    emitir(lista);
}

function quitar(i) {
    emitir(filas().filter((_, j) => j !== i));
}

/** Mover es un intercambio con el vecino, igual que en la lista de textos. */
function mover(i, salto) {
    const destino = i + salto;

    if (destino < 0 || destino >= props.modelValue.length) return;

    const lista = filas();
    [lista[i], lista[destino]] = [lista[destino], lista[i]];
    emitir(lista);
}

function alternarIdiomas(i) {
    const abiertas = new Set(traduciendo.value);

    abiertas.has(i) ? abiertas.delete(i) : abiertas.add(i);
    traduciendo.value = abiertas;
}

async function anadir() {
    emitir([...filas(), { value: '', label: null, tone: null }]);
    await nextTick();
    entradas.value?.[props.modelValue.length - 1]?.focus?.();
}
</script>

<template>
    <div class="ale">
        <p v-if="!modelValue.length" class="ale__empty">{{ $t('form_templates.list_empty') }}</p>

        <!-- Por qué hay que elegir el tono, dicho antes de elegirlo. Sin esto,
             el desplegable parece un adorno y se deja en blanco. -->
        <p v-else class="ale__hint">{{ $t('form_templates.answer_tone_hint') }}</p>

        <div class="ale__rows">
            <div v-for="(fila, i) in filas()" :key="i" class="ale__row">
                <div class="ale__main">
                    <Input
                        ref="entradas"
                        :value="fila.value"
                        :disabled="disabled"
                        :placeholder="$t('form_templates.answer_value_placeholder')"
                        :maxlength="180"
                        @update:value="cambiar(i, { value: $event })"
                    />

                    <Select
                        class="ale__tone"
                        :value="fila.tone"
                        :disabled="disabled"
                        :placeholder="$t('form_templates.answer_tone_guess')"
                        allow-clear
                        :options="TONOS.map((x) => ({ value: x.value, label: x.label() }))"
                        @update:value="cambiar(i, { tone: $event ?? null })"
                    />

                    <Tooltip v-if="locales.length > 1" :title="$t('form_templates.answer_labels')">
                        <Button class="ale__btn" :disabled="disabled" @click="alternarIdiomas(i)">
                            <TranslationOutlined />
                        </Button>
                    </Tooltip>
                    <Tooltip :title="$t('form_templates.move_up')">
                        <Button class="ale__btn" :disabled="disabled || i === 0" @click="mover(i, -1)">
                            <ArrowUpOutlined />
                        </Button>
                    </Tooltip>
                    <Tooltip :title="$t('form_templates.move_down')">
                        <Button class="ale__btn" :disabled="disabled || i === modelValue.length - 1" @click="mover(i, 1)">
                            <ArrowDownOutlined />
                        </Button>
                    </Tooltip>
                    <Tooltip :title="$t('global.delete')">
                        <Button class="ale__btn ale__btn--danger" :disabled="disabled" @click="quitar(i)">
                            <DeleteOutlined />
                        </Button>
                    </Tooltip>
                </div>

                <!-- Los rótulos por idioma, plegados: la mayoría de los formatos
                     son de un solo idioma y esto sólo estorbaría. Lo que se
                     escribe aquí es lo que se LEE; lo de arriba es lo que se
                     GUARDA, y no cambia al traducir. -->
                <div v-if="traduciendo.has(i)" class="ale__langs">
                    <label v-for="idioma in locales" :key="idioma.code" class="ale__lang">
                        <span class="ale__langcode">{{ idioma.code }}</span>
                        <Input
                            :value="fila.label?.[idioma.code] ?? ''"
                            :disabled="disabled"
                            :placeholder="fila.value"
                            :maxlength="180"
                            @update:value="rotular(i, idioma.code, $event)"
                        />
                    </label>
                </div>
            </div>
        </div>

        <Button v-if="!disabled" type="dashed" class="ale__add" @click="anadir">
            <PlusOutlined /> {{ $t('form_templates.list_add') }}
        </Button>
    </div>
</template>

<style scoped>
.ale { display: flex; flex-direction: column; gap: 6px; }

.ale__empty,
.ale__hint {
    margin: 0 0 2px;
    font-size: 0.8125rem;
    color: var(--color-text-dim);
}

.ale__rows { display: flex; flex-direction: column; gap: 10px; }
.ale__row { display: flex; flex-direction: column; gap: 6px; }

.ale__main { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ale__main :deep(.ant-input) { flex: 1 1 12rem; min-width: 0; }

.ale__tone { flex: 0 0 auto; min-width: 13rem; }
.ale__tone :deep(.ant-select-selector) { height: 44px !important; align-items: center; }

/* 44px: el objetivo de toque con guantes (docs/UI.md §3). */
.ale__btn {
    flex: 0 0 auto;
    width: 44px; height: 44px;
    display: inline-flex; align-items: center; justify-content: center;
    padding: 0;
}
.ale__btn--danger:not(:disabled) { color: var(--color-danger); }

.ale__langs {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
    gap: 6px;
    padding: 8px 10px;
    border-left: 3px solid var(--color-border-soft);
    background: var(--color-surface-hover, #f5f9fe);
    border-radius: 0 6px 6px 0;
}
.ale__lang { display: flex; align-items: center; gap: 8px; }
.ale__langcode {
    flex: 0 0 auto;
    min-width: 2.2rem;
    font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
    color: var(--color-text-muted);
}

.ale__add { align-self: flex-start; height: 44px; }
</style>
