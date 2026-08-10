<script setup>
import { computed, ref, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import { Card, Tag, Button, Alert, Result } from 'ant-design-vue';
import { CheckCircleFilled, EditOutlined, ArrowLeftOutlined } from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import SignaturePad from '@/Components/FieldWork/SignaturePad.vue';
import { useFaceVerify } from '@/Composables/useFaceVerify';
import { useI18n } from '@/Plugins/i18n';

/**
 * Firmar. **Una persona, la que se eligió en la ficha.**
 *
 * Antes esta pantalla repetía las tres listas del plan entero: pulsabas Firmar
 * al lado de alguien y llegabas a un listado donde había que volver a buscarlo,
 * con la cámara abierta y guantes puestos. Ahora se llega con `?target=<slug>`
 * y se abre directamente sobre esa fila.
 *
 * Lo que se le pide depende de si **ya tiene firma en archivo**, que es la
 * lógica del sistema anterior y no la había portado:
 *
 * - **Sin firma** → la traza una vez, en el lienzo, y queda guardada.
 * - **Con firma** → sólo la foto. Su firma se reutiliza; en la v1 eso era el
 *   marcador `signed_by_IA`.
 * - **Con firma, pero quiere cambiarla** → el botón «Actualizar mi firma»,
 *   equivalente al `replace_signature` de allí.
 *
 * Redibujarla cada día no sólo es incómodo: una firma distinta cada vez no
 * prueba nada. La gracia es que sea la misma.
 */
const props = defineProps({
    plan: Object,
    target: { type: String, default: null },
    people: Array,
    approvals: Array,
    settings: Object,
});

defineOptions({ layout: AppLayout });

const { t } = useI18n();

const video = ref(null);
const fase = ref('');           // buscando · comparando · evidencia · reto
const mensaje = ref('');
const error = ref(false);
const trabajando = ref(false);
const reto = ref(null);
const listo = ref(false);       // ya firmó en esta pantalla

// La firma trazada y si se está reemplazando una que ya existe.
const trazo = ref(null);
const reemplazando = ref(false);

let stream = null;
const cara = useFaceVerify({
    sinMatchSegundos: props.settings?.timeoutSeconds ?? 20,
    retoDeVida: props.settings?.liveness ?? false,
});

// ── Sobre quién se abre ──────────────────────────────────────────────────────

/** Las dos listas en un formato común: la pantalla no distingue. */
const filas = computed(() => [
    ...props.people.map((p) => ({ ...p, tipo: 'work_plan_person', rol: 'worker', titulo: p.person?.list_name })),
    ...props.approvals.filter((a) => a.person).map((a) => ({
        ...a, tipo: 'work_plan_approval', rol: 'supervisor',
        titulo: a.person?.list_name, subtitulo: a.rule_name,
    })),
]);

const fila = computed(() => filas.value.find((f) => f.slug === props.target) ?? null);

/** Hay que trazar la firma: no tiene ninguna, o pidió cambiarla. */
const pideTrazo = computed(() => !!fila.value && (!fila.value.has_signature || reemplazando.value));

const volver = () => router.get(route('business_management.work_plans.show', props.plan.slug));

/**
 * Vuelta sola al plan, con unos segundos para leer el aviso.
 *
 * La firma limpia ya se iba sola. La que queda pendiente de revision se paraba
 * aqui con un boton «Volver al plan» que habia que pulsar — y eso es un toque de
 * mas con la siguiente persona esperando, con guantes, delante de la tablet. El
 * aviso hay que leerlo, pero leerlo no es lo mismo que confirmar que se ha
 * leido: se enseña, se cuenta atras y se vuelve.
 *
 * El boton se queda: quien ya lo leyo no tiene por que esperar. Y el mensaje no
 * se pierde al irse — el servidor lo dejo tambien en la sesion y sale otra vez
 * en el plan.
 */
const SEGUNDOS_PARA_LEERLO = 6;
const cuentaAtras = ref(0);
let temporizador = null;

const volverEnUnosSegundos = () => {
    cuentaAtras.value = SEGUNDOS_PARA_LEERLO;

    temporizador = setInterval(() => {
        cuentaAtras.value -= 1;

        if (cuentaAtras.value <= 0) {
            clearInterval(temporizador);
            temporizador = null;
            volver();
        }
    }, 1000);
};

// Si alguien pulsa el boton antes de que acabe la cuenta, o se va de la
// pantalla, el temporizador no puede seguir vivo disparando una navegacion.
onBeforeUnmount(() => { if (temporizador) clearInterval(temporizador); });

// ── El reto de vida ──────────────────────────────────────────────────────────

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

// ── Firmar ───────────────────────────────────────────────────────────────────

async function firmar() {
    const f = fila.value;
    if (!f) return;

    if (pideTrazo.value && !trazo.value) {
        error.value = true;
        mensaje.value = t('field_work.sign.draw_first');

        return;
    }

    mensaje.value = '';
    error.value = false;
    trabajando.value = true;

    try {
        await cara.cargarModelos();
        stream = await cara.abrirCamara(video.value);

        let data;

        try {
            ({ data } = await axios.get(route('field_work.signatures.descriptors', f.person.slug)));
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
                error.value = true;
                mensaje.value = t('field_work.sign.enroll_failed');

                return;
            }

            await axios.post(route('field_work.signatures.enroll', f.person.slug), {
                descriptors: enrol.descriptores, consent: true,
            });

            ({ data } = await axios.get(route('field_work.signatures.descriptors', f.person.slug)));
            mensaje.value = t('field_work.sign.enroll_done');
        }

        const resultado = await cara.verificar(video.value, data.descriptors, data.threshold, (e) => {
            fase.value = e.fase;
            reto.value = e.fase === 'reto' ? { gesto: e.gesto, paso: e.paso } : null;
        });

        if (resultado.estado === 'cancelada') {
            error.value = true;
            mensaje.value = t('field_work.sign.nobody_found');

            return;
        }

        // El servidor vuelve a comparar y decide: aquí no se afirma nada.
        const respuesta = await axios.post(route('field_work.signatures.store'), {
            signable_type: f.tipo,
            signable_slug: f.slug,
            person_slug: f.person.slug,
            role_signed: f.rol,
            descriptor: resultado.descriptor,
            photo: resultado.foto,
            // Sólo viaja cuando hay que guardarla: si ya tiene firma y no la
            // cambia, el servidor reutiliza la que hay.
            signature: pideTrazo.value ? trazo.value : null,
            replace_signature: reemplazando.value,
        });

        // Firmado: al plan, y punto.
        //
        // Antes se quedaba aquí enseñando lo que acabas de hacer, con un botón
        // «Volver» que había que pulsar. En obra eso es un toque de más con la
        // siguiente persona esperando, y la confirmación de verdad no es este
        // cartel: es la fila del plan en verde con su hora.
        //
        // El aviso lo dejó el servidor en la sesión, así que sale en el plan.
        // Si el gesto de vida falló, eso hay que leerlo: se dice aquí y se
        // espera a que se lea antes de irse.
        if (resultado.retoFallido) {
            listo.value = true;
            mensaje.value = t('field_work.sign.challenge_failed');
            volverEnUnosSegundos();

            return;
        }

        volver();
    } catch (e) {
        error.value = true;
        mensaje.value = e?.response?.data?.message ?? t('field_work.sign.failed');
    } finally {
        cara.cerrarCamara(stream);
        trabajando.value = false;
        fase.value = '';
        reto.value = null;
    }
}

