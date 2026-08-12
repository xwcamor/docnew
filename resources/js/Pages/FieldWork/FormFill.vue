<script setup>
import { computed, reactive, ref } from 'vue';
import { Modal } from 'ant-design-vue';
// `FileOutlined` es el icono con el que un formato sale en el menú lateral y en
// la lista de formatos del plan: un concepto, un icono en toda la aplicación.
// Sin él la cabecera de esta pantalla —que se abre justo desde esa lista— era la
// única de la aplicación sin marca a la izquierda. Antes era peor: `SectionHeader`
// pintaba el recuadro de color aunque no le pasaran nada, así que aquí salía un
// cuadrado azul vacío que se lee como un icono que no cargó.
import { ArrowLeftOutlined, FileOutlined } from '@ant-design/icons-vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import RiskMatrixField from '@/Components/FormFields/RiskMatrixField.vue';
import PersonChecklistField from '@/Components/FormFields/PersonChecklistField.vue';
import ToolChecklistField from '@/Components/FormFields/ToolChecklistField.vue';
import QuestionBankField from '@/Components/FormFields/QuestionBankField.vue';
import AttachField from '@/Components/FormFields/AttachField.vue';
import SignatureField from '@/Components/FormFields/SignatureField.vue';
import { humanizar } from '@/Components/FormFields/respuestas';
import { useI18n } from '@/Plugins/i18n';

const props = defineProps({
    submission: Object,
    template: Object,
    answers: Array,
    // Lo que falta para poder confirmar, dos veces: `missing` trae las
    // ETIQUETAS y es para el aviso —se lee—; `missingCodes` trae los CODIGOS y
    // es para encontrar cada campo pendiente y marcarlo en rojo. Las dos
    // llegan frescas tras cada guardado que se quedó a medias, porque el
    // servidor vuelve a esta misma pantalla y recalcula sus props.
    missing: Array,
    missingCodes: { type: Array, default: () => [] },
    // Si ya hubo un intento de guardar antes de abrir la pantalla. El marcado
    // rojo solo se enciende tras un intento: pintar de rojo un formato recién
    // abierto y vacío es gritarle a quien todavía no ha hecho nada.
    attempted: { type: Boolean, default: false },
    // El plan del que cuelga el formato: es a donde vuelve la salida del pie.
    plan: Object,
    people: { type: Array, default: () => [] },
    // Lo que ya está subido, para poder verlo y quitar el que sobra.
    attachments: { type: Array, default: () => [] },
    canReopen: { type: Boolean, default: false },
    /** El plan está cerrado: aquí no se escribe, se mira. */
    planClosed: { type: Boolean, default: false },
    canViewPlan: { type: Boolean, default: false },
});

defineOptions({ layout: AppLayout });

const { t } = useI18n();

/**
 * Los compuestos que guardan UNA respuesta POR FILA, usando `row_index`: la
 * matriz de riesgo (una fila por peligro), el EPP (una por trabajador) y el IHM
 * (una por herramienta). El banco de preguntas no: es una sola respuesta con la
 * lista completa.
 */
const POR_FILA = ['risk_matrix', 'person_checklist', 'tool_checklist'];

const COMPUESTOS = {
    risk_matrix: RiskMatrixField,
    person_checklist: PersonChecklistField,
    tool_checklist: ToolChecklistField,
    question_bank: QuestionBankField,
};

/**
 * Los que guardan su valor en `form_attachments` y no en `form_answers`.
 *
 * Caían al `<a-input v-else>` del final de la cadena: definías un campo de foto
 * y en obra salía una caja de escribir texto. El servidor sí estaba listo —el
 * adjunto acepta `form_field_id` desde el principio y el PDF sabe pintarlo—;
 * lo que faltaba era el control.
 *
 * Va aparte de `COMPUESTOS` porque estos necesitan además el slug de la entrega
 * y sus adjuntos: su valor no está en `valores`, está en el servidor.
 */
