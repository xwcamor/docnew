<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import {
    Card, Tag, Button, Select, SelectOption, Popconfirm, Tooltip,
} from 'ant-design-vue';
import {
    FileTextOutlined, FilePdfOutlined, DeleteOutlined, PlusOutlined, EditOutlined,
} from '@ant-design/icons-vue';

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
});

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

        <ul v-else class="ff-items">
            <li v-for="f in forms" :key="f.slug" class="ff-item">
                <div class="ff-item__main ff-item__name">
                    <strong>{{ f.code }}</strong>
                    <span class="ff-item__sub">
                        {{ f.required ? $t('work_plans.forms_required') : $t('work_plans.forms_optional') }}
                    </span>
                </div>

                <div class="ff-item__meta">
                    <Tag :color="COLOR_ORIGEN[f.source]" :bordered="false">
                        {{ $t('work_plans.forms_source_' + f.source) }}
                    </Tag>
                    <Tag :color="COLOR_ESTADO[f.status]" :bordered="false">
                        {{ $t('field_work.status.' + f.status) }}
                    </Tag>
                </div>

                <div class="ff-item__acts">
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
                        <Tooltip v-if="!f.can_remove" :title="$t('work_plans.form_filled_cannot_remove', { code: f.code })">
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
                            <Button size="small" type="text" danger :loading="quitando === f.slug">
                                <DeleteOutlined />
                            </Button>
                        </Popconfirm>
                    </template>
                </div>
            </li>
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
