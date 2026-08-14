<script setup>
/**
 * Las bandas de la matriz: qué tramo de puntaje es qué nivel de riesgo.
 *
 * QUÉ ESTABA MAL Y ESTE CONTROL VIENE A CERRAR
 * ---------------------------------------------
 * `config.levels` se leía y no se podía escribir: no estaba entre las claves
 * configurables, así que las bandas de otra empresa sólo se cargaban entrando a
 * la base. Y aunque se cargaran, había dos suposiciones metidas en el código:
 *
 *  1. La CLAVE de la banda se usaba de clave de traducción («level_alto») y de
 *     clase de CSS («.is-alto»), o sea que las bandas eran configurables siempre
 *     que se llamaran alto, medio y bajo. Una empresa con `crítico / moderado /
 *     aceptable` las veía sin traducir y sin color.
 *  2. El tramo era un solo `hasta` acumulado, lo que da por supuesto que el
 *     número pequeño es el peor. Es verdad en la matriz de la v1 (1..25 donde el
 *     1 es lo peor) y es FALSO en la clásica de severidad × probabilidad, donde
 *     lo peor es el 25: ahí las bandas salían del revés y lo crítico se pintaba
 *     de verde.
 *
 * Por eso una banda es ahora un RANGO —desde/hasta— con su propio rótulo y su
 * propio color, y da igual en qué dirección vaya la escala.
 *
 * LA BANDA TOLERABLE SE MARCA
 * ---------------------------
 * Es la que NO cuenta como observación. Antes se cogía la última declarada y, si
 * no había ninguna, la palabra `bajo` escrita en el código —el nombre que le
 * puso la v1—, con lo que un formato con otros nombres contaba como no
 * conformidad cada peligro evaluado del documento. Sin marcar ninguna se sigue
 * cogiendo la última, que es lo que hacen las cuatro plantillas migradas: van de
 * peor a mejor.
 *
 * EL COLOR ES UN TONO, NO UN HEX. Los cinco tonos son los `--state-*` del
 * sistema: así el rojo de una banda y el rojo de una no conformidad son el mismo
 * rojo, y siguen funcionando en el tema oscuro y en los cuatro esquemas de color
 * del perfil. Un `#c00` tecleado aquí no sabe hacer nada de eso.
 */
