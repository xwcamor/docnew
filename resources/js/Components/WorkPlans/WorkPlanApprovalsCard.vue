<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Alert, Card, Tag, Button, Input, Tooltip,
} from 'ant-design-vue';
import {
    SafetyCertificateOutlined, EditOutlined, SolutionOutlined,
    IdcardOutlined, LoadingOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import WorkPlanBoardRow from '@/Components/WorkPlans/WorkPlanBoardRow.vue';

/**
 * Flujo de aprobaciones del plan.
 *
 * No es una lista que se edite: las filas las crea la regla del país al nacer
 * el plan (`approval_rules` → `WorkPlanSetupService::seedApprovalsFromRules`) y
 * se firman **en orden de prioridad**. Lo único que se hace aquí es decir
 * **quién** firma cada una, y firmar.
 *
 * Tres cosas que estaban mal y que el dueño señaló:
 *
 * - **No se borran.** Pertenecen al flujo, no al plan. Quitar la fila del
 *   supervisor HSE no quita la obligación de que firme, sólo la esconde.
 * - **No se configura el flujo desde aquí.** Eso se decide en Reglas de
 *   aprobación, y sacar el botón a la ficha invitaba a cambiar la norma del
 *   país desde el plan de un martes.
 * - **No se lista a nadie.** Se escribe el documento; si existe, sale el
 *   nombre. Como en el sistema anterior, que nunca desplegó el padrón.
 */
const props = defineProps({
    planSlug:  { type: String, required: true },
    approvals: { type: Array,  default: () => [] },
    canEdit:   { type: Boolean, default: false },
    canSign:   { type: Boolean, default: false },
    /**
     * Quién responde por los trabajadores, tal como lo manda el servidor.
     *
     * No es una aprobación y por eso no sale en esta lista, pero sí la bloquea:
     * hasta que alguien responda por el equipo, nadie autoriza. Se lee de aquí
     * en vez de deducirlo de las filas, que es lo que se hacía antes y dejó de
     * ser cierto en cuanto el representante salió del flujo.
     */
    representative: { type: Object, default: () => ({ person: null }) },
});

// Lo que desbloquea el flujo no vive en esta tarjeta, así que el botón del
// aviso no puede resolverlo solo: lo pide, y la ficha lleva hasta allí.
defineEmits(['ir-al-representante']);

const { t } = useI18n();

const firmadas = computed(() => props.approvals.filter((a) => a.signed).length);

/**
 * El plan está aprobado cuando firmaron **las obligatorias**.
 *
 * Salía en ámbar con «2 de 3» porque la tercera estaba sin firmar — pero esa
 * tercera es opcional, y pintar de aviso algo que nadie tiene que hacer es
 * pedir atención para nada. Si lo obligatorio está, está.
 */
const faltaObligatoria = computed(() => props.approvals.some((a) => a.required && !a.signed));

const rotulo = (rol) => (rol ? t('work_plans.approver_role.' + rol) : '—');

/**
 * Como se llama esa firma. El nombre de la regla —«Supervisor Autorizante -
 * HITACHI»— y no el rol generico, que era lo que yo enseñaba: el rol dice que
 * clase de persona firma, el nombre dice por parte de quien, y eso es lo que
 * distingue una firma de otra en el documento.
 */
const nombre = (a) => a.rule_name || rotulo(a.role);

/** dd-mm-aaaa hh:mm, hora de obra: no se reinterpreta la zona. */
const cuando = (v) => {
    if (!v) return '';
    const s = String(v);
    const [y, m, d] = s.slice(0, 10).split('-');
    return d ? `${d}-${m}-${y} ${s.slice(11, 16)}` : s;
};

/**
 * Nadie autoriza hasta que alguien responda por los trabajadores, y aquí se
 * dice por qué una aprobación todavía no se puede firmar.
 *
 * El sistema anterior escondía las que no tocaban. Esconder deja al supervisor
 * sin saber cuántos pasos le quedan; enseñarlas en gris con el motivo enseña el
 * camino entero sin permitir saltárselo.
 *
 * La condición es la del sistema anterior, literal:
 *
 *     required_workers_pending = @list_plan_approvals.select { |p|
 *       p.approver_type == "Worker" && p.approval_rule.is_required && !p.is_approved }
 *     next if approver_type != "worker" && !all_required_workers_signed
 *
 * Allí el que respondía por el trabajo era una fila más de esta lista —la del
 * «ejecutante»— y aquí se miraba igual, buscando las de rol `worker` sin
 * firmar. Ese rol ya no existe: quien responde es una columna del plan y tiene
 * su propia tarjeta, al lado de la lista de la que sale. La regla no cambia,
 * cambia dónde vive el dato, así que se lee de la prop en vez de deducirse de
 * unas filas que ya no están.
 *
 * Ojo con la tentación de mirarlo contra las firmas de los trabajadores: esas
 * son firmas de asistencia a la charla y no gobiernan la autorización. Es el
 * error que ya se cometió una vez.
 *
 * El mismo texto que devuelve el servidor cuando se intenta firmar de todas
 * formas (SignatureController): una regla, una frase.
 */
const faltaRepresentante = computed(() => !props.representative?.person);

/**
 * Si esta fila esta bloqueada. Devuelve un booleano y NO el motivo.
 *
 * El motivo se decia entero debajo de cada fila, en gris de doce puntos: en un
 * flujo de dos pasos era el mismo parrafo dos veces, y en uno de cuatro son
 * cuatro. Lo que se repite deja de leerse. Ahora el motivo se dice UNA vez,
 * arriba de la tarjeta y con color, junto al boton que lo arregla.
 */
const bloqueada = (a) => ! a.signed && faltaRepresentante.value;

/** El estado del paso, con el mismo vocabulario que las otras dos columnas. */
const estado = (a) => {
    if (a.signed) return 'done';
    if (bloqueada(a)) return 'blocked';

    return a.required ? 'pending' : 'optional';
};

/**
 * Firmada: manda la PERSONA. Sin firmar: manda el paso.
 *
 * El flujo se lee en dos momentos distintos y no preguntan lo mismo. Mientras
 * está a medias, la pregunta es «¿qué paso toca?» y el título tiene que ser el
 * paso — «Supervisor HSE»—, con quien lo va a firmar debajo. Una vez firmada,
 * la pregunta pasa a ser **quién** lo autorizó: eso es lo que se busca en una
 * auditoría, y el nombre del paso ya sólo hace de aclaración.
 *
 * Es el mismo criterio con el que se lee un documento firmado en papel: arriba
 * la firma, debajo el cargo.
 */
const titulo = (a) => (a.signed && a.person ? a.person.name : nombre(a));

const subtitulo = (a) => (
    a.signed && a.person ? nombre(a) : (a.person ? a.person.name : t('work_plans.approval_unassigned'))
);

const etiqueta = (a) => {
    if (a.signed) return t('work_plans.approval_approved');
    // «En espera», no «Pendiente»: pendiente es la que se puede firmar y nadie
    // ha firmado todavia. Con la misma palabra para las dos, la lista no decia
    // cual de los dos pasos tocaba.
    if (bloqueada(a)) return t('work_plans.approval_blocked');

    return a.required ? t('work_plans.approval_required') : t('work_plans.approval_optional');
};

// ── Asignar al firmante ──────────────────────────────────────────────────────
//
// Se escribe el documento y la persona entra sola. **Sin desplegable.**
//
// Aquí había un `Select` con búsqueda: se tecleaba el documento entero y
// después había que elegir de una lista que casi siempre tenía un solo
// elemento. Dos gestos para una decisión que ya estaba tomada al terminar de
// teclear, y encima con guantes. Es exactamente lo que ya se quitó en la
// tarjeta de trabajadores, y este selector se quedó atrás.
//
// Es el mismo mecanismo que allí: se busca al soltar la tecla, y sólo se asigna
// con coincidencia EXACTA y única. «4001» encaja con veinte documentos y no se
// puede adivinar cuál, así que hasta que no sea exacta no se toca nada.

// Un flujo puede tener varias filas sin asignar a la vez, y las tres llevan su
// campo abierto. Lo escrito y el aviso van POR FILA: con un solo `ref` para
// todas, teclear el documento del supervisor lo pintaba también en la fila del
// HSE, como si ya estuviera puesto.
const abierta   = ref(null);          // fila donde se está cambiando al que ya hay
const documento = reactive({});       // slug de la aprobación → lo tecleado
const aviso     = reactive({});       // slug de la aprobación → qué pasa con ello
const buscando  = ref(null);
const guardando = ref(null);
/**
 * Cuántos caracteres hacen falta antes de preguntar al servidor.
 *
 * Lo decide el servidor (`docufiz.num_doc_minimum`) y llega en la respuesta,
 * pero la primera búsqueda ocurre ANTES de tener respuesta, así que el valor de
 * partida tiene que quedarse CORTO a propósito: si arranca por encima del real
 * —estaba en 8 y el servidor dice 7— un documento de siete dígitos no llega
 * nunca a preguntarse, y como no se pregunta tampoco se aprende el 7. El campo
 * se queda mudo para siempre. Pasarse de corto sólo cuesta una consulta que el
 * servidor contesta con `partial` y el número bueno.
 */
const minimo    = ref(7);

let temporizador = null;

const buscar = (a, texto) => {
    clearTimeout(temporizador);
    const q = String(texto ?? '').trim();
    aviso[a.slug] = '';

    if (q.length < minimo.value) return;

    temporizador = setTimeout(async () => {
        buscando.value = a.slug;
        try {
            const { data } = await axios.get(
                route('business_management.work_plans.crew.candidates', props.planSlug),
                // Se busca **dentro del rol** que hay que firmar. El sistema
                // anterior tenia un endpoint distinto por tipo de aprobador;
                // aqui salia cualquiera y se podia poner al ayudante a firmar
                // como supervisor de seguridad.
                { params: { q, role: a.role } },
            );

            minimo.value = data.minimum ?? minimo.value;

            if (data.exact) {
                asignar(a, data.exact.slug);

                return;
            }

            // Sin coincidencia exacta hay dos motivos distintos, y no dan el
            // mismo consejo: o falta escribir, o esa persona no tiene el rol que
            // esta firma exige. Lo segundo no se arregla escribiendo más.
            aviso[a.slug] = data.people.length
                ? t('work_plans.crew_keep_typing')
                : t('work_plans.approval_no_one_with_role', { role: rotulo(a.role) });
        } finally {
            buscando.value = null;
        }
    }, 250);
};

const abrir = (a) => {
    abierta.value = abierta.value === a.slug ? null : a.slug;
    documento[a.slug] = '';
    aviso[a.slug] = '';
};

const asignar = (a, personSlug) => {
    if (!personSlug) return;
    guardando.value = a.slug;
    router.put(
        route('business_management.work_plans.approvals.approver', [props.planSlug, a.slug]),
        { person_slug: personSlug },
        {
            preserveScroll: true,
            onSuccess: () => { documento[a.slug] = ''; aviso[a.slug] = ''; abierta.value = null; },
            onFinish: () => { guardando.value = null; },
        },
    );
};

// Sobre esta aprobación, no sobre el plan entero.
const firmar = (a) => router.get(
    route('field_work.signatures.show', props.planSlug), { target: a.slug });
</script>

<template>
    <Card :bodyStyle="{ padding: 18 }" class="info-card">
        <template #title>
            <SafetyCertificateOutlined /> {{ $t('work_plans.approvals_title') }}
        </template>
        <template v-if="approvals.length" #extra>
            <Tag
                :color="faltaRepresentante ? 'warning' : (faltaObligatoria ? 'warning' : 'success')"
                :bordered="false"
            >
                {{ faltaRepresentante
                    ? $t('work_plans.approvals_blocked_tag')
                    : $tc('work_plans.approvals_summary', firmadas, { done: firmadas, total: approvals.length }) }}
            </Tag>
        </template>

        <p v-if="!approvals.length" class="ff-empty">{{ $t('work_plans.approvals_empty') }}</p>

        <!-- El motivo, UNA vez y con color.
             Estaba repetido bajo cada fila en gris de doce puntos, que es la
             manera mas segura de que no se lea: lo mismo dos veces deja de ser
             informacion. Y lo que hay que hacer estaba tres tarjetas mas arriba
             sin nada que lo señalara, asi que aqui va tambien el boton que
             lleva alli. -->
        <Alert
            v-if="approvals.length && faltaRepresentante"
            type="warning"
            show-icon
            class="wp-block"
            :message="$t('work_plans.approvals_blocked_title')"
            :description="$t('work_plans.approvals_blocked_body')"
        >
            <template #icon><SolutionOutlined /></template>
            <template #action>
                <Button size="small" type="primary" @click="$emit('ir-al-representante')">
                    {{ $t('work_plans.approvals_blocked_cta') }}
                </Button>
            </template>
        </Alert>

        <ol v-if="approvals.length" class="wp-rows">
            <WorkPlanBoardRow
                v-for="a in approvals"
                :key="a.slug"
                chained
                :state="estado(a)"
                :title="titulo(a)"
                :subtitle="subtitulo(a)"
                :when="a.signed ? a.signed_at : null"
                :subtitle-time="a.signed ? cuando(a.signed_at) : ''"
                :label="etiqueta(a)"
            >
                <template #actions>
                    <!-- Mientras el flujo esta bloqueado no sale ningun boton.
                         «Asignar firmante» abria un buscador para elegir a
                         alguien que despues no iba a poder firmar, y «Firmar»
                         salia apagado: un boton que solo puede fallar es peor
                         que un boton que no esta (docs/UI.md §6). Lo que hay
                         que hacer lo dice el aviso de arriba, con su boton. -->
                    <template v-if="!a.signed && !bloqueada(a)">
                        <!-- Sólo «Cambiar», y sólo cuando ya hay alguien
                             asignado. Sin nadie, lo que hay que hacer es
                             escribir el documento y el campo ya está abierto
                             debajo: un botón para destapar lo que de todos
                             modos hay que usar es un clic de más. -->
                        <Tooltip
                            v-if="canEdit && a.person"
                            :title="$t('work_plans.approval_change_hint', { role: nombre(a) })"
                        >
                            <Button size="small" :loading="guardando === a.slug" @click="abrir(a)">
                                <template #icon><EditOutlined /></template>
                                {{ $t('work_plans.approval_change') }}
                            </Button>
                        </Tooltip>

                        <!-- Todas las filas de aquí recogen una firma propia.
                             La que no la recogía era la del ejecutante, que
                             valía la firma que esa misma persona ya había dado
                             como trabajador: por eso llevaba un botón que no
                             había que pulsar y por eso dejó de ser una fila. -->
                        <Tooltip
                            v-if="canSign && a.person"
                            :title="$t('work_plans.crew_sign_hint', { name: a.person.name })"
                        >
                            <Button size="small" type="primary" @click="firmar(a)">
                                {{ $t('work_plans.approval_sign') }}
                            </Button>
                        </Tooltip>
                    </template>
                </template>

                <!-- Quién firma: se escribe el documento y entra solo, igual
                     que en la tarjeta de trabajadores. En su propia línea
                     porque el campo necesita el ancho entero — metido entre los
                     botones aplastaba el título hasta partirlo letra a letra. -->
                <template
                    v-if="canEdit && !a.signed && !bloqueada(a) && (!a.person || abierta === a.slug)"
                    #wide
                >
                    <div class="wp-assign">
                        <Input
                            v-model:value="documento[a.slug]"
                            size="large"
                            allow-clear
                            inputmode="numeric"
                            autocomplete="off"
                            :maxlength="20"
                            :disabled="guardando === a.slug"
                            :placeholder="$t('work_plans.approval_assign_hint')"
                            @update:value="(v) => buscar(a, v)"
                            @press-enter="buscar(a, documento[a.slug])"
                        >
                            <template #prefix><IdcardOutlined /></template>
                            <template v-if="buscando === a.slug || guardando === a.slug" #suffix>
                                <LoadingOutlined />
                            </template>
                        </Input>

                        <!-- «Sigue escribiendo» es un consejo; «nadie con ese
                             rol» es un callejón sin salida. Se distinguen por
                             el color, que es lo único que se mira de un texto
                             de trece pixeles. -->
                        <p
                            class="wp-assign__hint"
                            :class="{ 'is-bad': aviso[a.slug] && aviso[a.slug] !== $t('work_plans.crew_keep_typing') }"
                        >
                            {{ aviso[a.slug] || $tc('work_plans.crew_search_hint', minimo, { count: minimo }) }}
                        </p>
                    </div>
                </template>
            </WorkPlanBoardRow>
        </ol>
    </Card>
</template>

<style scoped>
/* La escalera la dibuja WorkPlanBoardRow con `chained`: es la misma fila que
   usan las otras dos columnas del tablero, para que no vuelvan a divergir. */
.wp-rows { list-style: none; margin: 0; padding: 0; }
.wp-block { margin-bottom: 14px; }
.wp-assign { width: 100%; }
</style>
