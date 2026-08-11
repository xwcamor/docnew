<script setup>
/**
 * Fecha y hora sin tener que confirmar nada.
 *
 * El selector de Ant Design, en cuanto se le pide la hora, obliga a pulsar
 * «Aceptar»: son cuatro clics —día, hora, minuto y el botón— y hasta el último
 * no se guarda nada. En una tablet, con guantes y a pleno sol, eso es un
 * formulario que se abandona. Y no hay forma de quitarlo desde fuera: en
 * `vc-picker/Picker.js` el botón está atado a `picker === 'date' && showTime`,
 * sin propiedad que lo desactive.
 *
 * Así que se parte en dos: el calendario de Ant Design para el día —que se
 * cierra solo al elegirlo, sin botón— y el campo de hora nativo del navegador,
 * que en Android abre el reloj del sistema y en escritorio se teclea. Ninguno
 * de los dos pide confirmación.
 *
 * Habla en cadenas `YYYY-MM-DD HH:mm`, que es lo que espera el servidor, para
 * que el campo se pueda enlazar directo sin nada en medio que convierta.
 */
import { computed } from 'vue';
import { DatePicker } from 'ant-design-vue';
import dayjs from 'dayjs';

const props = defineProps({
    value:    { type: String, default: null },
    size:     { type: String, default: 'large' },
    disabled: { type: Boolean, default: false },
    /** Los días que no se pueden elegir; se pasa tal cual al calendario. */
    disabledDate: { type: Function, default: undefined },
    /**
     * El instante más temprano admitido, `YYYY-MM-DD HH:mm`.
     *
     * Sustituye al `disabled-time` del selector de Ant Design, que aquí no
     * aplica. En vez de dejar horas en gris —que en un campo nativo no se
     * puede— se corrige lo que se elija: pedir las 07:00 cuando el trabajo
     * empezó a las 09:30 devuelve las 09:30.
     */
    minTime: { type: String, default: null },
    placeholder: { type: String, default: undefined },
    /**
     * El día que el calendario ENSEÑA cuando el valor está vacío, `YYYY-MM-DD`.
     *
     * Para la fecha de fin del plan: el dueño pidió que salga la fecha de hoy
     * ya puesta y que el usuario solo ponga la hora. El valor real sigue vacío
     * hasta que se toca algo — teclear la hora compone «día enseñado + hora»,
     * que es el gesto pedido. Si se guarda sin tocar nada, no se guarda fecha:
     * un día que nadie eligió no es un dato.
     */
    defaultDay: { type: String, default: null },
});

const emit = defineEmits(['update:value', 'change']);

const fecha = computed(() => (props.value ? String(props.value).slice(0, 10) : props.defaultDay));
const hora  = computed(() => (props.value ? String(props.value).slice(11, 16) : ''));

/** La hora de ahora, redondeada a los 5 minutos: nadie apunta las 12:26. */
const ahoraRedondeado = () => {
    const d = dayjs();

    return d.minute(Math.floor(d.minute() / 5) * 5).format('HH:mm');
};

const emitir = (dia, minutos) => {
    if (! dia) {
        emit('update:value', null);
        emit('change', null);

        return;
    }

    let compuesto = `${dia} ${minutos || ahoraRedondeado()}`;

    if (props.minTime && compuesto < props.minTime) {
        compuesto = props.minTime;
    }

    emit('update:value', compuesto);
    emit('change', compuesto);
};

/** Al elegir el día, si no había hora se propone la de ahora. Se ve y se cambia. */
const ponerFecha = (dia) => emitir(dia, hora.value);

const ponerHora = (e) => {
    // Sin día elegido, la hora sola no significa nada: se asume hoy, que es el
    // caso real —se registra el trabajo del día— y se ve al momento en el
    // calendario, así que si no era eso se corrige de un clic.
    emitir(fecha.value ?? dayjs().format('YYYY-MM-DD'), e.target.value);
};
</script>

<template>
    <div class="fecha-hora" :class="`fecha-hora--${size}`">
        <DatePicker
            class="fecha-hora__dia"
            :value="fecha"
            :size="size"
            :disabled="disabled"
            :disabled-date="disabledDate"
            :placeholder="placeholder"
            format="DD-MM-YYYY"
            value-format="YYYY-MM-DD"
            @update:value="ponerFecha"
        />
        <!-- Si el reloj sale de 12 o de 24 horas lo decide el navegador por
             su idioma de instalación, no la página: Chrome ignora tanto el
             `lang` de aquí como el idioma del contenido —comprobado abriéndolo
             con el navegador en `es-PE` y sale «02:30 PM» igual—, y solo lo
             respeta Firefox. Se deja puesto por eso. Lo que se guarda y lo que
             viaja al servidor es SIEMPRE `HH:mm` en 24 horas, se vea como se
             vea. -->
        <input
            type="time"
            class="fecha-hora__hora"
            lang="es-PE"
            :value="hora"
            :disabled="disabled"
            step="300"
            @input="ponerHora"
        >
    </div>
</template>

<style scoped>
.fecha-hora {
    display: flex;
    gap: 8px;
    width: 100%;
}

.fecha-hora__dia {
    flex: 1 1 auto;
    min-width: 0;
}

/* El campo nativo puesto a juego con los de Ant Design: mismo borde, mismo
   radio y misma altura, o en una fila de formulario canta. */
.fecha-hora__hora {
    flex: 0 0 auto;
    width: 8rem;
    padding: 4px 11px;
    border: 1px solid var(--ant-color-border, #d9d9d9);
    border-radius: var(--ant-border-radius, 6px);
    background: var(--ant-color-bg-container, #fff);
    color: var(--ant-color-text, rgba(0, 0, 0, 0.88));
    font-size: 14px;
    line-height: 1.5714;
    transition: border-color .2s, box-shadow .2s;
}

.fecha-hora--large .fecha-hora__hora {
    padding: 7px 11px;
    font-size: 16px;
}

.fecha-hora__hora:hover:not(:disabled) {
    border-color: var(--ant-color-primary-hover, #4096ff);
}

.fecha-hora__hora:focus {
    outline: none;
    border-color: var(--ant-color-primary, #1677ff);
    box-shadow: 0 0 0 2px rgba(5, 145, 255, .1);
}

.fecha-hora__hora:disabled {
    background: var(--ant-color-bg-container-disabled, rgba(0, 0, 0, .04));
    color: var(--ant-color-text-disabled, rgba(0, 0, 0, .25));
    cursor: not-allowed;
}

/* El reloj del campo nativo es negro sobre fondo oscuro y no se ve. */
html[data-theme="dark"] .fecha-hora__hora::-webkit-calendar-picker-indicator {
    filter: invert(1) opacity(.65);
}

/* En móvil la hora no cabe al lado: se pone debajo, a todo el ancho. */
@media (max-width: 575px) {
    .fecha-hora {
        flex-wrap: wrap;
    }

    .fecha-hora__hora {
        width: 100%;
    }
}
</style>
