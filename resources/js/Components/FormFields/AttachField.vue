<script setup>
import { computed, ref } from 'vue';
import { Alert, Button, Popconfirm, Upload } from 'ant-design-vue';
import { FileOutlined, InboxOutlined } from '@ant-design/icons-vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from '@/Plugins/i18n';

/**
 * Subir archivos: se arrastran varios y se ve lo que ya entró.
 *
 * Sirve para los dos sitios donde se adjunta, que son el mismo gesto en dos
 * sitios distintos y por eso comparten componente:
 *
 * - **El formato entero** (`field` a nulo). La HOJA X: el documento es el papel
 *   y sólo se le toman fotos. El adjunto va sin `form_field_id`.
 * - **Un campo** de tipo `photo` o `file`. El adjunto se cuelga de ese campo, y
 *   ahí es donde lo busca el PDF para pintarlo en su sitio.
 *
 * El filtro de tipos de aquí es una cortesía —decirle a quien arrastra una hoja
 * de cálculo que ahí no va, en el momento, sin gastar un viaje al servidor—,
 * nunca un candado: esto entra por `curl` sin despeinarse y por eso la lista
 * entera se vuelve a comprobar en `FormSubmissionController::attach()`.
 */
const props = defineProps({
    submissionSlug: { type: String, required: true },
    /** El campo del que cuelga. Nulo = el adjunto es del formato entero. */
    field:       { type: Object,  default: null },
    attachments: { type: Array,   default: () => [] },
    readonly:    { type: Boolean, default: false },
    /** Obligatorio y sin nada subido: se marca como el resto de campos. */
    faltante:    { type: Boolean, default: false },
});

const { t } = useI18n();

const IMAGENES = ['image/jpeg', 'image/png', 'image/webp'];
const PDF = 'application/pdf';
const TAMANO_MAX = 8 * 1024 * 1024;   // el `max:8192` (KB) de la validación

/**
 * Qué acepta este sitio en concreto.
 *
 * Un campo de foto es sólo imágenes: llamarlo «foto» y tragarse un PDF es
 * mentir. Un campo de archivo respeta su `mimes` si lo tiene puesto, y si no,
 * lo mismo que el formato entero.
 */
const permitidos = computed(() => {
    if (props.field?.field_type === 'photo') return IMAGENES;

    const mimes = props.field?.config?.mimes;
    if (Array.isArray(mimes) && mimes.length) {
        const ext = mimes.map((m) => String(m).toLowerCase().replace(/^\./, ''));
        return [
            ...(ext.includes('jpg') || ext.includes('jpeg') ? ['image/jpeg'] : []),
            ...(ext.includes('png')  ? ['image/png']  : []),
            ...(ext.includes('webp') ? ['image/webp'] : []),
            ...(ext.includes('pdf')  ? [PDF] : []),
        ];
    }

    return [...IMAGENES, PDF];
});

const accept = computed(() => permitidos.value.join(','));

/** Cuántos admite. 0 = sin tope. */
const tope = computed(() => Number(props.field?.config?.max_files ?? 0) || 0);
const lleno = computed(() => tope.value > 0 && props.attachments.length >= tope.value);

const enCola = ref([]);
const rechazados = ref([]);
const subiendo = ref(false);

/** `false` para que Ant no suba por su cuenta: el envío lo controlamos. */
const alSoltar = (archivo) => {
    const malTipo   = ! permitidos.value.includes(archivo.type);
    const muyGrande = archivo.size > TAMANO_MAX;

    if (malTipo || muyGrande) {
        rechazados.value.push({
            name: archivo.name,
            reason: malTipo ? t('field_work.attach_bad_type') : t('field_work.attach_too_big'),
        });
    } else if (! enCola.value.some((f) => f.name === archivo.name && f.size === archivo.size)) {
        enCola.value.push(archivo);
    }

    return false;
};

const quitarDeLaCola = (i) => enCola.value.splice(i, 1);

function subir() {
    if (! enCola.value.length) return;

    const datos = new FormData();
    enCola.value.forEach((f) => datos.append('files[]', f));
    if (props.field) datos.append('form_field_id', props.field.id);

    subiendo.value = true;
    router.post(route('field_work.forms.attach', props.submissionSlug), datos, {
        preserveScroll: true,
        // La cola se vacía SÓLO cuando el servidor ha dicho que sí. Si falla,
        // lo arrastrado sigue ahí para reintentar sin volver a buscarlo.
        onSuccess: () => { enCola.value = []; rechazados.value = []; },
        onFinish:  () => { subiendo.value = false; },
    });
}

