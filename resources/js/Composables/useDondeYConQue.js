import { ref } from 'vue';

const CLAVE = 'docufiz:device-id';

/**
 * Desde qué aparato y desde dónde se firma.
 *
 * Las columnas `device_id`, `latitude` y `longitude` de `signature_events`
 * existen desde el primer día, el servidor las acepta, y **la pantalla de
 * firma nunca las mandaba**: estaban vacías en las 13 764 firmas. Un plan que
 * acaba delante de un inspector se defiende con «esta persona firmó a las 7:12
 * desde la tablet de la cuadrilla, en la subestación», y de eso sólo se tenía
 * la hora.
 *
 * Las dos son **de mejor esfuerzo y nunca bloquean la firma**, que es la regla
 * que gobierna toda esta pantalla: en obra no se puede dejar a nadie sin firmar
 * porque el GPS tardó o porque alguien dijo que no al permiso. Si no se
 * consiguen, se firma igual y esos campos quedan vacíos, como hasta ahora.
 */
export function useDondeYConQue() {
    const coords = ref(null);

    /**
     * El identificador de este aparato.
     *
     * Se genera una vez y vive en el navegador. No identifica a una persona
     * —identifica a la tablet, que es lo que se pregunta: «¿se firmó desde el
     * aparato de la cuadrilla o desde otro sitio?»— y se puede borrar limpiando
     * los datos del navegador, que es lo correcto para un dato así.
     */
    const dispositivo = () => {
        try {
            let id = localStorage.getItem(CLAVE);

            if (!id) {
                id = (crypto?.randomUUID?.() ?? String(Date.now()) + Math.random()).slice(0, 36);
                localStorage.setItem(CLAVE, id);
            }

            return id;
        } catch {
            // Navegador con el almacenamiento bloqueado: se firma igual.
            return null;
        }
    };

    /**
     * Dónde está la tablet, si el navegador lo da y a tiempo.
     *
     * Se pide al abrir la pantalla y no al pulsar «firmar»: el permiso puede
     * abrir un diálogo del navegador, y ese diálogo encima de la cámara,
     * después de que la persona ya haya puesto la cara, sería lo peor posible.
     *
     * Con tope de 8 segundos. Una posición que llega después de la firma no
     * sirve para nada, y esperarla deja a la cuadrilla mirando la pantalla.
     */
    const pedirUbicacion = () => {
        if (!navigator.geolocation) return;

        navigator.geolocation.getCurrentPosition(
            (pos) => {
                coords.value = {
                    latitude: pos.coords.latitude,
                    longitude: pos.coords.longitude,
                };
            },
            // Denegado, sin señal o fuera de tiempo: se firma igual, sin dónde.
            () => { coords.value = null; },
            { enableHighAccuracy: false, timeout: 8000, maximumAge: 300000 },
        );
    };

    return { coords, dispositivo, pedirUbicacion };
}
