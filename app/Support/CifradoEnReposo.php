<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Lo que hay que saber para cifrar columnas sin perder datos por el camino.
 *
 * QUE PROTEGE ESTO Y QUE NO
 * -------------------------
 * Cifrar una columna protege contra **quien lee un backup, un volcado o el
 * disco**: el `.sql` que se baja de la noche, la copia que se pasa por correo,
 * el droplet que alguien clona. No protege contra quien tiene el `APP_KEY` de
 * la aplicacion — con la clave, esto se descifra entero en una linea. Un
 * atacante que entra al servidor lee el `.env` y con eso lo lee todo.
 *
 * O sea: esto cierra el agujero del backup, no el del servidor. Los dos hay que
 * cerrarlos, pero con cosas distintas (ver `docs/DROPLET-POSTGRES-SECURITY.md`).
 *
 * SI SE PIERDE EL `APP_KEY`, LOS DATOS NO VUELVEN
 * -----------------------------------------------
 * No hay recuperacion, no hay pregunta de seguridad, no hay soporte que lo
 * arregle. Son los DNI y las caras de 14 000 personas. La clave se custodia
 * fuera del servidor y se rota **con un plan**, porque rotarla obliga a
 * RE-CIFRAR: primero se guarda la vieja en `APP_PREVIOUS_KEYS` para poder
 * seguir leyendo, luego se vuelve a escribir cada fila con la nueva, y solo
 * entonces se retira la vieja. Sin ese orden, rotar la clave es tirar los datos.
 *
 * POR QUE HACE FALTA DETECTAR SI ALGO YA ESTA CIFRADO
 * ---------------------------------------------------
 * Por dos motivos, y los dos son de los que pierden datos:
 *
 *   1. El comando que cifra lo que ya existe se puede quedar a medias —se corta
 *      la conexion, se acaba el tiempo, alguien pulsa Ctrl+C— y hay que poder
 *      volver a lanzarlo. Cifrar dos veces lo ya cifrado deja un dato que no
 *      se puede leer: el descifrado devuelve el sobre de dentro, no el DNI.
 *   2. Entre desplegar el codigo y correr el comando hay una ventana en la que
 *      la tabla tiene filas cifradas y filas en claro a la vez. Si el `cast`
 *      exigiera que todo estuviera cifrado, la aplicacion entera reventaria
 *      durante esa ventana. Por eso `App\Casts\Cifrado` lee las dos cosas.
 */
class CifradoEnReposo
{
    /**
     * ¿Este valor es un sobre de Laravel, o texto en claro?
     *
     * Se mira la FORMA, no se intenta descifrar: descifrar cuesta y ademas
     * fallaria por dos motivos distintos —«esto no estaba cifrado» y «esto se
     * cifro con otra clave»— que no se pueden confundir. El primero se arregla
     * cifrando; el segundo es una clave perdida y hay que gritarlo, no
     * sobrescribirlo.
     *
     * Un `Crypt::encryptString()` es base64 de un JSON con `iv`, `value` y
     * `mac`. Ningun DNI, ningun texto de consentimiento y ningun descriptor
     * facial tiene esa forma por accidente.
     */
    public static function estaCifrado(mixed $valor): bool
    {
        if (! is_string($valor) || $valor === '') {
            return false;
        }

        $crudo = base64_decode($valor, true);

        if ($crudo === false) {
            return false;
        }

        $sobre = json_decode($crudo, true);

        return is_array($sobre)
            && isset($sobre['iv'], $sobre['value'], $sobre['mac']);
    }

    /**
     * El valor en claro, venga cifrado o no.
     *
     * Devuelve `null` cuando tiene forma de sobre pero NO se puede abrir. Eso
     * es exactamente el sintoma de una clave que ya no es la que cifro esa
     * fila, y quien llama tiene que poder distinguirlo de un campo vacio para
     * contarlo y avisar, en vez de escribir encima.
     */
    public static function enClaro(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return is_string($valor) ? $valor : null;
        }

        if (! static::estaCifrado($valor)) {
            return (string) $valor;
        }

        try {
            return Crypt::decryptString($valor);
        } catch (DecryptException) {
            return null;
        }
    }
}
