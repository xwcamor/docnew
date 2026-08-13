<script setup>
/**
 * Checklist por persona (EPP).
 *
 * En el papel esto era una tabla con una columna por item de proteccion y el
 * nombre del item escrito en vertical para que cupieran: ilegible en una
 * tablet. Aqui es una tarjeta por trabajador del plan con sus items debajo.
 *
 * Las filas NO las elige el usuario: son los trabajadores del plan, que es de
 * donde salian en el sistema anterior (plan_workers).
 *
 * Cada tarjeta esta **plegada**, y encima va el indice (`RowNavigator`) con el
 * nombre y el estado de cada uno. Con siete trabajadores y 25 items la lista
 * abierta median 12 400 px en el escritorio y casi 18 000 en la tablet en
 * vertical; lo que se perdio al pasar de la cuadricula de papel a una tarjeta
 * por persona fue justo la vista de conjunto, y el indice es lo que la
 * devuelve. Ver `plegado.js` y `RowNavigator.vue`.
 *
 * FORMA DEL VALOR que emite (una respuesta por trabajador, con su `row`) — no
 * cambia al plegar, porque el valor vive en la pagina y no en el DOM:
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
import { ArrowRightOutlined, CheckOutlined, DownOutlined, RightOutlined } from '@ant-design/icons-vue';
import AnswerToggle from './AnswerToggle.vue';
import ExtraFields from './ExtraFields.vue';
import RowNavigator from './RowNavigator.vue';
import { usePlegado } from './plegado';
import {
    agrupar, catalogo, claveExigida, estadoChecklist, filaConforme, respondidos, respuestaPositiva,
    textoEstado,
} from './respuestas';
import { useI18n } from '@/Plugins/i18n';

const props = defineProps({
    field:    { type: Object, required: true },
    value:    { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
    /** Trabajadores del plan: una fila por cada uno. */
    people:   { type: Array, default: () => [] },
    /** El servidor dijo que este campo sigue faltando tras intentar guardar. */
    faltante: { type: Boolean, default: false },
});

const emit = defineEmits(['update:value']);

const { t } = useI18n();

const config = computed(() => props.field?.config ?? {});
const items = computed(() => catalogo(config.value, 'items'));
const respuestas = computed(() => catalogo(config.value, 'answers'));
const extras = computed(() => catalogo(config.value, 'extra'));

/**
 * Los items repartidos por parte del cuerpo: cabeza, cara, manos, oídos…
 *
 * Es como estaba el EPP en el sistema anterior (`epp_categories`) y es la única
 * de las cuatro listas que se agrupa. No es decoración: veinticinco casillas
 * seguidas se recorren leyendo una a una, y agrupadas se recorren mirando la
 * parte del cuerpo que interesa — que además es como el trabajador se revisa a
 * sí mismo, de arriba abajo.
 *
 * `agrupar()` es una VISTA sobre `items`, no otra lista: sin `config.groups`
 * devuelve un solo grupo sin rótulo con todo dentro, que es exactamente como se
 * pintaba antes. Así los otros checklists no cambian.
 */
const grupos = computed(() => agrupar(items.value, config.value?.groups));

/** ¿Hay rótulos que pintar, o es la lista de siempre? */
const conGrupos = computed(() => grupos.value.some((g) => g.name));

const { todas, idFila, estaAbierta, abierta, abrir, alternar, alternarTodo } =
    usePlegado(`epp-${props.field?.id ?? 'x'}`);

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
 * Las filas se recomponen contra los trabajadores actuales: si alguien entro al
 * plan despues de guardar, aparece con su fila vacia en vez de desaparecer. Se
 * busca por slug de persona y, si la entrega es vieja y no lo trae, por posicion.
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

/** Con veinticinco items por trabajador, marcar todo y corregir la excepcion es lo rapido. */
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

/**
 * La respuesta de cada item, por nombre.
 *
 * Los grupos pintan los items en SU orden, que no tiene por qué ser el de
 * `fila.items` —el catálogo manda ahí y los grupos son una vista— así que no
 * vale con recorrer por posición. Un mapa por fila y se acabó; buscar con
 * `find()` dentro del `v-for` serían 625 recorridos por trabajador.
 */
