<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Select, SelectOption, Popconfirm, Tooltip,
} from 'ant-design-vue';
import {
    TeamOutlined, DeleteOutlined, PlusOutlined, CheckCircleFilled,
    ExclamationCircleFilled, EditOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

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

/** dd-mm-aaaa hh:mm, hora de obra: no se reinterpreta la zona. */
const cuando = (v) => {
    if (!v) return '';
    const s = String(v);
    const [y, m, d] = s.slice(0, 10).split('-');
    return d ? `${d}-${m}-${y} ${s.slice(11, 16)}` : s;
};

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

// Firmar se hace en la pantalla de obra, que abre la cámara. El botón vive aquí
// —al lado de la persona que le falta— y no en un menú aparte del que nadie
// sabía que existía.
const firmar = () => router.get(route('field_work.signatures.show', props.planSlug));
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

        <ul v-else class="ff-items">
            <li v-for="fila in crew" :key="fila.slug" class="ff-item" :class="{ 'is-missing': !fila.signed }">
                <div class="ff-item__main ff-item__name">
                    <strong>{{ fila.name }}</strong>
                    <!-- El cargo, como en la ficha del sistema anterior. El
                         documento llega ya enmascarado del servidor. -->
                    <span class="ff-item__sub">
                        <template v-if="fila.position">{{ fila.position }} · </template>{{ fila.doc_type }} {{ fila.num_doc || '—' }}
                    </span>
                </div>

                <!-- Verde con la hora, o ámbar diciendo que falta. Color Y
                     palabra: al sol y con daltonismo el color solo no basta
                     (docs/UI.md §5). -->
                <div class="ff-item__meta">
                    <Tooltip v-if="fila.signed" :title="$t('work_plans.crew_signed_at', { when: cuando(fila.signed_at) })">
                        <Tag color="success" :bordered="false">
                            <CheckCircleFilled /> {{ cuando(fila.signed_at) || $t('work_plans.crew_signed') }}
                        </Tag>
                    </Tooltip>
                    <Tag v-else color="warning" :bordered="false">
                        <ExclamationCircleFilled /> {{ $t('work_plans.crew_pending') }}
                    </Tag>
                </div>

                <div class="ff-item__acts">
                    <Tooltip v-if="canSign && !fila.signed" :title="$t('work_plans.crew_sign_hint', { name: fila.name })">
                        <Button size="small" type="primary" @click="firmar">
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
                </div>
            </li>
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
/* Una firma que falta se ve sin leer la fila entera: barra ámbar a la
   izquierda. Es la misma señal en las tres columnas del tablero. */
.ff-item.is-missing { box-shadow: inset 3px 0 0 var(--color-warning, #E9730C); padding-left: 12px; }
</style>
