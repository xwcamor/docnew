<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import RiskMatrixField from '@/Components/FormFields/RiskMatrixField.vue';
import PersonChecklistField from '@/Components/FormFields/PersonChecklistField.vue';
import ToolChecklistField from '@/Components/FormFields/ToolChecklistField.vue';
import QuestionBankField from '@/Components/FormFields/QuestionBankField.vue';
import { humanizar } from '@/Components/FormFields/respuestas';
import { useI18n } from '@/Plugins/i18n';

const props = defineProps({
    submission: Object,
    template: Object,
    answers: Array,
    missing: Array,
    people: { type: Array, default: () => [] },
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

const campos = props.template.sections.flatMap((s) => s.fields);
const porId = new Map(campos.map((c) => [c.id, c]));

// Una entrega confirmada se mira, no se edita: lo firmado no se altera.
const soloLectura = computed(() => props.submission.status === 'confirmed');

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

const archivo = ref(null);
const guardando = ref(false);

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

function guardar(alTerminar = null) {
    // Enganchado como `@click="guardar"` llegaria aqui el evento del raton en
    // vez de una funcion. Se comprueba en vez de confiar: cuesta una linea y el
    // fallo seria que «Guardar» deja de guardar sin decir nada.
    const seguir = typeof alTerminar === 'function' ? alTerminar : null;
    const respuestas = respuestasDeLaPantalla();

    if (! respuestas.length) { seguir?.(); return; }

    guardando.value = true;

    router.post(route('field_work.forms.answer', props.submission.slug), { answers: respuestas }, {
        preserveScroll: true,
        onSuccess: () => seguir?.(),
        onFinish: () => { guardando.value = false; },
    });
}

function subir() {
    if (! archivo.value) return;

    const datos = new FormData();
    datos.append('file', archivo.value);

    router.post(route('field_work.forms.attach', props.submission.slug), datos, { preserveScroll: true });
}

/**
 * Confirmar guarda primero lo que hay en pantalla, y despues cierra.
 *
 * No lo hacia, y ese era el fallo: se rellenaba la matriz de riesgo, se pulsaba
 * «Confirmar» sin pasar por «Guardar», y el servidor —que mira la base de datos,
 * no la pantalla— contestaba que faltaba la matriz de riesgo. Con el campo
 * relleno delante. Y ademas reventaba con un error 500.
 *
 * Guardar antes de cerrar es lo unico que tiene sentido en una tablet: en obra
 * se rellena y se cierra, y nadie tiene por que saber que son dos pasos.
 */
function confirmar() {
    guardar(() => router.post(
        route('field_work.forms.confirm', props.submission.slug), {}, { preserveScroll: true },
    ));
}
</script>

<template>
    <div class="mi-console">
        <SectionHeader :title="template.label || template.code"
                       :subtitle="`${$t('field_work.version')} ${submission.template_version} · ${$t(`field_work.status.${submission.status}`)}`" />

        <a-alert v-if="soloLectura" type="success" show-icon class="mb-4"
                 :message="$t('field_work.readonly_notice')" />

        <a-alert v-else-if="missing.length" type="warning" show-icon class="mb-4"
                 :message="`${$t('field_work.missing')}: ${missing.join(', ')}`" />

        <!-- La HOJA X: el formato es el papel, solo se le toma la foto -->
        <a-card v-if="template.kind !== 'structured' && !soloLectura"
                :title="$t('field_work.document')" size="small" class="mb-4">
            <input type="file" accept="image/*,application/pdf" @change="archivo = $event.target.files[0]" />
            <a-button type="primary" class="ml-2" @click="subir">{{ $t('field_work.attach') }}</a-button>
        </a-card>

        <!-- El titulo del bloque, cuando lo tiene. En el papel el AST lleva
             «Permisos», «Objetivos» y «Trabajos a realizar», y aqui salian tres
             tarjetas iguales sin nada que las distinguiera. El EPP y el IHM son
             una sola tabla sin cabecera: esos siguen sin titulo, y esta bien. -->
        <a-card v-for="s in template.sections" :key="s.id" size="small" class="mb-4"
                :title="s.label || null">
            <div v-for="c in s.fields" :key="c.id" class="ff-block">
                <label class="ff-block__label">
                    {{ etiqueta(c) }}<span v-if="c.is_required" class="ff-block__req"> *</span>
                </label>

                <!-- Compuestos: reproducen lo que antes era un formato entero -->
                <component
                    v-if="COMPUESTOS[c.field_type]"
                    :is="COMPUESTOS[c.field_type]"
                    :field="c"
                    :value="valores[c.id]"
                    :readonly="soloLectura"
                    :people="c.field_type === 'person_checklist' ? people : undefined"
                    @update:value="valores[c.id] = $event"
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
                <a-input v-else v-model:value="valores[c.id]" />
            </div>
        </a-card>

        <div v-if="!soloLectura" class="ff-actions">
            <!-- `guardar()` con paréntesis: sin ellos, Vue le pasa el evento
                 del ratón como primer argumento, que aquí es la continuación
                 que se llama al terminar — y un MouseEvent no se puede llamar. -->
            <a-button size="large" :loading="guardando" @click="guardar()">{{ $t('field_work.save') }}</a-button>
            <a-button type="primary" size="large" @click="confirmar">{{ $t('field_work.confirm') }}</a-button>
        </div>
    </div>
</template>
