<?php

namespace App\Support;

use App\Models\User;

/**
 * Los valores viejos y nuevos de un apunte del historial, legibles.
 *
 * POR QUE HACE FALTA ESTO
 * -----------------------
 * `Auditable` escribe en `audit_logs` los valores TAL Y COMO van a la base, y
 * eso es lo correcto: seria absurdo cifrar una columna y dejar una copia en
 * claro en la tabla de al lado —una que ademas guarda cada version anterior—.
 * La consecuencia es que desde que el documento, el descriptor facial y el
 * texto de consentimiento van cifrados, el historial enseñaba un churro de
 * base64 donde antes ponia el DNI.
 *
 * El sobre se abre al PINTAR, no al guardar, y por eso vive aqui: lo necesitan
 * dos pantallas distintas —la ficha de cada modulo (`AuditLogResource`) y el
 * modulo de Auditoria, que enseña el JSON crudo— y si cada una lo resolviera
 * por su cuenta acabarian enmascarando con criterios distintos.
 *
 * LA REGLA DE QUIEN VE QUE NO CAMBIA POR ESTAR EN EL HISTORIAL
 * ------------------------------------------------------------
 * El documento sale enmascarado igual que en todas partes. El enmascarado del
 * listado de personas no serviria de nada si el mismo DNI saliera entero en la
 * pestaña de al lado; se decide con `PrivateInfo`, que es la unica regla del
 * sistema sobre esto y que a proposito NO deja pasar al super por ser super,
 * sino por tener el permiso.
 *
 * El texto de consentimiento sale entero: es justo lo que hay que poder leer
 * cuando alguien pregunta a que dijo que si.
 */
class ValoresAuditados
{
    /**
     * Claves que no aportan nada al leer un cambio.
     *
     * `num_doc_hash` es el indice ciego: un HMAC derivado del documento, que
     * cambia solo cuando cambia el documento —y ese SI sale, en la linea de al
     * lado—. Enseñarlo seria contar el mismo cambio dos veces, una de ellas con
     * 64 caracteres de hexadecimal.
     *
     * `face_descriptor` no deberia llegar nunca (esta en el `auditExclude` de
     * `PersonBiometric`), pero puede haber apuntes viejos de antes de esa
     * regla. No se pintan 128 numeros en una pantalla, y menos esos.
     */
    protected const FUERA = ['num_doc_hash', 'face_descriptor'];

    /**
     * @param  array<string, mixed>|null  $valores
     * @return array<string, mixed>|null
     */
    public static function legibles(?array $valores, ?User $usuario = null): ?array
    {
        if ($valores === null) {
            return null;
        }

        $salida = [];

        foreach ($valores as $clave => $valor) {
            if (in_array($clave, self::FUERA, true)) {
                continue;
            }

            $salida[$clave] = is_string($valor) && CifradoEnReposo::estaCifrado($valor)
                ? self::abrir($clave, $valor, $usuario)
                : $valor;
        }

        return $salida;
    }

    /**
     * Abre un sobre, con la regla de quien lo mira.
     *
     * Lo que no abre con la clave de hoy no se inventa ni revienta la pantalla:
     * se dice. Es el sintoma de un `APP_KEY` rotado sin re-cifrar, y en un
     * historial eso es informacion, no una averia.
     */
    protected static function abrir(string $clave, string $valor, ?User $usuario): string
    {
        $plano = CifradoEnReposo::enClaro($valor);

        if ($plano === null) {
            return (string) __('audit_logs.unreadable');
        }

        return $clave === 'num_doc'
            ? (string) PrivateInfo::documento($plano, $usuario)
            : $plano;
    }
}
