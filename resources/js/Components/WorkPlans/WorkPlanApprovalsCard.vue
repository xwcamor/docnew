<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Select, SelectOption, Tooltip,
} from 'ant-design-vue';
import { SafetyCertificateOutlined, EditOutlined } from '@ant-design/icons-vue';
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
    /** Los trabajadores del plan: de ahí sale el ejecutante, y sólo de ahí. */
    crew:      { type: Array,  default: () => [] },
});

const { t } = useI18n();

const firmadas = computed(() => props.approvals.filter((a) => a.signed).length);

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
 * Las aprobaciones se firman en orden, y aquí se dice por qué una todavía no.
 *
 * El sistema anterior escondía las que no tocaban (`next if approver_type !=
 * "worker" && !all_required_workers_signed`). Esconder deja al supervisor sin
 * saber cuántos pasos le quedan; enseñarlas en gris con el motivo enseña el
 * camino entero sin permitir saltárselo.
 */
/**
 * Las **aprobaciones de rol trabajador** que siguen sin firmar.
 *
 * Ésta es la condición del sistema anterior, literal:
 *
 *     required_workers_pending = @list_plan_approvals.select { |p|
 *       p.approver_type == "Worker" && p.approval_rule.is_required && !p.is_approved }
 *     next if approver_type != "worker" && !all_required_workers_signed
 *
 * Lo que bloquea al supervisor es que **el ejecutante haya firmado su
 * aprobación** — no que haya firmado toda la cuadrilla. Yo lo había puesto
 * contra `work_plan_people`, que es otra cosa: son las firmas de asistencia a
 * la charla, y no gobiernan el flujo de autorización.
 */
const ejecutantesPendientes = computed(() =>
    props.approvals.filter((a) => a.role === 'worker' && a.required && !a.signed));

const bloqueo = (a) => {
    if (a.signed) return null;

    // El rol «trabajador» es el primer eslabón: lo firma el ejecutante y no
    // espera a nadie.
    if (a.role === 'worker') return null;

    if (ejecutantesPendientes.value.length) {
        return t('work_plans.approval_waits_worker', {
            role: rotulo(ejecutantesPendientes.value[0].role),
        });
    }

    return null;
};

/** El estado del paso, con el mismo vocabulario que las otras dos columnas. */
const estado = (a) => {
    if (a.signed) return 'done';
    if (bloqueo(a)) return 'blocked';

    return a.required ? 'pending' : 'optional';
};

/**
 * Al ejecutante se le designa, no se le vuelve a pedir la firma.
 *
 * Su aprobación queda dada en el momento en que se le designa, porque para
 * poder designarlo tiene que haber firmado ya como trabajador. Eso es lo que
 * dice esta línea, para que nadie busque un botón que no está.
 */
const comoFirmo = (a) => (a.signs_as_worker && a.signed
    ? t('work_plans.approval_signed_as_worker')
    : '');

const etiqueta = (a) => {
    if (a.signed) return t('work_plans.approval_approved');
    if (bloqueo(a)) return t('work_plans.approval_pending');

    return a.required ? t('work_plans.approval_required') : t('work_plans.approval_optional');
};

// ── Asignar al firmante ──────────────────────────────────────────────────────

const abierta   = ref(null);   // slug de la aprobación que se está asignando
const candidatos = ref([]);
const buscando  = ref(false);
const parcial   = ref(true);   // el texto todavía no llega al mínimo
const minimo    = ref(8);
const guardando = ref(null);

let temporizador = null;

/**
 * Busca por documento. Con menos caracteres que el mínimo el servidor devuelve
 * la lista vacía a propósito — no es que no haya nadie, es que todavía no se ha
 * escrito un documento.
 */
const buscar = (texto) => {
    clearTimeout(temporizador);
    const q = (texto || '').trim();

    if (!q) { candidatos.value = []; parcial.value = true; return; }

    // Se busca **dentro del rol** que hay que firmar. El sistema anterior tenia
    // un endpoint distinto por tipo de aprobador; aqui salia cualquiera y se
    // podia poner al ayudante a firmar como supervisor de seguridad.
    const rol = props.approvals.find((a) => a.slug === abierta.value)?.role;

    temporizador = setTimeout(async () => {
        buscando.value = true;
        try {
            const { data } = await axios.get(
                route('business_management.work_plans.crew.candidates', props.planSlug),
                { params: { q, role: rol } },
            );
            candidatos.value = data.people;
            parcial.value = !!data.partial;
            minimo.value = data.minimum ?? minimo.value;
        } finally {
            buscando.value = false;
        }
    }, 250);
};

// Sin resultados no significa «no existe»: puede ser que exista y no tenga el
// rol. Decirlo evita que alguien de por hecho que hay que darlo de alta otra vez.
const rolAbierto = computed(() => props.approvals.find((a) => a.slug === abierta.value)?.role);

const sinResultados = computed(() => {
    if (parcial.value) return t('work_plans.approval_assign_hint');

    return t('work_plans.approval_no_one_with_role', { role: rotulo(rolAbierto.value) });
});

