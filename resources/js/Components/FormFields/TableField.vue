<script setup>
/**
 * El campo «tabla»: las filas que el formato defina, con las columnas que quiera.
 *
 * EL AGUJERO QUE CIERRA
 * ---------------------
 * `table` estaba en `FormField::TIPOS` desde el primer dia, asi que el editor de
 * formatos lo OFRECIA y pedia sus columnas… y la pantalla de llenado no lo
 * despachaba: caia en el `<a-input v-else>` del final y se pintaba como **una
 * caja de texto de una linea**. Quien lo eligiera de buena fe se encontraba un
 * campo con seis columnas configuradas y un renglon para escribirlas todas, y el
 * PDF recibia una cadena donde esperaba filas. Un tipo que se ofrece y no se
 * puede llenar es peor que un tipo que no existe.
 *
 * POR QUE NO ES UNA `<table>`
 * ---------------------------
 * Porque esto se llena en una tablet de 10" y una tabla de seis columnas ahi solo
 * cabe con scroll horizontal, que esta vetado (docs/UI.md §3). Cada fila es una
 * tarjeta y cada celda lleva SU rotulo encima: en una pantalla ancha las celdas
 * se reparten en linea y se lee como una tabla; en la tablet se apilan. Y con el
 * rotulo pegado a cada casilla no hay que acordarse de que columna era la cuarta
 * mientras se escribe con guantes.
 *
 * LAS COLUMNAS SALEN DEL CATALOGO, con las dos formas de siempre: la clave es lo
 * que se GUARDA en cada fila —y lo que casa el PDF con su columna— y el rotulo es
 * lo unico que cambia con el idioma. Ver `Support/catalogo.js`.
 *
 * FORMA DEL VALOR que emite: una lista de filas, cada fila un mapa columna → texto.
 *
 *   [{ "Equipo": "Amoladora", "Serie": "A-31" }, …]
 *
 * Es la que ya leia `FormSubmissionPdfService::tabla()`, que saca las cabeceras
 * de `config.columns` y las filas de las claves del JSON.
 */
import { computed } from 'vue';
import { Button, Input, Tooltip } from 'ant-design-vue';
import { DeleteOutlined, PlusOutlined } from '@ant-design/icons-vue';
import { catalogo, catalogoConRotulos } from './respuestas';
import { useCatalogos } from '@/Composables/useCatalogos';

const props = defineProps({
    field:    { type: Object, required: true },
    value:    { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
    /** El servidor dijo que este campo sigue faltando tras intentar guardar. */
    faltante: { type: Boolean, default: false },
});

const emit = defineEmits(['update:value']);

const { locale } = useCatalogos();

const config = computed(() => props.field?.config ?? {});

/** Las claves: lo que se guarda en cada fila. No cambian con el idioma. */
const columnas = computed(() => catalogo(config.value, 'columns'));

/** Y sus rotulos, que es lo que se lee encima de cada casilla. */
const cabeceras = computed(() => catalogoConRotulos(config.value, locale.value, 'columns'));

const filas = computed(() => (Array.isArray(props.value) ? props.value : []));

function emitir(lista) {
    emit('update:value', lista);
}

function escribir(i, columna, texto) {
    emitir(filas.value.map((fila, j) => (j === i ? { ...fila, [columna]: texto } : fila)));
}

/** Una fila nueva nace con todas sus columnas, para que el PDF las alinee. */
function anadir() {
    emitir([...filas.value, Object.fromEntries(columnas.value.map((c) => [c, '']))]);
}

function quitar(i) {
    emitir(filas.value.filter((_, j) => j !== i));
}

/** Una fila esta vacia cuando no se escribio nada en ninguna casilla. */
const vacia = (fila) => columnas.value.every((c) => String(fila?.[c] ?? '').trim() === '');
</script>

<template>
    <div class="ff-field">
        <!-- El guardado dejo el campo pendiente. Se dice arriba y se marca fila
             a fila, como en el resto de los compuestos. -->
        <p v-if="faltante && !readonly && !filas.length" class="ff-missing" role="alert">
            {{ $t('field_work.table.missing') }}
        </p>

        <!-- Sin columnas no hay nada que llenar, y decirlo es mas util que una
             tarjeta vacia: lo que falta es configurar el formato. -->
        <p v-if="!columnas.length" class="ff-hint">{{ $t('field_work.table.no_columns') }}</p>

        <template v-else>
            <p v-if="!filas.length" class="ff-hint">{{ $t('field_work.table.empty') }}</p>

            <ol v-else class="ff-tab">
                <li
                    v-for="(fila, i) in filas"
                    :key="i"
                    class="ff-tab__fila"
                    :class="{ 'is-missing': faltante && !readonly && vacia(fila) }"
                >
                    <span class="ff-tab__num" aria-hidden="true">{{ i + 1 }}</span>

                    <div class="ff-tab__celdas">
                        <label v-for="c in cabeceras" :key="c.value" class="ff-tab__celda">
                            <span class="ff-tab__rotulo">{{ c.label }}</span>

                            <span v-if="readonly" class="ff-readonly">{{ fila?.[c.value] || '—' }}</span>
                            <Input
                                v-else
                                :value="fila?.[c.value] ?? ''"
                                :maxlength="255"
                                size="large"
                                @update:value="escribir(i, c.value, $event)"
                            />
                        </label>
                    </div>

                    <Tooltip v-if="!readonly" :title="$t('field_work.table.remove_row')">
                        <Button class="ff-tab__quitar" :aria-label="$t('field_work.table.remove_row')" @click="quitar(i)">
                            <DeleteOutlined />
                        </Button>
                    </Tooltip>
                </li>
            </ol>

            <Button v-if="!readonly" type="dashed" class="ff-tab__add" @click="anadir">
                <PlusOutlined /> {{ $t('field_work.table.add_row') }}
            </Button>
        </template>
    </div>
</template>
