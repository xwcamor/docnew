<script setup>
import { computed } from 'vue';
import { Tag, Tooltip } from 'ant-design-vue';
import {
    CheckCircleFilled, ClockCircleOutlined, LockOutlined, MinusCircleOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

/**
 * Una fila del tablero del plan. **La misma para las tres columnas.**
 *
 * Existe porque no la había: trabajadores, formatos y aprobaciones se pintaban
 * cada uno por su cuenta y salieron tres cosas distintas en la misma pantalla.
 * En una captura se veía de golpe:
 *
 * - Las aprobaciones llevaban un icono de estado a la izquierda; las otras dos
 *   columnas, no. Tres anatomías de fila una al lado de otra.
 * - El estado se contaba de tres maneras: etiqueta verde con la hora en
 *   trabajadores, pastilla «Confirmado» sin hora ni icono en formatos, e icono
 *   más etiqueta en aprobaciones.
 * - Los formatos no enseñaban **cuándo** se confirmaron, y los otros dos sí.
 *
 * Nada de eso era una decisión: era que cada tarjeta se escribió en un momento
 * distinto. Con una sola fila compartida no pueden volver a separarse.
 *
 * La anatomía, de izquierda a derecha:
 *
 *     [marca] │ título          │ [estado con hora] [acciones]
 *             │ subtítulo       │
 *
 * La marca encadena visualmente los pasos —el flujo de firmas se lee en orden—
 * y en las otras dos columnas hace de viñeta de estado. Es la misma pieza.
 */
const props = defineProps({
    /** done · pending · blocked · optional — decide marca y color. */
    state:    { type: String, required: true },
    title:    { type: String, required: true },
    subtitle: { type: String, default: '' },
    /** Cuándo pasó (firma, confirmación). Se pinta dentro de la etiqueta. */
    when:     { type: String, default: null },
    /** Texto de la etiqueta cuando no hay hora que enseñar. */
    label:    { type: String, default: '' },
    /** Por qué está bloqueada. Sale bajo el subtítulo, en gris. */
    reason:   { type: String, default: '' },
    /** Encadena esta fila con la siguiente. Sólo el flujo de aprobaciones. */
    chained:  { type: Boolean, default: false },
});

const { t } = useI18n();

const MARCAS = {
    done:     CheckCircleFilled,
    pending:  ClockCircleOutlined,
    blocked:  LockOutlined,
    optional: MinusCircleOutlined,
};

const marca = computed(() => MARCAS[props.state] ?? ClockCircleOutlined);

// Color Y palabra, nunca sólo color: al sol se pierde el matiz y hay gente que
// no distingue el rojo del verde (docs/UI.md §5).
const color = computed(() => ({
    done: 'success', pending: 'warning', blocked: 'default', optional: 'default',
}[props.state] ?? 'default'));

/** dd-mm-aaaa hh:mm, hora de obra: no se reinterpreta la zona. */
const cuando = computed(() => {
    if (!props.when) return '';
    const s = String(props.when);
    const [y, m, d] = s.slice(0, 10).split('-');
    return d ? `${d}-${m}-${y} ${s.slice(11, 16)}` : s;
});

const etiqueta = computed(() => cuando.value || props.label);
</script>

<template>
    <li class="wp-row" :class="[`is-${state}`, { 'is-chained': chained }]">
        <span class="wp-row__mark" aria-hidden="true"><component :is="marca" /></span>

        <div class="wp-row__body">
            <strong class="wp-row__title">{{ title }}</strong>
            <span v-if="subtitle" class="wp-row__sub">{{ subtitle }}</span>
            <span v-if="reason" class="wp-row__why">{{ reason }}</span>
        </div>

        <div class="wp-row__side">
            <Tooltip v-if="etiqueta" :title="when ? t('work_plans.crew_signed_at', { when: cuando }) : etiqueta">
                <Tag :color="color" :bordered="false" class="wp-row__tag">
                    <component :is="marca" /> {{ etiqueta }}
                </Tag>
            </Tooltip>

            <div v-if="$slots.actions" class="wp-row__acts"><slot name="actions" /></div>
        </div>
    </li>
</template>

<style scoped>
.wp-row {
    display: grid;
    grid-template-columns: 22px minmax(0, 1fr) auto;
    gap: 10px;
    align-items: start;
    padding: 12px 0;
    border-top: 1px solid var(--color-border-soft, #f0f0f0);
}
.wp-row:first-child { border-top: 0; }

.wp-row__mark {
    position: relative;
    display: flex; justify-content: center;
    padding-top: 2px;
    font-size: 15px;
    color: var(--color-text-muted, #6A6D70);
}
.wp-row.is-done  .wp-row__mark { color: var(--color-success, #107E3E); }
.wp-row.is-pending .wp-row__mark { color: var(--color-warning, #E9730C); }

/* El hilo que encadena un paso con el siguiente: sólo donde el orden importa. */
.wp-row.is-chained:not(:last-child) .wp-row__mark::after {
    content: '';
    position: absolute;
    top: 21px; bottom: -14px;
    width: 2px;
    background: var(--color-border, #e5e7eb);
}

.wp-row__body  { min-width: 0; }
.wp-row__title { display: block; font-weight: 600; font-size: 0.95rem; color: var(--color-text, #32363A); overflow-wrap: anywhere; }
.wp-row__sub   { display: block; margin-top: 2px; font-size: 0.8125rem; color: var(--color-text-muted, #6A6D70); }
.wp-row__why   { display: block; margin-top: 4px; font-size: 0.75rem; color: var(--color-text-muted, #6A6D70); }

.wp-row__side  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
.wp-row__tag   { margin: 0; white-space: nowrap; }
.wp-row__acts  { display: inline-flex; align-items: center; gap: 6px; }
/* Con guantes, a pleno sol (docs/UI.md §3). */
.wp-row__acts :deep(.ant-btn) { min-height: 32px; }

/* En una columna estrecha —y en el tablero lo son— la etiqueta y los botones
   bajan a su propia línea en vez de estrujar el nombre hasta partirlo por
   letras. Es container query, no media query: la columna mide un tercio
   aunque la ventana sea ancha. */
@container card (max-width: 460px) {
    .wp-row { grid-template-columns: 22px minmax(0, 1fr); }
    .wp-row__side { grid-column: 2; justify-content: flex-start; }
}
</style>
