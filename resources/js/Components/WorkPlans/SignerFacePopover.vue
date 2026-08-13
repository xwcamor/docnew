<script setup>
import { computed } from 'vue';
import { Button, Popover, Tooltip } from 'ant-design-vue';
import { CameraOutlined } from '@ant-design/icons-vue';
import { useDateFormat } from '@/Composables/useDateFormat';
import SignatureMark from '@/Components/WorkPlans/SignatureMark.vue';

/**
 * La cara con la que se firmó, y debajo el rastro de esa firma.
 *
 * Antes esto era solo la foto. La foto contesta «¿quién estuvo?», que es la
 * mitad; la otra mitad —con qué se comprobó, cuándo, desde qué IP y con qué
 * aparato— estaba guardada desde el principio y no se enseñaba en ninguna
 * pantalla. Un plan firmado que acaba delante de un inspector se defiende con
 * las dos cosas.
 *
 * Todo esto va con `people.view_private_info`: el servidor manda `face_url` y
 * `audit` en nulo a quien no lo tenga, así que a un perfil de campo este botón
 * ni le aparece.
 */
const props = defineProps({
    faceUrl:   { type: String, default: null },
    signature: { type: Object, default: null },
    name:      { type: String, default: '' },
});

const { formatDateTime } = useDateFormat();

const auditoria = computed(() => props.signature?.audit ?? null);

/** Las filas del rastro, ya resueltas: sólo se pintan las que tienen algo. */
const filas = computed(() => {
    const a = auditoria.value;

    if (!a) return [];

    return [
        ['signed_at', a.signed_at ? formatDateTime(a.signed_at) : null],
        ['match', a.match_percent !== null && a.match_percent !== undefined
            ? `${a.match_percent}%` : null],
        ['ip', a.ip],
        ['device', a.device_id],
        ['coords', a.coords],
        ['browser', a.user_agent],
        ['reason', a.override_reason],
    ].filter(([, valor]) => !!valor);
});
</script>

<template>
    <Popover v-if="faceUrl" trigger="click" placement="left">
        <template #content>
            <div class="firmante">
                <img :src="faceUrl" class="firmante__cara" alt="">

                <SignatureMark :signature="signature" :name="name" />

                <dl v-if="filas.length" class="firmante__rastro">
                    <template v-for="[clave, valor] in filas" :key="clave">
                        <dt>{{ $t(`work_plans.sign_audit_${clave}`) }}</dt>
                        <dd>{{ valor }}</dd>
                    </template>
                </dl>
            </div>
        </template>

        <Tooltip :title="$t('work_plans.sign_audit_open')">
            <Button size="small" type="text" :aria-label="$t('work_plans.sign_audit_open')">
                <template #icon><CameraOutlined /></template>
            </Button>
        </Tooltip>
    </Popover>
</template>

<style scoped>
.firmante { display: flex; flex-direction: column; gap: 10px; max-width: 260px; }
.firmante__cara {
    display: block; width: 220px; height: 220px; object-fit: cover;
    border-radius: 8px; align-self: center;
}

/* Etiqueta encima y valor debajo: el user-agent es larguísimo y en dos
   columnas empuja la tarjeta fuera de la pantalla. */
.firmante__rastro { margin: 0; font-size: 0.8125rem; }
.firmante__rastro dt { color: var(--color-text-muted); }
.firmante__rastro dd {
    margin: 0 0 6px; overflow-wrap: anywhere;
}
</style>
