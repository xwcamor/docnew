<script setup>
/**
 * Matriz de riesgo (AST y PTF).
 *
 * En el sistema anterior esto eran tres tablas encadenadas y una tabla de 7
 * columnas en pantalla: actividad → peligro → control → severidad →
 * probabilidad → riesgo. En una tablet de 10" esa tabla obliga a scroll
 * horizontal, asi que aqui cada fila es una tarjeta.
 *
 * Y plegable, con el mismo indice que el EPP y el IHM: un AST de verdad trae
 * diez o quince peligros, cada uno con seis desplegables, y desplegados son
 * otra columna infinita. Lo que se quiere ver de un vistazo es cuantos peligros
 * quedan sin evaluar y cuantos salieron altos, no los desplegables.
 *
 * FORMA DEL VALOR que emite (una respuesta por fila, con su `row`):
 *
 *   { actividad, peligro, riesgo, control, severidad, probabilidad,
 *     valor_riesgo, nivel }
 *
 * `riesgo` es TEXTO: la consecuencia («Agotamiento de recurso natural»), que en
 * la v1 es la columna `name_risk` y va entre el peligro y el control. El numero
 * de la matriz es `valor_riesgo`.
 *
 * Aqui se llamaba `riesgo` al numero, y como la migracion —que sigue el nombre
 * del dominio— escribia el texto en `riesgo` y el numero en `valor_riesgo`, los
 * 3 657 AST migrados salian con la consecuencia donde va el numero y sin banda
 * de color. Manda el nombre del dominio, que ademas es el de la v1.
 *
 * `severidad` y `probabilidad` son obligatorias porque es lo que exige
 * FormSubmissionService::validarValor() para este tipo. El resto acompaña.
 */
import { computed } from 'vue';
import { Button } from 'ant-design-vue';
import { DeleteOutlined, DownOutlined, PlusOutlined, RightOutlined } from '@ant-design/icons-vue';
import CatalogSelect from './CatalogSelect.vue';
import RowNavigator from './RowNavigator.vue';
import { usePlegado } from './plegado';
import { catalogo } from './respuestas';
import { useI18n } from '@/Plugins/i18n';

