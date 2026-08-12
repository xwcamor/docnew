<script setup>
import { computed, useSlots } from 'vue';
import { Tooltip } from 'ant-design-vue';
import {
    CheckCircleFilled, ClockCircleOutlined, LockOutlined, MinusCircleOutlined, WarningFilled,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import { useDateFormat } from '@/Composables/useDateFormat';

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
    /**
     * La hora, en la misma línea que el subtítulo y en monoespaciada.
     *
     * Estaba suelta a la derecha, y ahí era una tercera columna de datos que
     * empujaba los botones y dejaba la fila con tres alineaciones distintas.
     * Junto al cargo y el país se lee de corrido: «Supervisor · Perú ·
     * 12-08-2026 12:33» es una frase, no tres cosas repartidas por la fila.
     */
    subtitleTime: { type: String, default: '' },
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
    /**
     * Si esta fila puede decir «sin observaciones» cuando no tiene ninguna.
     *
     * Sólo tiene sentido en algo ya terminado: un documento en borrador no está
     * conforme, es que todavía no se ha llenado, y ponerle «sin observaciones»
     * sería afirmar un resultado limpio que nadie ha comprobado.
     */
    showClean: { type: Boolean, default: false },
});

const { t, tc } = useI18n();
// `when` es SIEMPRE un instante estampado por el servidor —una firma, una
// confirmación— y viaja en UTC explícito. Va por el conversor del huso del
// usuario, nunca troceando la cadena: eso enseñaba la hora UTC.
const { formatDateTime } = useDateFormat();

const MARCAS = {
    done:     CheckCircleFilled,
    pending:  ClockCircleOutlined,
    blocked:  LockOutlined,
    optional: MinusCircleOutlined,
};

const marca = computed(() => MARCAS[props.state] ?? ClockCircleOutlined);

/**
 * Lo que dice la marca al posarse encima: qué pasó y cuándo.
 *
 * Aquí vivía también una pastilla de estado con la hora dentro, y era el mismo
 * icono otra vez: dos checks verdes por fila diciendo lo mismo. El estado lo
 * cuenta la marca —forma distinta por estado, no sólo color, que al sol se
 * pierde el matiz (docs/UI.md §5)— y la hora la pone la tarjeta en el
 * subtítulo, donde se lee sin hover. El tooltip sólo añade el verbo.
 */
const tituloDeLaMarca = computed(() => (
    props.when
        ? t(`work_plans.done_at_${props.doneVerb}`, { when: formatDateTime(props.when) })
        : props.label
));

const slots = useSlots();

/**
 * ¿Hay algo que contar en la segunda línea? Si no, no se pinta el hueco.
 *
 * El slot cuenta: una fila cuyo único dato de la segunda línea sea la marca de
 * cómo se firmó se quedaría sin línea, y la marca no se vería.
 */
const hayLineaDos = computed(() => (
    !!props.subtitle || !!props.subtitleTime || props.findings > 0 || props.showClean || !!slots.sub
));
</script>

<template>
    <li class="wp-row" :class="[`is-${state}`, { 'is-chained': chained }]">
        <!-- La única marca de estado de la fila. -->
        <Tooltip :title="tituloDeLaMarca">
            <span class="wp-row__mark"><component :is="marca" /></span>
        </Tooltip>

        <div class="wp-row__body">
            <strong class="wp-row__title">{{ title }}</strong>
            <!-- La segunda línea: lo que la fila es, cuándo pasó y cómo salió.
                 Cada trozo es un hijo suelto y los puntos de separación los
                 pone el CSS, así que no hay que encadenarlos a mano ni
                 preocuparse de los que falten. Y NUNCA con `v-html`: aquí
                 dentro se pintan nombres de personas, que son datos de
                 usuario. -->
            <span v-if="hayLineaDos" class="wp-row__sub">
                <span v-if="subtitle">{{ subtitle }}</span>

                <!-- Primero QUÉ pasó, después CUÁNDO. Es el orden en que se
                     pregunta: «¿salió limpio?» y sólo entonces «¿a qué hora?».
                     Al revés, la hora se leía antes que el resultado, que es lo
                     que de verdad se viene a mirar. -->
                <Tooltip v-if="findings > 0" :title="tc('work_plans.findings_hint', findings, { count: findings })">
                    <span class="wp-row__flag">
                        <WarningFilled /> {{ tc('work_plans.findings_count', findings, { count: findings }) }}
                    </span>
                </Tooltip>
                <span v-else-if="showClean">{{ t('work_plans.findings_none') }}</span>

                <!-- La hora, en pastilla y SIN color. Es un dato, no un estado:
                     el color ya lo lleva la marca de la izquierda, y una
                     segunda cosa de color en la misma fila competiría con ella
                     sin significar nada nuevo. La caja sólo la separa del
                     texto. -->
                <b v-if="subtitleTime" class="wp-row__when">{{ subtitleTime }}</b>

                <!-- Y al final, CÓMO se produjo. Lo pone quien usa la fila: la
                     cuadrilla y las aprobaciones ponen aquí con qué se comprobó
                     la firma. -->
                <slot name="sub" />
            </span>
            <span v-if="reason" class="wp-row__why">{{ reason }}</span>
        </div>

        <div class="wp-row__side">
            <!-- Aquí a la derecha vivían la pastilla de estado con la hora y,
                 debajo, las observaciones. Las tres se han ido a la segunda
                 línea: el estado ya lo cuenta la marca, y la hora y el
                 resultado son la misma frase —«se confirmó a las 06:06 y salió
                 con una observación»— que partida en dos sitios se leía dos
                 veces. Aquí sólo quedan las acciones. -->
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
/**
 * La fila se parte cuando LA FILA no cabe, no cuando la tarjeta es estrecha.
 *
 * Era una rejilla de tres columnas con un corte fijo —«por debajo de 460px de
 * tarjeta, el lado derecho baja»— y ese número no puede acertar, porque cada
 * tarjeta lleva un lado derecho distinto. Medido en el tablero, con las tres
 * columnas a 473px: el representante pide 91px a la derecha, los trabajadores
 * 227, el flujo 241 y los documentos **422**. Los tres primeros caben; el
 * cuarto no, y como 473 > 460 la regla no saltaba: el nombre del formato se
 * estrujaba hasta cero y «AST (Análisis de Seguridad en el Trabajo)» salía en
 * vertical, una letra por línea, con el título recortado a «D…».
 *
 * Con flex y `wrap` no hay ancho que adivinar: el cuerpo pide 170px como mínimo
 * y el lado derecho baja a su propia línea **sólo en la fila donde de verdad no
 * entra**. Los documentos se parten y los trabajadores no, en la misma pantalla
 * y sin una sola media query.
 */
