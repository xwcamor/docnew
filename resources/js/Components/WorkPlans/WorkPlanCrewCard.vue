<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Select, SelectOption, Popconfirm, Tooltip,
} from 'ant-design-vue';
import {
    TeamOutlined, DeleteOutlined, PlusOutlined, CameraOutlined, CheckCircleOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
/**
 * Trabajadores del plan: quién sale a obra ese día.
 *
 * Se llama «Trabajadores del proveedor», que es literalmente la etiqueta del
 * sistema anterior (`plans.workers`), y no «Cuadrilla», que la inventé yo.
 *
 * De cada persona interesan tres cosas antes de salir: **el nombre** —no su
 * documento—, si tiene la cara enrolada (sin eso no puede firmar con
 * reconocimiento y hay que enrolarla en el momento) y si ya firmó, porque en
 * ese caso ya no se la puede quitar.
 *
 * **El documento sólo se escribe, no se lee.** Se añade gente escaneando o
 * tecleando el DNI —«Escanea DNI ó documento del trabajador aquí»— y lo que
 * vuelve es el nombre. Nunca se despliega el padrón: antes esta pantalla
 * pedía la lista al recibir el foco y el servidor contestaba con 25 personas
 * y su documento completo.
 */
const props = defineProps({
    planSlug: { type: String, required: true },
    crew:     { type: Array,  default: () => [] },
    canEdit:  { type: Boolean, default: false },
});

const { t } = useI18n();

// El dato de la tarjeta es cuántos firmaron, no cuántos hay: eso es lo que
// decide si el plan puede cerrarse.
const firmados = computed(() => props.crew.filter((f) => f.signed).length);

const candidatos = ref([]);
const buscando = ref(false);
const elegido = ref(undefined);
const guardando = ref(false);
const quitando = ref(null);

// Antirrebote: en obra se teclea con guantes y salen ráfagas de eventos; sin
// esto cada letra sería una consulta.
let temporizador = null;

const parcial = ref(true);   // lo escrito todavía no llega a un documento

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
</script>

<template>
    <Card :bodyStyle="{ padding: 18 }" class="info-card">
        <template #title><TeamOutlined /> {{ $t('work_plans.crew_title') }} ({{ crew.length }})</template>
        <template v-if="crew.length" #extra>
            <span class="ff-count" :class="{ 'is-done': firmados === crew.length }">
                {{ $tc('work_plans.crew_summary', firmados, { signed: firmados, total: crew.length }) }}
            </span>
        </template>

        <p v-if="!crew.length" class="ff-empty">{{ $t('work_plans.crew_empty') }}</p>

        <ul v-else class="ff-items">
            <li v-for="fila in crew" :key="fila.slug" class="ff-item">
                <div class="ff-item__main ff-item__name">
                    <strong>{{ fila.name }}</strong>
                    <!-- Enmascarado en el servidor (App\Support\PrivateInfo):
                         llega ya como ******78 salvo permiso. -->
                    <span class="ff-item__sub">{{ fila.doc_type }} {{ fila.num_doc || '—' }}</span>
                </div>

                <!-- Sin cara registrada va en naranja, no en gris: es lo que va a
                     frenar la firma en la tablet y tiene que verse sin leer. -->
                <div class="ff-item__meta">
                    <Tag :color="fila.enrolled ? 'blue' : 'orange'" :bordered="false">
                        <CameraOutlined />
                        {{ fila.enrolled ? $t('work_plans.crew_enrolled') : $t('work_plans.crew_not_enrolled') }}
                    </Tag>
                    <Tag :color="fila.signed ? 'success' : 'warning'" :bordered="false">
                        <CheckCircleOutlined v-if="fila.signed" />
                        {{ fila.signed ? $t('work_plans.crew_signed') : $t('work_plans.crew_pending') }}
                    </Tag>
                </div>

                <div v-if="canEdit" class="ff-item__acts">
                    <!-- Quien ya firmó no se quita: el servidor lo rechaza igual,
                         pero decirlo aquí evita el clic que no hace nada. -->
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
                        <Button size="small" type="text" danger :loading="quitando === fila.slug">
                            <DeleteOutlined />
                        </Button>
                    </Popconfirm>
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
            <Button type="primary" :disabled="!elegido" :loading="guardando" @click="anadir">
                <template #icon><PlusOutlined /></template>
                {{ $t('work_plans.crew_add') }}
            </Button>
        </div>
    </Card>
</template>
