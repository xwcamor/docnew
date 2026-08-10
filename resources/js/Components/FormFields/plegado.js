import { nextTick, ref } from 'vue';

/**
 * Plegado de las filas de un campo compuesto (EPP, IHM y matriz de riesgo).
 *
 * Los tres campos son lo mismo: una lista de filas gordas —25 items por
 * trabajador, 10 por herramienta, seis desplegables por peligro— apiladas en
 * una sola columna. Con siete trabajadores el EPP medía 12 400 px a 1400 y
 * casi 18 000 en la tablet en vertical: trece pantallas seguidas de botones
 * iguales sin saber por dónde ibas ni cuánto quedaba.
 *
 * Se pliega **una fila abierta a la vez**. No es una manía de diseño: si se
 * pudieran abrir todas a mano se vuelve a la página infinita en tres toques, y
 * lo que se estaba arreglando era justo eso. «Desplegar todo» sigue existiendo
 * porque un formato confirmado se lee entero, y ahí no hay nada que marcar.
 *
 * Plegar NO quita nada del formulario: el valor del campo vive en la página
 * (`FormFill.vue`), no en el DOM de la fila. Lo plegado se guarda igual.
 */
export function usePlegado(prefijo) {
    /** Clave de la fila abierta, o null si están todas plegadas. */
    const abierta = ref(null);
    /** «Desplegar todo»: manda sobre `abierta` mientras esté puesto. */
    const todas = ref(false);

    const idFila = (clave) => `${prefijo}-${clave}`;

    const estaAbierta = (clave) => todas.value || abierta.value === clave;

    /**
     * Al saltar a una fila hay que llevar la vista a su cabecera, porque el
     * salto se pide desde el índice de arriba o desde el pie de la fila
     * anterior — sin esto, la fila se abre fuera de la pantalla y parece que no
     * ha pasado nada. `nextTick` porque el cuerpo todavía no está pintado y la
     * página aún no mide lo que va a medir.
     */
    async function desplazarA(clave) {
        await nextTick();
        document.getElementById(idFila(clave))?.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }

    function abrir(clave) {
        todas.value = false;
        abierta.value = clave;
        desplazarA(clave);
    }

    /** Toque en la cabecera: abre la fila, o la cierra si ya era la abierta. */
    function alternar(clave) {
        if (todas.value) {
            todas.value = false;
            abierta.value = clave;

            return;
        }

        abierta.value = abierta.value === clave ? null : clave;
    }

    function alternarTodo() {
        todas.value = ! todas.value;
        abierta.value = null;
    }

    /** Todo plegado. Lo usa quien borra una fila: ver `quitar()` en el IHM. */
    function cerrar() {
        todas.value = false;
        abierta.value = null;
    }

    return { abierta, todas, idFila, estaAbierta, abrir, alternar, alternarTodo, cerrar };
}
