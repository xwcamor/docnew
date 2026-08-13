<script setup>
/**
 * Los grupos de un checklist: un rótulo y qué puntos van debajo.
 *
 * Hoy sólo lo usa el EPP, que reparte los equipos por parte del cuerpo —cabeza,
 * cara, cuerpo, ojos, manos, oídos, vías respiratorias, pies— como hacía
 * `epp_categories` en el sistema anterior.
 *
 * ES UNA VISTA SOBRE «PUNTOS A REVISAR», NO OTRA LISTA.
 *
 * El catálogo sigue siendo `config.items`: es lo que se guarda en cada
 * respuesta, lo que alinea las columnas del PDF y contra lo que casa lo
 * migrado. Aquí sólo se dice bajo qué rótulo va cada uno, y por eso los puntos
 * se ELIGEN de una lista y no se escriben: escribiéndolos, un dedazo crearía un
 * grupo que apunta a un equipo que no existe, y ese equipo desaparecería del
 * reparto sin que nadie se entere. Lo que no se meta en ningún grupo sale igual
 * en la tablet y en el papel, al final y sin rótulo.
 *
 * Sin este control la clave caería en el editor de listas de textos —es el
 * comportamiento por defecto— y el reparto entero se guardaría como
 * «[object Object]» al primer guardado desde la pantalla.
 */
import { computed } from 'vue';
import { Button, Input, Select, Tooltip } from 'ant-design-vue';
import {
    ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, PlusOutlined,
} from '@ant-design/icons-vue';

const props = defineProps({
    /** [{ name, items: [...] }] */
    modelValue: { type: Array, default: () => [] },
    /** El catálogo del campo: de aquí salen los puntos que se pueden repartir. */
    items:      { type: Array, default: () => [] },
    disabled:   { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const grupos = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []));

const opciones = computed(() => props.items.map((x) => ({ value: x, label: x })));

/** Lo que no ha reclamado ningún grupo: sale avisado, no escondido. */
const sueltos = computed(() => {
    const puestos = new Set(grupos.value.flatMap((g) => (Array.isArray(g?.items) ? g.items : [])));

    return props.items.filter((x) => !puestos.has(x));
});

const emitir = (lista) => emit('update:modelValue', lista);

function fijar(i, campo, valor) {
    emitir(grupos.value.map((g, j) => (j === i ? { ...g, [campo]: valor } : g)));
}

function quitar(i) {
    emitir(grupos.value.filter((_, j) => j !== i));
}

/** Mover es un intercambio con el vecino: el orden es el de las columnas del PDF. */
function mover(i, salto) {
    const destino = i + salto;

    if (destino < 0 || destino >= grupos.value.length) return;

    const lista = [...grupos.value];
    [lista[i], lista[destino]] = [lista[destino], lista[i]];
    emitir(lista);
}

function anadir() {
    emitir([...grupos.value, { name: '', items: [] }]);
}
</script>

<template>
    <div class="gle">
        <div v-for="(grupo, i) in grupos" :key="i" class="gle__row">
            <div class="gle__head">
                <Input
                    :value="grupo.name ?? ''"
                    :disabled="disabled"
                    :maxlength="60"
                    size="large"
                    :placeholder="$t('form_templates.group_name_placeholder')"
                    @update:value="fijar(i, 'name', $event)"
                />

                <Tooltip :title="$t('form_templates.move_up')">
                    <Button :disabled="disabled || i === 0" size="large" @click="mover(i, -1)">
                        <template #icon><ArrowUpOutlined /></template>
                    </Button>
                </Tooltip>
                <Tooltip :title="$t('form_templates.move_down')">
                    <Button :disabled="disabled || i === grupos.length - 1" size="large" @click="mover(i, 1)">
                        <template #icon><ArrowDownOutlined /></template>
                    </Button>
                </Tooltip>
                <Tooltip :title="$t('global.delete')">
                    <Button danger :disabled="disabled" size="large" @click="quitar(i)">
                        <template #icon><DeleteOutlined /></template>
                    </Button>
                </Tooltip>
            </div>

            <!-- Se eligen, no se escriben: ver la nota de arriba. -->
            <Select
                mode="multiple"
                :value="Array.isArray(grupo.items) ? grupo.items : []"
                :options="opciones"
                :disabled="disabled"
                size="large"
                class="gle__items"
                :placeholder="$t('form_templates.group_items_placeholder')"
                @update:value="fijar(i, 'items', $event)"
            />
        </div>

        <Button type="dashed" :disabled="disabled" size="large" class="gle__add" @click="anadir">
            <PlusOutlined /> {{ $t('form_templates.group_add') }}
        </Button>

        <!-- Un punto sin grupo no es un error, pero hay que verlo: se imprime al
             final y sin rótulo, y quien acaba de añadirlo al catálogo espera
             encontrarlo dentro de alguno. -->
        <p v-if="sueltos.length" class="gle__loose">
            {{ $t('form_templates.group_ungrouped', { items: sueltos.join(', ') }) }}
        </p>
    </div>
</template>

<style scoped>
.gle { display: flex; flex-direction: column; gap: 12px; }

.gle__row {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 10px;
    border: 1px solid var(--color-border-soft, #f0f0f0);
    border-radius: 8px;
}

.gle__head { display: flex; gap: 8px; align-items: center; }
.gle__head > :first-child { flex: 1 1 auto; min-width: 0; }

.gle__items { width: 100%; }

.gle__add { align-self: flex-start; }

.gle__loose {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--color-text-muted, #6A6D70);
}
</style>
