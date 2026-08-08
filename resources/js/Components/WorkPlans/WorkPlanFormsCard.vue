<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Card, Tag, Button, Switch, Tooltip } from 'ant-design-vue';
import { FileTextOutlined, FilePdfOutlined, EditOutlined, DownloadOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import WorkPlanBoardRow from '@/Components/WorkPlans/WorkPlanBoardRow.vue';

/**
 * Formatos de seguridad del plan. **Todos, con un interruptor cada uno.**
 *
 * Así lo hacía el sistema anterior y es lo que yo no había entendido. Su
 * `_list_documents.html.erb` recorre el catálogo entero y pinta un checkbox por
 * formato: marcado si el tipo de trabajo lo exige o si el plan ya lo tiene, y
 * **deshabilitado** cuando lo exige el tipo.
 *
 * Aquí había un desplegable de «formatos que quedan» más un botón Añadir y una
 * papelera por fila. Con cuatro formatos en el catálogo y los cuatro ya en el
 * plan, el desplegable salía vacío y parecía que el sistema no dejaba añadir
 * ninguno. El problema no era el desplegable: era el modelo mental. No se
 * «añade» un formato — están todos siempre, y se encienden y se apagan.
 *
 * Lo que no se puede apagar:
 *
 * - **Lo que exige el tipo de trabajo.** Es lo que impide que un trabajo en
 *   altura salga sin AST. Se cambia en el tipo, que afecta a todos los planes.
 * - **Lo que ya tiene respuestas, adjuntos o firmas.** Apagarlo borraría el
 *   documento de seguridad de ese día.
 */
const props = defineProps({
    planSlug:  { type: String, required: true },
    forms:     { type: Array,  default: () => [] },
    canEdit:   { type: Boolean, default: false },
    canOpen:   { type: Boolean, default: false },
    canExport: { type: Boolean, default: false },
    /** Código del tipo de trabajo — se nombra al explicar por qué uno es obligatorio. */
    workTypeCode: { type: String, default: '' },
});

const { t } = useI18n();
const workTypeCode = computed(() => props.workTypeCode || '—');

// Sólo cuentan los que el plan exige: los apagados no son trabajo pendiente.
const enElPlan    = computed(() => props.forms.filter((f) => f.included));
const confirmados = computed(() => enElPlan.value.filter((f) => f.status === 'confirmed').length);
const todosLlenos = computed(() => enElPlan.value.length > 0 && confirmados.value === enElPlan.value.length);

// Todo lo que salió mal en el plan, sumado. Es el dato que decide si la jornada
// salió limpia, y sin él la cabecera dice «4 de 4» en verde igual.
const observaciones = computed(() => enElPlan.value.reduce((n, f) => n + (f.findings || 0), 0));

// El PDF sólo tiene sentido con el formato cerrado: en borrador sería un
// documento a medias con firmas que aún pueden cambiar.
const conPdf = (f) => f.submission && f.status === 'confirmed';

/**
 * Debajo del código, sólo lo que **no** se da por supuesto.
 *
 * Antes ponía «Obligatorio» en las cuatro filas, y si lo son todas la palabra
 * no distingue nada: es ruido repetido cuatro veces. El interruptor bloqueado
 * ya dice que ese no se toca. Lo que sí merece una palabra es la excepción.
 */
const subtitulo = (f) => {
    // El codigo va debajo, no arriba: sirve para reconocerlo de un vistazo pero
    // no dice lo que es. Arriba va el nombre.
    const partes = [f.code];

    if (!f.included) partes.push(t('work_plans.forms_not_in_plan'));
    else if (!f.locked_by_work_type) partes.push(t('work_plans.forms_optional'));

    return partes.join(' · ');
};

/** Por qué el interruptor está bloqueado. Son dos motivos y no son el mismo. */
const motivoBloqueo = (f) => {
    if (f.locked_by_work_type) {
        return t('work_plans.form_required_by_work_type', { code: f.code, type: workTypeCode.value });
    }

    return f.has_content ? t('work_plans.form_filled_cannot_remove', { code: f.code }) : '';
};

const cambiando = ref(null);

const alternar = (f, valor) => {
    cambiando.value = f.slug;
    const opciones = { preserveScroll: true, onFinish: () => { cambiando.value = null; } };

    valor
        ? router.post(route('business_management.work_plans.forms.store', props.planSlug),
            { form_template_slug: f.slug, is_required: true }, opciones)
        : router.delete(route('business_management.work_plans.forms.destroy', [props.planSlug, f.slug]), opciones);
};
</script>

<template>
    <Card :bodyStyle="{ padding: 18 }" class="info-card">
        <template #title>
            <FileTextOutlined /> {{ $t('work_plans.forms_title') }} ({{ enElPlan.length }})
        </template>
        <!-- Etiqueta, como en las otras dos columnas. Antes era un `span` suelto
             sin color mientras las hermanas usaban Tag ámbar: la misma
             información contada de dos maneras en la misma pantalla. -->
        <template v-if="enElPlan.length" #extra>
            <Tag :color="todosLlenos ? 'success' : 'warning'" :bordered="false">
                {{ $tc('work_plans.forms_summary', confirmados, { done: confirmados, total: enElPlan.length }) }}
            </Tag>
            <!-- «4 de 4 llenos» en verde y tres arneses rotos dentro es la misma
                 cabecera que un día limpio. El total va al lado, no en su
                 lugar: las dos cosas son ciertas. -->
            <Tag v-if="observaciones" color="error" :bordered="false">
                {{ $tc('work_plans.forms_findings', observaciones, { count: observaciones }) }}
            </Tag>

            <!-- El expediente de la jornada de golpe. Bajarlo formato por
                 formato son cuatro clics y cuatro archivos que hay que volver a
                 juntar; es lo que se manda al cliente o a una inspección. -->
            <Tooltip v-if="canExport && confirmados" :title="$t('work_plans.export_zip_hint')">
                <a :href="route('field_work.forms.zip', planSlug)">
                    <Button size="small">
                        <template #icon><DownloadOutlined /></template>
                        {{ $t('work_plans.export_zip') }}
                    </Button>
                </a>
            </Tooltip>
        </template>

        <p v-if="!forms.length" class="ff-empty">{{ $t('work_plans.forms_empty') }}</p>

        <ul v-else class="wp-rows">
            <WorkPlanBoardRow
                v-for="f in forms"
                :key="f.slug"
                :state="!f.included ? 'optional' : (f.status === 'confirmed' ? 'done' : 'pending')"
                :title="f.name || f.code"
                :subtitle="subtitulo(f)"
                :when="f.status === 'confirmed' ? f.confirmed_at : null"
                :label="f.included ? $t('field_work.status.' + f.status) : ''"
                :findings="f.included ? (f.findings || 0) : 0"
                done-verb="completed"
            >
                <template #actions>
                    <template v-if="f.included">
                        <Tooltip v-if="canExport && conPdf(f)" :title="$t('work_plans.forms_pdf_hint', { code: f.code })">
                            <a :href="route('field_work.forms.pdf', f.submission)">
                                <Button size="small">
                                    <template #icon><FilePdfOutlined /></template>
                                    {{ $t('work_plans.forms_pdf') }}
                                </Button>
                            </a>
                        </Tooltip>

                        <Tooltip v-if="canOpen" :title="$t('work_plans.forms_open_hint', { code: f.code })">
                            <Link :href="route('field_work.forms.open', [planSlug, f.slug])">
                                <Button size="small" type="primary">
                                    <template #icon><EditOutlined /></template>
                                    {{ $t('work_plans.forms_open') }}
                                </Button>
                            </Link>
                        </Tooltip>
                    </template>

                    <!-- El interruptor de la v1: bloqueado cuando lo exige el
                         tipo de trabajo o cuando ya hay trabajo hecho, y en los
                         dos casos el tooltip dice cuál de los dos es. -->
                    <Tooltip v-if="canEdit" :title="motivoBloqueo(f) || $t('work_plans.forms_toggle_hint', { code: f.code })">
                        <Switch
                            :checked="f.included"
                            :disabled="!f.can_toggle"
                            :loading="cambiando === f.slug"
                            size="small"
                            @change="(v) => alternar(f, v)"
                        />
                    </Tooltip>
                </template>
            </WorkPlanBoardRow>
        </ul>
    </Card>
</template>

<style scoped>
/* Cada fila es WorkPlanBoardRow, la misma de las tres columnas del tablero. */
.wp-rows { list-style: none; margin: 0; padding: 0; }
</style>