onBeforeUnmount(() => cara.cerrarCamara(stream));
</script>

<template>
    <div class="mi-console firma">
        <SectionHeader :title="$t('work_plans.field_work_sign')" :subtitle="plan.code" />

        <!-- Sin destino no se adivina a quién firmar: se vuelve a la ficha, que
             es donde están los botones de cada fila. -->
        <Result
            v-if="!fila"
            status="404"
            :title="$t('field_work.sign.no_target')"
            :sub-title="$t('field_work.sign.no_target_hint')"
        >
            <template #extra>
                <Button type="primary" @click="volver">
                    <template #icon><ArrowLeftOutlined /></template>
                    {{ $t('global.back') }}
                </Button>
            </template>
        </Result>

        <template v-else>
            <Card :bodyStyle="{ padding: 20 }" class="info-card firma__quien">
                <h2 class="firma__nombre">{{ fila.titulo }}</h2>
                <p v-if="fila.subtitulo" class="firma__rol">{{ fila.subtitulo }}</p>

                <Tag v-if="fila.signed" color="success" :bordered="false">
                    <CheckCircleFilled /> {{ $t('work_plans.crew_signed') }}
                </Tag>
                <Tag v-else-if="fila.has_signature" color="blue" :bordered="false">
                    {{ $t('field_work.sign.has_signature_on_file') }}
                </Tag>
                <Tag v-else color="warning" :bordered="false">
                    {{ $t('field_work.sign.needs_signature') }}
                </Tag>
            </Card>

            <Alert
                v-if="mensaje"
                :message="mensaje"
                :type="error ? 'error' : (listo ? 'success' : 'info')"
                show-icon
                class="mb-4"
            />

            <template v-if="!fila.signed && !listo">
                <!-- Sin firma en archivo: se traza una vez y queda guardada. -->
                <Card v-if="pideTrazo" :bodyStyle="{ padding: 20 }" class="info-card">
                    <template #title>{{ $t('field_work.sign.draw_title') }}</template>
                    <p class="firma__ayuda">{{ $t('field_work.sign.draw_hint') }}</p>
                    <SignaturePad v-model="trazo" />
                </Card>

                <!-- Con firma en archivo: sólo la foto, y la opción de cambiarla. -->
                <Card v-else :bodyStyle="{ padding: 20 }" class="info-card">
                    <p class="firma__ayuda">{{ $t('field_work.sign.reuse_hint') }}</p>
                    <Button size="small" @click="reemplazando = true">
                        <template #icon><EditOutlined /></template>
                        {{ $t('field_work.sign.replace') }}
                    </Button>
                </Card>

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

                <div class="firma__acciones">
                    <Button size="large" @click="volver">{{ $t('global.cancel') }}</Button>
                    <Button size="large" type="primary" :loading="trabajando" @click="firmar">
                        {{ $t('field_work.sign.take_photo_and_sign') }}
                    </Button>
                </div>
            </template>

            <!-- Sólo se llega aquí cuando la firma quedó pendiente de revisión:
                 es un aviso que hay que leer. La firma limpia no pasa por esta
                 pantalla — se va al plan sola, y ésta también, en cuanto da
                 tiempo a leerlo. El botón se queda para quien no quiera esperar. -->
            <div v-else class="firma__acciones">
                <Button size="large" type="primary" @click="volver">
                    <template #icon><ArrowLeftOutlined /></template>
                    {{ cuentaAtras > 0
                        ? $t('field_work.sign.back_to_plan_in', { n: cuentaAtras })
                        : $t('field_work.sign.back_to_plan') }}
                </Button>
            </div>
        </template>
    </div>
