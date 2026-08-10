<script setup>
import { computed } from 'vue';
import { Tag, Tooltip } from 'ant-design-vue';
import {
    CheckCircleFilled, ClockCircleOutlined, LockOutlined, MinusCircleOutlined, WarningFilled,
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
    /**
     * Cuántas cosas salieron mal en esta fila. Cero no se pinta.
     *
     * Es el entero `observations` del sistema anterior, que los cuatro formatos
     * recalculaban solos y era lo que el supervisor leía de un vistazo: un EPP
     * confirmado con tres arneses en mal estado no es lo mismo que uno
     * confirmado y limpio, y con sólo la etiqueta verde los dos se ven igual.
     */
    findings: { type: Number, default: 0 },
    /**
     * Qué se hizo en esta fila, para el tooltip de la hora.
     *
     * Las tres columnas comparten la fila pero no el verbo: una persona
     * **firma**, un documento se **completa**. Salía «Firmado el 07-08-2026»
     * encima de un AST porque la fila nació en la columna de trabajadores y el
     * texto se quedó pegado. En el sistema anterior el documento decía
     * «Completado» (`standard.completed`).
     */
    doneVerb: { type: String, default: 'signed' },
});

const { t, tc } = useI18n();

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
            <Tooltip v-if="etiqueta" :title="when ? t(`work_plans.done_at_${doneVerb}`, { when: cuando }) : etiqueta">
                <Tag :color="color" :bordered="false" class="wp-row__tag">
                    <component :is="marca" /> {{ etiqueta }}
                </Tag>
            </Tooltip>

            <!-- Va junto a la etiqueta de estado, no en su lugar: las dos cosas
                 son ciertas a la vez —el formato está confirmado Y tiene tres
                 observaciones— y sustituir una por otra escondería una. -->
            <Tooltip v-if="findings > 0" :title="tc('work_plans.findings_hint', findings, { count: findings })">
                <Tag color="error" :bordered="false" class="wp-row__tag">
                    <WarningFilled /> {{ tc('work_plans.findings_count', findings, { count: findings }) }}
                </Tag>
            </Tooltip>

            <div v-if="$slots.actions" class="wp-row__acts"><slot name="actions" /></div>
        </div>

        <!-- Un control que necesita el ancho entero —un buscador, un selector—
             va aquí, **fuera** de las acciones y en su propia línea.
             Metido entre los botones empujaba la columna de la derecha a 200px
             y dejaba el título con dos: «Supervisor» salía una letra por línea,
             en vertical. Es el mismo fallo que rompía la tarjeta de formatos,
             visto desde el otro lado. -->
        <div v-if="$slots.wide" class="wp-row__wide"><slot name="wide" /></div>
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
/* `break-word` y no `anywhere`: con `anywhere` el navegador parte por donde le
   viene bien y «AST (Análisis de Seguridad en el Trabajo)» salía como «AST
   (Análisi / s de / Segurid / ad», una sílaba por línea. Así solo se parte la
   palabra que de verdad no cabe, y el resto respeta los espacios. */
.wp-row__title { display: block; font-weight: 600; font-size: 0.95rem; color: var(--color-text, #32363A); overflow-wrap: break-word; }
.wp-row__sub   { display: block; margin-top: 2px; font-size: 0.8125rem; color: var(--color-text-muted, #6A6D70); }
.wp-row__why   { display: block; margin-top: 4px; font-size: 0.75rem; color: var(--color-text-muted, #6A6D70); }

.wp-row__side  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
/* Ocupa de la segunda columna al final: debajo del cuerpo, alineado con él. */
.wp-row__wide  { grid-column: 2 / -1; margin-top: 8px; }
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
