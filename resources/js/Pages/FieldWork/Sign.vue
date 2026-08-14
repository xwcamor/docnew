<script setup>
import { computed, ref, onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { Card, Tag, Button, Alert, Checkbox, Modal, Result } from 'ant-design-vue';
import { CameraOutlined, CheckCircleFilled, EditOutlined, ArrowLeftOutlined } from '@ant-design/icons-vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import SignaturePad from '@/Components/FieldWork/SignaturePad.vue';
import { useFaceVerify } from '@/Composables/useFaceVerify';
import { useDondeYConQue } from '@/Composables/useDondeYConQue';
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
// La firma trazada y si se está reemplazando una que ya existe.
const trazo = ref(null);
const reemplazando = ref(false);

let stream = null;
const cara = useFaceVerify({
    sinMatchSegundos: props.settings?.timeoutSeconds ?? 20,
    retoDeVida: props.settings?.liveness ?? false,
});

// Desde qué aparato y desde dónde se firma. Las dos de mejor esfuerzo: si no
// se consiguen, se firma igual y los campos quedan vacíos.
const { coords, dispositivo, pedirUbicacion } = useDondeYConQue();

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

/*
 * Aquí había una cuenta atrás («Volviendo al plan… 6») que se enseñaba al
 * acabar de firmar, con su botón «Volver al plan» debajo.
 *
 * Se va entera. Estaba puesta para dar tiempo a leer el aviso, pero le contaba
 * al usuario un detalle del funcionamiento que no le sirve de nada —cuántos
 * segundos faltan para que la pantalla haga sola lo que iba a hacer igual— y
 * encima cambiaba el texto del botón cada segundo: en una tablet, con guantes,
 * eso es un botón que se mueve mientras lo apuntas.
 *
 * La regla que queda, y es la única: **firmó → al plan**. Las dos, la limpia y
 * la que queda pendiente de revisión. Cero toques.
 *
 * La pendiente se quedaba aquí, con un cartel y un botón, porque la persona
 * tiene que saber que su firma todavía no vale. Eso sigue siendo cierto; el
 * sitio era el equivocado. En obra la tablet pasa a la siguiente persona en
 * cuanto la anterior suelta el dedo, así que el cartel era un toque de más
 * entre firma y firma. Y era la única vez que se decía: se cerraba y no dejaba
 * nada detrás.
 *
 * Ahora lo dice el plan, con el aviso naranja al llegar —canal `warning`, que
 * se añadió para esto: «éxito» sería mentir y «error» diría que no se guardó, y
 * sí se guardó— y, sobre todo, con la fila de esa persona marcada «sin
 * reconocer» hasta que un supervisor la mire. Eso no se desvanece.
 *
 * Quién decide si una firma vale es **el servidor**, y desde aquí no se afirma
 * nada: se manda el descriptor y la foto, y lo que él resuelva es lo que se
 * guarda y lo que el plan enseña. El navegador ni siquiera lee la respuesta.
 */

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

// ── Consentimiento del enrolamiento ─────────────────────────────────────────
//
// Registrar la cara de alguien es tratar un dato biométrico, y esto es lo único
// que hay entre el trabajador y que se le registre. Antes no existía: la
// pantalla mandaba `consent: true` a pelo y el servidor lo daba por bueno.
//
// Va DELANTE de la cámara de enrolamiento —no después, no en letra pequeña al
// pie— y sólo sale la primera vez, que es cuando de verdad se decide. Si dice
// que no, no se registra nada y la firma se para con un mensaje: es una
// negativa legítima, no un error del sistema.
//
// El texto viaja al servidor y se guarda entero junto a la aceptación: la
// pregunta que hay que poder responder dos años después no es «¿aceptó?» sino
// «¿a qué dijo que sí?».

const consentimiento = ref({ abierto: false, marcado: false, resolver: null });

/** Su nombre dentro del texto: quien firma tiene que verse nombrado. */
const textoConsentimiento = computed(
    () => t('field_work.consent.text', { name: fila.value?.titulo ?? '' }),
);

// Espejo de PersonBiometric::CONSENT_VERSION. Sube junto al texto: la v2
// autoriza también la foto del momento de firmar y la firma trazada.
const CONSENT_VERSION = '2026-08-v2';

/** Abre el aviso y espera. Resuelve a true sólo si acepta a conciencia. */
function pedirConsentimiento() {
    return new Promise((resolve) => {
        consentimiento.value = { abierto: true, marcado: false, resolver: resolve };
    });
}

function responderConsentimiento(acepta) {
    const { resolver } = consentimiento.value;

    consentimiento.value = { abierto: false, marcado: false, resolver: null };
    resolver?.(acepta);
}

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

            // Antes de la cámara, el permiso. Se cierra la cámara mientras se
            // lee: nadie tiene que decidir sobre su cara con su propia cara
            // enfocada en pantalla.
            cara.cerrarCamara(stream);
            stream = null;

            const acepta = await pedirConsentimiento();

            if (! acepta) {
                error.value = true;
                mensaje.value = t('field_work.consent.declined');

                return;
            }

            stream = await cara.abrirCamara(video.value);

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
                descriptors: enrol.descriptores,
                // Los tres juntos: el sí, sobre qué versión y con qué texto
                // delante. El servidor los guarda en `person_biometrics`.
                consent: true,
                consent_version: CONSENT_VERSION,
                consent_text: textoConsentimiento.value,
                // Un fotograma del enrolamiento, para que a esta persona le
                // quede cara si no tenía ninguna. El servidor sólo lo usa en
                // ese caso: nunca pisa la foto que subió el administrador.
                //
                // Sin esto quedaba un agujero que no cerraba nunca: alta sin
                // foto → enrolamiento (que guarda descriptores y ninguna
                // imagen) → y a partir de ahí el reconocimiento acierta
                // siempre, con lo que tampoco se toma foto de evidencia. La
                // ficha del plan diría «reconocimiento facial» sin una sola
                // cara que enseñar, por muchas veces que firmara.
                photo: cara.capturar(video.value),
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

        // El servidor vuelve a comparar y decide: aquí no se afirma nada. Y de
        // lo que responde no se lee nada tampoco — si la firma quedó pendiente
        // lo cuenta el plan, no esta pantalla.
        await axios.post(route('field_work.signatures.store'), {
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
            // El reto de vida lo corre el navegador y el servidor no lo puede
            // recalcular con el descriptor: si no se superó, hay que decírselo.
            // Sólo sirve para dejar la firma pendiente de revisión, nunca para
            // darla por buena — ver SignatureController::store().
            liveness_failed: resultado.retoFallido === true,

            // Desde qué aparato y desde dónde. Las tres columnas existían desde
            // el primer día y esta pantalla nunca las mandaba, así que estaban
            // vacías en todas las firmas. Van de mejor esfuerzo: si el
            // navegador no da la ubicación o no hay permiso, se firma igual.
            device_id: dispositivo(),
            latitude:  coords.value?.latitude ?? null,
            longitude: coords.value?.longitude ?? null,
        });

        // Firmada: al plan, y punto. Las dos — la limpia y la que queda
        // pendiente de revisión. Sin cartel y sin toques.
        //
        // La pendiente se detenía aquí, con su aviso y su botón «Volver al
        // plan». Lo que decía era cierto —esa firma todavía no vale— pero el
        // sitio era el equivocado: en obra la tablet pasa a la siguiente
        // persona en cuanto la anterior suelta el dedo, y el cartel solo añadía
        // un toque para poder seguir. Y no dejaba nada detrás: se cerraba y ahí
        // se acababa la única vez que se decía.
        //
        // Ahora lo dice el plan, que es donde queda: el aviso naranja al
        // llegar, y sobre todo la fila de esa persona marcada «sin reconocer»
        // mientras un supervisor no la mire.
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

// La ubicación se pide al ABRIR y no al firmar: el permiso puede abrir un
// diálogo del navegador, y ese diálogo encima de la cámara, con la persona ya
// mirando al objetivo, sería lo peor posible.
onMounted(() => pedirUbicacion());

onBeforeUnmount(() => cara.cerrarCamara(stream));
</script>

<template>
    <div class="mi-console firma">
        <!-- El icono no es decoración: el hueco de SectionHeader se pinta igual
             —un cuadrado tintado de 40px— lleve glifo o no, y sin él la
             cabecera abría con una caja azul vacía. Es la cámara porque es lo
             que va a pasar en cuanto se pulse el botón. -->
        <SectionHeader :title="$t('work_plans.field_work_sign')" :subtitle="plan.code">
            <template #icon><CameraOutlined /></template>
        </SectionHeader>

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

            <!-- Lo que se dice mientras se está aquí: que hace falta enrolar la
                 cara, que el enrolamiento salió bien, o que algo falló. Una vez
                 firmada, esta pantalla ya no cuenta nada — se va al plan. -->
            <Alert
                v-if="mensaje"
                :message="mensaje"
                :type="error ? 'error' : 'info'"
                show-icon
                class="mb-4"
            />

            <template v-if="!fila.signed">
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

            <!-- Sólo si se llega con la firma ya puesta: el servidor manda al
                 plan cuando el destino ya firmó, así que en la práctica es una
                 red por si alguien vuelve atrás con la pantalla vieja. Sin este
                 botón se quedaría sin salida. -->
            <div v-else class="firma__acciones">
                <Button size="large" type="primary" @click="volver">
                    <template #icon><ArrowLeftOutlined /></template>
                    {{ $t('field_work.sign.back_to_plan') }}
                </Button>
            </div>
        </template>

        <!-- EL PERMISO PARA REGISTRAR LA CARA.
             Delante de la cámara y sólo la primera vez. No se cierra tocando
             fuera ni con Escape: una ventana que se va con un roce del guante
             no es una decisión — y esta lo es. Los dos botones dicen lo que
             hacen, y «No acepto» no es el camino difícil: está al mismo nivel. -->
        <Modal
            :open="consentimiento.abierto"
            :title="$t('field_work.consent.title')"
            :closable="false"
            :mask-closable="false"
            :keyboard="false"
            :footer="null"
            :width="560"
        >
            <p class="consent__texto">{{ textoConsentimiento }}</p>

            <Checkbox v-model:checked="consentimiento.marcado" class="consent__check">
                {{ $t('field_work.consent.checkbox') }}
            </Checkbox>

            <div class="consent__acciones">
                <Button size="large" @click="responderConsentimiento(false)">
                    {{ $t('field_work.consent.decline') }}
                </Button>
                <Button
                    size="large"
                    type="primary"
                    :disabled="!consentimiento.marcado"
                    @click="responderConsentimiento(true)"
                >
                    {{ $t('field_work.consent.accept') }}
                </Button>
            </div>
        </Modal>
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

/* El aviso de consentimiento. Se lee de pie y con una tablet en la mano, así
   que el cuerpo va a tamaño normal y no en letra pequeña: la letra pequeña en
   un consentimiento es exactamente lo que no se debe hacer. */
.consent__texto  { margin: 0 0 16px; line-height: 1.55; }
.consent__check  { margin-bottom: 18px; }
.consent__acciones { display: flex; gap: 10px; }
.consent__acciones :deep(.ant-btn) { min-height: 48px; flex: 1 1 0; font-weight: 600; }
</style>