const respuestaPorItem = computed(() => filas.value.map(
    (fila) => new Map((fila.items ?? []).map((x) => [x.item, x.answer])),
));

const estados = computed(() => filas.value.map((fila) => estadoChecklist(fila.items ?? [])));

/**
 * Con `faltante` encendido, el trabajador a medias pasa de aviso a bloqueo
 * (rojo) en el indice Y en su tarjeta a la vez: los dos leen de aqui, asi que
 * no pueden contar cosas distintas. Solo cambia el COLOR: la palabra sigue
 * saliendo del estado real («Sin empezar», «Faltan 3») — ver `claveExigida`
 * en respuestas.js y la mentira que evita.
 */
const claves = computed(() => estados.value.map(
    (e) => claveExigida(e, props.faltante && ! props.readonly),
));

/**
 * El estado en palabra, precalculado.
 *
 * Se resuelve aqui y no en la plantilla porque `$t` es una propiedad global de
 * Vue que lee `this.$page`: pasada como argumento a otra funcion pierde el
 * `this` y revienta con «Cannot read properties of undefined». El `t` del
 * composable no depende de nadie.
 */
const textos = computed(() => estados.value.map((e) => textoEstado(t, e)));

/** Lo que lee el indice de arriba: nombre y estado en palabra. */
const resumenFilas = computed(() => filas.value.map((fila, i) => ({
    key: i,
    label: fila.person_name || t('field_work.progress.row', { n: i + 1 }),
    state: claves.value[i],
    stateText: textos.value[i],
})));

const hechos = computed(() => estados.value.reduce((n, e) => n + e.hechos, 0));
const totales = computed(() => estados.value.reduce((n, e) => n + e.total, 0));
const completas = computed(() => estados.value.filter((e) => e.total > 0 && e.faltan === 0).length);

/** Los trabajadores que el aviso de `faltante` nombra: los que tienen hueco. */
const pendientes = computed(() => estados.value.filter((e) => e.faltan > 0).length);

