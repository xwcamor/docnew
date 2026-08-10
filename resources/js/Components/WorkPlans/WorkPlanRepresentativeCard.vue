<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Card, Button, Select, SelectOption, Tooltip,
} from 'ant-design-vue';
import { SolutionOutlined, EditOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import WorkPlanBoardRow from '@/Components/WorkPlans/WorkPlanBoardRow.vue';

/**
 * Quién responde por el equipo que sale a la obra.
 *
 * Va aquí, debajo de los trabajadores, y **no** en el flujo de aprobaciones,
 * que es donde estaba. Allí era una fila más —la del «ejecutante»— y eso tenía
 * dos problemas que nadie vio hasta que el dueño preguntó por qué esa firma
 * aparecía dos veces:
 *
 * - **No recogía ninguna firma propia.** Apuntaba a alguien que ya había
 *   firmado como trabajador de este mismo plan, y esa firma —con su foto y su
 *   hora— es la que vale. Pedirle una segunda sería la misma persona, el mismo
 *   día, el mismo plan y la misma cámara: la segunda no prueba nada que no
 *   probara la primera.
 * - **Viviendo en el flujo se podía borrar o volver opcional** desde las reglas
 *   de aprobación del país. Un plan podía acabar con gente en la obra y sin
 *   nadie que respondiera por ella, y nadie se enteraba.
 *
 * Por eso ahora es una columna del plan (`crew_representative_person_id`) y
 * tiene su propia tarjeta: se ve al lado de la lista de la que sale, no tres
 * columnas más a la derecha.
 *
 * No es un cargo ni un rol de la ficha de la persona: un electricista que va
 * solo a la obra es el representante ese día y uno más al siguiente. Depende
 * del plan, no de quién sea.
 */
const props = defineProps({
    planSlug: { type: String, required: true },
    /**
     * Lo que manda el servidor: `{ person, can_designate, signed_crew }`.
     *
     * `can_designate` no es el permiso —eso es `canEdit`—, es si hay a quien
     * designar: el representante sale de los que YA firmaron, así que con el
     * equipo entero sin firmar todavía no hay candidatos.
     */
    representative: { type: Object, default: () => ({ person: null, can_designate: false, signed_crew: 0 }) },
    canEdit: { type: Boolean, default: false },
});

const { t } = useI18n();

const persona = computed(() => props.representative?.person ?? null);

// Armar el plan exige permiso Y plan abierto, igual que en las otras tarjetas:
// lo decide el servidor en `setup.can` y aquí sólo se obedece. Y además tiene
// que haber alguien que ya haya firmado — un botón que sólo puede fallar es
// peor que un botón que no está (docs/UI.md §6).
const puedeDesignar = computed(() => props.canEdit && !!props.representative?.can_designate);

// ── Elegir a la persona ──────────────────────────────────────────────────────
//
// El mismo buscador por documento que las otras dos tarjetas: se escanea o se
// teclea el DNI y sale el nombre. Nunca un desplegable con el padrón dentro.
// La lista la acota el servidor con `representante=1`, que devuelve sólo a los
// de ESTE plan que ya firmaron.

const abierto   = ref(false);
const candidatos = ref([]);
const buscando  = ref(false);
const parcial   = ref(true);   // lo tecleado todavía no llega al mínimo
const minimo    = ref(8);      // lo dice el servidor; hasta entonces, el del DNI
const guardando = ref(false);

// Antirrebote: en obra se teclea con guantes y el lector manda los dígitos de
// golpe. Sin esto cada carácter sería una consulta.
let temporizador = null;

const buscar = (texto) => {
    clearTimeout(temporizador);
    const q = (texto || '').trim();

    if (!q) { candidatos.value = []; parcial.value = true; return; }

    temporizador = setTimeout(async () => {
        buscando.value = true;
        try {
            const { data } = await axios.get(
                route('business_management.work_plans.crew.candidates', props.planSlug),
                { params: { q, representante: 1 } },
            );
            candidatos.value = data.people;
            parcial.value = !!data.partial;
            minimo.value = data.minimum ?? minimo.value;
        } finally {
            buscando.value = false;
        }
    }, 250);
};

/**
 * Por qué la lista está vacía, que no es lo mismo en cada caso.
 *
 * Sin resultados no significa «no existe»: casi siempre significa que existe
 * pero todavía no ha firmado en este plan, y decirlo evita buscar un nombre
 * que no va a aparecer.
 */
const sinResultados = computed(() => {
    if (buscando.value) return undefined;
    if (parcial.value) return t('work_plans.approval_assign_hint');

    return t('work_plans.representative_no_results');
});

const abrir = () => {
    abierto.value = !abierto.value;
    candidatos.value = [];
    parcial.value = true;
};

const designar = (personSlug) => {
    if (!personSlug) return;
    guardando.value = true;
    router.put(
        route('business_management.work_plans.representative', props.planSlug),
        { person_slug: personSlug },
        {
            preserveScroll: true,
            onFinish: () => {
                guardando.value = false;
                abierto.value = false;
                candidatos.value = [];
            },
        },
    );
};
</script>

<template>
    <Card :bodyStyle="{ padding: 18 }" class="info-card">
        <template #title><SolutionOutlined /> {{ $t('work_plans.representative') }}</template>

        <!-- Quién puede serlo, antes de que nadie vaya a buscar al jefe: sale de
             los que ya firmaron y vale cualquiera del equipo. -->
        <p class="wp-rep__help">{{ $t('work_plans.representative_help') }}</p>

        <ul v-if="persona" class="wp-rows">
            <WorkPlanBoardRow
                :key="persona.slug"
                state="done"
                :title="persona.name"
                :subtitle="persona.position || ''"
            >
                <template #actions>
                    <Tooltip v-if="puedeDesignar" :title="$t('work_plans.representative_change')">
                        <Button size="small" :loading="guardando" @click="abrir">
                            <template #icon><EditOutlined /></template>
                            {{ $t('work_plans.representative_change') }}
                        </Button>
                    </Tooltip>
                </template>
            </WorkPlanBoardRow>
        </ul>

        <template v-else>
            <!-- Dos maneras de no tenerlo, y no son la misma: o falta
                 designarlo, o todavía no hay a quien. La segunda no lleva
                 botón — pulsarlo sólo podría acabar en un error. -->
            <p v-if="representative.can_designate" class="ff-empty">
                {{ $t('work_plans.representative_none') }}
            </p>
            <p v-else class="ff-empty">
                {{ $t('work_plans.representative_needs_signature') }}
            </p>

            <Button v-if="puedeDesignar" type="primary" :loading="guardando" @click="abrir">
                <template #icon><EditOutlined /></template>
                {{ $t('work_plans.representative_designate') }}
            </Button>
        </template>

        <!-- El buscador va en su propia línea y a todo el ancho: metido entre
             los botones deja la columna en 200px y parte el nombre letra a
             letra, que es lo que ya pasó en el flujo de aprobaciones. -->
        <div v-if="puedeDesignar && abierto" class="wp-rep__pick">
            <Select
                show-search
                allow-clear
                autofocus
                :filter-option="false"
                :loading="buscando"
                :placeholder="$t('work_plans.approval_assign_hint')"
                :not-found-content="sinResultados"
                style="width: 100%"
                @search="buscar"
                @change="designar"
            >
                <SelectOption v-for="p in candidatos" :key="p.slug" :value="p.slug">
                    {{ p.name }}
                </SelectOption>
            </Select>
        </div>
    </Card>
</template>

<style scoped>
/* La misma lista que las otras dos columnas: la fila la pinta
   WorkPlanBoardRow, para que no vuelvan a divergir. */
.wp-rows { list-style: none; margin: 0; padding: 0; }

.wp-rep__help { margin: 0 0 12px; font-size: 0.8125rem; color: var(--color-text-muted, #6A6D70); }
.wp-rep__pick { margin-top: 12px; }
/* Con guantes, a pleno sol: 44px de objetivo de toque (docs/UI.md §3). */
.wp-rep__pick :deep(.ant-select-selector) { min-height: 44px; }
</style>
