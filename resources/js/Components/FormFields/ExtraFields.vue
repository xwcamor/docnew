<script setup>
/**
 * Campos de correccion de una fila (`config.extra`).
 *
 * El EPP de papel traia tres columnas al final —medida de correccion, fecha
 * limite y verificacion— que solo se llenan cuando algun item salio "No
 * conforme". Aqui no son columnas: aparecen debajo de la fila y solo cuando
 * hacen falta, que es como se usan de verdad.
 */
import { DatePicker, Textarea } from 'ant-design-vue';
import { usePage } from '@inertiajs/vue3';
import { translate } from '@/Plugins/i18n';
import { humanizar } from './respuestas';

const props = defineProps({
    keys:     { type: Array, default: () => [] },
    values:   { type: Object, default: () => ({}) },
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['change']);

const page = usePage();

/**
 * El nombre de la clave lo pone la config del campo (`config.extra`), asi que
 * puede llegar cualquiera: si no hay traduccion para ella, se humaniza el code.
 */
function etiqueta(clave) {
    const texto = translate(page.props?.translations, `field_work.extra.${clave}`);

    return texto.startsWith('field_work.') ? humanizar(clave) : texto;
}

/** Las claves que terminan en _date son fechas: calendario, no texto libre. */
const esFecha = (clave) => String(clave).endsWith('_date');
</script>

<template>
    <div class="ff-extra">
        <div v-for="clave in keys" :key="clave" class="ff-extra__item">
            <label class="ff-label" :for="`extra-${clave}`">{{ etiqueta(clave) }}</label>

            <span v-if="readonly" class="ff-readonly">{{ values[clave] || '—' }}</span>

            <DatePicker
                v-else-if="esFecha(clave)"
                :id="`extra-${clave}`"
                class="ff-input"
                :value="values[clave] || null"
                value-format="YYYY-MM-DD"
                size="large"
                @update:value="emit('change', clave, $event || null)"
            />

            <Textarea
                v-else
                :id="`extra-${clave}`"
                class="ff-input"
                :value="values[clave] || ''"
                :rows="2"
                @update:value="emit('change', clave, $event)"
            />
        </div>
    </div>
</template>
