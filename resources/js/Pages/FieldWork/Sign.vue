<script setup>
import { ref, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import { useFaceVerify } from '@/Composables/useFaceVerify';

const props = defineProps({
    plan: Object,
    people: Array,
    approvals: Array,
    settings: Object,
});

defineOptions({ layout: AppLayout });

const video = ref(null);
const activo = ref(null);       // a quien se esta firmando
const fase = ref('');           // buscando · comparando · evidencia · reto
const distancia = ref(null);
const mensaje = ref('');
const trabajando = ref(false);
const reto = ref(null);         // { gesto, paso } mientras dura el reto de vida

let stream = null;
const cara = useFaceVerify({
    sinMatchSegundos: props.settings?.timeoutSeconds ?? 20,
    retoDeVida: props.settings?.liveness ?? false,
});

/**
 * Qué se le pide en pantalla durante el reto. Dos frases por gesto: primero el
 * movimiento, después volver al centro, que es la mitad que descarta una foto.
 */
const INSTRUCCION = {
    girar:   { gesto: 'Gira la cabeza hacia un lado', centro: 'Ahora vuelve a mirar al frente' },
    asentir: { gesto: 'Asiente con la cabeza',        centro: 'Ahora vuelve a mirar al frente' },
};

const textoReto = () => {
    if (!reto.value) return '';
    if (reto.value.paso === 'encuadra') return 'Encuadra el rostro';

    return INSTRUCCION[reto.value.gesto]?.[reto.value.paso] ?? '';
};

async function firmar(fila, tipo, rol) {
    activo.value = { ...fila, tipo, rol };
    mensaje.value = '';
    trabajando.value = true;

    try {
        await cara.cargarModelos();
        stream = await cara.abrirCamara(video.value);

        let data;

        try {
            ({ data } = await axios.get(route('field_work.signatures.descriptors', fila.person.slug)));
        } catch (e) {
            // 404 = la persona no tiene cara registrada. Se enrola en el momento,
            // que es justo lo que pasa la primera vez que alguien va a firmar.
            if (e?.response?.status !== 404) throw e;

            mensaje.value = 'Esta persona no tiene su cara registrada. Manten el rostro encuadrado.';
            const enrol = await cara.enrolar(video.value, 3, (e2) => {
                fase.value = e2.fase;
                mensaje.value = e2.fase === 'muestra'
                    ? `Registrando cara: ${e2.tomadas} de ${e2.total}`
                    : 'Encuadra el rostro en la camara';
            });

            if (enrol.estado !== 'listo') {
                mensaje.value = 'No se pudo registrar la cara. Intentalo de nuevo con mejor luz.';
                return;
            }

            await axios.post(route('field_work.signatures.enroll', fila.person.slug), {
                descriptors: enrol.descriptores,
                consent: true,
            });

            ({ data } = await axios.get(route('field_work.signatures.descriptors', fila.person.slug)));
            mensaje.value = 'Cara registrada. Ahora firma.';
        }

        const resultado = await cara.verificar(
            video.value,
            data.descriptors,
            data.threshold,
            (e) => {
                fase.value = e.fase;
                distancia.value = e.distancia ?? null;
                reto.value = e.fase === 'reto' ? { gesto: e.gesto, paso: e.paso } : null;
            },
        );

        if (resultado.estado === 'cancelada') {
            mensaje.value = 'No se detecto a nadie frente a la camara. No se registro nada.';
            return;
        }

        if (resultado.retoFallido) {
            mensaje.value = 'La cara coincide pero no se completo el gesto. '
                + 'La firma queda registrada y pendiente de revision del supervisor.';
        }

        // El servidor vuelve a comparar y decide: aqui no se afirma nada.
        const respuesta = await axios.post(route('field_work.signatures.store'), {
            signable_type: tipo,
            signable_slug: fila.slug,
            person_slug: fila.person.slug,
            role_signed: rol,
            descriptor: resultado.descriptor,
            photo: resultado.foto,
        });

        mensaje.value = respuesta.data.message;
        router.reload({ only: ['people', 'approvals'] });
    } catch (e) {
        mensaje.value = e?.response?.data?.message ?? 'No se pudo completar la firma.';
    } finally {
        cara.cerrarCamara(stream);
        trabajando.value = false;
        fase.value = '';
        reto.value = null;
        activo.value = null;
    }
}

onBeforeUnmount(() => cara.cerrarCamara(stream));
</script>

<template>
    <div class="mi-console">
        <SectionHeader :title="$t('sidebar.work_plans')" :subtitle="plan.code" />

        <div v-show="trabajando" class="firma-camara">
            <video ref="video" playsinline muted autoplay />
            <p class="firma-estado">
                <span v-if="fase === 'buscando'">Buscando un rostro...</span>
                <span v-else-if="fase === 'comparando'">Comparando ({{ distancia?.toFixed(3) }})</span>
                <span v-else-if="fase === 'evidencia'">Sin coincidencia: tomando la foto para revision</span>
                <span v-else-if="fase === 'encuadra'">Encuadra el rostro</span>
                <span v-else-if="fase === 'muestra'">Registrando cara…</span>
                <span v-else-if="fase === 'reto'" class="firma-reto">{{ textoReto() }}</span>
            </p>
        </div>

        <a-alert v-if="mensaje" :message="mensaje" type="info" show-icon class="mb-4" />

        <a-card title="Cuadrilla" size="small" class="mb-4">
            <a-list :data-source="people" item-layout="horizontal">
                <template #renderItem="{ item }">
                    <a-list-item>
                        <a-list-item-meta
                            :title="`${item.person.name} ${item.person.lastname}`"
                            :description="item.person.num_doc" />
                        <a-tag v-if="item.signed" color="green">Firmado</a-tag>
                        <a-button v-else type="primary" :disabled="trabajando"
                                  @click="firmar(item, 'work_plan_person', 'worker')">
                            Firmar
                        </a-button>
                    </a-list-item>
                </template>
            </a-list>
        </a-card>

        <a-card title="Aprobaciones" size="small">
            <a-list :data-source="approvals" item-layout="horizontal">
                <template #renderItem="{ item }">
                    <a-list-item>
                        <a-list-item-meta
                            :title="item.person ? `${item.person.name} ${item.person.lastname}` : 'Sin asignar'"
                            :description="item.required ? 'Obligatoria' : 'Opcional'" />
                        <a-tag v-if="item.signed" color="green">Aprobado</a-tag>
                        <a-button v-else-if="item.person" :disabled="trabajando"
                                  @click="firmar(item, 'work_plan_approval', 'supervisor')">
                            Firmar
                        </a-button>
                    </a-list-item>
                </template>
            </a-list>
        </a-card>
    </div>
</template>

<style scoped>
.firma-camara { text-align: center; margin-bottom: 16px; }
.firma-camara video { width: 320px; height: 240px; border-radius: 12px; background: #000; }
.firma-estado { color: var(--color-text-muted); margin-top: 8px; }
/* El reto se lee de un vistazo y en movimiento: en obra nadie se acerca a leer
   una línea gris de 13 px. */
.firma-reto { display: block; font-size: 20px; font-weight: 700; color: var(--color-primary, #0A6ED1); }
</style>
