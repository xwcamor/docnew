<script setup>
import { computed } from 'vue';
import { Tag, Tooltip } from 'ant-design-vue';
import {
    ScanOutlined, WarningOutlined, EditOutlined,
    DatabaseOutlined, CheckCircleOutlined,
} from '@ant-design/icons-vue';

/**
 * Cómo se produjo una firma, en una marca.
 *
 * La hora dice CUÁNDO se firmó. Esto dice si el servidor llegó a reconocer la
 * cara, y son cosas distintas: en la ficha del plan una firma verificada y una
 * que se capturó porque **no** reconoció salían exactamente iguales —la misma
 * hora, el mismo check verde— y la segunda es justo la que hay que ir a
 * revisar. Nada en la pantalla lo decía.
 *
 * Vive en un componente y no en cada tarjeta porque lo usan la cuadrilla y las
 * aprobaciones, y dos copias acabarían diciendo cosas distintas de lo mismo.
 *
 * Color Y palabra, como el resto del sistema: quien no distingue el verde del
 * naranja tiene que poder leerlo.
 */
const props = defineProps({
    // { method, verified, pending_review } — lo que manda el servidor. En nulo
    // no se pinta nada: la firma es de antes de que esto se guardara.
    signature: { type: Object, default: null },
    name:      { type: String, default: '' },
});

const CLASES = {
    face_recognition: { icon: ScanOutlined,        color: 'success', clave: 'face_recognition' },
    timeout_capture:  { icon: WarningOutlined,     color: 'warning', clave: 'timeout_capture' },
    manual:           { icon: EditOutlined,        color: 'warning', clave: 'manual' },
    migrated:         { icon: DatabaseOutlined,    color: 'default', clave: 'migrated' },
    reused:           { icon: CheckCircleOutlined, color: 'success', clave: 'reused' },
};

/**
 * Qué marca le toca a esta firma.
 *
 * Las migradas son el caso que hay que tratar aparte. Su método es `migrated`
 * porque el reconocimiento de la v1 lo decidía el navegador y no dejó prueba,
 * pero sí se conservó lo que aquel sistema creía (`used_ai`). Decir «del
 * sistema anterior» de una firma que **sí** se reconoció no cuenta nada: lo que
 * se pregunta mirando la fila es cómo se comprobó a esa persona, y la respuesta
 * es que por la cara. La salvedad —que no la verificó este servidor— va en el
 * tooltip, que es donde cabe sin quitarle sitio a lo que importa.
 *
 * Un método que no esté en el mapa tampoco deja el hueco en blanco.
 */
const marca = computed(() => {
    const firma = props.signature;

    if (!firma?.method) return null;

    if (firma.method === 'migrated') {
        return firma.used_ai
            ? { ...CLASES.face_recognition, clave: 'migrated_recognised' }
            : CLASES.migrated;
    }

    return CLASES[firma.method] ?? CLASES.migrated;
});
</script>

<template>
    <Tooltip v-if="marca" :title="$t(`work_plans.sign_${marca.clave}_hint`, { name })">
        <Tag :color="marca.color" :bordered="false" class="sign-mark">
            <component :is="marca.icon" aria-hidden="true" />
            {{ $t(`work_plans.sign_${marca.clave}`) }}
        </Tag>
    </Tooltip>
</template>

<style scoped>
.sign-mark {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin: 0;
    font-size: 0.75rem;
}
</style>
