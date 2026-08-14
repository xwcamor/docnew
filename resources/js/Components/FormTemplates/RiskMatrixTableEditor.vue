<script setup>
/**
 * La tabla de evaluación de riesgos: severidad × probabilidad → puntaje.
 *
 * POR QUÉ HAY QUE PODER ESCRIBIRLA, Y NO SÓLO LEERLA
 * ---------------------------------------------------
 * `config.matrix` se leía desde que existe el motor, pero no había forma de
 * escribirla: no estaba en la lista de claves configurables, así que la única
 * manera de cargar la tabla de otra empresa era entrar a la base y editar el
 * JSON a mano. El dueño del producto lo preguntó tal cual: «ese puntaje de
 * catastrófico, ¿qué pasa si otra empresa tiene otros valores?».
 *
 * EL PUNTAJE NO ES SEVERIDAD × PROBABILIDAD
 * ------------------------------------------
 * Y por eso hace falta la tabla entera y no un cálculo. La matriz de la v1 es un
 * ranking del 1 al 25 donde el 1 es lo peor, y DOCE de sus veinticinco celdas
 * caen en otra banda si se multiplica: c2×p4 vale 12 en la tabla y 8 en el
 * producto, que es la diferencia entre «medio» y «alto» en un documento de
 * seguridad. El producto se queda sólo de red, para un formato al que todavía no
 * le hayan cargado su tabla.
 *
 * SE PINTA COMO EL PAPEL, y no como una lista de celdas: filas de severidad,
 * columnas de probabilidad, cada casilla con su número y teñida con el color de
 * la banda en la que cae. Así se coteja de un vistazo con la hoja que trae el
 * cliente, que es exactamente lo que va a hacer quien la configure.
 *
 * LAS CABECERAS SON LOS RÓTULOS, NO LAS CLAVES. Lo que se guarda en cada
 * respuesta es `c1..c5` / `p1..p5` —es lo que indexa esta tabla por posición y
 * lo que traen las 3 657 matrices migradas— pero en pantalla se lee
 * «Catastrófico», «Podría suceder», como en el papel.
 */
import { computed } from 'vue';
import { InputNumber } from 'ant-design-vue';
import { bandaDeValor, bandasDeRiesgo, textoTraducible, valoresDeCatalogo } from '@/Support/catalogo';
import { useI18n } from '@/Plugins/i18n';

