<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Alert, Card, Tag, Button, Tooltip } from 'ant-design-vue';
import { SolutionOutlined, EditOutlined } from '@ant-design/icons-vue';
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
    /**
     * Los trabajadores del plan, los mismos que pinta la tarjeta de arriba.
     *
     * De aquí salen los candidatos, filtrando por `signed`. No se piden al
     * servidor: son exactamente la lista que ya está en pantalla, así que
     * pedirlos otra vez sería arriesgarse a que las dos no coincidan.
     */
    crew: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
});

const persona = computed(() => props.representative?.person ?? null);

/**
 * A quién se puede designar: **los que ya firmaron, y nadie más.**
 *
 * Esa firma —con su foto y su hora— es la que vale como representante, así que
 * designar a quien todavía no ha firmado dejaría al plan esperando una
 * responsabilidad que nadie ha asumido. El servidor lo comprueba igual
 * (`WorkPlanSetupService::designarRepresentante`); esto es para no ofrecer lo
 * que va a rechazar.
 */
const candidatos = computed(() => props.crew.filter((f) => f.signed));

/**
 * Falta, y se nota.
 *
 * Es lo unico que bloquea el flujo de aprobaciones entero, y la tarjeta lo
 * contaba con un parrafo gris dentro de una caja gris: al lado de las otras
 * dos columnas, que llevan sus pastillas de color, esta parecia informativa.
 * Con la pantalla llena de tarjetas, lo que no tiene color no existe.
 */
const falta = computed(() => ! persona.value);

// ── Elegir a la persona ──────────────────────────────────────────────────────
//
// Se elige de una lista que se ve, no de un buscador.
//
// Aquí había un botón «Designar» que abría un campo donde había que teclear el
// documento. Son dos gestos y una búsqueda para escoger entre la media docena
// de personas que están pintadas justo encima, en la tarjeta de trabajadores:
// el candidato ya está en pantalla y hay que ir a buscarlo escribiendo su DNI.
//
// En trabajadores el buscador SÍ tiene sentido, porque de ahí se saca a alguien
// de un padrón de miles y hay que identificarlo sin lista. Aquí el conjunto es
// cerrado y pequeño —los de este plan que ya firmaron—, así que se enseña
// entero y se pulsa. Sin botón que lo destape: lo que hay que hacer es elegir,
// y elegir es lo que se ve.

const cambiando = ref(false);   // con representante ya puesto, para elegir otro
const guardando = ref(null);    // slug de la persona que se está designando

// Armar el plan exige permiso Y plan abierto, igual que en las otras tarjetas:
// lo decide el servidor en `setup.can` y aquí sólo se obedece.
const puedeDesignar = computed(() => props.canEdit && candidatos.value.length > 0);

// La lista sale sola cuando falta el representante —que es lo que hay que
// hacer— y bajo petición cuando ya hay uno y se quiere cambiar.
const eligiendo = computed(() => puedeDesignar.value && (!persona.value || cambiando.value));

const subtitulo = (fila) => [fila.position, fila.nationality].filter(Boolean).join(' · ');

const designar = (fila) => {
    const slug = fila.person ?? fila.slug;
    if (!slug) return;

    guardando.value = slug;
    router.put(
        route('business_management.work_plans.representative', props.planSlug),
        { person_slug: slug },
        {
            preserveScroll: true,
            onFinish: () => {
                guardando.value = null;
                cambiando.value = false;
            },
        },
    );
};
</script>

<template>
    <Card :bodyStyle="{ padding: 18 }" class="info-card">
        <template #title><SolutionOutlined /> {{ $t('work_plans.representative') }}</template>

        <!-- La misma pastilla que llevan las otras dos columnas del tablero, y
             por el mismo motivo: el estado se ve sin leer (docs/UI.md §5). -->
        <template #extra>
            <Tag :color="falta ? 'warning' : 'success'" :bordered="false">
                {{ falta ? $t('work_plans.representative_pending') : $t('work_plans.representative_done') }}
            </Tag>
        </template>

        <!-- Quien es, cuando ya lo hay. -->
        <ul v-if="persona" class="wp-rows">
            <WorkPlanBoardRow
                :key="persona.slug"
                state="done"
                :title="persona.name"
                :subtitle="persona.position || ''"
            >
                <template #actions>
                    <Tooltip v-if="puedeDesignar && !cambiando" :title="$t('work_plans.representative_change')">
                        <Button size="small" @click="cambiando = true">
                            <template #icon><EditOutlined /></template>
                            {{ $t('work_plans.representative_change') }}
                        </Button>
                    </Tooltip>
                </template>
            </WorkPlanBoardRow>
        </ul>

        <!-- Todavía no ha firmado nadie: no hay entre quién elegir, y el paso
             que va antes es de la tarjeta de arriba. Azul informativo y sin nada
             que pulsar — un botón que sólo puede fallar es peor que ninguno
             (docs/UI.md §6). -->
        <Alert
            v-else-if="!candidatos.length"
            type="info"
            show-icon
            :message="$t('work_plans.representative_needs_signature')"
            :description="$t('work_plans.representative_needs_signature_why')"
        />

        <!-- Y si hay a quien designar pero esta pantalla es de sólo lectura, se
             dice qué falta aunque no se pueda arreglar desde aquí. -->
        <Alert
            v-else-if="!canEdit"
            type="warning"
            show-icon
            :message="$t('work_plans.representative_none')"
            :description="$t('work_plans.representative_none_why')"
        />

        <!-- La lista, que es la parte que importa: los que ya firmaron, con su
             cargo, y un botón por fila. Sale directamente, sin nada que abrir
             primero: elegir ES lo que hay que hacer aquí. -->
        <div v-if="eligiendo" class="wp-rep__pick">
            <p class="wp-rep__help">
                {{ persona ? $t('work_plans.representative_pick_other') : $t('work_plans.representative_pick') }}
            </p>

            <ul class="wp-rows">
                <WorkPlanBoardRow
                    v-for="fila in candidatos"
                    :key="fila.slug"
                    :state="persona && persona.slug === fila.person ? 'done' : 'pending'"
                    :title="fila.name"
                    :subtitle="subtitulo(fila)"
                >
                    <template #actions>
                        <Button
                            v-if="!persona || persona.slug !== fila.person"
                            type="primary"
                            size="small"
                            :loading="guardando === (fila.person ?? fila.slug)"
                            @click="designar(fila)"
                        >
                            {{ $t('work_plans.representative_designate') }}
                        </Button>
                    </template>
                </WorkPlanBoardRow>
            </ul>

            <Button v-if="persona" size="small" type="text" class="wp-rep__cancel" @click="cambiando = false">
                {{ $t('global.cancel') }}
            </Button>
        </div>
    </Card>
</template>

<style scoped>
/* La misma lista que las otras dos columnas: la fila la pinta
   WorkPlanBoardRow, para que no vuelvan a divergir. */
.wp-rows { list-style: none; margin: 0; padding: 0; }

.wp-rep__help { margin: 0 0 10px; font-size: 0.8125rem; color: var(--color-text-muted, #6A6D70); }
.wp-rep__pick { margin-top: 12px; }
.wp-rep__cancel { margin-top: 8px; }
</style>
