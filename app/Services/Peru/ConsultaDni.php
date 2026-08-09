<?php

namespace App\Services\Peru;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Consulta de DNI contra RENIEC (via apis.net.pe).
 *
 * La v1 ya la tenia —`services_management/peru/search_codes_controller.rb:31`—
 * y al portar el modulo se perdio, asi que el nombre de cada trabajador se
 * teclea a mano. Ese tecleo es justo el origen de la basura que hubo que
 * limpiar en la base maestra: el mismo DNI escrito de dos formas, un celular
 * metido en el campo del documento, un «11111111» inventado para salir del
 * paso. Un nombre que viene de RENIEC no se escribe mal.
 *
 * Solo aplica a Peru y solo al DNI. Un carne de extranjeria, un PTP o un
 * pasaporte no estan en RENIEC: ahi no hay nada que consultar y quien lo llama
 * ni lo intenta.
 *
 * Degradar bien es parte del contrato, igual que en {@see ConsultaRuc}: sin
 * token, con la API caida o con un DNI que no existe, esto NO revienta ni
 * bloquea el alta. Devuelve un estado y la pantalla deja escribir a mano.
 */
class ConsultaDni
{
    public const LARGO_DNI = 8;

    /** Un nombre no cambia. Lo que se guarda vale para un mes largo. */
    private const DIAS_EN_CACHE = 30;

    /**
     * @return array{estado:'encontrado'|'no_encontrado'|'sin_configurar'|'error', nombres?:string, apellidos?:string}
     */
    public function buscar(string $dni): array
    {
        $dni = preg_replace('/\D/', '', $dni) ?? '';

        if (strlen($dni) !== self::LARGO_DNI) {
            return ['estado' => 'no_encontrado'];
        }

        $token = config('services.apis_net_pe.token');

        if (blank($token)) {
            // Sin credencial no se puede consultar. No es un error del usuario:
            // la pantalla se queda callada y se teclea a mano.
            return ['estado' => 'sin_configurar'];
        }

        $clave = 'reniec:dni:' . $dni;
        $cacheado = Cache::get($clave);

        if (is_array($cacheado)) {
            return $cacheado;
        }

        $resultado = $this->consultar($dni, (string) $token);

        // Solo se guarda el acierto. Un fallo en cache es un fallo repetido
        // durante un mes, y el que no encuentra hoy puede encontrar mañana.
        if ($resultado['estado'] === 'encontrado') {
            Cache::put($clave, $resultado, now()->addDays(self::DIAS_EN_CACHE));
        }

        return $resultado;
    }

    /** @return array{estado:string, nombres?:string, apellidos?:string} */
    private function consultar(string $dni, string $token): array
    {
        try {
            $respuesta = Http::withToken($token)
                ->withHeaders(['Referer' => 'https://apis.net.pe/consulta-dni-api'])
                ->timeout((int) config('services.apis_net_pe.timeout', 6))
                ->get(rtrim((string) config('services.apis_net_pe.url'), '/') . '/v2/reniec/dni', [
                    'numero' => $dni,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Consulta DNI fallida', [
                'dni' => $this->tapado($dni), 'error' => $e->getMessage(),
            ]);

            return ['estado' => 'error'];
        }

        if ($respuesta->status() === 404) {
            return ['estado' => 'no_encontrado'];
        }

        if (! $respuesta->successful()) {
            Log::warning('Consulta DNI con respuesta no exitosa', [
                'dni' => $this->tapado($dni), 'status' => $respuesta->status(),
            ]);

            return ['estado' => 'error'];
        }

        return $this->leer($respuesta->json() ?? []);
    }

    /**
     * Saca nombres y apellidos de la respuesta.
     *
     * apis.net.pe devuelve `nombres`, `apellidoPaterno` y `apellidoMaterno`,
     * pero no siempre: segun el plan contratado a veces solo llega
     * `nombreCompleto`, y ahi hay que partirlo. RENIEC lo escribe siempre en el
     * mismo orden —PATERNO MATERNO NOMBRES—, asi que los dos primeros trozos
     * son los apellidos y el resto el nombre.
     *
     * @param  array<string, mixed>  $datos
     * @return array{estado:string, nombres?:string, apellidos?:string}
     */
    private function leer(array $datos): array
    {
        $nombres = trim((string) ($datos['nombres'] ?? ''));
        $apellidos = trim(
            trim((string) ($datos['apellidoPaterno'] ?? '')) . ' ' .
            trim((string) ($datos['apellidoMaterno'] ?? ''))
        );

        if ($nombres === '' && $apellidos === '') {
            $completo = trim((string) ($datos['nombreCompleto'] ?? ''));
            $trozos = array_values(array_filter(explode(' ', $completo), fn ($t) => $t !== ''));

            if (count($trozos) >= 3) {
                $apellidos = implode(' ', array_slice($trozos, 0, 2));
                $nombres = implode(' ', array_slice($trozos, 2));
            }
        }

        if ($nombres === '' || $apellidos === '') {
            return ['estado' => 'no_encontrado'];
        }

        return [
            'estado'    => 'encontrado',
            // RENIEC contesta en mayusculas. Se deja como viene: es el nombre
            // oficial, y es el que tiene que cuadrar con el documento que el
            // inspector pide en la puerta.
            'nombres'   => $nombres,
            'apellidos' => $apellidos,
        ];
    }

    /** Un DNI no se escribe entero en el log: es dato personal. */
    private function tapado(string $dni): string
    {
        return Str::mask($dni, '*', 0, 6);
    }
}