import { computed } from 'vue';
import { Button, Checkbox, Input, InputNumber, Select, Tooltip } from 'ant-design-vue';
import {
    ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, PlusOutlined,
} from '@ant-design/icons-vue';
import { TONOS_BANDA, textoTraducible } from '@/Support/catalogo';
import { useI18n } from '@/Plugins/i18n';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    /** El resto de la config: de ahí sale cuál es el puntaje máximo posible. */
    config:     { type: Object, default: () => ({}) },
    disabled:   { type: Boolean, default: false },
    locales:    { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();

/** El idioma en el que se escribe el rótulo suelto. El resto, en la fila de idiomas. */
const principal = computed(() => props.locales[0]?.code ?? 'es');

/**
 * Una banda cruda leída siempre igual, con la forma vieja incluida.
 *
 * `hasta` sin `min` es el tramo que va desde donde acabó la anterior: es como
 * están declaradas las cuatro plantillas migradas y se sigue leyendo así.
 */
function filas() {
    let desde = 1;

    return props.modelValue.map((b) => {
        const max = Number(b?.max ?? b?.hasta ?? 0) || 0;
        const min = Number.isFinite(Number(b?.min)) ? Number(b.min) : desde;

        desde = max + 1;

        return {
            clave: String(b?.clave ?? b?.key ?? ''),
            min,
            max,
            label: b?.label && typeof b.label === 'object' ? { ...b.label }
                : (typeof b?.label === 'string' ? { [principal.value]: b.label } : {}),
            tone: TONOS_BANDA.includes(b?.tone) ? b.tone : null,
            tolerable: b?.tolerable === true,
        };
    });
}

/**
 * Y de vuelta: se guarda `min`/`max` siempre, porque es lo que quita la
 * suposición de la dirección de la escala. Lo demás sólo si dice algo.
 */
function escribir(fila) {
    const banda = { clave: fila.clave, min: fila.min, max: fila.max };

    const traducciones = Object.fromEntries(
        Object.entries(fila.label ?? {}).filter(([, texto]) => String(texto ?? '').trim() !== ''),
    );

    if (Object.keys(traducciones).length) banda.label = traducciones;
    if (fila.tone) banda.tone = fila.tone;
    if (fila.tolerable) banda.tolerable = true;

    return banda;
}

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

/**
 * Marcar la tolerable DESMARCA la anterior.
 *
 * Sólo puede haber una: si hubiera dos, «cuenta como observación» dejaría de
 * tener una respuesta y el contador del plan pasaría a depender del orden en que
 * estuvieran escritas.
 */
function marcarTolerable(i, marcada) {
    emitir(filas().map((fila, j) => ({ ...fila, tolerable: marcada && i === j })));
}

function quitar(i) {
    emitir(filas().filter((_, j) => j !== i));
}

function mover(i, salto) {
    const destino = i + salto;

    if (destino < 0 || destino >= props.modelValue.length) return;

    const lista = filas();
    [lista[i], lista[destino]] = [lista[destino], lista[i]];
    emitir(lista);
}

function anadir() {
    const previas = filas();
    const ultimo  = previas.length ? Math.max(...previas.map((b) => b.max)) : 0;

    emitir([...previas, {
        clave: '', min: ultimo + 1, max: ultimo + 1, label: {}, tone: null, tolerable: false,
    }]);
}

const TONOS = [
    { value: 'bad',  label: () => t('form_templates.level_tone_bad') },
    { value: 'warn', label: () => t('form_templates.level_tone_warn') },
    { value: 'ok',   label: () => t('form_templates.level_tone_ok') },
    { value: 'info', label: () => t('form_templates.level_tone_info') },
    { value: 'off',  label: () => t('form_templates.level_tone_off') },
];

/** El tono que le tocaría por posición si no declara ninguno. Es lo que se ve. */
function tonoEfectivo(i, declarado) {
    if (declarado) return declarado;

    const ultima = props.modelValue.length - 1;

    if (ultima === 0) return 'info';

    return i === 0 ? 'bad' : i === ultima ? 'ok' : 'warn';
}
</script>

<template>
    <div class="rle">
        <p v-if="!modelValue.length" class="rle__empty">{{ $t('form_templates.levels_empty') }}</p>
        <p v-else class="rle__hint">{{ $t('form_templates.levels_hint') }}</p>

        <div class="rle__rows">
            <div v-for="(fila, i) in filas()" :key="i" class="rle__row" :class="`is-${tonoEfectivo(i, fila.tone)}`">
                <div class="rle__main">
                    <!-- La clave: es lo que se GUARDA en cada peligro evaluado y
                         no se traduce nunca. Cambiarla en un formato ya usado
                         deja las evaluaciones viejas apuntando a una banda que
                         ya no existe — que se siguen leyendo, pero en gris. -->
                    <Input
                        class="rle__clave"
                        :value="fila.clave"
                        :disabled="disabled"
                        :placeholder="$t('form_templates.level_key_placeholder')"
                        :maxlength="40"
                        @update:value="cambiar(i, { clave: $event })"
                    />

                    <label class="rle__rango">
                        <span class="rle__rotulo">{{ $t('form_templates.level_from') }}</span>
                        <InputNumber :value="fila.min" :disabled="disabled" :min="0" :controls="false"
                                     @update:value="cambiar(i, { min: Number($event ?? 0) })" />
                        <span class="rle__rotulo">{{ $t('form_templates.level_to') }}</span>
                        <InputNumber :value="fila.max" :disabled="disabled" :min="0" :controls="false"
                                     @update:value="cambiar(i, { max: Number($event ?? 0) })" />
                    </label>

                    <Select
                        class="rle__tone"
                        :value="fila.tone"
                        :disabled="disabled"
                        :placeholder="$t('form_templates.level_tone_auto')"
                        allow-clear
                        :options="TONOS.map((x) => ({ value: x.value, label: x.label() }))"
                        @update:value="cambiar(i, { tone: $event ?? null })"
                    />

                    <Tooltip :title="$t('form_templates.level_tolerable_help')">
                        <Checkbox
                            class="rle__tol"
                            :checked="fila.tolerable"
                            :disabled="disabled"
                            @change="marcarTolerable(i, $event.target.checked)"
                        >{{ $t('form_templates.level_tolerable') }}</Checkbox>
                    </Tooltip>

                    <Tooltip :title="$t('form_templates.move_up')">
                        <Button class="rle__btn" :disabled="disabled || i === 0" @click="mover(i, -1)">
                            <ArrowUpOutlined />
                        </Button>
                    </Tooltip>
                    <Tooltip :title="$t('form_templates.move_down')">
                        <Button class="rle__btn" :disabled="disabled || i === modelValue.length - 1" @click="mover(i, 1)">
                            <ArrowDownOutlined />
                        </Button>
                    </Tooltip>
                    <Tooltip :title="$t('global.delete')">
                        <Button class="rle__btn rle__btn--danger" :disabled="disabled" @click="quitar(i)">
                            <DeleteOutlined />
                        </Button>
                    </Tooltip>
                </div>

                <!-- El rótulo con el que se LEE la banda, por idioma. Sin
                     escribir ninguno, las bandas que se llamen como las de la v1
                     caen a la traducción del producto y el resto se leen por su
                     clave. -->
                <div class="rle__langs">
                    <label v-for="idioma in locales" :key="idioma.code" class="rle__lang">
                        <span class="rle__langcode">{{ idioma.code }}</span>
                        <Input
                            :value="fila.label?.[idioma.code] ?? ''"
                            :disabled="disabled"
                            :placeholder="fila.clave"
                            :maxlength="80"
                            @update:value="rotular(i, idioma.code, $event)"
                        />
                    </label>
                </div>
            </div>
        </div>

        <Button v-if="!disabled" type="dashed" class="rle__add" @click="anadir">
            <PlusOutlined /> {{ $t('form_templates.list_add') }}
        </Button>
    </div>
</template>

<style scoped>
.rle { display: flex; flex-direction: column; gap: 6px; }

.rle__empty,
.rle__hint {
    margin: 0 0 2px;
    font-size: 0.8125rem;
    color: var(--color-text-dim);
}

.rle__rows { display: flex; flex-direction: column; gap: 10px; }

/* El filete de color al canto dice de qué tono es la banda sin tener que leer
   el desplegable — y es el mismo token que pinta la pastilla del AST. */
.rle__row {
    display: flex; flex-direction: column; gap: 6px;
    padding: 8px 10px;
    border-radius: 6px;
    border-left: 4px solid var(--color-border-soft);
    background: var(--color-surface);
}
.rle__row.is-bad  { border-left-color: var(--state-bad-text); }
.rle__row.is-warn { border-left-color: var(--state-warn-text); }
.rle__row.is-ok   { border-left-color: var(--state-ok-text); }
.rle__row.is-info { border-left-color: var(--color-primary, #0A6ED1); }
.rle__row.is-off  { border-left-color: var(--state-off-text); }

.rle__main { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

.rle__clave { flex: 1 1 10rem; min-width: 0; }

.rle__rango { display: inline-flex; align-items: center; gap: 6px; flex: 0 0 auto; }
.rle__rango :deep(.ant-input-number) { width: 5rem; }
.rle__rotulo { font-size: 0.75rem; font-weight: 600; color: var(--color-text-muted); }

.rle__tone { flex: 0 0 auto; min-width: 11rem; }
.rle__tone :deep(.ant-select-selector) { height: 44px !important; align-items: center; }

.rle__tol { flex: 0 0 auto; font-size: 0.8125rem; }

.rle__btn {
    flex: 0 0 auto;
    width: 44px; height: 44px;
    display: inline-flex; align-items: center; justify-content: center;
    padding: 0;
}
.rle__btn--danger:not(:disabled) { color: var(--color-danger); }

.rle__langs {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
    gap: 6px;
}
.rle__lang { display: flex; align-items: center; gap: 8px; }
.rle__langcode {
    flex: 0 0 auto; min-width: 2.2rem;
    font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
    color: var(--color-text-muted);
}

.rle__add { align-self: flex-start; height: 44px; }
</style>
