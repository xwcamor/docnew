<script setup>
/**
 * Los nombres con los que se LEEN las claves internas de un eje de la matriz.
 *
 * QUÉ RESUELVE
 * ------------
 * Lo que se guarda en cada peligro evaluado es `c1..c5` / `p1..p5`: es lo que
 * indexa la tabla de la matriz por posición y lo que traen las 3 657 entregas
 * migradas. Pero la v1 nunca enseñó eso — pintaba la traducción que escribió el
 * administrador: «Catastrófico», «Podría suceder». Esos nombres llegan en
 * `config.severity_labels` y `config.probability_labels`, y hasta ahora no había
 * forma de escribirlos desde la pantalla: un formato nuevo enseñaba «c3» pelado
 * en la tablet y en el papel.
 *
 * LAS CLAVES SALEN DEL EJE, NO SE ESCRIBEN AQUÍ
 * ----------------------------------------------
 * Este control no da de alta claves: pinta una fila por cada severidad (o
 * probabilidad) que el campo ya declara, y pide su nombre. Al revés se podrían
 * escribir rótulos de claves que no existen —invisibles para siempre— y quedaría
 * sin nombre la que sí existe, que es el fallo que de verdad se ve.
 *
 * UN RECUADRO POR IDIOMA. Es lo que hace que un idioma nuevo no pida ni columna
 * ni migración: antes eran dos claves paralelas (`severity_labels` y
 * `severity_labels_en`) y el tercer idioma no tenía dónde ir.
 */
import { computed } from 'vue';
import { Input } from 'ant-design-vue';
import { textoTraducible, valoresDeCatalogo } from '@/Support/catalogo';

const props = defineProps({
    /** El mapa clave → texto (o clave → {es, en, …}). */
    modelValue: { type: Object, default: () => ({}) },
    /** Las claves del eje: de dónde salen las filas. */
    claves:     { type: Array, default: () => [] },
    disabled:   { type: Boolean, default: false },
    locales:    { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

const filas = computed(() => valoresDeCatalogo(props.claves));

/**
 * El texto de una clave en un idioma.
 *
 * La forma vieja es una cadena suelta —el mapa entero en castellano, con su
 * `_en` al lado— y se lee como el texto del PRIMER idioma: es donde estaba
 * escrito, y así abrir el editor no lo borra.
 */
function texto(clave, idioma) {
    const valor = props.modelValue?.[clave];

    if (typeof valor === 'string') return idioma === props.locales[0]?.code ? valor : '';

    return valor?.[idioma] ?? '';
}

/** Lo que se va a leer de verdad, para enseñarlo de sugerencia en los demás idiomas. */
const vigente = (clave) => textoTraducible(props.modelValue?.[clave], null) || clave;

function escribir(clave, idioma, nuevo) {
    const previo = props.modelValue?.[clave];

    // Una cadena suelta se asciende a mapa en cuanto se toca cualquier idioma:
    // así no se pierde lo que ya estaba escrito al añadir el segundo.
    const mapa = typeof previo === 'string'
        ? { [props.locales[0]?.code ?? 'es']: previo }
        : { ...(previo ?? {}) };

    if (String(nuevo ?? '').trim() === '') {
        delete mapa[idioma];
    } else {
        mapa[idioma] = nuevo;
    }

    const salida = { ...(props.modelValue ?? {}) };

    if (! Object.keys(mapa).length) {
        delete salida[clave];
    } else {
        // Con un solo idioma se guarda la cadena a secas, que es la forma que
        // tienen las cuatro plantillas migradas: abrir el editor y guardar sin
        // tocar nada no puede reescribirles la config.
        const idiomas = Object.keys(mapa);

        salida[clave] = idiomas.length === 1 && idiomas[0] === (props.locales[0]?.code ?? 'es')
            ? mapa[idiomas[0]]
            : mapa;
    }

    emit('update:modelValue', salida);
}
</script>

<template>
    <div class="lme">
        <p v-if="!filas.length" class="lme__empty">{{ $t('form_templates.labels_need_keys') }}</p>

        <div v-for="clave in filas" :key="clave" class="lme__row">
            <span class="lme__clave" :title="$t('form_templates.labels_key_help')">{{ clave }}</span>

            <div class="lme__langs">
                <label v-for="idioma in locales" :key="idioma.code" class="lme__lang">
                    <span v-if="locales.length > 1" class="lme__langcode">{{ idioma.code }}</span>
                    <Input
                        :value="texto(clave, idioma.code)"
                        :disabled="disabled"
                        :placeholder="vigente(clave)"
                        :maxlength="120"
                        @update:value="escribir(clave, idioma.code, $event)"
                    />
                </label>
            </div>
        </div>
    </div>
</template>

<style scoped>
.lme { display: flex; flex-direction: column; gap: 6px; }

.lme__empty {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--color-text-dim);
}

.lme__row { display: flex; align-items: center; gap: 8px; }

/* La clave interna, en monoespaciada y apagada: es un identificador, no un
   texto que nadie vaya a leer en obra. */
.lme__clave {
    flex: 0 0 auto;
    min-width: 3.5rem;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.8125rem;
    color: var(--color-text-dim);
}

.lme__langs {
    flex: 1 1 auto; min-width: 0;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
    gap: 6px;
}
.lme__lang { display: flex; align-items: center; gap: 6px; }
.lme__langcode {
    flex: 0 0 auto; min-width: 2rem;
    font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
    color: var(--color-text-muted);
}
</style>