/**
 * El ejecutante se elige **entre los trabajadores del plan**, no se busca.
 *
 * Es quien está en la obra y responde por lo que se va a hacer, así que no
 * puede ser alguien que no salió a trabajar ese día. Ni el sistema anterior ni
 * mi primera versión lo comprobaban: los dos buscaban por documento en las 231
 * personas del padrón.
 *
 * Además es una lista de dos o cinco nombres: se elige con un clic en vez de
 * teclear ocho dígitos con guantes. El buscador por documento se queda para
 * supervisor y HSE, que no salen a obra con el plan.
 *
 * Se llama `esEjecutante` y no por el nombre de la lista: lo que decide de dónde
 * salen los candidatos es **el rol que firma**, no de dónde se sacan.
 */
const esEjecutante = (a) => a?.role === 'worker';

const opciones = computed(() => (esEjecutante(props.approvals.find((a) => a.slug === abierta.value))
    ? props.crew.map((f) => ({ slug: f.person, name: f.name }))
    : candidatos.value));

const abrir = (a) => {
    abierta.value = abierta.value === a.slug ? null : a.slug;
    candidatos.value = [];
    parcial.value = true;
};

const asignar = (a, personSlug) => {
    if (!personSlug) return;
    guardando.value = a.slug;
    router.put(
        route('business_management.work_plans.approvals.approver', [props.planSlug, a.slug]),
        { person_slug: personSlug },
        {
            preserveScroll: true,
            onFinish: () => {
                guardando.value = null;
                abierta.value = null;
                candidatos.value = [];
            },
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
            <Tag :color="firmadas === approvals.length ? 'success' : 'warning'" :bordered="false">
                {{ $tc('work_plans.approvals_summary', firmadas, { done: firmadas, total: approvals.length }) }}
            </Tag>
        </template>

        <p v-if="!approvals.length" class="ff-empty">{{ $t('work_plans.approvals_empty') }}</p>

        <ol v-else class="wp-rows">
            <WorkPlanBoardRow
                v-for="a in approvals"
                :key="a.slug"
                chained
                :state="estado(a)"
                :title="nombre(a)"
                :subtitle="[a.person ? a.person.name : $t('work_plans.approval_unassigned'), comoFirmo(a)].filter(Boolean).join(' · ')"
                :when="a.signed ? a.signed_at : null"
                :label="etiqueta(a)"
                :reason="bloqueo(a) || ''"
            >
                <template #actions>
                    <template v-if="!a.signed">
                        <Tooltip
                            v-if="canEdit"
                            :title="a.person
                                ? $t('work_plans.approval_change_hint', { role: nombre(a) })
                                : (esEjecutante(a)
                                    ? $t('work_plans.approval_pick_from_crew')
                                    : $t('work_plans.approval_assign_hint'))"
                        >
                            <Button size="small" :loading="guardando === a.slug" @click="abrir(a)">
                                <template #icon><EditOutlined /></template>
                                {{ a.person ? $t('work_plans.approval_change') : $t('work_plans.approval_assign') }}
                            </Button>
                        </Tooltip>

                        <!-- El ejecutante NO lleva botón de firmar: la
                             aprobación se da con la firma que ya dio como
                             trabajador de este mismo plan. Pedírsela otra vez
                             sería la misma persona, el mismo día, el mismo
                             plan y la misma cámara, y la segunda no prueba
                             nada que no probara la primera. -->
                        <Tooltip
                            v-if="canSign && a.person && !a.signs_as_worker"
                            :title="bloqueo(a) || $t('work_plans.crew_sign_hint', { name: a.person.name })"
                        >
                            <Button size="small" type="primary" :disabled="!!bloqueo(a)" @click="firmar(a)">
                                {{ $t('work_plans.approval_sign') }}
                            </Button>
                        </Tooltip>
                    </template>
                </template>

                <!-- Asignar quién firma. En su propia línea porque el selector
                     necesita el ancho entero: metido entre los botones aplastaba
                     el título hasta partirlo letra a letra. -->
                <template v-if="canEdit && !a.signed && abierta === a.slug" #wide>
                    <div class="wp-assign">
                        <!-- Ejecutante: lista corta de los que están en la obra.
                             Supervisor y HSE: buscador por documento. -->
                        <Select
                            :show-search="!esEjecutante(a)"
                            allow-clear
                            autofocus
                            :filter-option="false"
                            :loading="buscando"
                            :placeholder="esEjecutante(a)
                                ? $t('work_plans.approval_pick_from_crew')
                                : $t('work_plans.approval_assign_hint')"
                            :not-found-content="esEjecutante(a)
                                ? $t('work_plans.crew_empty')
                                : (buscando ? undefined : sinResultados)"
                            style="width: 100%"
                            @search="esEjecutante(a) ? undefined : buscar($event)"
                            @change="(v) => asignar(a, v)"
                        >
                            <SelectOption v-for="p in opciones" :key="p.slug" :value="p.slug">
                                {{ p.name }}
                            </SelectOption>
                        </Select>
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
.wp-assign { width: 100%; }
</style>
