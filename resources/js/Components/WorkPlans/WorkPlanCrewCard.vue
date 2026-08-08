<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Select, SelectOption, Popconfirm, Tooltip,
} from 'ant-design-vue';
import { TeamOutlined, DeleteOutlined, PlusOutlined, EditOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import WorkPlanBoardRow from '@/Components/WorkPlans/WorkPlanBoardRow.vue';

/**
 * Trabajadores del plan: quién sale a obra ese día.
 *
 * Se llama «Trabajadores» a secas. No «Trabajadores del proveedor» —la empresa
 * principal también pone gente aquí— y desde luego no «Cuadrilla», que la
 * inventé yo.
 *
 * De cada uno se enseña lo que hacía falta saber en la ficha del sistema
 * anterior: **el nombre, su cargo y si firmó, con la hora**. Nada más. Si tiene
 * la cara registrada o no es asunto del módulo de Trabajadores, no de este plan.
 *
 * **El documento sólo se escribe, no se lee.** Se añade gente escaneando o
 * tecleando el DNI y lo que vuelve es el nombre; nunca se despliega el padrón.
 */
const props = defineProps({
    planSlug: { type: String, required: true },
    crew:     { type: Array,  default: () => [] },
    canEdit:  { type: Boolean, default: false },
    canSign:  { type: Boolean, default: false },
});

const { t } = useI18n();

// El dato de la tarjeta es cuántos firmaron, no cuántos hay: eso es lo que
// decide si el plan puede cerrarse.
const firmados = computed(() => props.crew.filter((f) => f.signed).length);
const todosFirmaron = computed(() => props.crew.length > 0 && firmados.value === props.crew.length);

// Cargo y documento, en una línea. El documento llega ya enmascarado del
// servidor; lo que identifica a la persona en pantalla es el nombre.
const subtitulo = (fila) => [
    fila.position,
    [fila.doc_type, fila.num_doc].filter(Boolean).join(' '),
].filter(Boolean).join(' · ');

const candidatos = ref([]);
const buscando = ref(false);
const elegido = ref(undefined);
const guardando = ref(false);
const quitando = ref(null);
const parcial = ref(true);   // lo escrito todavía no llega a un documento

// Antirrebote: en obra se teclea con guantes y salen ráfagas de eventos; sin
// esto cada letra sería una consulta.
let temporizador = null;

const buscar = (texto) => {
    clearTimeout(temporizador);
    const q = (texto || '').trim();

    // Sin texto no se pregunta nada. El servidor tampoco contestaría, pero así
    // ni se gasta la llamada ni se parpadea un «no hay resultados» que mentiría.
    if (!q) { candidatos.value = []; parcial.value = true; return; }

    temporizador = setTimeout(async () => {
        buscando.value = true;
        try {
            const { data } = await axios.get(
                route('business_management.work_plans.crew.candidates', props.planSlug),
                { params: { q, exclude_assigned: 1 } },
            );
            candidatos.value = data.people;
            parcial.value = !!data.partial;
        } finally {
            buscando.value = false;
        }
    }, 250);
};

// Un documento a medias no es «no existe nadie»: es «sigue escribiendo». Decir
// lo primero manda al supervisor a dar de alta a alguien que ya está.
const sinResultados = computed(() => (parcial.value
    ? t('work_plans.crew_search_hint')
    : t('work_plans.crew_no_results')));

const anadir = () => {
    if (!elegido.value) return;
    guardando.value = true;
    router.post(
        route('business_management.work_plans.crew.store', props.planSlug),
        { person_slug: elegido.value },
        {
            preserveScroll: true,
            onFinish: () => {
                guardando.value = false;
                elegido.value = undefined;
                candidatos.value = [];
            },
        },
    );
};

const quitar = (fila) => {
    quitando.value = fila.slug;
    router.delete(
        route('business_management.work_plans.crew.destroy', [props.planSlug, fila.slug]),
        { preserveScroll: true, onFinish: () => { quitando.value = null; } },
    );
};

// Firmar abre la cámara **sobre esta persona**. El `target` es lo que evita
// llegar a un listado y tener que volver a buscar a quien acabas de elegir.
const firmar = (fila) => router.get(
    route('field_work.signatures.show', props.planSlug), { target: fila.slug });
</script>

<template>
    <Card :bodyStyle="{ padding: 18 }" class="info-card">
        <template #title><TeamOutlined /> {{ $t('work_plans.crew_title') }} ({{ crew.length }})</template>
        <template v-if="crew.length" #extra>
            <Tag :color="todosFirmaron ? 'success' : 'warning'" :bordered="false">
                {{ $tc('work_plans.crew_summary', firmados, { signed: firmados, total: crew.length }) }}
            </Tag>
        </template>

        <p v-if="!crew.length" class="ff-empty">{{ $t('work_plans.crew_empty') }}</p>

        <ul v-else class="wp-rows">
            <WorkPlanBoardRow
                v-for="fila in crew"
                :key="fila.slug"
                :state="fila.signed ? 'done' : 'pending'"
                :title="fila.name"
                :subtitle="subtitulo(fila)"
                :when="fila.signed ? fila.signed_at : null"
                :label="fila.signed ? $t('work_plans.crew_signed') : $t('work_plans.crew_pending')"
            >
                <template #actions>
                    <Tooltip v-if="canSign && !fila.signed" :title="$t('work_plans.crew_sign_hint', { name: fila.name })">
                        <Button size="small" type="primary" @click="firmar(fila)">
                            <template #icon><EditOutlined /></template>
                            {{ $t('work_plans.approval_sign') }}
                        </Button>
                    </Tooltip>

                    <template v-if="canEdit">
                        <!-- Quien ya firmó no se quita: su firma es la prueba de
                             que estuvo. El servidor lo rechaza igual, pero
                             decirlo aquí evita el clic que no hace nada. -->
                        <Tooltip v-if="fila.signed" :title="$t('work_plans.crew_signed_cannot_remove', { name: fila.name })">
                            <Button size="small" type="text" disabled>
                                <DeleteOutlined />
                            </Button>
                        </Tooltip>
                        <Popconfirm
                            v-else
                            :title="$t('work_plans.crew_remove_confirm', { name: fila.name })"
                            :ok-text="$t('work_plans.crew_remove')"
                            :cancel-text="$t('global.cancel')"
                            placement="topRight"
                            @confirm="quitar(fila)"
                        >
                            <Tooltip :title="$t('work_plans.crew_remove')">
                                <Button size="small" type="text" danger :loading="quitando === fila.slug">
                                    <DeleteOutlined />
                                </Button>
                            </Tooltip>
                        </Popconfirm>
                    </template>
                </template>
            </WorkPlanBoardRow>
        </ul>

        <div v-if="canEdit" class="ff-addrow">
            <Select
                v-model:value="elegido"
                class="ff-addrow__grow"
                show-search
                allow-clear
                :filter-option="false"
                :loading="buscando"
                :placeholder="$t('work_plans.crew_search_placeholder')"
                :not-found-content="buscando ? undefined : sinResultados"
                @search="buscar"
            >
                <!-- El nombre, y sólo el nombre: es lo que confirma que es la
                     persona correcta. El documento ya lo escribió quien busca. -->
                <SelectOption v-for="p in candidatos" :key="p.slug" :value="p.slug">
                    {{ p.name }}
                </SelectOption>
            </Select>
            <Tooltip :title="$t('work_plans.crew_add')">
                <Button type="primary" :disabled="!elegido" :loading="guardando" @click="anadir">
                    <template #icon><PlusOutlined /></template>
                    {{ $t('work_plans.crew_add') }}
                </Button>
            </Tooltip>
        </div>
    </Card>
</template>

<style scoped>
/* La lista no pone nada: cada fila es WorkPlanBoardRow, que es la misma en las
   tres columnas del tablero. */
.wp-rows { list-style: none; margin: 0; padding: 0; }
</style>