const props = defineProps({
    field:    { type: Object, required: true },
    value:    { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['update:value']);

const { t } = useI18n();

const config = computed(() => props.field?.config ?? {});
const actividades = computed(() => catalogo(config.value, 'activities'));
const peligros = computed(() => catalogo(config.value, 'dangers'));
const riesgos = computed(() => catalogo(config.value, 'risks'));
const controles = computed(() => catalogo(config.value, 'controls'));
const severidades = computed(() => catalogo(config.value, 'severities', 'severidades'));
const probabilidades = computed(() => catalogo(config.value, 'probabilities', 'probabilidades'));

const filas = computed(() => (Array.isArray(props.value) ? props.value : []));

const { todas, idFila, estaAbierta, abierta, abrir, alternar, alternarTodo, cerrar } =
    usePlegado(`riesgo-${props.field?.id ?? 'x'}`);

/**
 * El riesgo sale de la tabla, no de una multiplicacion.
 *
 * La matriz del sistema anterior es un ranking del 1 al 25 donde el 1 es lo
 * peor (c1 = mas grave, p1 = mas probable). No es severidad × probabilidad:
 * doce de las veinticinco celdas caen en otra banda si se multiplica. c2×p4
 * vale 12 en la tabla y 8 multiplicando, que es la diferencia entre «medio» y
 * «alto» en un documento de seguridad. `docufiz:migrate-formats` copia la tabla
 * real a `config.matrix`.
 *
 * El producto queda solo de red, para un formato nuevo que se defina sin matriz.
 */
function valorRiesgo(fila) {
    const s = severidades.value.indexOf(fila?.severidad) + 1;
    const p = probabilidades.value.indexOf(fila?.probabilidad) + 1;

    if (!s || !p) return null;

    const tabla = config.value.matrix;

    if (Array.isArray(tabla) && Array.isArray(tabla[s - 1])) {
        return tabla[s - 1][p - 1] ?? null;
    }

    return s * p;
}

/**
 * Bandas del sistema anterior (`Risk#level_name`): 1-8 alto, 9-15 medio, 16-25
 * bajo. Vienen en `config.levels` junto con la matriz, para no tener que
 * deducirlas. Si un formato nuevo no las define, se reparten en tercios.
 */
function nivelRiesgo(valor) {
    if (!valor) return null;

    const bandas = config.value.levels;

    if (Array.isArray(bandas) && bandas.length) {
        return bandas.find((b) => valor <= b.hasta)?.clave ?? bandas.at(-1)?.clave ?? null;
    }

    const maximo = severidades.value.length * probabilidades.value.length;

    if (!maximo) return null;
    if (valor <= maximo / 3) return 'alto';
    if (valor <= (maximo * 2) / 3) return 'medio';

    return 'bajo';
}

/** El riesgo se recalcula en la fila cada vez que cambia, no se pide aparte. */
function conRiesgo(fila) {
    const riesgo = valorRiesgo(fila);

    return { ...fila, valor_riesgo: riesgo, nivel: nivelRiesgo(riesgo) };
}

function filaVacia() {
    return {
        actividad: null, peligro: null, riesgo: null, control: null,
        severidad: null, probabilidad: null, valor_riesgo: null, nivel: null,
    };
}

function cambiar(indice, clave, valor) {
    emit('update:value', filas.value.map(
        (fila, i) => (i === indice ? conRiesgo({ ...fila, [clave]: valor }) : fila),
    ));
}

/** El peligro recien añadido se abre solo: esta vacio y hay que llenarlo. */
function agregar() {
    emit('update:value', [...filas.value, filaVacia()]);
    abrir(filas.value.length);
}

/** Se pliega todo al borrar: la fila abierta se guarda por posicion y al
 *  quitar la 2 de cuatro la que era 3 pasa a ser 2. Ver el IHM. */
function quitar(indice) {
    emit('update:value', filas.value.filter((_, i) => i !== indice));
    cerrar();
}

/**
 * Aqui el estado de la fila NO es cuanto lleva rellenado sino que riesgo
 * salio: eso es lo que se busca al abrir un AST, y es lo unico que decide algo.
 * Una fila sin severidad y probabilidad esta sin evaluar, que es el hueco.
 */
const TONO_NIVEL = { alto: 'bad', medio: 'warn', bajo: 'ok' };

const estados = computed(() => filas.value.map((fila) => (
    fila?.nivel
        ? { clave: TONO_NIVEL[fila.nivel] ?? 'off', texto: t(`field_work.risk_matrix.level_${fila.nivel}`) }
        : { clave: 'off', texto: t('field_work.risk_matrix.no_risk') }
)));

/** Actividad → peligro, que es como se lee una fila del AST de papel. */
function titulo(fila, i) {
    const partes = [fila?.actividad, fila?.peligro].filter(Boolean);

    return partes.length ? partes.join(' → ') : t('field_work.progress.hazard', { n: i + 1 });
}

const resumenFilas = computed(() => filas.value.map((fila, i) => ({
    key: i,
    label: titulo(fila, i),
    state: estados.value[i].clave,
    stateText: estados.value[i].texto,
})));

const evaluadas = computed(() => filas.value.filter((f) => f?.nivel).length);
const porNivel = (nivel) => filas.value.filter((f) => f?.nivel === nivel).length;
</script>

<template>
    <div class="ff-field">
        <p v-if="!filas.length" class="ff-empty">{{ $t('field_work.risk_matrix.empty') }}</p>

        <RowNavigator
            v-if="filas.length > 1"
            :rows="resumenFilas"
            :active="todas ? null : abierta"
            :summary="$t('field_work.progress.hazards_rated', { done: evaluadas, total: filas.length })"
            :detail="$t('field_work.progress.by_level', {
                high: porNivel('alto'), mid: porNivel('medio'), low: porNivel('bajo'),
            })"
            :ratio="filas.length ? evaluadas / filas.length : 0"
            :all-open="todas"
            :label="$t('field_work.progress.index_hazards')"
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

                <span v-if="fila.nivel" class="ff-risk" :class="`is-${fila.nivel}`">
                    {{ $t(`field_work.risk_matrix.level_${fila.nivel}`) }} · {{ fila.valor_riesgo }}
                </span>
                <span v-else class="ff-risk is-none">{{ $t('field_work.risk_matrix.no_risk') }}</span>
            </button>

            <div v-if="estaAbierta(i)" :id="`${idFila(i)}-cuerpo`">
                <div class="ff-row__body">
                    <div class="ff-cell">
                        <label class="ff-label">{{ $t('field_work.risk_matrix.activity') }}</label>
                        <CatalogSelect
                            :value="fila.actividad" :options="actividades" :readonly="readonly"
                            :placeholder="$t('field_work.risk_matrix.search')"
                            @update:value="cambiar(i, 'actividad', $event)" />
                    </div>

                    <div class="ff-cell">
                        <label class="ff-label">{{ $t('field_work.risk_matrix.danger') }}</label>
                        <CatalogSelect
                            :value="fila.peligro" :options="peligros" :readonly="readonly"
                            :placeholder="$t('field_work.risk_matrix.search')"
                            @update:value="cambiar(i, 'peligro', $event)" />
                    </div>

                    <div class="ff-cell">
                        <label class="ff-label">{{ $t('field_work.risk_matrix.risk') }}</label>
                        <CatalogSelect
                            :value="fila.riesgo" :options="riesgos" :readonly="readonly"
                            :placeholder="$t('field_work.risk_matrix.search')"
                            @update:value="cambiar(i, 'riesgo', $event)" />
                    </div>

                    <div class="ff-cell ff-cell--wide">
                        <label class="ff-label">{{ $t('field_work.risk_matrix.control') }}</label>
                        <CatalogSelect
                            :value="fila.control" :options="controles" :readonly="readonly"
                            :placeholder="$t('field_work.risk_matrix.search')"
                            @update:value="cambiar(i, 'control', $event)" />
                    </div>

                    <div class="ff-cell">
                        <label class="ff-label">{{ $t('field_work.risk_matrix.severity') }}</label>
                        <div class="ff-chips">
                            <span v-if="readonly" class="ff-readonly">{{ fila.severidad || '—' }}</span>
                            <button
                                v-for="s in (readonly ? [] : severidades)" :key="s" type="button"
                                class="ff-chip" :class="{ 'is-on': fila.severidad === s }"
                                :aria-pressed="fila.severidad === s"
                                @click="cambiar(i, 'severidad', s)">{{ s }}</button>
                        </div>
                    </div>

                    <div class="ff-cell">
                        <label class="ff-label">{{ $t('field_work.risk_matrix.probability') }}</label>
                        <div class="ff-chips">
                            <span v-if="readonly" class="ff-readonly">{{ fila.probabilidad || '—' }}</span>
                            <button
                                v-for="p in (readonly ? [] : probabilidades)" :key="p" type="button"
                                class="ff-chip" :class="{ 'is-on': fila.probabilidad === p }"
                                :aria-pressed="fila.probabilidad === p"
                                @click="cambiar(i, 'probabilidad', p)">{{ p }}</button>
                        </div>
                    </div>
                </div>

                <footer v-if="!readonly" class="ff-row__foot">
                    <Button
                        class="ff-row__del"
                        danger
                        size="large"
                        :title="$t('field_work.risk_matrix.remove_row')"
                        @click="quitar(i)"
                    >
                        <template #icon><DeleteOutlined /></template>
                        {{ $t('field_work.risk_matrix.remove_row') }}
                    </Button>
                </footer>
            </div>
        </article>

        <Button v-if="!readonly" class="ff-add" size="large" block @click="agregar">
            <template #icon><PlusOutlined /></template>
            {{ $t('field_work.risk_matrix.add_row') }}
        </Button>
    </div>
</template>
