<?php

namespace App\Casts;

use App\Support\CifradoEnReposo;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Cifra una columna al escribir y la descifra al leer, **tolerando lo viejo**.
 *
 * POR QUE NO EL `encrypted` DE LARAVEL
 * ------------------------------------
 * El cast de la casa revienta con `DecryptException` en cuanto encuentra un
 * valor en claro. Eso convierte el despliegue en una operacion de todo o nada:
 * entre subir el codigo y terminar de correr `docufiz:cifrar-datos-sensibles`
 * hay una ventana —minutos con 14 000 personas— en la que la tabla tiene las
 * dos cosas a la vez. Con el cast de la casa, durante esa ventana el listado de
 * personas, la busqueda de la puerta y los PDF fallan todos con un 500.
 *
 * Este cast lee las dos formas y escribe siempre cifrado, asi que la migracion
 * puede ir por lotes con la aplicacion en marcha, y una fila que se guarde
 * antes de que el comando llegue a ella queda cifrada por el camino.
 *
 * Lo que NO hace es descifrar con una clave que no es la suya: eso lanza, y
 * tiene que lanzar. Un `APP_KEY` cambiado sin re-cifrar es una perdida de datos
 * y no puede parecer un campo vacio.
 *
 * @implements CastsAttributes<mixed, mixed>
 */
class Cifrado implements CastsAttributes
{
    /**
     * @param string|null $como `array` para columnas que guardan JSON (el
     *                          descriptor facial son 128 numeros). Sin esto el
     *                          modelo recibiria la cadena JSON y el codigo que
     *                          la recorre —la comparacion 1:1 al firmar— se
     *                          quedaria callado en vez de comparar.
     */
    public function __construct(private ?string $como = null)
    {
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Sin cifrar todavia: es una fila que el comando de migracion aun no ha
        // tocado. Se devuelve tal cual, que es lo que hace que la ventana del
        // despliegue no tumbe la aplicacion.
        $plano = CifradoEnReposo::estaCifrado($value)
            ? Crypt::decryptString($value)
            : (string) $value;

        if ($this->como !== 'array') {
            return $plano;
        }

        $decodificado = json_decode($plano, true);

        return is_array($decodificado) ? $decodificado : null;
    }

    /** @return array<string, string|null> */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $plano = $this->como === 'array'
            ? (string) json_encode($value)
            : (string) $value;

        // Ya venia cifrado: pasa entero. Cifrar dos veces no da un error, da un
        // dato que al descifrarse devuelve el sobre de dentro en vez del DNI —
        // o sea una perdida silenciosa. Pasa de verdad: el comando de migracion
        // relee y reescribe filas, y un `$persona->save()` sobre un modelo
        // hidratado desde una consulta cruda entraria por aqui.
        if (CifradoEnReposo::estaCifrado($plano)) {
            return [$key => $plano];
        }

        return [$key => Crypt::encryptString($plano)];
    }
}
