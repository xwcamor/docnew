<script setup>
/**
 * La matriz de formatos de un tipo de trabajo.
 *
 * Es la pantalla que faltaba: cuando el sistema le dice a un supervisor
 * «cámbialo en el tipo de trabajo», éste es el sitio.
 *
 * Dos decisiones que no son de estilo:
 *
 *  - se listan TODOS los formatos del país, no sólo los ya exigidos. Una lista
 *    que enseña únicamente lo marcado obliga a añadir a ciegas;
 *  - la fila es una tarjeta y no una fila de tabla. Son tres controles por
 *    formato y a 1024×768 una tabla se va de ancho — y el ancho de más se
 *    resuelve siempre con scroll horizontal, que en tablet con guantes es
 *    inservible.
 *
 * El segundo control sólo aparece cuando el formato está exigido: obligatorio u
 * opcional no significa nada si el formato no se pide, y un control que no
 * significa nada invita a tocarlo.
 */
import { computed, ref } from 'vue';
import { Alert, Input, Switch, Segmented, Tag, Tooltip, Empty } from 'ant-design-vue';
import { SearchOutlined, WarningOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();

const props = defineProps({
    // Catálogo completo: [{ id, code, name, status, is_published }]
    templates: { type: Array,  default: () => [] },
    disabled:  { type: Boolean, default: false },
});

// [{ id, is_required }] — sólo los exigidos. Es exactamente lo que viaja al
// servidor y lo que el pivote acaba guardando.
const seleccion = defineModel({ type: Array, default: () => [] });

const busqueda = ref('');

const visibles = computed(() => {
    const q = busqueda.value.trim().toLowerCase();
    if (!q) return props.templates;
    return props.templates.filter((f) =>
        (f.code ?? '').toLowerCase().includes(q) || (f.name ?? '').toLowerCase().includes(q));
});

const marcado = (id) => seleccion.value.find((f) => f.id === id) ?? null;
const estaExigido = (id) => marcado(id) !== null;
const esObligatorio = (id) => marcado(id)?.is_required === true;

const exigidos = computed(() => seleccion.value.length);
const obligatorios = computed(() => seleccion.value.filter((f) => f.is_required).length);

const alternarExigido = (formato, exigir) => {
    if (!exigir) {
        seleccion.value = seleccion.value.filter((f) => f.id !== formato.id);
        return;
    }
    // Un borrador entra como opcional: no se puede llenar todavía, así que
    // exigirlo bloquearía el plan contra una pantalla que no existe. El
    // servidor lo rechaza igual; aquí se evita que el usuario llegue a verlo.
    seleccion.value = [...seleccion.value, { id: formato.id, is_required: formato.is_published }];
};

const cambiarCaracter = (formato, valor) => {
    seleccion.value = seleccion.value.map((f) =>
        f.id === formato.id ? { ...f, is_required: valor === 'required' } : f);
};

// «Obligatorio» sale deshabilitado en un borrador, y el porqué se dice en la
// etiqueta de la propia fila: un control que falla al pulsarlo es peor que uno
// que no está.
const opcionesCaracter = (formato) => [
    { value: 'required', label: t('work_types.required'), disabled: !formato.is_published },
    { value: 'optional', label: t('work_types.optional') },
];
</script>

<template>
    <div class="fmx">
        <div class="fmx__head">
            <p class="fmx__subtitle">{{ $t('work_types.forms_subtitle') }}</p>
            <div class="fmx__counters">
                <Tag :color="exigidos > 0 ? 'geekblue' : 'default'" :bordered="false">
                    {{ $t('work_types.forms_count') }}: {{ exigidos }}
                </Tag>
                <Tag v-if="exigidos > 0" :color="obligatorios > 0 ? 'red' : 'default'" :bordered="false">
                    {{ $t('work_types.required') }}: {{ obligatorios }}
                </Tag>
            </div>
        </div>

        <!-- Un tipo sin ningún formato deja salir el trabajo sin papeles. Se
             dice aquí y no en una ayuda escondida. -->
        <Alert v-if="templates.length > 0 && exigidos === 0" type="warning" show-icon class="fmx__alert">
            <template #icon><WarningOutlined /></template>
            <template #message>{{ $t('work_types.forms_none_warning') }}</template>
        </Alert>

        <Input
            v-if="templates.length > 6"
            v-model:value="busqueda"
            :placeholder="$t('work_types.forms_search')"
            allow-clear
            size="large"
            class="fmx__search"
        >
            <template #prefix><SearchOutlined /></template>
        </Input>

        <Empty
            v-if="templates.length === 0"
            :description="$t('work_types.forms_empty_country')"
            class="fmx__empty"
        />
        <Empty
            v-else-if="visibles.length === 0"
            :description="$t('work_types.forms_no_results')"
            class="fmx__empty"
        />

        <ul v-else class="fmx__list">
            <li
                v-for="formato in visibles"
                :key="formato.id"
                class="fmx__row"
                :class="{ 'fmx__row--on': estaExigido(formato.id) }"
            >
                <div class="fmx__id">
                    <span class="fmx__name">{{ formato.name || formato.code }}</span>
                    <span class="fmx__meta">
                        <code class="fmx__code">{{ formato.code }}</code>
                        <Tooltip v-if="!formato.is_published" :title="$t('work_types.forms_draft_hint')">
                            <Tag color="orange" :bordered="false">{{ $t('work_types.forms_draft') }}</Tag>
                        </Tooltip>
                    </span>
                </div>

                <div class="fmx__controls">
                    <label class="fmx__toggle">
                        <Switch
                            :checked="estaExigido(formato.id)"
                            :disabled="disabled"
                            :aria-label="formato.code"
                            @update:checked="(v) => alternarExigido(formato, v)"
                        />
                        <!-- Color y palabra, nunca sólo el color: al sol se
                             pierde cualquier matiz. -->
                        <span class="fmx__state" :class="estaExigido(formato.id) ? 'fmx__state--on' : 'fmx__state--off'">
                            {{ estaExigido(formato.id) ? $t('work_types.forms_demanded') : $t('work_types.forms_not_demanded') }}
                        </span>
                    </label>

                    <Segmented
                        v-if="estaExigido(formato.id)"
                        :value="esObligatorio(formato.id) ? 'required' : 'optional'"
                        :options="opcionesCaracter(formato)"
                        :disabled="disabled"
                        class="fmx__seg"
                        @change="(v) => cambiarCaracter(formato, v)"
                    />
                </div>
            </li>
        </ul>

        <p class="fmx__note">{{ $t('work_types.required_help') }}</p>
    </div>
</template>

<style scoped>
.fmx__head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 12px; flex-wrap: wrap; margin-bottom: 10px;
}
.fmx__subtitle { margin: 0; color: var(--color-text-muted); font-size: 0.85rem; line-height: 1.5; max-width: 62ch; }
.fmx__counters { display: inline-flex; gap: 6px; flex-wrap: wrap; }
.fmx__alert  { margin-bottom: 12px; }
.fmx__search { margin-bottom: 12px; max-width: 380px; }
.fmx__empty  { padding: 32px 12px; }

