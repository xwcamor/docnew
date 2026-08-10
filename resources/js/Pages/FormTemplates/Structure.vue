<script setup>
/**
 * Secciones y campos de un documento.
 *
 * El motor (`form_templates` → `form_sections` → `form_fields`) existía entero
 * y no había ninguna pantalla para llenarlo: el único camino era el comando que
 * portó los cuatro formatos de la v1, así que un documento nuevo nacía sin
 * campos, y un documento con campos y sin ninguno no se puede publicar. De ahí
 * la sensación de que no se podían añadir documentos a un plan.
 *
 * Tres decisiones que se ven en esta pantalla:
 *
 * 1. **Un documento que ya es evidencia no se reestructura.** Publicado, o con
 *    entregas detrás, se abre en sólo lectura y lo que se ofrece es sacar una
 *    versión nueva. Las firmas se recogen en obra con foto y DNI: quitarle un
 *    campo por detrás a algo que alguien firmó cambia el documento que va a
 *    ver un inspector. Es la misma regla que ya aplicaba
 *    `FormTemplateBuilder::soloBorrador()`, sólo que ahora también mira las
 *    entregas — «Despublicar» devolvía el estado a borrador y dejaba la puerta
 *    abierta.
 *
 * 2. **Subir y bajar, no arrastrar.** No hay ninguna librería de drag&drop en
 *    el proyecto y no merece la pena traer una: quien usa esto está de pie, en
 *    una tablet, con guantes. Arrastrar con guantes falla; un botón de 44px
 *    no. Y el orden se guarda en `position`, que es lo que había que resolver.
 *
 * 3. **Se guarda todo al final.** Como el resto de los formularios del
 *    producto. Mover un campo son dos escrituras y media docena de posiciones
 *    renumeradas: con guardado por cambio, una tablet que pierde la señal a
 *    mitad deja dos campos en la misma posición.
 */
import { computed, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Alert, Button, Card, Input, Popconfirm, Select, Space, Switch, Tag, Tooltip,
} from 'ant-design-vue';
import {
    ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, FileOutlined, PlusOutlined,
    RightOutlined,
} from '@ant-design/icons-vue';

import AppLayout from '@/Layouts/AppLayout.vue';
import SectionHeader from '@/Components/Common/SectionHeader.vue';
import EditAllFooter from '@/Components/Common/EditAllFooter.vue';
import FieldConfigEditor from '@/Components/FormTemplates/FieldConfigEditor.vue';
import { useI18n } from '@/Plugins/i18n';

defineOptions({ layout: AppLayout });

const { t, tc } = useI18n();

const props = defineProps({
    formTemplate: { type: Object, required: true },
    sections:     { type: Array,  default: () => [] },
    fieldTypes:   { type: Array,  default: () => [] },
    editable:     { type: Boolean, default: false },
    lockedReason: { type: [String, null], default: null },
    submissions:  { type: Number, default: 0 },
    nextVersion:  { type: Number, default: 2 },
});

// `uid` sólo vive en el navegador: es la clave de `v-for` de lo que todavía no
// tiene id en la base. Sin él, Vue reutiliza el nodo equivocado al reordenar y
// el texto que escribiste salta de campo.
let siguienteUid = 0;
const uid = () => `nuevo-${siguienteUid++}`;

const clonar = (secciones) => secciones.map((s) => ({
    id: s.id ?? null,
    uid: uid(),
    name_es: s.name_es ?? '',
    name_en: s.name_en ?? '',
    fields: (s.fields ?? []).map((f) => ({
        id: f.id ?? null,
        uid: uid(),
        code: f.code ?? '',
        label_es: f.label_es ?? '',
        label_en: f.label_en ?? '',
        field_type: f.field_type ?? 'text',
        is_required: !!f.is_required,
        config: { ...(f.config ?? {}) },
    })),
}));

const arbol = ref(clonar(props.sections));

const form = useForm({ sections: [] });

// Al volver de un guardado correcto Inertia recarga la página con el árbol ya
// persistido: hay que soltar el estado local o se sigue enseñando el de antes.
watch(() => props.sections, (nuevas) => { arbol.value = clonar(nuevas); });

// ── Lo que se puede tocar ────────────────────────────────────────────────
const soloLectura = computed(() => !props.editable);

