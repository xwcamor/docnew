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
 * `migrated` sigue en el mapa aunque la importación ya no lo escriba —las firmas
 * del sistema anterior llegan como reconocimiento facial— porque puede haber
 * bases cargadas antes de ese cambio, y un método que el mapa no conozca dejaría
 * el hueco en blanco. Es la salida por defecto, no un caso normal.
 */
const marca = computed(() => {
    if (!props.signature?.method) return null;

    return CLASES[props.signature.method] ?? CLASES.migrated;
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
