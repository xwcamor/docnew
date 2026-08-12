<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { Card, Tag, Button, Switch, Tooltip } from 'ant-design-vue';
import { FileTextOutlined, FilePdfOutlined, EditOutlined, EyeOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import WorkPlanBoardRow from '@/Components/WorkPlans/WorkPlanBoardRow.vue';
import { useDateFormat } from '@/Composables/useDateFormat';

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
    /** Plan cerrado: los documentos se miran, no se editan. */
    planClosed: { type: Boolean, default: false },
    // Aquí vivía `workTypeCode`. Servía para un solo texto —«lo exige el tipo
    // MTTO»— que se decía en el tooltip del interruptor bloqueado, y ese
    // interruptor ya no se pinta: un documento obligatorio no lleva ninguno.
});

const { t } = useI18n();
// `confirmed_at` es `submitted_at`, que el servidor estampa con `now()` y viaja
// en UTC: se convierte al huso del usuario, no se trocea la cadena.
const { formatDateTime } = useDateFormat();

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
 * Debajo del nombre, sólo lo que **no** se da por supuesto.
 *
 * Antes ponía «Obligatorio» en las cuatro filas, y si lo son todas la palabra
 * no distingue nada: es ruido repetido cuatro veces. El interruptor bloqueado
 * ya dice que ese no se toca. Lo que sí merece una palabra es la excepción.
 *
 * Y ya no lleva el código. Estaba ahí con el argumento de que sirve para
 * reconocer el formato de un vistazo, y no es cierto: el nombre completo va
 * justo encima y dice lo mismo mejor, así que «AST» debajo de «AST (Análisis de
 * Seguridad en el Trabajo)» sólo repetía sus propias siglas una línea más
 * abajo. Cuatro documentos, cuatro repeticiones. Donde el código sí identifica
 * algo —el PDF, los avisos del interruptor— se sigue diciendo.
 */
const subtitulo = (f) => {
    const partes = [];

    if (!f.included) return t('work_plans.forms_not_in_plan');

    if (!f.locked_by_work_type) partes.push(t('work_plans.forms_optional'));

    // Cómo va: «Sin empezar», «En borrador», «Enviado». Confirmado NO, y es a
    // propósito: para eso está la hora justo al lado, que dice lo mismo y
    // además dice cuándo. La fila de un documento sin llenar se quedaba con la
    // segunda línea vacía y no había manera de distinguirla de una a medias.
    if (f.status !== 'confirmed') partes.push(t('field_work.status.' + f.status));

    return partes.join(' · ');
};

/**
 * Por qué no hay interruptor, cuando el motivo NO se ve solo.
 *
 * Son dos motivos y no son el mismo. Que un documento sea obligatorio se sabe
 * sin que nadie lo diga —lo dijo el dueño mirando la pantalla, y tiene razón:
 * es lo normal, y escribirlo en las cuatro filas es ruido repetido cuatro
 * veces—. Que uno OPCIONAL no se pueda quitar porque ya está lleno, en cambio,
 * no se deduce de nada: sin esta línea la fila es un documento opcional sin
 * interruptor y parece que la pantalla se rompió.
 */
const motivoNoObvio = (f) => (
    !f.can_toggle && !f.locked_by_work_type && f.has_content
        ? t('work_plans.form_filled_cannot_remove', { code: f.code })
        : ''
);

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

            <!-- «Descargar todo» estaba aquí y se ha ido arriba, con las
                 acciones del plan.
                 Es una acción y no un estado, y era la única de la pantalla que
                 vivía dentro de la cabecera de una tarjeta: al lado de las dos
                 pastillas empujaba el título de «Documentos (4)» hasta dejarlo
                 en «D…». Y además no es del formato ni de esta tarjeta — se
                 lleva el expediente entero de la jornada, que es del plan. -->
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
                :subtitle-time="f.status === 'confirmed' ? formatDateTime(f.confirmed_at) : ''"
                :label="f.included ? $t('field_work.status.' + f.status) : ''"
                :findings="f.included ? (f.findings || 0) : 0"
                :show-clean="f.included && f.status === 'confirmed'"
                :reason="motivoNoObvio(f)"
                done-verb="completed"
            >
                <template #actions>
                    <template v-if="f.included">
                        <Tooltip v-if="canExport && conPdf(f)" :title="$t('work_plans.forms_pdf_hint', { code: f.code })">
                            <!-- Sólo el icono: el de PDF es de los pocos que se
                                 reconocen sin leer, y la palabra al lado
                                 ocupaba en cada fila el sitio que necesita el
                                 nombre del documento. El tooltip lo nombra. -->
                            <a :href="route('field_work.forms.pdf', f.submission)">
                                <Button size="small" :aria-label="$t('work_plans.forms_pdf')">
                                    <template #icon><FilePdfOutlined /></template>
                                </Button>
                            </a>
                        </Tooltip>

                        <!-- Lápiz con el plan abierto, ojo con el plan cerrado.
                             El lápiz salía siempre y en un plan cerrado sólo
                             podía fallar: el servidor rechaza cualquier
                             escritura (`exigirQueElPlanSigaAbierto`), así que
                             era prometer una edición imposible — la regla de
                             docs/UI.md §6.

                             Y se queda un botón, no desaparece: la pantalla es
                             de sólo lectura y es la única forma de MIRAR el
                             documento para quien no tiene permiso de exportar
                             el PDF, que pide dos permisos más. -->
                        <Tooltip
                            v-if="canOpen"
                            :title="planClosed
                                ? $t('work_plans.forms_view_hint', { code: f.code })
                                : $t('work_plans.forms_open_hint', { code: f.code })"
                        >
                            <Link :href="route('field_work.forms.open', [planSlug, f.slug])">
                                <Button
                                    size="small"
                                    :type="planClosed ? 'default' : 'primary'"
                                    :aria-label="planClosed ? $t('global.view') : $t('work_plans.forms_open')"
                                >
                                    <template #icon>
                                        <EyeOutlined v-if="planClosed" />
                                        <EditOutlined v-else />
                                    </template>
                                </Button>
                            </Link>
                        </Tooltip>
                    </template>

                    <!-- El interruptor de la v1, y SÓLO cuando se puede mover.
                         Un documento obligatorio salía con el interruptor
                         encendido y bloqueado en las cuatro filas: no dice nada
                         —obligatorio ya se sabe— y es un objetivo de toque que
                         sólo puede fallar, que es peor que no tenerlo
                         (docs/UI.md §6). Cuando el bloqueo NO es obvio —el
                         documento es opcional pero ya está lleno, y por eso no
                         se puede quitar— se explica con una línea, que ahí sí
                         hace falta. -->
                    <Tooltip v-if="canEdit && f.can_toggle" :title="$t('work_plans.forms_toggle_hint', { code: f.code })">
                        <Switch
                            :checked="f.included"
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