const avisoBloqueo = computed(() => {
    if (props.lockedReason === 'submissions') {
        return tc('form_templates.structure_locked_submissions', props.submissions);
    }
    if (props.lockedReason === 'published') {
        return t('form_templates.structure_locked_published');
    }
    return '';
});

// ── Tipos de campo (los manda el servidor) ───────────────────────────────
const tipoOptions = computed(() => props.fieldTypes.map((tp) => ({ value: tp.value, label: tp.label })));

const specDe = (tipo) => props.fieldTypes.find((tp) => tp.value === tipo)?.config ?? [];

/**
 * La etiqueta ya no viaja aquí: vive en `label_es`/`label_en`, que son columnas
 * y se editan arriba, junto al código. Esto es sólo lo que el tipo configura.
 */
const specConfig = (tipo) => specDe(tipo);

const etiquetaTipo = (tipo) => props.fieldTypes.find((tp) => tp.value === tipo)?.label ?? tipo;

// ── Contadores ───────────────────────────────────────────────────────────
const totalCampos = computed(() => arbol.value.reduce((n, s) => n + s.fields.length, 0));

const resumen = computed(() => [
    tc('form_templates.structure_summary', arbol.value.length),
    tc('form_templates.structure_fields_total', totalCampos.value),
].join(' · '));

/**
 * Cuántos cambios hay sin guardar, de verdad.
 *
 * Se compara elemento a elemento contra lo que mandó el servidor —posición
 * incluida, que mover es un cambio— en vez de un «hay cambios» a secas: la
 * franja de abajo dice «3 cambios sin guardar» en el resto del producto y aquí
 * tenía que decir lo mismo.
 */
function inventario(secciones) {
    const mapa = new Map();

    secciones.forEach((s, i) => {
        mapa.set(`s:${s.id ?? s.uid}`, JSON.stringify({
            pos: i, es: s.name_es, en: s.name_en,
        }));

        s.fields.forEach((f, j) => {
            mapa.set(`f:${f.id ?? f.uid}`, JSON.stringify({
                sec: i, pos: j, code: f.code, tipo: f.field_type,
                es: f.label_es, en: f.label_en,
                req: f.is_required, config: f.config,
            }));
        });
    });

    return mapa;
}

const cambios = computed(() => {
    const antes = inventario(clonar(props.sections));
    const ahora = inventario(arbol.value);
    let n = 0;

    ahora.forEach((valor, clave) => { if (antes.get(clave) !== valor) n += 1; });
    antes.forEach((_, clave) => { if (!ahora.has(clave)) n += 1; });

    return n;
});

// ── Secciones ────────────────────────────────────────────────────────────

/**
 * El título de la cabecera, en el idioma en el que se está mirando.
 *
 * Mismo criterio que el accesor `label` del modelo: el que corresponde al
 * idioma y, si está en blanco, el otro — más vale enseñar el título en el
 * idioma equivocado que una tarjeta sin nombre. Se ponía siempre el castellano,
 * así que con la pantalla en inglés la cabecera decía «Permisos» mientras el
 * campo de al lado decía «Permits».
 */
const tituloDeSeccion = (seccion) => (usePage().props.locale === 'en'
    ? (seccion.name_en || seccion.name_es)
    : (seccion.name_es || seccion.name_en));

const anadirSeccion = () => arbol.value.push({ id: null, uid: uid(), name_es: '', name_en: '', fields: [] });

const quitarSeccion = (i) => arbol.value.splice(i, 1);

function moverSeccion(i, salto) {
    const destino = i + salto;
    if (destino < 0 || destino >= arbol.value.length) return;
    const lista = arbol.value;
    [lista[i], lista[destino]] = [lista[destino], lista[i]];
}

// ── Campos ───────────────────────────────────────────────────────────────
/**
 * Un código de partida que no choque dentro de la sección. Es NOT NULL y único
 * por sección: dejarlo vacío obliga a inventárselo antes de poder hacer nada.
 */
function codigoLibre(seccion) {
    const base = t('form_templates.field_new_code');
    const usados = new Set(seccion.fields.map((f) => f.code));
    let n = 1;
    let code = base;
    while (usados.has(code)) { n += 1; code = `${base}_${n}`; }
    return code;
}

