<script setup>
import { computed, ref, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import { Card, Tag, Button, Alert, Result } from 'ant-design-vue';
import { CameraOutlined, CheckCircleFilled, EditOutlined, ArrowLeftOutlined } from '@ant-design/icons-vue';
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
// Firmó, pero su firma quedó pendiente de revisión. Es lo único que detiene la
// pantalla: la que sale limpia se va al plan sin pasar por aquí.
const pendiente = ref(false);

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
 * La regla que queda, y es la única:
 *
 * - **Firma limpia → al plan, sin pasar por aquí.** La confirmación de verdad
 *   no es un cartel: es la fila del plan en verde con su hora, y el aviso que
 *   el servidor dejó en la sesión. Cero toques.
 * - **Firma que queda pendiente de revisión → la pantalla se queda.** Eso sí
 *   hay que leerlo: la persona cree que ya firmó y en realidad su firma no vale
 *   hasta que la mire un supervisor. Ese aviso no se puede sacar por el flash
 *   —sólo hay canal `success` y `error`, y son avisos que se desvanecen solos—,
 *   así que se enseña aquí y se sale con un botón. Un toque, y sólo cuando algo
 *   salió mal.
 *
 * Quién decide cuál de las dos es **el servidor**: la pantalla mira el
 * `pending_review` que devuelve al registrar la firma, no lo que crea el
 * navegador. Antes lo decidía el resultado del reto de vida del cliente y podía
 * no coincidir con lo que el servidor había guardado.
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
        const { data: firma } = await axios.post(route('field_work.signatures.store'), {
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
        });

        // Firma limpia: al plan, y punto. Sin cartel y sin toques — la
        // confirmación es la fila del plan en verde con su hora, y el aviso lo
        // dejó el servidor en la sesión.
        if (! firma.pending_review) {
            volver();

            return;
        }

        // Pendiente de revisión: aquí sí hay algo que la persona tiene que
        // saber antes de irse, porque su firma todavía no vale. Se dice por qué
        // cuando se sabe —el gesto no se completó— y si no, en general.
        pendiente.value = true;
        mensaje.value = resultado.retoFallido
            ? t('field_work.sign.challenge_failed')
            : t('field_work.sign.pending_review');
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

            <!-- Naranja y no verde cuando ya se firmó: lo único que se queda en
                 esta pantalla es una firma **pendiente** de revisión, y pintarla
                 de conforme diría lo contrario del texto (docs/UI.md §5). -->
            <Alert
                v-if="mensaje"
                :message="mensaje"
                :type="error ? 'error' : (pendiente ? 'warning' : 'info')"
                show-icon
                class="mb-4"
            />

            <template v-if="!fila.signed && !pendiente">
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
                 pantalla, se va al plan sola. El botón se queda porque aquí no
                 se va nadie solo: quien lee esto decide cuándo ha terminado de
                 leerlo, y el botón siempre lleva al plan (docs/UI.md §6). -->
            <div v-else class="firma__acciones">
                <Button size="large" type="primary" @click="volver">
                    <template #icon><ArrowLeftOutlined /></template>
                    {{ $t('field_work.sign.back_to_plan') }}
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
