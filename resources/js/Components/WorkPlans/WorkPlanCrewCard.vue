<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Alert, Card, Tag, Button, Input, Popconfirm, Tooltip, Popover,
} from 'ant-design-vue';
import {
    TeamOutlined, DeleteOutlined, EditOutlined, IdcardOutlined, LoadingOutlined, CameraOutlined,
} from '@ant-design/icons-vue';
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
    // La nacionalidad SOLO si no es la del país donde se trabaja. El servidor
    // ya decide eso: aquí llega el nombre o no llega nada. Una banderita
    // peruana en las 380 filas peruanas no informa de nada; la del que viene
    // de fuera sí, porque lleva carné de extranjería y no DNI.
    fila.foreign,
].filter(Boolean).join(' · ');

const documento = ref('');
const buscando = ref(false);
const guardando = ref(false);
const quitando = ref(null);
const aviso = ref('');       // qué pasa con lo que hay escrito
const minimo = ref(7);       // lo dice el servidor; 7 es el de la v1

// Antirrebote: en obra se teclea con guantes y el lector de códigos manda los
// dígitos de golpe. Sin esto cada carácter sería una consulta.
let temporizador = null;

/**
 * Se escribe el documento y la persona entra sola. **Sin desplegable.**
 *
 * Así se trabaja en la puerta: se escanea el DNI o se teclea, y ya está. El
 * sistema anterior tenía aquí un select2 —y esto también— que obligaba a
 * teclear el documento entero y ADEMÁS elegir de una lista de un solo elemento,
 * con guantes y a pleno sol. Dos gestos para una decisión que ya estaba tomada
 * al terminar de teclear.
 *
 * Se añade sólo con coincidencia EXACTA y única: «4001» encaja con veinte
 * documentos y no se puede adivinar cuál, así que hasta que no sea exacta no
 * se toca nada. Y por debajo del mínimo ni se pregunta, para que el buscador
 * no acabe siendo un volcado del padrón.
 */
const buscar = (texto) => {
    clearTimeout(temporizador);
    const q = String(texto ?? '').trim();
    aviso.value = '';

    if (q.length < minimo.value) return;

    temporizador = setTimeout(async () => {
        buscando.value = true;
        try {
            const { data } = await axios.get(
                route('business_management.work_plans.crew.candidates', props.planSlug),
                { params: { q, exclude_assigned: 1 } },
            );

            minimo.value = data.minimum ?? minimo.value;

            if (data.exact) {
                anadir(data.exact.slug);

                return;
            }

            // Ni exacta ni nada parecido: o no está dado de alta, o ya está en
            // el plan. Las dos cosas se dicen, que no es lo mismo.
            aviso.value = data.people.length
                ? t('work_plans.crew_keep_typing')
                : t('work_plans.crew_no_results');
        } finally {
            buscando.value = false;
        }
    }, 250);
};

const anadir = (slug) => {
    guardando.value = true;
    router.post(
        route('business_management.work_plans.crew.store', props.planSlug),
        { person_slug: slug },
        {
            preserveScroll: true,
            onSuccess: () => { documento.value = ''; aviso.value = ''; },
            onFinish: () => { guardando.value = false; },
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
        <!-- La pastilla también cuando está vacía, y en ámbar: es el primer
             paso del plan y sin ella la tarjeta se veía igual de tranquila que
             una ya terminada. -->
        <template #extra>
            <Tag
                :color="crew.length ? (todosFirmaron ? 'success' : 'warning') : 'warning'"
                :bordered="false"
            >
                {{ crew.length
                    ? $tc('work_plans.crew_summary', firmados, { signed: firmados, total: crew.length })
                    : $t('work_plans.crew_pending_tag') }}
            </Tag>
        </template>

        <!-- Sin nadie en la cuadrilla no hay plan: no se pueden llenar los
             formatos, no hay a quien designar representante y no se puede
             firmar nada. Era un párrafo gris que se leía como un pie de página;
             es lo primero que hay que hacer y ahora se ve como tal. -->
        <Alert
            v-if="!crew.length"
            type="warning"
            show-icon
            class="wp-crew__vacio"
            :message="$t('work_plans.crew_empty')"
            :description="$t('work_plans.crew_empty_why')"
        />

        <ul v-if="crew.length" class="wp-rows">
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
                    <!-- La cara con la que firmo. Solo llega con
                         `people.view_private_info` —el servidor manda `face_url`
                         en nulo si no— y es lo que le dice al admin quien estuvo
                         de verdad en obra cuando la cuadrilla es de una
                         contratista que no conoce. La firma NO se enseña aqui ni
                         en ningun otro sitio: solo se imprime en el PDF. -->
                    <Popover v-if="fila.face_url && fila.signed" trigger="click" placement="left">
                        <template #content>
                            <img :src="fila.face_url" class="crew-face" alt="">
                        </template>
                        <Tooltip :title="$t('people.signer_face')">
                            <Button size="small" type="text">
                                <template #icon><CameraOutlined /></template>
                            </Button>
                        </Tooltip>
                    </Popover>

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

        <div v-if="canEdit" class="wp-add">
            <!-- Un input, no un desplegable: se escanea o se teclea el
                 documento y la persona entra sola en cuanto coincide. -->
            <Input
                v-model:value="documento"
                size="large"
                allow-clear
                inputmode="numeric"
                autocomplete="off"
                :maxlength="20"
                :disabled="guardando"
                :placeholder="$t('work_plans.crew_search_placeholder')"
                @update:value="buscar"
                @press-enter="buscar(documento)"
            >
                <template #prefix><IdcardOutlined /></template>
                <template v-if="buscando || guardando" #suffix><LoadingOutlined /></template>
            </Input>

            <p class="wp-add__hint" :class="{ 'is-bad': aviso === $t('work_plans.crew_no_results') }">
                {{ aviso || $tc('work_plans.crew_search_hint', minimo, { count: minimo }) }}
            </p>
        </div>

    </Card>
</template>

<style scoped>
/* La cara de quien firmo, dentro del globo. Grande de verdad: si no se le
   reconoce, no sirve de nada enseñarla. */
.crew-face { display: block; width: 220px; height: 220px; object-fit: cover; border-radius: 8px; }
.wp-crew__vacio { margin-bottom: 4px; }
.wp-add { margin-top: 14px; }
.wp-add__hint { margin: 6px 2px 0; font-size: 0.8125rem; color: var(--color-text-muted, #6A6D70); }
.wp-add__hint.is-bad { color: var(--color-error, #BB0000); }

/* La lista no pone nada: cada fila es WorkPlanBoardRow, que es la misma en las
   tres columnas del tablero. */
.wp-rows { list-style: none; margin: 0; padding: 0; }
</style>