/**
 * El siguiente al que le falta algo, dando la vuelta a la lista.
 *
 * La vuelta importa: se empieza por el tercero porque es el que estaba a mano,
 * y al terminarlo lo que queda pendiente esta detras, no delante.
 */
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
        <Alert
            v-if="!people.length && !filas.length"
            type="warning"
            show-icon
            :message="$t('field_work.person_checklist.no_people')"
        />

        <!-- El guardado dejo el campo pendiente: en rojo, con la cuenta de
             QUIENES faltan, y en su sitio — no solo en el aviso de arriba.
             Los trabajadores concretos salen en rojo en el indice y en sus
             tarjetas (ver `estados`); esto es el titular. -->
        <p v-if="faltante && !readonly && pendientes" class="ff-missing" role="alert">
            {{ $tc('field_work.progress.required_people', pendientes) }}
        </p>

        <!-- Con una sola fila el indice sobra: la cabecera ya lo dice todo. -->
        <RowNavigator
            v-if="filas.length > 1"
            :rows="resumenFilas"
            :active="todas ? null : abierta"
            :summary="$t('field_work.progress.people_done', { done: completas, total: filas.length })"
            :detail="$t('field_work.progress.checks', { done: hechos, total: totales })"
            :ratio="totales ? hechos / totales : 0"
            :all-open="todas"
            :label="$t('field_work.progress.index_people')"
            @select="abrir"
            @toggle-all="alternarTodo"
        />

        <article
            v-for="(fila, i) in filas"
            :id="idFila(i)"
            :key="fila.person_slug ?? i"
            class="ff-row"
            :class="{ 'is-open': estaAbierta(i), 'is-missing': faltante && !readonly && estados[i].faltan > 0 }"
        >
            <!-- La cabecera ENTERA es el boton de plegado: con guantes no se
                 acierta a una flechita de 24 px, y aqui el objetivo de toque es
                 toda la franja. Por eso «Marcar todo» y «Quitar» bajaron al pie
                 de la tarjeta, donde no compiten con el. -->
            <button
                type="button"
                class="ff-row__head ff-row__head--toggle"
                :aria-expanded="estaAbierta(i)"
                :aria-controls="`${idFila(i)}-cuerpo`"
                @click="alternar(i)"
            >
                <component :is="estaAbierta(i) ? DownOutlined : RightOutlined" class="ff-row__chev" />

                <span class="ff-row__num">{{ i + 1 }}</span>

                <!-- Sólo el nombre. El documento iba detrás y no hace falta:
                     la lista son los trabajadores de ESTE plan, media docena de
                     personas que el supervisor acabó de meter él mismo, así que
                     el nombre ya distingue de sobra. Y este formato se imprime y
                     se enseña — el documento de cada uno no tiene por qué salir
                     repetido en veinticinco filas. Se sigue guardando en la
                     respuesta (`person_doc`), que es lo que identifica la fila
                     en el PDF legal y en la migración. -->
                <span class="ff-row__title">{{ fila.person_name }}</span>

                <span class="ff-count" :class="{ 'is-done': estados[i].clave === 'ok' }">{{ avance(fila) }}</span>

                <span class="ff-state" :class="`is-${claves[i]}`">{{ textos[i] }}</span>
            </button>

            <div v-if="estaAbierta(i)" :id="`${idFila(i)}-cuerpo`">
                <!-- Por parte del cuerpo, con su rótulo. Sin `config.groups`
                     sale un único grupo sin nombre con todo dentro: la lista de
                     siempre, que es lo que siguen viendo los demás checklists. -->
                <template v-for="grupo in grupos" :key="grupo.name ?? '_'">
                    <h5 v-if="conGrupos" class="ff-group">{{ grupo.name || $t('field_work.person_checklist.other_group') }}</h5>

                    <ul class="ff-items">
                        <li v-for="item in grupo.items" :key="item" class="ff-item">
                            <span class="ff-item__name">{{ item }}</span>
                            <AnswerToggle
                                :value="respuestaPorItem[i].get(item) ?? null"
                                :answers="respuestas" :readonly="readonly" :label="item"
                                @update:value="responder(i, item, $event)" />
                        </li>
                    </ul>
                </template>

                <!-- Los campos de correccion solo tienen sentido si algo salio mal. -->
                <section v-if="extras.length && !fila.conforme" class="ff-correction">
                    <h4 class="ff-correction__title">{{ $t('field_work.correction_title') }}</h4>
                    <ExtraFields
                        :keys="extras" :values="fila" :readonly="readonly"
                        @change="(clave, valor) => cambiarExtra(i, clave, valor)" />
                </section>

                <!--
                    El salto al siguiente es un boton, NO pasa solo.

                    Que la tarjeta se cierre y salte al vecino en cuanto se marca
                    el item 25 suena comodo y en obra es lo contrario: el ultimo
                    toque es el que mas se falla —guantes, sol, la tablet en una
                    mano— y si al fallarlo la pantalla se va, hay que volver a
                    buscar al trabajador para corregir una casilla que se acaba
                    de tocar. Ademas «Marcar todo» completa la fila de golpe: con
                    salto automatico, un toque te dejaria dos trabajadores mas
                    abajo sin haberlo pedido.

                    Asi que el movimiento lo pide siempre la persona. Lo unico
                    que hace la pantalla es ofrecerlo, y destacarlo cuando la
                    fila ya esta entera.
                -->
                <footer v-if="!readonly" class="ff-row__foot">
                    <Button size="large" class="ff-row__all" @click="marcarTodo(i)">
                        <template #icon><CheckOutlined /></template>
                        {{ $t('field_work.mark_all') }}
                    </Button>

                    <Button
                        v-if="siguientePendiente(i) !== null"
                        size="large"
                        class="ff-row__next"
                        :type="estados[i].faltan === 0 ? 'primary' : 'default'"
                        @click="abrir(siguientePendiente(i))"
                    >
                        {{ $t('field_work.progress.next', { name: filas[siguientePendiente(i)].person_name }) }}
                        <!-- La flecha va DESPUES del texto y por eso no usa el
                             slot `#icon` de Ant, que la pinta siempre delante:
                             «→ Siguiente: Ana» se lee como si volviera atras. -->
                        <ArrowRightOutlined class="ff-row__next-icon" />
                    </Button>
                </footer>
            </div>
        </article>
    </div>
</template>
