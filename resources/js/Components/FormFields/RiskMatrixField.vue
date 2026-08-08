<script setup>
/**
 * Matriz de riesgo (AST y PTF).
 *
 * En el sistema anterior esto eran tres tablas encadenadas y una tabla de 7
 * columnas en pantalla: actividad → peligro → control → severidad →
 * probabilidad → riesgo. En una tablet de 10" esa tabla obliga a scroll
 * horizontal, asi que aqui cada fila es una tarjeta.
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
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons-vue';
import CatalogSelect from './CatalogSelect.vue';
import { catalogo } from './respuestas';

const props = defineProps({
    field:    { type: Object, required: true },
    value:    { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['update:value']);

const config = computed(() => props.field?.config ?? {});
const actividades = computed(() => catalogo(config.value, 'activities'));
const peligros = computed(() => catalogo(config.value, 'dangers'));
const riesgos = computed(() => catalogo(config.value, 'risks'));
const controles = computed(() => catalogo(config.value, 'controls'));
const severidades = computed(() => catalogo(config.value, 'severities', 'severidades'));
const probabilidades = computed(() => catalogo(config.value, 'probabilities', 'probabilidades'));

const filas = computed(() => (Array.isArray(props.value) ? props.value : []));

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

function agregar() {
    emit('update:value', [...filas.value, filaVacia()]);
}

function quitar(indice) {
    emit('update:value', filas.value.filter((_, i) => i !== indice));
}
</script>

<template>
    <div class="ff-field">
        <p v-if="!filas.length" class="ff-empty">{{ $t('field_work.risk_matrix.empty') }}</p>

        <article v-for="(fila, i) in filas" :key="i" class="ff-row">
            <header class="ff-row__head">
                <span class="ff-row__num">{{ i + 1 }}</span>

                <span v-if="fila.nivel" class="ff-risk" :class="`is-${fila.nivel}`">
                    {{ $t(`field_work.risk_matrix.level_${fila.nivel}`) }} · {{ fila.valor_riesgo }}
                </span>
                <span v-else class="ff-risk is-none">{{ $t('field_work.risk_matrix.no_risk') }}</span>

                <Button
                    v-if="!readonly"
                    class="ff-row__del"
                    danger
                    size="large"
                    :title="$t('field_work.risk_matrix.remove_row')"
                    :aria-label="$t('field_work.risk_matrix.remove_row')"
                    @click="quitar(i)"
                >
                    <template #icon><DeleteOutlined /></template>
                </Button>
            </header>

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
        </article>

        <Button v-if="!readonly" class="ff-add" size="large" block @click="agregar">
            <template #icon><PlusOutlined /></template>
            {{ $t('field_work.risk_matrix.add_row') }}
        </Button>
    </div>
</template>