const CON_ARCHIVO = {
    photo: AttachField,
    file: AttachField,
    signature: SignatureField,
};

const campos = props.template.sections.flatMap((s) => s.fields);
const porId = new Map(campos.map((c) => [c.id, c]));

/**
 * Cuándo esta pantalla se mira en vez de rellenarse. Son DOS motivos.
 *
 * Una entrega confirmada se mira porque lo firmado no se altera — para eso está
 * «Volver a editar», que deja rastro.
 *
 * Y un plan cerrado no se toca, esté como esté el documento. Faltaba: se miraba
 * sólo el estado de la entrega, así que un formato en BORRADOR de un plan
 * cerrado abría editable. Se rellenaba, se guardaba, y el servidor lo rechazaba
 * con `plan_closed` — todo lo tecleado, perdido, después del trabajo.
 */
const soloLectura = computed(() => props.submission.status === 'confirmed' || props.planClosed);

const valores = reactive({});

/**
 * Cuantas filas tenia el campo la ultima vez que se guardo. Hace falta porque
 * `responder()` hace updateOrCreate por (campo, fila) y nunca borra: si el
 * usuario quita una fila hay que sobreescribirla con null, o reaparece.
 */
const filasGuardadas = {};

const porCampo = {};
props.answers.forEach((r) => { (porCampo[r.form_field_id] ??= []).push(r); });

Object.entries(porCampo).forEach(([id, lista]) => {
    const campo = porId.get(Number(id));

    if (! campo) return;

    lista.sort((a, b) => a.row_index - b.row_index);

    if (POR_FILA.includes(campo.field_type)) {
        filasGuardadas[campo.id] = lista[lista.length - 1].row_index + 1;
        // Las filas borradas quedan como null en la entrega: no se pintan.
        valores[campo.id] = lista.map((r) => r.value_json).filter((v) => v !== null && v !== undefined);

        return;
    }

    const r = lista[0];
    valores[campo.id] = r.value_text ?? r.value_number ?? r.value_json ?? r.value_boolean ?? r.value_datetime;
});

const guardando = ref(false);

/**
 * Si ya se intentó guardar, aquí o antes de abrir la pantalla.
 *
 * Es lo que enciende el marcado rojo de los campos que faltan. Arranca del
 * servidor (`attempted`) y se enciende también al terminar un guardado que se
 * quedó a medias: así el rojo aparece cuando un intento dejó faltantes, y
 * nunca mientras alguien teclea por primera vez en un formato vacío.
 */
const intento = ref(props.attempted);

/**
 * Si el campo quedó pendiente en el último intento de guardar.
 *
 * Se busca por CODIGO y no por etiqueta: la etiqueta depende del idioma y
 * puede repetirse. En los compuestos el marcado lo pinta el propio componente
 * (prop `faltante`); en los simples lo pinta esta pantalla (`.ff-falta`).
 */
const faltaCampo = (campo) => intento.value
    && ! soloLectura.value
    && props.missingCodes.includes(campo.code);

/**
 * Foto de lo que hay tecleado, para saber si queda algo sin guardar.
 *
 * Se compara con la de la ultima vez que el servidor dijo que si. No se mira
 * campo a campo: da igual QUE cambio, lo unico que se decide con esto es si al
 * salir hay que avisar o no.
 *
 * Sirve `JSON.stringify` sobre el reactivo porque el orden de `campos` es
 * siempre el mismo —viene de la plantilla, no de un objeto— y los valores son
 * texto, numeros, listas o las filas de un compuesto, todo serializable.
 */
const huella = () => JSON.stringify(campos.map((c) => valores[c.id] ?? null));

let guardado = huella();

/**
 * La etiqueta del campo viene del formato, no de la interfaz: la trae el
 * servidor ya resuelta al idioma en curso (`FormField::getLabelAttribute()`,
 * sobre `label_es`/`label_en`).
 *
 * El respaldo se queda porque un campo creado antes de que existieran esas
 * columnas —o desde el editor sin rellenarlas— no tiene ninguna: entonces se
 * humaniza el codigo, que es como se leia hasta ahora.
 */
