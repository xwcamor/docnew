<script setup>
import { computed, ref, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import { useFaceVerify } from '@/Composables/useFaceVerify';
import { useI18n } from '@/Plugins/i18n';

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

const { t } = useI18n();

const firmadosCrew = computed(() => props.people.filter((p) => p.signed).length);

/**
 * Por qué una aprobación todavía no se puede firmar.
 *
 * El sistema anterior escondía las que no tocaban hasta que la cuadrilla
 * hubiera firmado. Aquí se enseñan en gris con el motivo: se ve el camino
 * entero sin poder saltárselo, que en obra evita la pregunta «¿y ahora qué?».
 */
const bloqueo = (a, i) => {
    if (a.signed) return null;

    if (a.role !== 'worker' && firmadosCrew.value < props.people.length) {
        return t('work_plans.approval_waits_crew');
    }

    const previa = props.approvals.slice(0, i).find((p) => p.required && !p.signed);
    const rol = previa?.role ? t('work_plans.approver_role.' + previa.role) : null;

    return rol ? t('work_plans.approval_waits_prior', { role: rol }) : null;
};

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
    girar:   { gesto: 'field_work.sign.turn_head', centro: 'field_work.sign.back_center' },
    asentir: { gesto: 'field_work.sign.nod_head',  centro: 'field_work.sign.back_center' },
};

const textoReto = () => {
    if (!reto.value) return '';
    if (reto.value.paso === 'encuadra') return t('field_work.sign.frame_face');

    const clave = INSTRUCCION[reto.value.gesto]?.[reto.value.paso];

    return clave ? t(clave) : '';
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

            mensaje.value = t('field_work.sign.no_face_registered');
            const enrol = await cara.enrolar(video.value, 3, (e2) => {
                fase.value = e2.fase;
                mensaje.value = e2.fase === 'muestra'
                    ? t('field_work.sign.enroll_progress', { done: e2.tomadas, total: e2.total })
                    : t('field_work.sign.frame_face');
            });

            if (enrol.estado !== 'listo') {
                mensaje.value = t('field_work.sign.enroll_failed');
                return;
            }

            await axios.post(route('field_work.signatures.enroll', fila.person.slug), {
                descriptors: enrol.descriptores,
                consent: true,
            });

            ({ data } = await axios.get(route('field_work.signatures.descriptors', fila.person.slug)));
            mensaje.value = t('field_work.sign.enroll_done');
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
            mensaje.value = t('field_work.sign.nobody_found');
            return;
        }

        if (resultado.retoFallido) {
            mensaje.value = t('field_work.sign.challenge_failed');
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
        mensaje.value = e?.response?.data?.message ?? t('field_work.sign.failed');
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
                <span v-if="fase === 'buscando'">{{ $t('field_work.sign.searching') }}</span>
                <span v-else-if="fase === 'comparando'">{{ $t('field_work.sign.comparing') }}</span>
                <span v-else-if="fase === 'evidencia'">{{ $t('field_work.sign.evidence') }}</span>
                <span v-else-if="fase === 'encuadra'">{{ $t('field_work.sign.frame_face') }}</span>
                <span v-else-if="fase === 'muestra'">{{ $t('field_work.sign.enrolling') }}</span>
                <span v-else-if="fase === 'reto'" class="firma-reto">{{ textoReto() }}</span>
            </p>
        </div>

        <a-alert v-if="mensaje" :message="mensaje" type="info" show-icon class="mb-4" />

        <!-- Mismo nombre que en la ficha: «Trabajadores del proveedor». -->
        <a-card :title="`${$t('work_plans.crew_title')} (${firmadosCrew}/${people.length})`" size="small" class="mb-4">
            <a-list :data-source="people" item-layout="horizontal">
                <template #renderItem="{ item }">
                    <a-list-item>
                        <!-- El nombre. El documento llega ya enmascarado del
                             servidor y no hace falta para reconocer a nadie:
                             para eso está la cara delante de la cámara. -->
                        <a-list-item-meta :title="item.person.list_name" />
                        <a-tag v-if="item.signed" color="green">{{ $t('work_plans.crew_signed') }}</a-tag>
                        <a-button v-else type="primary" :disabled="trabajando"
                                  @click="firmar(item, 'work_plan_person', 'worker')">
                            {{ $t('work_plans.approval_sign') }}
                        </a-button>
                    </a-list-item>
                </template>
            </a-list>
        </a-card>

        <!-- Las aprobaciones esperan a que la cuadrilla termine, y cuando no se
             pueden firmar se dice por qué en vez de dejar un botón muerto. -->
        <a-card :title="$t('work_plans.approvals_title')" size="small">
            <a-list :data-source="approvals" item-layout="horizontal">
                <template #renderItem="{ item, index }">
                    <a-list-item>
                        <a-list-item-meta
                            :title="item.person ? item.person.list_name : $t('work_plans.approval_unassigned')"
                            :description="bloqueo(item, index)
                                || (item.required ? $t('work_plans.approval_required') : $t('work_plans.approval_optional'))" />
                        <a-tag v-if="item.signed" color="green">{{ $t('work_plans.approval_approved') }}</a-tag>
                        <a-button v-else-if="item.person" type="primary"
                                  :disabled="trabajando || !!bloqueo(item, index)"
                                  @click="firmar(item, 'work_plan_approval', 'supervisor')">
                            {{ $t('work_plans.approval_sign') }}
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
