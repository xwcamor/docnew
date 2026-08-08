<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Select, SelectOption, Popconfirm, Tooltip,
} from 'ant-design-vue';
import {
    FileTextOutlined, FilePdfOutlined, DeleteOutlined, PlusOutlined, EditOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import WorkPlanBoardRow from '@/Components/WorkPlans/WorkPlanBoardRow.vue';

/**
 * Formatos de seguridad del plan.
 *
 * La lista por defecto la pone el tipo de trabajo y eso no se toca: es lo que
 * impide que un plan de trabajo en altura salga sin AST porque alguien tenía
 * prisa. Lo que sí se puede es sumarle un formato a ESTE plan o quitarle el que
 * no aplica; la etiqueta de origen dice de dónde salió cada uno.
 *
 * Un formato ya trabajado no se quita, y se ve desde aquí: el botón sale
 * apagado con el motivo, en vez de dejar pulsar y devolver un error.
 */
const props = defineProps({
    planSlug:  { type: String, required: true },
    forms:     { type: Array,  default: () => [] },
    options:   { type: Array,  default: () => [] },
    canEdit:   { type: Boolean, default: false },
    canOpen:   { type: Boolean, default: false },
    canExport: { type: Boolean, default: false },
    /** Código del tipo de trabajo — se nombra al explicar por qué un formato es obligatorio. */
    workTypeCode: { type: String, default: '' },
});

const { t } = useI18n();
const workTypeCode = props.workTypeCode || '—';

// Un formato sin empezar sale en gris pero con borde: en gris plano se leía
// como "no aplica" cuando en realidad es lo que falta hacer.
const COLOR_ESTADO = { pending: 'default', draft: 'orange', submitted: 'blue', confirmed: 'success' };
const COLOR_ORIGEN = { work_type: 'blue', extra: 'purple', submitted: 'gold' };

const elegido = ref(undefined);
const guardando = ref(false);
const quitando = ref(null);

// Cuántos están cerrados: es el número que decide si el plan puede terminarse.
const confirmados = computed(() => props.forms.filter((f) => f.status === 'confirmed').length);

// El PDF solo tiene sentido con el formato cerrado: en borrador sería un
// documento a medias con firmas que aún pueden cambiar.
const conPdf = (f) => f.submission && f.status === 'confirmed';

/**
 * Debajo del código: si es obligatorio y de dónde sale.
 *
 * El origen sólo cuando NO es el del tipo de trabajo — ese es el caso normal y
 * repetirlo en cada fila es ruido. El candado del obligatorio ya no se dibuja
 * aparte: la marca de estado de la fila es la misma en las tres columnas.
 */
const subtitulo = (f) => [
    f.required ? t('work_plans.forms_required') : t('work_plans.forms_optional'),
    f.source !== 'work_type' ? t('work_plans.forms_source_' + f.source) : null,
].filter(Boolean).join(' · ');

/**
 * Por qué este formato no se puede quitar. Son dos casos y no significan lo
 * mismo: uno es una norma del catálogo, el otro es que ya hay trabajo hecho.
 */
const motivoNoQuitar = (f) => (f.locked_by_work_type
    ? t('work_plans.form_required_by_work_type', { code: f.code, type: workTypeCode })
    : t('work_plans.form_filled_cannot_remove', { code: f.code }));

const anadir = () => {
    if (!elegido.value) return;
    guardando.value = true;
    router.post(
        route('business_management.work_plans.forms.store', props.planSlug),
        { form_template_slug: elegido.value, is_required: true },
        {
            preserveScroll: true,
            onFinish: () => { guardando.value = false; elegido.value = undefined; },
        },
    );
};

const quitar = (f) => {
    quitando.value = f.slug;
    router.delete(
        route('business_management.work_plans.forms.destroy', [props.planSlug, f.slug]),
        { preserveScroll: true, onFinish: () => { quitando.value = null; } },
    );
};
</script>

<template>
    <Card :bodyStyle="{ padding: 18 }" class="info-card">
        <template #title><FileTextOutlined /> {{ $t('work_plans.forms_title') }} ({{ forms.length }})</template>
        <template v-if="forms.length" #extra>
            <span class="ff-count" :class="{ 'is-done': confirmados === forms.length }">
                {{ $tc('work_plans.forms_summary', confirmados, { done: confirmados, total: forms.length }) }}
            </span>
        </template>

        <p v-if="canEdit" class="ff-cardhint">{{ $t('work_plans.forms_subtitle') }}</p>

        <p v-if="!forms.length" class="ff-empty">{{ $t('work_plans.forms_empty') }}</p>

        <ul v-else class="wp-rows">
            <WorkPlanBoardRow
                v-for="f in forms"
                :key="f.slug"
                :state="f.status === 'confirmed' ? 'done' : 'pending'"
                :title="f.code"
                :subtitle="subtitulo(f)"
                :when="f.status === 'confirmed' ? f.confirmed_at : null"
                :label="$t('field_work.status.' + f.status)"
            >
                <template #actions>
                    <a v-if="canExport && conPdf(f)" :href="route('field_work.forms.pdf', f.submission)">
                        <Button size="small">
                            <template #icon><FilePdfOutlined /></template>
                            {{ $t('work_plans.forms_pdf') }}
                        </Button>
                    </a>

                    <Link v-if="canOpen" :href="route('field_work.forms.open', [planSlug, f.slug])">
                        <Button size="small" type="primary">
                            <template #icon><EditOutlined /></template>
                            {{ $t('work_plans.forms_open') }}
                        </Button>
                    </Link>

                    <template v-if="canEdit">
                        <!-- Dos motivos distintos para no poder quitarlo, y se
                             dicen distintos: uno lo exige el tipo de trabajo,
                             el otro ya está lleno. Un tooltip genérico obliga a
                             adivinar cuál de los dos es. -->
                        <Tooltip v-if="!f.can_remove" :title="motivoNoQuitar(f)">
                            <Button size="small" type="text" disabled><DeleteOutlined /></Button>
                        </Tooltip>
                        <Popconfirm
                            v-else
                            :title="$t('work_plans.forms_remove_confirm', { code: f.code })"
                            :ok-text="$t('work_plans.forms_remove')"
                            :cancel-text="$t('global.cancel')"
                            placement="topRight"
                            @confirm="quitar(f)"
                        >
                            <Tooltip :title="$t('work_plans.forms_remove')">
                                <Button size="small" type="text" danger :loading="quitando === f.slug">
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
                allow-clear
                :disabled="!options.length"
                :placeholder="options.length ? $t('work_plans.forms_add_title') : $t('work_plans.forms_none_left')"
            >
                <SelectOption v-for="o in options" :key="o.slug" :value="o.slug">{{ o.code }}</SelectOption>
            </Select>
            <Button type="primary" :disabled="!elegido" :loading="guardando" @click="anadir">
                <template #icon><PlusOutlined /></template>
                {{ $t('work_plans.forms_add') }}
            </Button>
        </div>
    </Card>
</template>

<style scoped>
/* Cada fila es WorkPlanBoardRow, la misma de las tres columnas del tablero. */
.wp-rows { list-style: none; margin: 0; padding: 0; }
</style>
