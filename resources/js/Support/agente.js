/**
 * El user-agent, en dos palabras.
 *
 * La cadena entera es un párrafo —«Mozilla/5.0 (Linux; Android 13; SM-A536B)
 * AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36»—
 * y en la ficha de una firma ocupaba más que todo lo demás junto, para decir
 * «Chrome en Android». Se resume, y **la cadena original se sigue pudiendo
 * ver**: es la que vale si un día hay que discutir una firma, y un resumen no
 * la sustituye.
 *
 * Esto es adivinar, no medir: el user-agent lo escribe el navegador y miente a
 * conciencia desde hace veinte años (por eso todos empiezan por «Mozilla»). El
 * resumen sirve para reconocer de un vistazo, no para sostener nada.
 */

const NAVEGADORES = [
    // El orden importa: Edge y Opera se hacen pasar por Chrome, y Chrome se
    // hace pasar por Safari. Gana el primero que encaje.
    [/\bEdgA?\/([\d.]+)/i, 'Edge'],
    [/\bOPR\/([\d.]+)/i, 'Opera'],
    [/\bSamsungBrowser\/([\d.]+)/i, 'Samsung Internet'],
    [/\bFirefox\/([\d.]+)/i, 'Firefox'],
    [/\bChrome\/([\d.]+)/i, 'Chrome'],
    [/\bVersion\/([\d.]+).*\bSafari\//i, 'Safari'],
];

const SISTEMAS = [
    [/\bAndroid ([\d.]+)/i, 'Android'],
    [/\biPhone OS ([\d_]+)/i, 'iPhone'],
    [/\biPad.*OS ([\d_]+)/i, 'iPad'],
    [/\bWindows NT 10\.0/i, 'Windows', '10/11'],
    [/\bWindows NT ([\d.]+)/i, 'Windows'],
    [/\bMac OS X ([\d_]+)/i, 'macOS'],
    [/\bLinux\b/i, 'Linux'],
];

/** Sólo la parte mayor: «126.0.0.0» no dice más que «126». */
const mayor = (version) => String(version ?? '').replace(/_/g, '.').split('.')[0] || null;

const buscar = (cadena, tabla) => {
    for (const [patron, nombre, fijo] of tabla) {
        const hallado = cadena.match(patron);

        if (hallado) return [nombre, fijo ?? mayor(hallado[1])].filter(Boolean).join(' ');
    }

    return null;
};

/**
 * «Chrome 126 · Android 13», o null si no se reconoce nada.
 *
 * Devolver null a propósito y no un «Desconocido»: quien lo llama enseña
 * entonces la cadena entera, que es más útil que una etiqueta que no dice nada.
 */
export function resumirAgente(userAgent) {
    if (!userAgent) return null;

    const partes = [buscar(userAgent, NAVEGADORES), buscar(userAgent, SISTEMAS)].filter(Boolean);

    return partes.length ? partes.join(' · ') : null;
}

/**
 * El identificador de la tablet, acortado.
 *
 * Es un UUID de 36 caracteres y nadie lo lee entero: lo que se hace con él es
 * comparar dos firmas a ver si salieron del mismo aparato, y para eso llegan
 * los primeros ocho. El completo se puede desplegar.
 */
export function acortarAparato(deviceId) {
    if (!deviceId) return null;

    return deviceId.length > 12 ? `${deviceId.slice(0, 8)}…` : deviceId;
}