</template>

<style scoped>
/* Una sola columna estrecha: se usa con una mano, mirando a la cámara. */
.firma { max-width: 560px; margin: 0 auto; }
.firma__quien { text-align: center; }
.firma__nombre { margin: 0 0 4px; font-size: 1.375rem; font-weight: 600; }
.firma__rol    { margin: 0 0 10px; color: var(--color-text-muted, #6A6D70); }
.firma__ayuda  { margin: 0 0 12px; color: var(--color-text-muted, #6A6D70); }

.firma__acciones { display: flex; gap: 10px; margin-top: 16px; }
/* Con guantes y a pleno sol: el botón principal se lleva el ancho (docs/UI.md §3). */
.firma__acciones :deep(.ant-btn) { min-height: 52px; flex: 1 1 0; font-weight: 600; }

.firma-camara { text-align: center; margin: 16px 0; }
.firma-camara video { width: 100%; max-width: 360px; border-radius: 12px; background: #000; }
.firma-estado { color: var(--color-text-muted); margin-top: 8px; }
/* El reto se lee de un vistazo y en movimiento: en obra nadie se acerca a leer
   una línea gris de 13 px. */
.firma-reto { display: block; font-size: 20px; font-weight: 700; color: var(--color-primary, #0A6ED1); }
</style>