const etiqueta = (campo) => campo.label || humanizar(campo.code);

/**
 * Como se lee un campo suelto cuando el formato ya esta confirmado.
 *
 * Hace falta porque una entrega cerrada se pinta con `{{ valor }}` a secas, y
 * Vue serializa lo que no es una cadena: un `multiselect` con dos objetivos
 * marcados salia como `[ "Retirar bloqueos", "Orden y limpieza" ]` —con sus
 * corchetes y sus comillas— en el AST firmado que ve el supervisor, y una
 * casilla marcada salia como «true». Lo guardado estaba bien; lo que estaba
 * mal era como se leia.
 */
const enLimpio = (valor) => {
    if (Array.isArray(valor)) return valor.length ? valor.join(', ') : '—';
    if (typeof valor === 'boolean') return valor ? t('global.yes') : t('global.no');

    return valor === null || valor === undefined || valor === '' ? '—' : valor;
};

/** Lo tecleado, en el formato que espera el servidor. */
function respuestasDeLaPantalla() {
    const respuestas = [];

    campos.forEach((campo) => {
        const valor = valores[campo.id];

        if (valor === undefined) return;

        if (POR_FILA.includes(campo.field_type)) {
            const filas = Array.isArray(valor) ? valor : [];

            filas.forEach((fila, i) => respuestas.push({ code: campo.code, row: i, value: fila }));

            // Lapidas de las filas que se quitaron (ver filasGuardadas).
            for (let i = filas.length; i < (filasGuardadas[campo.id] ?? 0); i++) {
                respuestas.push({ code: campo.code, row: i, value: null });
            }

            filasGuardadas[campo.id] = Math.max(filasGuardadas[campo.id] ?? 0, filas.length);

            return;
        }

        // Una lista vacia no la acepta el servidor: se omite en vez de fallar.
        if (Array.isArray(valor) && valor.length === 0) return;

        respuestas.push({ code: campo.code, value: valor });
    });

    return respuestas;
}

/**
 * El único botón de acción: guarda y, si con eso el formato queda completo, el
 * servidor lo confirma y manda a la ficha del plan.
 *
 * Eran dos botones —«Confirmar formato» y «Guardar cambios»— y el dueño del
 * producto lo dijo tal cual: nadie guarda para después volver a confirmar. El
 * que decide es el servidor (`answer` en el controlador): si no falta nada,
 * confirma y redirige; si falta algo, lo guardado se queda guardado, el
 * formato sigue en borrador y esta pantalla vuelve con la lista fresca de
 * faltantes en sus props. Por eso aquí ya no se llama a `confirm`.
 *
 * Se manda TAMBIEN con la pantalla vacía: en la HOJA X —el formato que es
 * solo la foto del papel— no hay nada que teclear y aun así este botón tiene
 * que poder confirmar una vez adjuntado el archivo. Antes había un atajo que
 * ni llamaba al servidor si no había respuestas, y con él la HOJA X no se
 * podía cerrar.
 */
function guardar() {
    guardando.value = true;

    router.post(route('field_work.forms.answer', props.submission.slug), { answers: respuestasDeLaPantalla() }, {
        preserveScroll: true,
        // La foto se renueva SOLO cuando el servidor ha dicho que si: si el
        // guardado falla, lo tecleado sigue siendo trabajo pendiente y salir
        // tiene que seguir avisando. Y desde este momento ya hubo un intento:
        // si el servidor devolvio faltantes, ahora si se marcan en rojo.
        onSuccess: () => { guardado = huella(); intento.value = true; },
        onFinish: () => { guardando.value = false; },
    });
}

// ─── Adjuntos ───────────────────────────────────────────────────────────────
//
// Toda la mecánica —arrastrar, la cola, los rechazados, quitar— vive en
// `AttachField`, porque es el mismo gesto en dos sitios: el formato entero (la
// HOJA X, sin campo) y cada campo de foto o archivo.