function anadirCampo(seccion) {
    const nuevo = uid();
    // Recién añadido = configuración abierta: es cuando hay que rellenarla.
    desplegados.value = new Set(desplegados.value).add(nuevo);

    seccion.fields.push({
        id: null,
        uid: nuevo,
        code: codigoLibre(seccion),
        label_es: '',
        label_en: '',
        field_type: props.fieldTypes[0]?.value ?? 'text',
        is_required: false,
        config: {},
    });
}

const quitarCampo = (seccion, j) => seccion.fields.splice(j, 1);

function moverCampo(seccion, j, salto) {
    const destino = j + salto;
    if (destino < 0 || destino >= seccion.fields.length) return;
    const lista = seccion.fields;
    [lista[j], lista[destino]] = [lista[destino], lista[j]];
}

/**
 * Al cambiar de tipo se conserva lo que el tipo nuevo también admite y se tira
 * el resto. Guardar las opciones de un `select` dentro de una `signature` no
 * sirve de nada y confunde al volver a cambiarlo.
 */
function cambiarTipo(campo, tipo) {
    const admitidas = new Set(specDe(tipo).map((c) => c.key));
    const config = {};

    Object.entries(campo.config ?? {}).forEach(([clave, valor]) => {
        if (admitidas.has(clave)) config[clave] = valor;
    });

    campo.field_type = tipo;
    campo.config = config;
}

// ── Errores del servidor, pegados al campo que los provocó ───────────────
const errorDe = (i, j, clave) => form.errors[`sections.${i}.fields.${j}.${clave}`] ?? '';

/**
 * Qué campos tienen la configuración abierta.
 *
 * Cerrada por defecto, y no por gusto: los catálogos del AST que trajo la v1
 * son 126 actividades, 83 peligros, 84 riesgos y 40 controles, todos dentro de
 * un solo campo. Con todo desplegado la pantalla del AST medía veinte mil
 * píxeles y para llegar a «Observaciones» había que scrollear un minuto.
 *
 * Se abre sola en los dos casos en que hace falta: un campo recién añadido —que
 * es justo cuando hay que rellenar sus opciones— y uno cuya configuración el
 * servidor ha rechazado, para que el error no quede escondido detrás de un
 * pliegue.
 */
const desplegados = ref(new Set());

const estaDesplegado = (campo, i, j) => desplegados.value.has(campo.uid) || !!errorDe(i, j, 'config');

function alternarConfig(campo) {
    const abiertos = new Set(desplegados.value);
    abiertos.has(campo.uid) ? abiertos.delete(campo.uid) : abiertos.add(campo.uid);
    desplegados.value = abiertos;
}

/**
 * Qué hay configurado, sin abrir: «Actividades 126 · Peligros 83». Es lo que se
 * quiere saber de un vistazo, y ahorra el despliegue la mayoría de las veces.
 */
function resumenConfig(campo) {
    const partes = specConfig(campo.field_type).map((item) => {
        const valor = campo.config?.[item.key];
        if (Array.isArray(valor)) return valor.length ? `${item.label} ${valor.length}` : null;
        if (valor === null || valor === undefined || valor === '') return null;
        return `${item.label} ${valor}`;
    }).filter(Boolean);

    return partes.length ? partes.join(' · ') : t('form_templates.field_config_empty');
}

const hayErrores = computed(() => Object.keys(form.errors).length > 0);

// ── Guardar / descartar ──────────────────────────────────────────────────
function guardar() {
    form
        .transform(() => ({
            sections: arbol.value.map((s) => ({
                id: s.id,
                name_es: s.name_es,
                name_en: s.name_en,
                fields: s.fields.map((f) => ({
                    id: f.id,
                    code: f.code,
                    label_es: f.label_es,
                    label_en: f.label_en,
                    field_type: f.field_type,
                    is_required: f.is_required,
                    config: f.config,
                })),
            })),
        }))
        .put(route('business_management.form_templates.structure_update', props.formTemplate.slug), {
            preserveScroll: true,
        });
}

const descartar = () => { arbol.value = clonar(props.sections); form.clearErrors(); };

const creandoVersion = ref(false);

function crearVersion() {
    creandoVersion.value = true;
    router.post(route('business_management.form_templates.new_version', props.formTemplate.slug), {}, {
        onFinish: () => { creandoVersion.value = false; },
    });
}

