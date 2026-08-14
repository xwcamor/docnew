<script setup>
/**
 * La configuración de un campo, con los controles que pide su tipo.
 *
 * Un `select` necesita sus opciones, una `table` sus columnas, un `number` su
 * rango y una matriz de riesgo sus severidades y sus probabilidades. Cada tipo
 * pide una cosa distinta, así que lo que se pinta aquí no es un formulario
 * fijo: es lo que el servidor haya dicho que ese tipo admite.
 *
 * Esa lista viene en `spec` y la arma el controlador leyendo `FormField::TIPOS`
 * y `FormTemplateBuilder::TIPOS`. Aquí no hay ni un tipo escrito a mano a
 * propósito: se están portando formatos de la v1 y aparecen tipos nuevos. Con
 * la lista copiada en el front, un tipo nuevo existiría en la base de datos y
 * no en la pantalla, y nadie se enteraría hasta que alguien preguntara por qué
 * no se puede elegir.
 *
 * Y por eso tampoco hay un cuadro de texto con el JSON crudo: eso no configura
 * nada, sólo traslada el problema a quien está en obra con guantes.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { InputNumber, Input } from 'ant-design-vue';
import StringListEditor from './StringListEditor.vue';
import GroupListEditor from './GroupListEditor.vue';
import AnswerListEditor from './AnswerListEditor.vue';
import RiskMatrixTableEditor from './RiskMatrixTableEditor.vue';
import RiskLevelsEditor from './RiskLevelsEditor.vue';
import LabelMapEditor from './LabelMapEditor.vue';

const props = defineProps({
    // [{ key, label, control: 'list'|'answers'|'groups'|'matrix'|'levels'|'labels'|'number'|'text', required }]
    spec:       { type: Array,  default: () => [] },
    modelValue: { type: Object, default: () => ({}) },
    disabled:   { type: Boolean, default: false },
    // Texto de error del servidor para la config de este campo, si lo hubo.
    error:      { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const pagina = usePage();

/**
 * Los idiomas en los que se puede escribir un rótulo del cliente.
 *
 * Son los que el super tiene activos, los mismos del selector de la barra: dar
 * de alta uno nuevo abre su recuadro aquí sin tocar nada más, que es justo lo
 * que no pasaba cuando esto eran dos claves paralelas (`…` y `…_en`).
 */
const idiomas = computed(() => pagina.props?.availableLocales ?? []);

const hayAlgoQueConfigurar = computed(() => props.spec.length > 0);

function fijar(clave, valor) {
    emit('update:modelValue', { ...props.modelValue, [clave]: valor });
}

/** Una clave de lista puede llegar como número (`severidades: 5`): ver catalogo(). */
function lista(clave) {
    const valor = props.modelValue?.[clave];

    if (Array.isArray(valor)) return valor;

    const n = Number(valor);

    if (Number.isInteger(n) && n > 0) {
        return Array.from({ length: n }, (_, i) => String(i + 1));
    }

    return [];
}
</script>

<template>
    <div class="fce">
        <p v-if="!hayAlgoQueConfigurar" class="fce__none">{{ $t('form_templates.field_config_none') }}</p>

        <div v-for="item in spec" :key="item.key" class="fce__item">
            <label class="fce__label" :for="`cfg-${item.key}`">
                {{ item.label }}
                <span v-if="item.required" class="fce__req" :title="$t('global.required')">*</span>
            </label>

            <StringListEditor
                v-if="item.control === 'list'"
                :model-value="lista(item.key)"
                :disabled="disabled"
                @update:model-value="fijar(item.key, $event)"
            />

            <!-- Los grupos no son textos sueltos: cada uno lleva rótulo y su
                 propia lista, que se elige del catálogo del campo. Con el
                 editor de listas se guardarían como «[object Object]». -->
            <GroupListEditor
                v-else-if="item.control === 'groups'"
                :model-value="Array.isArray(modelValue?.[item.key]) ? modelValue[item.key] : []"
                :items="lista('items')"
                :disabled="disabled"
                @update:model-value="fijar(item.key, $event)"
            />

            <!-- Las respuestas llevan su TONO: cuál cuenta como no conformidad
                 no se puede adivinar del castellano. Ver `AnswerListEditor`. -->
            <AnswerListEditor
                v-else-if="item.control === 'answers'"
                :model-value="Array.isArray(modelValue?.[item.key]) ? modelValue[item.key] : []"
                :disabled="disabled"
                :locales="idiomas"
                @update:model-value="fijar(item.key, $event)"
            />

            <!-- La tabla de la matriz, pintada como el papel del cliente para
                 poder cotejarla de un vistazo. El puntaje NO es severidad ×
                 probabilidad: doce de las veinticinco celdas de la matriz de la
                 v1 caen en otra banda si se multiplica. -->
            <RiskMatrixTableEditor
                v-else-if="item.control === 'matrix'"
                :model-value="Array.isArray(modelValue?.[item.key]) ? modelValue[item.key] : []"
                :config="modelValue ?? {}"
                :disabled="disabled"
                @update:model-value="fijar(item.key, $event)"
            />

            <!-- Las bandas: rango, rótulo, color y cuál es la tolerable. -->
            <RiskLevelsEditor
                v-else-if="item.control === 'levels'"
                :model-value="Array.isArray(modelValue?.[item.key]) ? modelValue[item.key] : []"
                :config="modelValue ?? {}"
                :disabled="disabled"
                :locales="idiomas"
                @update:model-value="fijar(item.key, $event)"
            />

            <!-- Los nombres de las claves internas de cada eje («c3» →
                 «Permanente»). Las filas salen del eje, no se escriben aquí. -->
            <LabelMapEditor
                v-else-if="item.control === 'labels'"
                :model-value="modelValue?.[item.key] ?? {}"
                :claves="item.key === 'severity_labels'
                    ? (modelValue?.severities ?? modelValue?.severidades ?? [])
                    : (modelValue?.probabilities ?? modelValue?.probabilidades ?? [])"
                :disabled="disabled"
                :locales="idiomas"
                @update:model-value="fijar(item.key, $event)"
            />

            <InputNumber
                v-else-if="item.control === 'number'"
                :id="`cfg-${item.key}`"
                class="fce__number"
                :value="modelValue?.[item.key] ?? null"
                :disabled="disabled"
                size="large"
                @update:value="fijar(item.key, $event)"
            />

            <Input
                v-else
                :id="`cfg-${item.key}`"
                :value="modelValue?.[item.key] ?? ''"
                :disabled="disabled"
                :maxlength="180"
                size="large"
                :placeholder="$t('form_templates.field_label_placeholder')"
                @update:value="fijar(item.key, $event)"
            />
        </div>

        <p v-if="error" class="fce__error">{{ error }}</p>
    </div>
</template>

<style scoped>
.fce { display: flex; flex-direction: column; gap: 14px; }

.fce__none {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--color-text-dim);
}

.fce__label {
    display: block;
    margin-bottom: 6px;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-text-muted);
}

.fce__req { color: var(--color-danger); margin-left: 2px; }

.fce__number { width: 160px; }

.fce__error {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--color-danger);
}
</style>
