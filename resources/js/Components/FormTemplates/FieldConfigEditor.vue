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
import { InputNumber, Input } from 'ant-design-vue';
import StringListEditor from './StringListEditor.vue';

const props = defineProps({
    // [{ key, label, control: 'list'|'number'|'text', required }]
    spec:       { type: Array,  default: () => [] },
    modelValue: { type: Object, default: () => ({}) },
    disabled:   { type: Boolean, default: false },
    // Texto de error del servidor para la config de este campo, si lo hubo.
    error:      { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

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