const colorEstado = computed(() => ({
    published: 'success',
    draft: 'orange',
    archived: 'default',
}[props.formTemplate.status] ?? 'orange'));
</script>

<template>
    <Head :title="`${$t('form_templates.structure_title')} — ${formTemplate.label || formTemplate.name}`" />

    <div class="form-page sap-form">
        <SectionHeader
            :back-href="route('business_management.form_templates.show', formTemplate.slug)"
            :title="$t('form_templates.structure_title')"
            :subtitle="formTemplate.label || formTemplate.name"
        >
            <template #icon><FileOutlined /></template>
            <template #actions>
                <Space :size="6" wrap>
                    <Tag :color="colorEstado" :bordered="false">
                        {{ $t(`form_templates.status_${formTemplate.status}`) }}
                    </Tag>
                    <Tag color="blue" :bordered="false">
                        {{ $t('form_templates.version') }} {{ formTemplate.version }}
                    </Tag>
                    <Tag v-if="soloLectura" color="default" :bordered="false">
                        {{ $t('form_templates.structure_readonly') }}
                    </Tag>
                </Space>
            </template>
        </SectionHeader>

        <div class="form-body">
            <!-- Por qué no se puede tocar, y qué hacer en su lugar. Un botón
                 que falla al pulsarlo es peor que uno que no está: aquí ni
                 siquiera se ofrece guardar, se ofrece la versión nueva. -->
            <Alert v-if="soloLectura" type="warning" show-icon class="fs-alert">
                <template #message>{{ avisoBloqueo }}</template>
                <template #action>
                    <Tooltip :title="$t('form_templates.new_version_hint')">
                        <Popconfirm
                            :title="$t('form_templates.new_version_confirm', { version: nextVersion })"
                            :ok-text="$t('form_templates.new_version')"
                            :cancel-text="$t('global.cancel')"
                            placement="bottomRight"
                            @confirm="crearVersion"
                        >
                            <Button type="primary" :loading="creandoVersion">
                                {{ $t('form_templates.new_version') }}
                            </Button>
                        </Popconfirm>
                    </Tooltip>
                </template>
            </Alert>

            <Alert
                v-else
                type="info"
                show-icon
                class="fs-alert"
                :message="$t('form_templates.structure_hint')"
            />

            <Alert
                v-if="hayErrores"
                type="error"
                show-icon
                class="fs-alert"
                :message="$t('global.fix_marked_fields')"
            />

            <p v-if="!arbol.length" class="fs-empty">{{ $t('form_templates.sections_empty') }}</p>

            <Card
                v-for="(seccion, i) in arbol"
                :key="seccion.id ?? seccion.uid"
                class="fs-section"
                :bodyStyle="{ padding: '16px' }"
            >
                <template #title>
                    <!-- El ordinal es la posición, que es un dato; el título es
                         lo que se lee en obra. Los dos, porque para mover una
                         sección hace falta saber cuál es la tercera. -->
                    <span class="fs-section__ord">{{ $t('form_templates.section', { n: i + 1 }) }}</span>
                    <span v-if="seccion.name_es || seccion.name_en" class="fs-section__name">
                        {{ tituloDeSeccion(seccion) }}
                    </span>
                    <span v-else class="fs-section__name fs-section__name--empty">
                        {{ $t('form_templates.section_unnamed') }}
                    </span>
                    <span class="fs-section__count">
                        {{ $tc('form_templates.structure_fields_total', seccion.fields.length) }}
                    </span>
                </template>

                <template #extra>
                    <span class="fs-tools">
                        <Tooltip :title="$t('form_templates.move_up')">
                            <Button class="fs-iconbtn" :disabled="soloLectura || i === 0" @click="moverSeccion(i, -1)">
                                <ArrowUpOutlined />
                            </Button>
                        </Tooltip>
                        <Tooltip :title="$t('form_templates.move_down')">
                            <Button class="fs-iconbtn" :disabled="soloLectura || i === arbol.length - 1" @click="moverSeccion(i, 1)">
                                <ArrowDownOutlined />
                            </Button>
                        </Tooltip>
                        <Popconfirm
                            :title="$t('form_templates.section_delete_confirm')"
                            :ok-text="$t('global.delete')"
                            :cancel-text="$t('global.cancel')"
                            placement="bottomRight"
                            :disabled="soloLectura"
                            @confirm="quitarSeccion(i)"
                        >
                            <Tooltip :title="$t('form_templates.section_delete')">
                                <Button class="fs-iconbtn fs-iconbtn--danger" :disabled="soloLectura">
                                    <DeleteOutlined />
                                </Button>
                            </Tooltip>
                        </Popconfirm>
                    </span>
                </template>

                <!-- El título del bloque, en los dos idiomas. Va en columnas
                     (`name_es`/`name_en`) y no en `resources/lang`, porque el
                     formato lo define el cliente: «Permisos», «Objetivos»,
                     «Trabajos a realizar» son suyos, no del repositorio. -->
                <div class="fs-pair fs-pair--section">
                    <div class="fs-cell">
                        <label class="fs-cell__label" :for="`sname-es-${seccion.uid}`">
                            {{ $t('form_templates.section_name_es') }}
                        </label>
                        <Input
                            :id="`sname-es-${seccion.uid}`"
                            v-model:value="seccion.name_es"
                            size="large"
                            :maxlength="120"
                            :disabled="soloLectura"
                            :status="form.errors[`sections.${i}.name_es`] ? 'error' : ''"
                            :placeholder="$t('form_templates.section_name_es_placeholder')"
                        />
                    </div>
                    <div class="fs-cell">
                        <label class="fs-cell__label" :for="`sname-en-${seccion.uid}`">
                            {{ $t('form_templates.section_name_en') }}
                        </label>
                        <Input
                            :id="`sname-en-${seccion.uid}`"
                            v-model:value="seccion.name_en"
                            size="large"
                            :maxlength="120"
                            :disabled="soloLectura"
                            :placeholder="$t('form_templates.section_name_en_placeholder')"
                        />
                    </div>
                </div>
                <!-- La ayuda sólo donde hace falta. Repetida bajo cada
                     sección, el AST la enseñaba tres veces y el ojo deja de
                     leerla; en la que todavía no tiene título sí se lee. -->
                <p v-if="!seccion.name_es && !seccion.name_en" class="fs-help">
                    {{ $t('form_templates.section_name_help') }}
                </p>

                <p v-if="!seccion.fields.length" class="fs-empty fs-empty--inline">
                    {{ $t('form_templates.section_empty') }}
                </p>

                <div
                    v-for="(campo, j) in seccion.fields"
                    :key="campo.id ?? campo.uid"
                    class="fs-field"
                >
                    <div class="fs-field__head">
                        <div class="fs-cell">
                            <label class="fs-cell__label" :for="`code-${campo.uid}`">
                                {{ $t('form_templates.field_code') }}
                            </label>
                            <Input
                                :id="`code-${campo.uid}`"
                                v-model:value="campo.code"
                                size="large"
                                :maxlength="60"
                                :disabled="soloLectura"
                                :status="errorDe(i, j, 'code') ? 'error' : ''"
                                :placeholder="$t('form_templates.field_code_placeholder')"
                            />
                            <p v-if="errorDe(i, j, 'code')" class="fs-cell__error">{{ errorDe(i, j, 'code') }}</p>
                        </div>

                        <div class="fs-cell">
                            <label class="fs-cell__label">{{ $t('form_templates.field_type') }}</label>
                            <Select
                                :value="campo.field_type"
                                size="large"
                                class="fs-cell__select"
                                show-search
                                option-filter-prop="label"
                                :options="tipoOptions"
                                :disabled="soloLectura"
                                :status="errorDe(i, j, 'field_type') ? 'error' : ''"
                                @update:value="cambiarTipo(campo, $event)"
                            />
                        </div>

                        <div class="fs-cell fs-cell--switch">
                            <label class="fs-cell__label">{{ $t('form_templates.field_required') }}</label>
                            <Tooltip :title="$t('form_templates.field_required_help')">
                                <span class="fs-switch">
                                    <Switch v-model:checked="campo.is_required" :disabled="soloLectura" />
                                    <span class="fs-switch__text">
                                        {{ campo.is_required ? $t('global.yes') : $t('global.no') }}
                                    </span>
                                </span>
                            </Tooltip>
                        </div>

                        <span class="fs-tools fs-tools--field">
                            <Tooltip :title="$t('form_templates.move_up')">
                                <Button class="fs-iconbtn" :disabled="soloLectura || j === 0" @click="moverCampo(seccion, j, -1)">
                                    <ArrowUpOutlined />
                                </Button>
                            </Tooltip>
                            <Tooltip :title="$t('form_templates.move_down')">
                                <Button class="fs-iconbtn" :disabled="soloLectura || j === seccion.fields.length - 1" @click="moverCampo(seccion, j, 1)">
                                    <ArrowDownOutlined />
                                </Button>
                            </Tooltip>
                            <Popconfirm
                                :title="$t('form_templates.field_delete_confirm')"
                                :ok-text="$t('global.delete')"
                                :cancel-text="$t('global.cancel')"
                                placement="bottomRight"
                                :disabled="soloLectura"
                                @confirm="quitarCampo(seccion, j)"
                            >
                                <Tooltip :title="$t('form_templates.field_delete')">
                                    <Button class="fs-iconbtn fs-iconbtn--danger" :disabled="soloLectura">
                                        <DeleteOutlined />
                                    </Button>
                                </Tooltip>
                            </Popconfirm>
                        </span>
                    </div>

                    <!-- Lo que lee el trabajador al lado del campo, en los dos
                         idiomas. Estuvo dentro de `config.label` mientras el
                         motor no tenía columna: un texto de pantalla metido en
                         un JSON de configuración no se puede ni traducir ni
                         buscar. Ahora son `label_es` y `label_en`. -->
                    <div class="fs-pair">
                        <div class="fs-cell">
                            <label class="fs-cell__label" :for="`label-es-${campo.uid}`">
                                {{ $t('form_templates.field_label_es') }}
                            </label>
                            <Input
                                :id="`label-es-${campo.uid}`"
                                v-model:value="campo.label_es"
                                size="large"
                                :maxlength="180"
                                :disabled="soloLectura"
                                :status="errorDe(i, j, 'label_es') ? 'error' : ''"
                                :placeholder="$t('form_templates.field_label_es_placeholder')"
                            />
                        </div>
                        <div class="fs-cell">
                            <label class="fs-cell__label" :for="`label-en-${campo.uid}`">
                                {{ $t('form_templates.field_label_en') }}
                            </label>
                            <Input
                                :id="`label-en-${campo.uid}`"
                                v-model:value="campo.label_en"
                                size="large"
                                :maxlength="180"
                                :disabled="soloLectura"
                                :placeholder="$t('form_templates.field_label_en_placeholder')"
                            />
                        </div>
                    </div>

                    <!-- Lo que necesita ESTE tipo, y nada más. El servidor dice
                         qué controles pinta cada uno. -->
                    <div v-if="specConfig(campo.field_type).length" class="fs-field__config">
                        <button
                            type="button"
                            class="fs-disclose"
                            :aria-expanded="estaDesplegado(campo, i, j)"
                            @click="alternarConfig(campo)"
                        >
                            <RightOutlined
                                class="fs-disclose__caret"
                                :class="{ 'is-open': estaDesplegado(campo, i, j) }"
                            />
                            <span class="fs-disclose__title">
                                {{ $t('form_templates.field_config') }} · {{ etiquetaTipo(campo.field_type) }}
                            </span>
                            <span class="fs-disclose__summary">{{ resumenConfig(campo) }}</span>
                        </button>

                        <FieldConfigEditor
                            v-if="estaDesplegado(campo, i, j)"
                            class="fs-field__configbody"
                            :spec="specConfig(campo.field_type)"
                            :model-value="campo.config"
                            :disabled="soloLectura"
                            :error="errorDe(i, j, 'config')"
                            @update:model-value="campo.config = $event"
                        />
                    </div>
                </div>

                <Button
                    v-if="!soloLectura"
                    type="dashed"
                    class="fs-add"
                    @click="anadirCampo(seccion)"
                >
                    <PlusOutlined /> {{ $t('form_templates.field_add') }}
                </Button>
            </Card>

            <Button
                v-if="!soloLectura"
                type="dashed"
                class="fs-add fs-add--section"
                @click="anadirSeccion"
            >
                <PlusOutlined /> {{ $t('form_templates.section_add') }}
            </Button>

            <EditAllFooter
                v-if="!soloLectura"
                :status-text="cambios ? $tc('form_templates.structure_pending', cambios) : resumen"
                :discard-label="$t('form_templates.edit_all_discard')"
                :save-label="$t('global.save_changes')"
                :discard-disabled="!cambios"
                :submitting="form.processing"
                @discard="descartar"
                @save="guardar"
            />
        </div>
    </div>
