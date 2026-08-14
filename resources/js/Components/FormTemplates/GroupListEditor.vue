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
import { computed, ref } from 'vue';
import { textoTraducible } from '@/Support/catalogo';
import { useCatalogos } from '@/Composables/useCatalogos';
import { Button, Input, Select, Tooltip } from 'ant-design-vue';
import {
    ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, PictureOutlined, PlusOutlined,
} from '@ant-design/icons-vue';

const props = defineProps({
    /** [{ name, items: [...], image? }] */
    modelValue: { type: Array, default: () => [] },
    /** El catálogo del campo: de aquí salen los puntos que se pueden repartir. */
    items:      { type: Array, default: () => [] },
    disabled:   { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const { locale } = useCatalogos();

/**
 * El nombre que se VE: el rotulo del grupo puede ser una cadena o un mapa por
 * idioma ({es, en, pt}), como todo texto del cliente. Aqui se enseña el del
 * idioma en curso.
 */
const nombreVisible = (grupo) => textoTraducible(grupo?.name, locale.value);

/**
 * Y al escribir NO se pisa el mapa: si el nombre trae traducciones, se cambia
 * solo la del idioma en que se esta navegando. Sin esto, corregir una tilde en
 * castellano borraba el ingles y el portugues del bloque de un plumazo.
 */
function fijarNombre(i, texto) {
    const actual = grupos.value[i]?.name;

    const nuevo = actual && typeof actual === 'object' && ! Array.isArray(actual)
        ? { ...actual, [locale.value ?? 'es']: texto }
        : texto;

    fijar(i, 'name', nuevo);
}

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

// ── La imagen del grupo ─────────────────────────────────────────────────────
//
// El papel de la v1 lleva un dibujo al lado de cada bloque del Pare y Tome 5
// (el semaforo, la cabeza, la lupa). Se guarda como DATA URI dentro de la
// propia config, y es una decision, no una comodidad: asi no hace falta
// almacen de archivos, la imagen se copia sola con cada version del formato
// —lo firmado con la v1 se imprime con el icono de la v1— y DomPDF la pinta
// sin salir a la red. El precio es el tamaño, y por eso se REDUCE al subirla:
// un icono de 96px pesa unos pocos KB; la foto de 4MB de una camara, no.

const selectorDeImagen = ref(null);
const grupoEligiendo = ref(null);

function pedirImagen(i) {
    grupoEligiendo.value = i;
    selectorDeImagen.value?.click();
}

async function imagenElegida(evento) {
    const archivo = evento.target.files?.[0];
    const i = grupoEligiendo.value;

    evento.target.value = '';

    if (! archivo || i === null) return;

    fijar(i, 'image', await comoIcono(archivo));
}

/** El archivo reducido a un icono cuadrado de 96px, como data URI. */
function comoIcono(archivo) {
    return new Promise((resolver, rechazar) => {
        const imagen = new Image();
        const url = URL.createObjectURL(archivo);

        imagen.onload = () => {
            const LADO = 96;
            const escala = Math.min(LADO / imagen.width, LADO / imagen.height, 1);
            const lienzo = document.createElement('canvas');

            lienzo.width = Math.round(imagen.width * escala);
            lienzo.height = Math.round(imagen.height * escala);
            lienzo.getContext('2d').drawImage(imagen, 0, 0, lienzo.width, lienzo.height);

            URL.revokeObjectURL(url);
            resolver(lienzo.toDataURL('image/png'));
        };
        imagen.onerror = () => { URL.revokeObjectURL(url); rechazar(new Error('no es una imagen')); };
        imagen.src = url;
    });
}
</script>

<template>
    <div class="gle">
        <div v-for="(grupo, i) in grupos" :key="i" class="gle__row">
            <div class="gle__head">
                <!-- El icono actual, si lo hay: tocarlo lo cambia, la equis lo
                     quita. Un grupo sin imagen es un grupo normal. -->
                <button
                    v-if="grupo.image"
                    type="button"
                    class="gle__thumb"
                    :disabled="disabled"
                    :title="$t('form_templates.group_image_change')"
                    @click="pedirImagen(i)"
                >
                    <img :src="grupo.image" alt="">
                </button>

                <Input
                    :value="nombreVisible(grupo)"
                    :disabled="disabled"
                    :maxlength="120"
                    size="large"
                    :placeholder="$t('form_templates.group_name_placeholder')"
                    @update:value="fijarNombre(i, $event)"
                />

                <Tooltip :title="grupo.image ? $t('form_templates.group_image_remove') : $t('form_templates.group_image_add')">
                    <Button
                        :disabled="disabled"
                        size="large"
                        @click="grupo.image ? fijar(i, 'image', null) : pedirImagen(i)"
                    >
                        <template #icon><PictureOutlined /></template>
                    </Button>
                </Tooltip>

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

        <!-- Uno solo para todos los grupos: el que dispara `pedirImagen`. -->
        <input ref="selectorDeImagen" hidden type="file" accept="image/*" @change="imagenElegida">

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
.gle__head :deep(.ant-input) { flex: 1 1 auto; min-width: 0; }

.gle__thumb {
    flex: 0 0 auto;
    width: 44px; height: 44px; padding: 3px;
    border: 1px solid var(--color-border-soft, #f0f0f0); border-radius: 8px;
    background: var(--color-surface, #fff);
    cursor: pointer;
}
.gle__thumb img { width: 100%; height: 100%; object-fit: contain; display: block; }

.gle__items { width: 100%; }

.gle__add { align-self: flex-start; }

.gle__loose {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--color-text-muted, #6A6D70);
}
</style>