/** Los del formato entero: los que no cuelgan de ningún campo. */
const adjuntosDelFormato = computed(() => props.attachments.filter((a) => ! a.field_id));

/** Los de un campo concreto, que es donde el PDF los va a buscar. */
const adjuntosDe = (campo) => props.attachments.filter((a) => a.field_id === campo.id);

/**
 * La salida: de aqui se vuelve a la ficha del plan, que es de donde se viene.
 *
 * No la habia. Se abria un formato y las unicas puertas eran «Confirmar» —que
 * cierra el documento— y, en uno ya confirmado, «Volver a editar» —que lo
 * reabre—. Las dos escriben en la base: entrar a MIRAR un formato y salirse sin
 * tocar nada no se podia, y en obra eso acaba en «le doy a confirmar a ver que
 * pasa». Se colo porque la pantalla se penso solo para el que llena, y la abren
 * tambien el supervisor y el auditor.
 *
 * Dice «Volver al plan» y no «Cancelar» a proposito. Cancelar significa deshacer
 * lo hecho, y aqui no se deshace nada: lo que ya se guardo esta guardado y sigue
 * estandolo —nada destruye evidencia, docs/UI.md §6—. Nombrar el destino es
 * ademas lo que hace la pantalla de firma para este mismo viaje, y es el mismo
 * boton con el mismo icono.
 */
const volverA = computed(() => (props.canViewPlan
    ? route('business_management.work_plans.show', props.plan.slug)
    : route('field_work.forms.index', props.plan.slug)));

/**
 * Se avisa SOLO si de verdad hay algo tecleado sin guardar.
 *
 * Preguntar siempre es peor que no preguntar: con guantes y prisa, un aviso que
 * sale las veinte veces se contesta sin leerlo, y la vez que importa —la matriz
 * de riesgo recien rellenada— se contesta igual de rapido. Un aviso que solo
 * aparece cuando hay algo que perder se lee.
 *
 * Y se avisa, en vez de guardar solo al salir, porque salir no es guardar:
 * quien se equivoca de formato y se sale no quiere dejar rastro de su error en
 * un documento que puede acabar delante de un inspector.
 */
function salir() {
    if (huella() === guardado) { router.get(volverA.value); return; }

    Modal.confirm({
        title: t('field_work.leave_title'),
        content: t('field_work.leave_help'),
        // La accion peligrosa es la que se marca; la segura es la que se pulsa
        // sin querer al tocar fuera.
        okText: t('field_work.leave_discard'),
        okType: 'danger',
        cancelText: t('field_work.leave_stay'),
        onOk: () => router.get(volverA.value),
    });
}

/**
 * Volver a abrir un formato confirmado.
 *
 * Se pregunta antes porque no es «editar»: deshace el cierre de un documento y
 * deja rastro. La pregunta dice exactamente eso, que es lo que hay que saber
 * antes de aceptar, y no «¿Estás seguro?».
 */
function reabrir() {
    Modal.confirm({
        title: t('field_work.reopen_title'),
        content: t('field_work.reopen_help'),
        okText: t('field_work.reopen'),
        cancelText: t('global.cancel'),
        onOk: () => router.post(
            route('field_work.forms.reopen', props.submission.slug), {}, { preserveScroll: true },
        ),
    });
}
</script>