const props = defineProps({
    /** La tabla: una lista de filas, una fila por severidad. */
    modelValue: { type: Array, default: () => [] },
    /** El resto de la config del campo: de ahí salen los ejes y las bandas. */
    config:     { type: Object, default: () => ({}) },
    disabled:   { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();

const severidades    = computed(() => valoresDeCatalogo(props.config?.severities ?? props.config?.severidades));
const probabilidades = computed(() => valoresDeCatalogo(props.config?.probabilities ?? props.config?.probabilidades));

const bandas = computed(() => bandasDeRiesgo(props.config, null, t));

/** El rótulo de un eje: el que escribió el administrador, o la clave pelada. */
function rotulo(mapa, clave) {
    return textoTraducible(props.config?.[mapa]?.[clave], null) || clave;
}

const valor = (f, c) => props.modelValue?.[f]?.[c] ?? null;

/**
 * El color de la casilla, que es lo que hace cotejable esta tabla con el papel.
 *
 * Sale de la banda en la que cae el número —no de un color escrito— así que en
 * cuanto alguien corrige un puntaje, la casilla cambia de color sola y se ve si
 * la tabla quedó como la del cliente.
 */
const tonoDe = (f, c) => bandaDeValor(valor(f, c), bandas.value)?.tone ?? 'none';

/**
 * Escribir una casilla rellena la tabla hasta ella si hacía falta.
 *
 * Una matriz a medio escribir es lo normal mientras se copia del papel, y sin
 * esto la fila 4 no se podría tocar hasta haber rellenado las tres de arriba.
 */
function escribir(f, c, n) {
    const tabla = severidades.value.map((_, i) => {
        const fila = Array.isArray(props.modelValue?.[i]) ? [...props.modelValue[i]] : [];

        while (fila.length < probabilidades.value.length) fila.push(null);

        return fila.slice(0, probabilidades.value.length);
    });

    tabla[f][c] = n === null || n === undefined || n === '' ? null : Number(n);

    emit('update:modelValue', tabla);
}
</script>

<template>
    <div class="rmt">
        <!-- Sin ejes no hay tabla que pintar, y decirlo es más útil que una
             cuadrícula de cero por cero: lo que falta es configurar arriba las
             severidades y las probabilidades. -->
        <p v-if="!severidades.length || !probabilidades.length" class="rmt__empty">
            {{ $t('form_templates.matrix_needs_axes') }}
        </p>

        <template v-else>
            <p class="rmt__hint">{{ $t('form_templates.matrix_hint') }}</p>

            <div class="rmt__scroll">
                <table class="rmt__tabla">
                    <thead>
                        <tr>
                            <th class="rmt__esquina" scope="col">
                                {{ $t('field_work.risk_matrix.severity') }} \ {{ $t('field_work.risk_matrix.probability') }}
                            </th>
                            <th v-for="(p, c) in probabilidades" :key="p" scope="col" class="rmt__th">
                                {{ rotulo('probability_labels', p) }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(s, f) in severidades" :key="s">
                            <th scope="row" class="rmt__th rmt__th--fila">{{ rotulo('severity_labels', s) }}</th>
                            <td v-for="(p, c) in probabilidades" :key="p" class="rmt__td" :class="`is-${tonoDe(f, c)}`">
                                <InputNumber
                                    class="rmt__num"
                                    :value="valor(f, c)"
                                    :disabled="disabled"
                                    :min="1"
                                    :controls="false"
                                    @update:value="escribir(f, c, $event)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>

<style scoped>
.rmt { display: flex; flex-direction: column; gap: 6px; }

.rmt__empty,
.rmt__hint {
    margin: 0 0 2px;
    font-size: 0.8125rem;
    color: var(--color-text-dim);
}

/* La tabla puede tener seis columnas con rótulos largos: se desplaza DENTRO de
   su caja, nunca la página (docs/UI.md §3). */
.rmt__scroll { overflow-x: auto; }

.rmt__tabla { border-collapse: collapse; width: 100%; }

.rmt__th,
.rmt__esquina,
.rmt__td {
    border: 1px solid var(--color-border-soft);
    padding: 4px 6px;
    text-align: center;
    vertical-align: middle;
}

.rmt__th {
    background: var(--color-surface-hover, #f5f9fe);
    font-size: 0.75rem; font-weight: 700;
    color: var(--color-text-muted);
}
.rmt__th--fila { text-align: left; white-space: nowrap; }

.rmt__esquina {
    background: var(--color-surface-hover, #f5f9fe);
    font-size: 0.7rem; font-weight: 600;
    color: var(--color-text-dim);
    white-space: nowrap;
}

.rmt__td { padding: 2px; }
/* El color de la casilla sale de la banda, con los mismos tokens `--state-*`
   que el resto del sistema: así la tabla del editor y la pastilla de nivel del
   AST no pueden decir cosas distintas (docs/UI.md §5-bis). */
.rmt__td.is-bad  { background: var(--state-bad-bg); }
.rmt__td.is-warn { background: var(--state-warn-bg); }
.rmt__td.is-ok   { background: var(--state-ok-bg); }
.rmt__td.is-info { background: var(--state-info-bg, #eaf3fb); }
.rmt__td.is-off,
.rmt__td.is-none { background: transparent; }

.rmt__num { width: 4.5rem; }
.rmt__num :deep(input) { text-align: center; font-weight: 700; }
</style>
