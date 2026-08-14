import { ref } from 'vue';

/**
 * El ultimo toque dado en un checklist, para poder deshacerlo.
 *
 * POR QUE EXISTE
 * --------------
 * Las casillas ciclan al tocarlas (`AnswerCycle`), y un ciclo de tres estados
 * tiene una pega conocida: volver al estado anterior cuesta DOS toques mas. Con
 * guantes, al sol y con la tablet en una mano, pasarse de toque no es raro —es
 * lo normal— y la respuesta no puede ser «vuelve a dar la vuelta entera».
 *
 * Asi que se recuerda el ultimo cambio y se ofrece deshacerlo de un toque, al
 * lado de la casilla donde paso.
 *
 * UN SOLO NIVEL, Y UNO SOLO EN TODA LA PANTALLA
 * ---------------------------------------------
 * No es una pila de deshacer. Lo que se arregla es «me pase de toque en ESTA»,
 * que se descubre en el momento; en cuanto se toca otra casilla, el arrepentido
 * de la anterior ya no interesa y su boton estorba. Guardar mas historia
 * pondria varios botones de deshacer a la vez en la cuadricula y habria que
 * acertar el que era, que es exactamente el problema que se venia a resolver.
 *
 * Esto es estado de PANTALLA, no del formato: no viaja en el valor que emite el
 * campo (`items: [{item, answer}]`), no se guarda y se pierde al recargar. Lo
 * que se deshace es un toque, no un guardado.
 */
export function useUltimoToque() {
    /** `{ fila, item, anterior }` del ultimo cambio, o null si no hay ninguno. */
    const ultimo = ref(null);

    /**
     * `anterior` es el valor que TENIA la casilla, que es a donde vuelve al
     * deshacer. No se recalcula del ciclo al reves a proposito: una entrega
     * reabierta puede traer un «No aplica» que no esta en el ciclo, y girar el
     * ciclo hacia atras la devolveria a un estado en el que nunca estuvo.
     */
    function anotar(fila, item, anterior) {
        ultimo.value = { fila, item, anterior };
    }

    function olvidar() {
        ultimo.value = null;
    }

    /** ¿Es ESTA la casilla que se acaba de tocar? Decide donde sale el boton. */
    function esUltimo(fila, item) {
        return ultimo.value?.fila === fila && ultimo.value?.item === item;
    }

    return { ultimo, anotar, olvidar, esUltimo };
}
