<script setup>
import { computed } from 'vue';
import { Tag, Tooltip } from 'ant-design-vue';
import {
    CheckCircleFilled, CheckCircleOutlined, ClockCircleOutlined, LockOutlined,
    MinusCircleOutlined, WarningFilled,
} from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';
import { horaDeObra } from '@/Utils/horaDeObra';

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
        ? t(`work_plans.done_at_${props.doneVerb}`, { when: horaDeObra(props.when) })
        : props.label
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
            <!-- La hora va en el subtítulo pero como prop aparte, NUNCA
                 concatenada con `v-html`: aquí dentro se pintan nombres de
                 personas, que son datos de usuario. -->
            <span v-if="subtitle || subtitleTime" class="wp-row__sub">
                {{ subtitle }}<template v-if="subtitle && subtitleTime"> · </template><b v-if="subtitleTime">{{ subtitleTime }}</b>
            </span>
            <span v-if="reason" class="wp-row__why">{{ reason }}</span>
        </div>

        <div class="wp-row__side">
            <!-- Aquí vivía una pastilla de estado con la hora dentro, y llevaba
                 el mismo icono que la marca: dos veces lo mismo por fila. El
                 estado lo cuenta la marca y la hora va en el subtítulo, donde
                 se lee sin hover — que en una tablet no existe (docs/UI.md §3).

                 Lo que sí se queda a la derecha es el RESULTADO, que no es el
                 estado: un documento confirmado Y con tres observaciones son
                 dos cosas ciertas a la vez, y una no sustituye a la otra. -->
            <Tooltip v-if="findings > 0" :title="tc('work_plans.findings_hint', findings, { count: findings })">
                <Tag color="error" :bordered="false" class="wp-row__tag">
                    <WarningFilled /> {{ tc('work_plans.findings_count', findings, { count: findings }) }}
                </Tag>
            </Tooltip>

            <!-- Y cuando no hay ninguna, decirlo.
                 El hueco donde a veces sale un aviso rojo y a veces no sale
                 nada se lee como «todavía no lo han mirado». «Sin
                 observaciones» es un resultado, y es el que se busca. En gris y
                 no en verde: el día limpio es lo normal, no un logro, y compite
                 con la pastilla de estado que ya va en verde. -->
            <Tag v-else-if="showClean" :bordered="false" class="wp-row__tag wp-row__tag--clean">
                <CheckCircleOutlined /> {{ t('work_plans.findings_none') }}
            </Tag>

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

.wp-row__tag   { margin: 0; white-space: nowrap; }

/* La hora va dentro del subtítulo y en monoespaciada: son cuatro filas con la
   misma forma —dd-mm-aaaa hh:mm— y alineadas por columna se leen de un barrido
   en vez de una a una. Lo marca la tarjeta envolviéndola en un `<b>`, que es lo
   único que el subtítulo deja pasar como marca. */
.wp-row__sub :deep(b) {
    font-weight: 400;
    font-family: ui-monospace, Consolas, monospace;
}
/* El resultado limpio, en gris: es lo normal, no un logro, y en verde
   competiría con la pastilla de estado que ya lo es. */
.wp-row__tag--clean {
    background: var(--color-surface-alt, #F5F6F7);
    color: var(--color-text-muted, #6A6D70);
}
.wp-row__acts  { display: inline-flex; align-items: center; gap: 6px; }
/* Con guantes, a pleno sol (docs/UI.md §3). */
.wp-row__acts :deep(.ant-btn) { min-height: 32px; }

/* Aquí vivía la container query de los 460px. Ya no hace falta: el `wrap` de
   arriba hace lo mismo y lo hace bien, fila a fila, sin un ancho inventado. */
</style>