function quitar(a) {
    router.delete(route('field_work.forms.detach', [props.submissionSlug, a.id]), {
        preserveScroll: true,
    });
}

/** «1,2 MB» — el dato que distingue dos fotos de la misma obra. */
const enTamano = (bytes) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

/** Los adjuntos viejos no tienen nombre: nunca se guardó. Se numeran. */
const nombreDe = (a, i) => a.name || `${t('field_work.document')} ${i + 1}`;
</script>

<template>
    <div class="adj-campo" :class="{ 'adj-campo--falta': faltante }">
        <!-- Lo que ya está subido. Antes no se veía nada: adjuntabas y la
             pantalla no cambiaba, así que la única forma de saber si había
             entrado era darle a confirmar y ver si se quejaba. -->
        <ul v-if="attachments.length" class="adj">
            <li v-for="(a, i) in attachments" :key="a.id" class="adj__item">
                <FileOutlined class="adj__icon" />
                <span class="adj__name">{{ nombreDe(a, i) }}</span>
                <span class="adj__meta">{{ enTamano(a.size) }}</span>
                <Popconfirm
                    v-if="!readonly"
                    :title="$t('field_work.detach_confirm')"
                    :ok-text="$t('global.delete')"
                    :cancel-text="$t('global.cancel')"
                    @confirm="quitar(a)"
                >
                    <Button type="text" danger size="small">{{ $t('global.delete') }}</Button>
                </Popconfirm>
            </li>
        </ul>
        <p v-else-if="readonly" class="adj__empty">{{ $t('field_work.no_attachments') }}</p>

        <template v-if="!readonly">
            <!-- Con el cupo lleno la zona desaparece en vez de quedarse
                 deshabilitada: un objetivo que no hace nada al tocarlo se lee
                 como que la aplicación no responde. -->
            <p v-if="lleno" class="adj__empty">
                {{ $tc('field_work.attach_full', tope, { max: tope }) }}
            </p>

            <template v-else>
                <Upload.Dragger
                    :before-upload="alSoltar"
                    :show-upload-list="false"
                    :multiple="tope !== 1"
                    :accept="accept"
                    :disabled="subiendo"
                    class="adj-dragger"
                >
                    <p class="adj-dragger__icon"><InboxOutlined /></p>
                    <p class="adj-dragger__text">
                        <strong>{{ $t('field_work.attach_drag_strong') }}</strong>
                        {{ $t('field_work.attach_drag_or_click') }}
                    </p>
                    <p class="adj-dragger__hint">{{ $t('field_work.attach_formats_hint') }}</p>
                </Upload.Dragger>

                <!-- Lo arrastrado, antes de mandarlo: se puede quitar de aquí
                     sin haber gastado un viaje al servidor. -->
                <ul v-if="enCola.length" class="adj adj--cola">
                    <li v-for="(f, i) in enCola" :key="`${f.name}-${f.size}`" class="adj__item">
                        <FileOutlined class="adj__icon" />
                        <span class="adj__name">{{ f.name }}</span>
                        <span class="adj__meta">{{ enTamano(f.size) }}</span>
                        <Button type="text" size="small" @click="quitarDeLaCola(i)">
                            {{ $t('global.cancel') }}
                        </Button>
                    </li>
                </ul>

                <!-- Lo que NO se va a subir y por qué. Un archivo que
                     desaparece en silencio se da por subido. -->
                <Alert v-if="rechazados.length" type="warning" show-icon class="mt-2">
                    <template #message>{{ $t('field_work.attach_rejected') }}</template>
                    <template #description>
                        <ul class="adj__err">
                            <li v-for="(r, i) in rechazados" :key="i">{{ r.name }} — {{ r.reason }}</li>
                        </ul>
                    </template>
                </Alert>

                <Button
                    type="primary"
                    class="mt-2"
                    :disabled="!enCola.length"
                    :loading="subiendo"
                    @click="subir"
                >
                    {{ enCola.length > 1
                        ? $t('field_work.attach_many', { count: enCola.length })
                        : $t('field_work.attach') }}
                </Button>
            </template>
        </template>
    </div>
</template>