</template>

<style scoped>
.fs-alert { margin-bottom: 16px; }

.fs-empty {
    margin: 0 0 16px;
    font-size: 0.875rem;
    color: var(--color-text-muted);
}
.fs-empty--inline { margin: 4px 0 12px; }

.fs-section { margin-bottom: 16px; border-radius: 8px; }
.fs-section__ord {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-text-muted);
    margin-right: 10px;
}
.fs-section__name { font-weight: 600; color: var(--color-text-strong); }
.fs-section__name--empty { font-weight: 400; color: var(--color-text-dim); font-style: italic; }
.fs-section__count {
    margin-left: 10px;
    font-size: 0.8125rem;
    font-weight: 400;
    color: var(--color-text-muted);
}

/* Los dos idiomas, uno al lado del otro: son el mismo dato dicho dos veces y
   leerlos en paralelo es lo que deja ver cuál falta. */
.fs-pair {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-top: 12px;
}
.fs-pair--section { margin-top: 0; margin-bottom: 6px; }

.fs-help {
    margin: 0 0 14px;
    font-size: 0.75rem;
    color: var(--color-text-dim);
}

.fs-tools { display: inline-flex; gap: 6px; }

/* Objetivo de toque de 44px (docs/UI.md §3): con guantes, 32 no se acierta. */
.fs-iconbtn {
    width: 44px;
    height: 44px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.fs-iconbtn--danger:not(:disabled) { color: var(--color-danger); }

.fs-field {
    border: 1px solid var(--color-border-soft);
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 12px;
    background: var(--color-surface-alt);
}

.fs-field__head {
    display: grid;
    grid-template-columns: minmax(160px, 1.2fr) minmax(180px, 1fr) auto auto;
    gap: 10px;
    align-items: start;
}

.fs-cell { min-width: 0; }
.fs-cell__label {
    display: block;
    margin-bottom: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-muted);
}
.fs-cell__select { width: 100%; }
.fs-cell__error { margin: 4px 0 0; font-size: 0.75rem; color: var(--color-danger); }
.fs-cell--switch { display: flex; flex-direction: column; }

