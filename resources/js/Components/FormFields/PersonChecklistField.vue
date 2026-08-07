<script setup>
/**
 * Checklist por persona (EPP).
 *
 * En el papel esto era una tabla con una columna por item de proteccion y el
 * nombre del item escrito en vertical para que cupieran: ilegible en una
 * tablet. Aqui es una tarjeta por trabajador del plan con sus items debajo.
 *
 * Las filas NO las elige el usuario: son los trabajadores de la cuadrilla del
 * plan, que es de donde salian en el sistema anterior (plan_workers).
 *
 * FORMA DEL VALOR que emite (una respuesta por trabajador, con su `row`):
 *
 *   {
 *     person_slug, person_name, person_doc,
 *     items: [{ item, answer }, ...],
 *     conforme: bool,
 *     ...config.extra   // correction_measure, deadline_date, ...
 *   }
 *
 * Es una lista no vacia, que es lo que exige validarValor() para este tipo.
 */
import { computed } from 'vue';
import { Alert, Button } from 'ant-design-vue';
import { CheckOutlined } from '@ant-design/icons-vue';
import AnswerToggle from './AnswerToggle.vue';
import ExtraFields from './ExtraFields.vue';
import { catalogo, filaConforme, respondidos, respuestaPositiva } from './respuestas';

const props = defineProps({
    field:    { type: Object, required: true },
    value:    { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
    /** Cuadrilla del plan: una fila por trabajador. */
    people:   { type: Array, default: () => [] },
});

const emit = defineEmits(['update:value']);

const config = computed(() => props.field?.config ?? {});
const items = computed(() => catalogo(config.value, 'items'));
const respuestas = computed(() => catalogo(config.value, 'answers'));
const extras = computed(() => catalogo(config.value, 'extra'));

function filaVacia(persona) {
    return {
        person_slug: persona?.slug ?? null,
        person_name: persona?.name ?? '',
        person_doc:  persona?.doc ?? '',
        items: items.value.map((item) => ({ item, answer: null })),
        conforme: true,
    };
}

/**
 * Las filas se recomponen contra la cuadrilla actual: si alguien entro al plan
 * despues de guardar, aparece con su fila vacia en vez de desaparecer. Se busca
 * por slug de persona y, si la entrega es vieja y no lo trae, por posicion.
 */
const filas = computed(() => {
    const guardadas = Array.isArray(props.value) ? props.value : [];

    if (!props.people.length) return guardadas;

    return props.people.map((persona, i) => {
        const previa = guardadas.find((f) => f?.person_slug && f.person_slug === persona.slug)
            ?? guardadas[i]
            ?? null;

        if (! previa) return filaVacia(persona);

        // Los items se alinean con el catalogo vigente de la plantilla.
        const anteriores = Array.isArray(previa.items) ? previa.items : [];

        return {
            ...previa,
            person_slug: persona.slug,
            person_name: persona.name,
            person_doc:  persona.doc,
            items: items.value.map((item) => ({
                item,
                answer: anteriores.find((a) => a?.item === item)?.answer ?? null,
            })),
        };
    });
});

function publicar(nuevas) {
    emit('update:value', nuevas.map((fila) => ({ ...fila, conforme: filaConforme(fila.items) })));
}

function responder(indice, item, respuesta) {
    publicar(filas.value.map((fila, i) => (i !== indice ? fila : {
        ...fila,
        items: (fila.items ?? []).map((x) => (x.item === item ? { ...x, answer: respuesta } : x)),
    })));
}

/** Con doce items por trabajador, marcar todo y corregir la excepcion es lo rapido. */
function marcarTodo(indice) {
    const positiva = respuestaPositiva(respuestas.value);

    publicar(filas.value.map((fila, i) => (i !== indice ? fila : {
        ...fila,
        items: (fila.items ?? []).map((x) => ({ ...x, answer: positiva })),
    })));
}

function cambiarExtra(indice, clave, valor) {
    publicar(filas.value.map((fila, i) => (i !== indice ? fila : { ...fila, [clave]: valor })));
}

const avance = (fila) => `${respondidos(fila.items)}/${(fila.items ?? []).length}`;
</script>

<template>
    <div class="ff-field">
        <Alert
            v-if="!people.length && !filas.length"
            type="warning"
            show-icon
            :message="$t('field_work.person_checklist.no_people')"
        />

        <article v-for="(fila, i) in filas" :key="fila.person_slug ?? i" class="ff-row">
            <header class="ff-row__head">
                <span class="ff-row__num">{{ i + 1 }}</span>

                <span class="ff-row__title">
                    {{ fila.person_name }}
                    <small v-if="fila.person_doc">{{ fila.person_doc }}</small>
                </span>

                <span class="ff-count" :class="{ 'is-done': respondidos(fila.items) === (fila.items ?? []).length }">
                    {{ avance(fila) }}
                </span>

                <Button v-if="!readonly" size="large" class="ff-row__all" @click="marcarTodo(i)">
                    <template #icon><CheckOutlined /></template>
                    {{ $t('field_work.mark_all') }}
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

            <!-- Los campos de correccion solo tienen sentido si algo salio mal. -->
            <section v-if="extras.length && !fila.conforme" class="ff-correction">
                <h4 class="ff-correction__title">{{ $t('field_work.correction_title') }}</h4>
                <ExtraFields
                    :keys="extras" :values="fila" :readonly="readonly"
                    @change="(clave, valor) => cambiarExtra(i, clave, valor)" />
            </section>
        </article>
    </div>
</template>