.wp-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-start;
    padding: 12px 0;
    border-top: 1px solid var(--color-border-soft, #f0f0f0);
}
.wp-row:first-child { border-top: 0; }

.wp-row__mark {
    position: relative;
    flex: 0 0 22px;
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

/* 170px es lo que hace falta para leer un nombre; por debajo de eso el lado
   derecho se va abajo en vez de seguir estrujándolo. */
.wp-row__body  { flex: 1 1 170px; min-width: 0; }
/* `break-word` y no `anywhere`: con `anywhere` el navegador parte por donde le
   viene bien y «AST (Análisis de Seguridad en el Trabajo)» salía como «AST
   (Análisi / s de / Segurid / ad», una sílaba por línea. Así solo se parte la
   palabra que de verdad no cabe, y el resto respeta los espacios. */
.wp-row__title { display: block; font-weight: 600; font-size: 0.95rem; color: var(--color-text, #32363A); overflow-wrap: break-word; }
.wp-row__sub   { display: block; margin-top: 2px; font-size: 0.8125rem; color: var(--color-text-muted, #6A6D70); }

/* Los puntos de separación los pone el CSS y no la plantilla: los trozos de
   esta línea aparecen y desaparecen según la fila —hay hora o no, hay
   observaciones o no— y encadenarlos a mano acababa en « ·  · » cada vez que
   faltaba uno. */
.wp-row__sub > * + *::before {
    content: '·';
    margin: 0 6px;
    color: var(--color-border, #d9d9d9);
}
/* La hora, en pastilla y sin color: es un dato, no un estado. El color de la
   fila lo lleva la marca de la izquierda y no hay que repetirlo aquí.
   Monoespaciada porque son varias filas con la misma forma —dd-mm-aaaa hh:mm—
   y alineadas por columna se leen de un barrido. `<b>` sin negrita: marca que
   esto es un dato, no un énfasis. */
.wp-row__when {
    display: inline-block;
    padding: 1px 6px;
    border: 1px solid var(--color-border);
    border-radius: 4px;
    background: var(--color-surface-alt);
    font-weight: 400;
    font-size: 0.75rem;
    line-height: 1.5;
    color: var(--color-text-muted);
    white-space: nowrap;
}
/* La pastilla no lleva punto delante: la caja ya la separa, y el punto la haría
   parecer parte de la frase cuando es un dato aparte. El hueco se lo pone el
   margen, y sólo cuando va detrás de algo — sola, empieza a ras del título. */
.wp-row__sub > .wp-row__when::before { content: none; }
.wp-row__sub > * + .wp-row__when { margin-left: 8px; }
/* Lo que salió mal: color Y icono, nunca sólo color (docs/UI.md §5). */
.wp-row__flag { color: var(--color-error, #BB0000); white-space: nowrap; }
.wp-row__why   { display: block; margin-top: 4px; font-size: 0.75rem; color: var(--color-text-muted, #6A6D70); }

/* Los 32px son la marca (22) más el hueco (10): cuando el lado se va a su
   propia línea queda alineado con el cuerpo, no pegado al borde. En la misma
   línea no se nota — el cuerpo crece y se los come. */
.wp-row__side  {
    flex: 0 1 auto;
    margin-left: 32px;
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end;
}
/* Debajo de todo y a lo ancho, alineado con el cuerpo. */
.wp-row__wide  { flex: 0 0 calc(100% - 32px); margin-left: 32px; margin-top: 8px; }

.wp-row__acts  { display: inline-flex; align-items: center; gap: 6px; }
/* Con guantes, a pleno sol (docs/UI.md §3). */
.wp-row__acts :deep(.ant-btn) { min-height: 32px; }

/* Aquí vivía la container query de los 460px. Ya no hace falta: el `wrap` de
   arriba hace lo mismo y lo hace bien, fila a fila, sin un ancho inventado. */
</style>
