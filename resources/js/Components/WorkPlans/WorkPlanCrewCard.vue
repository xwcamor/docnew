<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Select, SelectOption, Popconfirm, Tooltip,
} from 'ant-design-vue';
import {
    TeamOutlined, DeleteOutlined, PlusOutlined, CameraOutlined, CheckCircleOutlined,
} from '@ant-design/icons-vue';
/**
 * Cuadrilla del plan: quién sale a obra ese día.
 *
 * De cada persona interesan tres cosas antes de salir, y son las tres que se
 * muestran: su documento (con eso se la identifica en obra), si tiene la cara
 * enrolada —sin eso no puede firmar con reconocimiento y hay que enrolarla en
 * el momento— y si ya firmó, porque en ese caso ya no se la puede quitar.
 *
 * El buscador va contra el servidor: son 228 personas y suben, así que mandar
 * el padrón entero en la ficha sería tirar 200 KB por plan abierto.
 */
const props = defineProps({
    planSlug: { type: String, required: true },
    crew:     { type: Array,  default: () => [] },
    canEdit:  { type: Boolean, default: false },
});

const candidatos = ref([]);
const buscando = ref(false);
const elegido = ref(undefined);
const guardando = ref(false);
const quitando = ref(null);

// Antirrebote: en obra se teclea con guantes y salen ráfagas de eventos; sin
// esto cada letra sería una consulta.
let temporizador = null;

const buscar = (texto) => {
    clearTimeout(temporizador);
    temporizador = setTimeout(async () => {
        buscando.value = true;
        try {
            const { data } = await axios.get(
                route('business_management.work_plans.crew.candidates', props.planSlug),
                { params: { q: texto, exclude_assigned: 1 } },
            );
            candidatos.value = data.people;
        } finally {
            buscando.value = false;
        }
    }, 250);
};

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

        <p class="ff-cardhint">{{ $t('work_plans.crew_subtitle') }}</p>

        <p v-if="!crew.length" class="ff-empty">{{ $t('work_plans.crew_empty') }}</p>

        <ul v-else class="ff-items">
            <li v-for="fila in crew" :key="fila.slug" class="ff-item">
                <div class="ff-item__main ff-item__name">
                    <strong>{{ fila.name }}</strong>
                    <span class="ff-item__sub">{{ fila.doc_type }} {{ fila.num_doc || '—' }}</span>
                </div>

                <div class="ff-item__meta">
                    <Tag :color="fila.enrolled ? 'blue' : 'default'" :bordered="false">
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
                :not-found-content="buscando ? undefined : $t('work_plans.crew_no_results')"
                @search="buscar"
                @focus="buscar('')"
            >
                <SelectOption v-for="p in candidatos" :key="p.slug" :value="p.slug">
                    {{ p.name }} — {{ p.num_doc }}
                </SelectOption>
            </Select>
            <Button type="primary" :disabled="!elegido" :loading="guardando" @click="anadir">
                <template #icon><PlusOutlined /></template>
                {{ $t('work_plans.crew_add') }}
            </Button>
        </div>
    </Card>
</template>
