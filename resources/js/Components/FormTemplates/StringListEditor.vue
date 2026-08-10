<script setup>
/**
 * Lista de textos con su orden: las opciones de un desplegable, las columnas de
 * una tabla, los puntos que se revisan de una herramienta.
 *
 * Casi toda la `config` de un campo es una lista de textos — así la lee
 * `catalogo()` al pintar el formato en obra — y el orden importa: es el orden
 * en que salen las opciones en la tablet. De ahí que cada fila lleve subir y
 * bajar y no sólo borrar.
 *
 * La alternativa era un cuadro con el JSON dentro. Eso no es un editor: es
 * pasarle el problema al usuario, que aquí es un supervisor de obra.
 */
import { nextTick, ref } from 'vue';
import { Button, Input, Tooltip } from 'ant-design-vue';
import {
    ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, PlusOutlined,
} from '@ant-design/icons-vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    disabled:   { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const entradas = ref(null);

const emitir = (lista) => emit('update:modelValue', lista);

function cambiar(i, valor) {
    const lista = [...props.modelValue];
    lista[i] = valor;
    emitir(lista);
}

function quitar(i) {
    emitir(props.modelValue.filter((_, j) => j !== i));
}

/** Mover es un intercambio con el vecino: no hace falta más para una lista. */
function mover(i, salto) {
    const destino = i + salto;

    if (destino < 0 || destino >= props.modelValue.length) return;

    const lista = [...props.modelValue];
    [lista[i], lista[destino]] = [lista[destino], lista[i]];
    emitir(lista);
}

/**
 * Al añadir se enfoca la fila nueva. Sin esto hay que apuntar con el dedo al
 * recuadro que acaba de aparecer, y añadir diez opciones seguidas son veinte
 * toques en vez de diez.
 */
async function anadir() {
    emitir([...props.modelValue, '']);
    await nextTick();
    entradas.value?.[props.modelValue.length - 1]?.focus?.();
}
</script>

<template>
    <div class="sle">
        <p v-if="!modelValue.length" class="sle__empty">{{ $t('form_templates.list_empty') }}</p>

        <!-- Las listas del AST no son de tres elementos: el catálogo de
             actividades de la v1 trae 126 y el de peligros 83. Pintadas
             seguidas, la ficha del AST medía veinte mil píxeles de alto y
             llegar al campo siguiente era un scroll de un minuto. Con tope y
             scroll propio, la lista larga se queda dentro de su caja. -->
        <div class="sle__rows" :class="{ 'sle__rows--tall': modelValue.length > 8 }">
        <div v-for="(item, i) in modelValue" :key="i" class="sle__row">
            <Input
                ref="entradas"
                :value="item"
                :disabled="disabled"
                :placeholder="placeholder || $t('form_templates.list_item_placeholder')"
                :maxlength="180"
                @update:value="cambiar(i, $event)"
            />
            <Tooltip :title="$t('form_templates.move_up')">
                <Button class="sle__btn" :disabled="disabled || i === 0" @click="mover(i, -1)">
                    <ArrowUpOutlined />
                </Button>
            </Tooltip>
            <Tooltip :title="$t('form_templates.move_down')">
                <Button class="sle__btn" :disabled="disabled || i === modelValue.length - 1" @click="mover(i, 1)">
                    <ArrowDownOutlined />
                </Button>
            </Tooltip>
            <Tooltip :title="$t('global.delete')">
                <Button class="sle__btn sle__btn--danger" :disabled="disabled" @click="quitar(i)">
                    <DeleteOutlined />
                </Button>
            </Tooltip>
        </div>
        </div>

        <Button v-if="!disabled" type="dashed" class="sle__add" @click="anadir">
            <PlusOutlined /> {{ $t('form_templates.list_add') }}
        </Button>
    </div>
</template>

<style scoped>
.sle { display: flex; flex-direction: column; gap: 6px; }

.sle__empty {
    margin: 0 0 2px;
    font-size: 0.8125rem;
    color: var(--color-text-dim);
}

.sle__rows { display: flex; flex-direction: column; gap: 6px; }
.sle__rows--tall {
    max-height: 320px;
    overflow-y: auto;
    padding-right: 4px;
    border: 1px solid var(--color-border-soft);
    border-radius: 6px;
    padding: 8px;
    background: var(--color-surface);
}

.sle__row { display: flex; align-items: center; gap: 6px; }
.sle__row :deep(.ant-input) { flex: 1 1 auto; min-width: 0; }

/* 44px: es el objetivo de toque con guantes (docs/UI.md §3). Un botón de
   Ant Design mide 32 y con el dedo enguantado se falla la mitad de las veces. */
.sle__btn {
    flex: 0 0 auto;
    width: 44px;
    height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}
.sle__btn--danger:not(:disabled) { color: var(--color-danger); }

.sle__add { align-self: flex-start; height: 44px; }
</style>