.fs-switch { display: inline-flex; align-items: center; gap: 8px; height: 40px; }
.fs-switch__text { font-size: 0.875rem; color: var(--color-text); }

.fs-tools--field { align-self: center; padding-top: 18px; }

.fs-field__config {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px dashed var(--color-border);
}
/* El pliegue: fila entera pulsable, con el resumen a la derecha. 44px de alto
   porque se pulsa con guantes igual que todo lo demás (docs/UI.md §3). */
.fs-disclose {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    min-height: 44px;
    padding: 0 4px;
    background: transparent;
    border: 0;
    cursor: pointer;
    text-align: left;
    color: inherit;
    font: inherit;
}
.fs-disclose:hover { background: var(--color-surface-hover); border-radius: 6px; }
.fs-disclose__caret {
    flex: 0 0 auto;
    font-size: 0.7rem;
    color: var(--color-text-muted);
    transition: transform 0.15s ease;
}
.fs-disclose__caret.is-open { transform: rotate(90deg); }
.fs-disclose__title {
    font-size: 0.8125rem;
    font-weight: 700;
    color: var(--color-text-strong);
}
.fs-disclose__summary {
    margin-left: auto;
    font-size: 0.75rem;
    color: var(--color-text-muted);
    text-align: right;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 55%;
}

.fs-field__configbody { margin-top: 10px; }

@media (prefers-reduced-motion: reduce) {
    .fs-disclose__caret { transition: none; }
}

.fs-add { height: 44px; }
.fs-add--section { width: 100%; margin-bottom: 8px; }

/* A 768 todo se apila: en una tablet en vertical cinco columnas no caben y lo
   que salía era scroll horizontal, que es justo lo que no puede haber. */
@media (max-width: 900px) {
    .fs-field__head { grid-template-columns: 1fr; }
    .fs-pair { grid-template-columns: 1fr; }
    .fs-tools--field { padding-top: 0; justify-content: flex-end; }
}
</style>