<template>
    <div class="mi-console">
        <SectionHeader :title="template.label || template.code"
                       :subtitle="`${$t('field_work.version')} ${submission.template_version} · ${$t(`field_work.status.${submission.status}`)}`">
            <template #icon><FileOutlined /></template>
        </SectionHeader>

        <!-- El motivo importa y no es el mismo: un documento confirmado se
             puede volver a editar, un plan cerrado hay que reabrirlo primero.
             Con un solo texto, quien llega a un plan cerrado buscaba el botón
             de reabrir el documento y no está. -->
        <a-alert v-if="planClosed" type="info" show-icon class="mb-4"
                 :message="$t('field_work.plan_closed')" />
        <a-alert v-else-if="soloLectura" type="success" show-icon class="mb-4"
                 :message="$t('field_work.readonly_notice')" />

        <!-- Guardar a medias es lo NORMAL en obra: el aviso es informativo
             —ámbar, no rojo— y dice primero que el trabajo no se perdió. Solo
             sale tras un intento de guardar (`intento`), no sobre un formato
             recién abierto y vacío. -->
        <a-alert v-else-if="intento && missing.length" type="warning" show-icon class="mb-4"
                 :message="`${$t('field_work.missing')}: ${missing.join(', ')}`"
                 :description="$t('field_work.missing_help')" />

        <!-- La HOJA X: el formato es el papel, se le toman las fotos.
             En plural: un permiso de trabajo son tres hojas, y de una en una
             son tres viajes al servidor desde una tablet con mala señal. -->
        <a-card v-if="template.kind !== 'structured'"
                :title="$t('field_work.document')" size="small" class="mb-4">
            <AttachField
                :submission-slug="submission.slug"
                :attachments="adjuntosDelFormato"
                :readonly="soloLectura"
            />
        </a-card>

        <!-- El titulo del bloque, cuando lo tiene. En el papel el AST lleva
             «Permisos», «Objetivos» y «Trabajos a realizar», y aqui salian tres
             tarjetas iguales sin nada que las distinguiera. El EPP y el IHM son
             una sola tabla sin cabecera: esos siguen sin titulo, y esta bien. -->
        <a-card v-for="s in template.sections" :key="s.id" size="small" class="mb-4"
                :title="s.label || null">
            <!-- `.ff-falta` marca en rojo el campo que un intento de guardar
                 dejó pendiente: etiqueta, borde del control y la palabra del
                 estado — el color nunca va solo (docs/UI.md §5). -->
            <div v-for="c in s.fields" :key="c.id" class="ff-block" :class="{ 'ff-falta': faltaCampo(c) }">
                <label class="ff-block__label">
                    {{ etiqueta(c) }}<span v-if="c.is_required" class="ff-block__req"> *</span>
                    <span v-if="faltaCampo(c)" class="ff-falta__palabra">{{ $t('field_work.field_missing') }}</span>
                </label>

                <!-- Compuestos: reproducen lo que antes era un formato entero.
                     El marcado de faltante lo pintan ellos por dentro con la
                     prop `faltante`; si un componente todavía no la conoce,
                     Vue la ignora y no pasa nada — la etiqueta de arriba ya
                     dice el estado. -->
                <component
                    v-if="COMPUESTOS[c.field_type]"
                    :is="COMPUESTOS[c.field_type]"
                    :field="c"
                    :value="valores[c.id]"
                    :readonly="soloLectura"
                    :faltante="faltaCampo(c)"
                    :people="c.field_type === 'person_checklist' ? people : undefined"
                    @update:value="valores[c.id] = $event"
                />

                <!-- Foto, archivo y firma: el valor es el adjunto, así que no
                     pasan por `valores` ni por el guardado de respuestas. Se
                     suben solos y el servidor los cuenta como respondidos. -->
                <component
                    v-else-if="CON_ARCHIVO[c.field_type]"
                    :is="CON_ARCHIVO[c.field_type]"
                    :field="c"
                    :submission-slug="submission.slug"
                    :attachments="adjuntosDe(c)"
                    :readonly="soloLectura"
                    :faltante="faltaCampo(c)"
                />

                <template v-else-if="soloLectura">
                    <span class="ff-readonly">{{ enLimpio(valores[c.id]) }}</span>
                </template>

                <a-textarea v-else-if="c.field_type === 'textarea'" v-model:value="valores[c.id]" :rows="3" />
                <a-input-number v-else-if="c.field_type === 'number'" v-model:value="valores[c.id]" />
                <a-date-picker v-else-if="c.field_type === 'date'" v-model:value="valores[c.id]" />
                <a-checkbox v-else-if="c.field_type === 'checkbox'" v-model:checked="valores[c.id]" />
                <a-select v-else-if="c.field_type === 'multiselect'" v-model:value="valores[c.id]"
                          mode="multiple" size="large" show-search option-filter-prop="label"
                          :options="(c.config?.options ?? []).map((o) => ({ value: o, label: o }))" />
                <a-select v-else-if="c.field_type === 'select'" v-model:value="valores[c.id]"
                          size="large" show-search allow-clear option-filter-prop="label"
                          :options="(c.config?.options ?? []).map((o) => ({ value: o, label: o }))" />

                <!-- `radio` y `time` también caían al input de texto del final:
                     un campo de hora se tecleaba a mano y uno de opción única
                     se escribía la opción. Los dos estaban en `FormField::TIPOS`
                     desde el principio. -->
                <a-radio-group v-else-if="c.field_type === 'radio'" v-model:value="valores[c.id]" size="large">
                    <a-radio v-for="o in (c.config?.options ?? [])" :key="o" :value="o">{{ o }}</a-radio>
                </a-radio-group>
                <a-time-picker v-else-if="c.field_type === 'time'" v-model:value="valores[c.id]"
                               value-format="HH:mm" format="HH:mm" size="large" />

                <a-input v-else v-model:value="valores[c.id]" />
            </div>
        </a-card>
    </div>

    <!-- La barra va FUERA de la tarjeta, y es la misma que la de todas las
         demás pantallas (`.sap-actionbar`, docs/UI.md §8).
         Dentro de `.mi-console` se quedaba metida en una caja redondeada: medía
         69 px donde el resto mide 57, empezaba a 25 px del borde y no llegaba a
         los lados. Parecía un panel flotando en vez del pie de la pantalla.
         Los 44 px de los botones sí se quedan —esto se pulsa con guantes, y para
         eso el alto de la barra sale de `--bar-control-h` en vez de estar
         clavado— igual que el hueco del borde inferior de la tablet. -->
    <div v-if="!soloLectura" class="sap-actionbar ff-actions">
        <div class="sap-actionbar__actions">
            <!-- UNA sola acción. Eran dos —«Confirmar formato» y «Guardar
                 cambios»— y con dos se perdía tiempo eligiendo: nadie guarda
                 para después volver a confirmar. Este botón guarda y el
                 servidor confirma solo si no falta nada; si falta, lo guardado
                 se queda y la pantalla dice qué queda. -->
            <a-button type="primary" size="large" :loading="guardando" @click="guardar">{{ $t('field_work.save') }}</a-button>
            <!-- La salida, la última del DOM y por tanto la más a la izquierda:
                 la primaria va pegada al borde derecho (docs/UI.md §8). -->
            <a-button size="large" @click="salir">
                <template #icon><ArrowLeftOutlined /></template>
                {{ $t('field_work.back_to_plan') }}
            </a-button>
        </div>
    </div>

    <!-- Confirmado, pero no en piedra: corregir un dato mal tecleado se hace
         desde aquí, en el mismo sitio donde estaba «Confirmar», y no es un
         atajo — reabre el formato y lo anota en el historial.
         La barra ya NO cuelga de `canReopen`: quien solo mira —el auditor, o
         cualquiera con el plan cerrado— se quedaba sin pie y sin salida, que es
         justo el caso en el que hace más falta, porque no hay nada más que
         hacer en esta pantalla. Ahora lo que depende del permiso es el botón de
         reabrir, no la franja entera. -->
    <div v-else class="sap-actionbar ff-actions">
        <div class="sap-actionbar__actions">
            <a-button v-if="canReopen" size="large" @click="reabrir">{{ $t('field_work.reopen') }}</a-button>
            <a-button size="large" @click="salir">
                <template #icon><ArrowLeftOutlined /></template>
                {{ $t('field_work.back_to_plan') }}
            </a-button>
        </div>
    </div>
</template>
