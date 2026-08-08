<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Select, SelectOption, Tooltip,
} from 'ant-design-vue';
import {
    SafetyCertificateOutlined, CheckCircleFilled, ClockCircleOutlined,
    LockOutlined, EditOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

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
    /** Cuántos de la cuadrilla faltan por firmar. Bloquea el resto del flujo. */
    crewPending: { type: Number, default: 0 },
});

const { t } = useI18n();

const firmadas = computed(() => props.approvals.filter((a) => a.signed).length);

const rotulo = (rol) => (rol ? t('work_plans.approver_role.' + rol) : '—');

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
const bloqueo = (a, i) => {
    if (a.signed) return null;

    // El rol «trabajador» es el primer eslabón: lo firma el ejecutante y no
    // espera a nadie. Los demás sí esperan a que la cuadrilla haya firmado.
    if (a.role !== 'worker' && props.crewPending > 0) {
        return t('work_plans.approval_waits_crew');
    }

    const previa = props.approvals
        .slice(0, i)
        .find((p) => p.required && !p.signed);

    return previa ? t('work_plans.approval_waits_prior', { role: rotulo(previa.role) }) : null;
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

    temporizador = setTimeout(async () => {
        buscando.value = true;
        try {
            const { data } = await axios.get(
                route('business_management.work_plans.crew.candidates', props.planSlug),
                { params: { q } },
            );
            candidatos.value = data.people;
            parcial.value = !!data.partial;
            minimo.value = data.minimum ?? minimo.value;
        } finally {
            buscando.value = false;
        }
    }, 250);
};

const sinResultados = computed(() => (parcial.value
    ? t('work_plans.approval_assign_hint')
    : t('work_plans.crew_no_results')));

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

const firmar = () => router.get(route('field_work.signatures.show', props.planSlug));
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

        <ol v-else class="wp-flow">
            <li
                v-for="(a, i) in approvals"
                :key="a.slug"
                class="wp-flow__step"
                :class="{ 'is-signed': a.signed, 'is-blocked': !!bloqueo(a, i) }"
            >
                <span class="wp-flow__mark" aria-hidden="true">
                    <CheckCircleFilled v-if="a.signed" />
                    <LockOutlined v-else-if="bloqueo(a, i)" />
                    <ClockCircleOutlined v-else />
                </span>

                <div class="wp-flow__body">
                    <div class="wp-flow__head">
                        <strong>{{ rotulo(a.role) }}</strong>
                        <Tag v-if="!a.required" :bordered="false">{{ $t('work_plans.approval_optional') }}</Tag>
                    </div>

                    <p class="wp-flow__who">
                        <template v-if="a.person">{{ a.person.name }}</template>
                        <em v-else>{{ $t('work_plans.approval_unassigned') }}</em>
                    </p>

                    <p v-if="bloqueo(a, i)" class="wp-flow__why">{{ bloqueo(a, i) }}</p>

                    <!-- Asignar quién firma. Se escribe el documento; nunca se
                         despliega una lista de personas. -->
                    <div v-if="canEdit && !a.signed && abierta === a.slug" class="wp-flow__assign">
                        <Select
                            show-search
                            allow-clear
                            autofocus
                            :filter-option="false"
                            :loading="buscando"
                            :placeholder="$t('work_plans.approval_assign_hint')"
                            :not-found-content="buscando ? undefined : sinResultados"
                            style="width: 100%"
                            @search="buscar"
                            @change="(v) => asignar(a, v)"
                        >
                            <SelectOption v-for="p in candidatos" :key="p.slug" :value="p.slug">
                                {{ p.name }}
                            </SelectOption>
                        </Select>
                    </div>
                </div>

                <div class="wp-flow__acts">
                    <!-- Firmado: verde con la hora. Es la prueba de cuándo se
                         autorizó el trabajo, no un adorno — la ficha del sistema
                         anterior la enseñaba y se había perdido. -->
                    <Tooltip v-if="a.signed" :title="$t('work_plans.approval_signed_cannot_reassign')">
                        <Tag color="success" :bordered="false">
                            <CheckCircleFilled /> {{ cuando(a.signed_at) || $t('work_plans.approval_approved') }}
                        </Tag>
                    </Tooltip>

                    <template v-else>
                        <Tooltip
                            v-if="canEdit"
                            :title="a.person
                                ? $t('work_plans.approval_change_hint', { role: rotulo(a.role) })
                                : $t('work_plans.approval_assign_hint')"
                        >
                            <Button size="small" :loading="guardando === a.slug" @click="abrir(a)">
                                <template #icon><EditOutlined /></template>
                                {{ a.person ? $t('work_plans.approval_change') : $t('work_plans.approval_assign') }}
                            </Button>
                        </Tooltip>

                        <Tooltip
                            v-if="canSign && a.person"
                            :title="bloqueo(a, i) || $t('work_plans.crew_sign_hint', { name: a.person.name })"
                        >
                            <Button size="small" type="primary" :disabled="!!bloqueo(a, i)" @click="firmar">
                                {{ $t('work_plans.approval_sign') }}
                            </Button>
                        </Tooltip>
                    </template>
                </div>
            </li>
        </ol>
    </Card>
</template>

<style scoped>
/* Una escalera, no una tabla: el orden de las firmas es la información. */
.wp-flow { list-style: none; margin: 0; padding: 0; }

.wp-flow__step {
    display: grid;
    grid-template-columns: 24px minmax(0, 1fr) auto;
    gap: 10px;
    align-items: start;
    padding: 12px 0;
    border-bottom: 1px solid var(--color-border, #e5e7eb);
}
.wp-flow__step:last-child { border-bottom: 0; }

/* Cada paso encadenado con el siguiente. */
.wp-flow__mark {
    position: relative;
    display: flex;
    justify-content: center;
    padding-top: 2px;
    font-size: 16px;
    color: var(--color-text-muted, #6b7280);
}
.wp-flow__step:not(:last-child) .wp-flow__mark::after {
    content: '';
    position: absolute;
    top: 22px;
    bottom: -14px;
    width: 2px;
    background: var(--color-border, #e5e7eb);
}
.is-signed .wp-flow__mark { color: var(--color-success, #16a34a); }
.is-blocked .wp-flow__mark { color: var(--color-text-muted, #9ca3af); }

.wp-flow__head { display: flex; align-items: center; gap: 8px; }
.wp-flow__who  { margin: 2px 0 0; color: var(--color-text, #111827); }
.wp-flow__who em { color: var(--color-text-muted, #6b7280); font-style: normal; }
.wp-flow__why  { margin: 4px 0 0; font-size: 12px; color: var(--color-text-muted, #6b7280); }
.wp-flow__assign { margin-top: 8px; }

/* Objetivos de toque de 44px: se usa con guantes (docs/UI.md §3). */
.wp-flow__acts { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
.wp-flow__acts :deep(.ant-btn) { min-height: 32px; }

@media (max-width: 640px) {
    .wp-flow__step { grid-template-columns: 24px minmax(0, 1fr); }
    .wp-flow__acts { grid-column: 2; justify-content: flex-start; }
}
</style>
