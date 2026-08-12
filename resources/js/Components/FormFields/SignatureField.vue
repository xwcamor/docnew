<script setup>
import { ref } from 'vue';
import { Button, Popconfirm } from 'ant-design-vue';
import { router } from '@inertiajs/vue3';
import SignaturePad from '@/Components/FieldWork/SignaturePad.vue';

/**
 * Un campo de firma dentro de un formato.
 *
 * Ojo con no confundirlo con la firma de una PERSONA del plan, que es otra cosa
 * y vive en `Sign.vue`: aquella identifica a alguien contra su firma de
 * referencia, ésta es un trazo que el formato pide en un sitio concreto — el
 * «recibí conforme» al pie de un permiso, la casilla del responsable de área.
 *
 * Se guarda como adjunto del campo y no como respuesta, por lo mismo que la
 * foto: es una imagen. Eso hace que el PDF ya sepa pintarla —`FormField::
 * CON_ARCHIVO` la incluye— sin que haya que enseñarle nada nuevo.
 *
 * El lienzo es el mismo `SignaturePad` de la pantalla de firmar: punteros en
 * vez de ratón, sin scroll al arrastrar el dedo y escalado por
 * `devicePixelRatio`, que son las tres cosas que hacen que se pueda firmar con
 * guantes en una tablet.
 */
const props = defineProps({
    submissionSlug: { type: String, required: true },
    field:       { type: Object,  required: true },
    attachments: { type: Array,   default: () => [] },
    readonly:    { type: Boolean, default: false },
    faltante:    { type: Boolean, default: false },
});

const trazo = ref(null);
const guardando = ref(false);

/** El dataURL del lienzo → el mismo `files[]` que usa cualquier adjunto. */
async function guardar() {
    if (! trazo.value) return;

    guardando.value = true;

    const blob = await (await fetch(trazo.value)).blob();

    const datos = new FormData();
    datos.append('files[]', blob, `firma-${props.field.code}.png`);
    datos.append('form_field_id', props.field.id);

    router.post(route('field_work.forms.attach', props.submissionSlug), datos, {
        preserveScroll: true,
        onSuccess: () => { trazo.value = null; },
        onFinish:  () => { guardando.value = false; },
    });
}

function borrar(a) {
    router.delete(route('field_work.forms.detach', [props.submissionSlug, a.id]), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="firma-campo" :class="{ 'firma-campo--falta': faltante }">
        <!-- Ya firmado: se ve el trazo, no un lienzo en blanco que invita a
             volver a firmar encima. -->
        <div v-if="attachments.length" class="firma-hecha">
            <img
                v-for="a in attachments"
                :key="a.id"
                :src="route('field_work.forms.attachment', [submissionSlug, a.id])"
                :alt="field.label"
                class="firma-hecha__img"
            />
            <Popconfirm
                v-if="!readonly"
                :title="$t('field_work.signature_redo_confirm')"
                :ok-text="$t('global.delete')"
                :cancel-text="$t('global.cancel')"
                @confirm="borrar(attachments[0])"
            >
                <Button type="text" danger size="small">{{ $t('field_work.signature_redo') }}</Button>
            </Popconfirm>
        </div>

        <p v-else-if="readonly" class="adj__empty">{{ $t('field_work.signature_none') }}</p>

        <template v-else>
            <SignaturePad v-model="trazo" />
            <Button
                type="primary"
                class="mt-2"
                :disabled="!trazo"
                :loading="guardando"
                @click="guardar"
            >
                {{ $t('field_work.signature_save') }}
            </Button>
        </template>
    </div>
</template>