.fmx__list { list-style: none; margin: 0; padding: 0; }

/* Tarjeta y no fila de tabla: tres controles por formato no caben en una fila
   a 1024×768 sin scroll horizontal. */
.fmx__row {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; flex-wrap: wrap;
    min-height: 60px; padding: 10px 12px;
    border: 1px solid var(--color-border-subtle, #f2f3f5);
    border-radius: 10px;
    margin-bottom: 8px;
    background: var(--color-surface, #fff);
    transition: border-color 0.12s ease, background 0.12s ease;
}
.fmx__row--on {
    border-color: rgba(10, 110, 209, 0.28);
    background: rgba(10, 110, 209, 0.04);
}

.fmx__id { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1 1 260px; }
.fmx__name { font-weight: 500; color: var(--color-text); line-height: 1.35; }
.fmx__meta { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.fmx__code { color: var(--color-text-muted); font-size: 0.78rem; }

.fmx__controls { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }

/* 44px de objetivo de toque: con guantes, menos no se acierta. */
.fmx__toggle {
    display: inline-flex; align-items: center; gap: 10px;
    min-height: 44px; padding: 0 4px; cursor: pointer;
}
.fmx__state { font-size: 0.82rem; font-weight: 600; white-space: nowrap; }
.fmx__state--on  { color: var(--color-primary, #0A6ED1); }
.fmx__state--off { color: var(--color-text-muted); }

.fmx__seg :deep(.ant-segmented-item-label) { min-height: 40px; line-height: 40px; padding: 0 14px; }

.fmx__note { margin: 10px 0 0 0; color: var(--color-text-muted); font-size: 0.8rem; line-height: 1.5; }

/* A 768 todo se apila: los controles pasan debajo del nombre, a ancho completo. */
@media (max-width: 767px) {
    .fmx__row { flex-direction: column; align-items: stretch; }
    .fmx__controls { justify-content: space-between; }
    .fmx__seg { flex: 1 1 auto; }
}
</style>
