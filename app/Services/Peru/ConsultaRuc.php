<?php

namespace App\Services\Peru;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta de RUC contra SUNAT (via apis.net.pe).
 *
 * Es lo unico del sistema anterior con valor de negocio real que no tenia
 * sustituto: al teclear un RUC de 11 digitos, la v1 llamaba a SUNAT y rellenaba
 * sola la razon social
 * (`services_management/peru/search_codes_controller.rb:55-74`). Sin eso, el
 * RUC y la razon social se teclean a mano — y son justo el par que un
 * inspector contrasta contra el registro oficial.
 *
 * Degradar bien es parte del contrato: sin token configurado, con la API caida
 * o con un RUC que no existe, esto NO revienta ni bloquea el alta. Devuelve un
 * estado y la pantalla sigue dejando escribir a mano, igual que hacia la v1.
 */
class ConsultaRuc
{
    /** Solo Peru tiene RUC de 11 digitos; el gate por pais lo hace quien llama. */
    public const LARGO_RUC = 11;

    /**
     * @return array{estado:'encontrado'|'no_encontrado'|'sin_configurar'|'error', razon_social?:string, direccion?:string, activo?:bool}
     */
    public function buscar(string $ruc): array
    {
        $ruc = preg_replace('/\D/', '', $ruc);

        if (strlen($ruc) !== self::LARGO_RUC) {
            return ['estado' => 'no_encontrado'];
        }

        $token = config('services.apis_net_pe.token');
        if (blank($token)) {
            // Sin credencial no se puede consultar. No es un error del usuario:
            // la pantalla se queda callada y se teclea a mano.
            return ['estado' => 'sin_configurar'];
        }

        try {
            $respuesta = Http::withToken($token)
                ->withHeaders(['Referer' => 'https://apis.net.pe/api-ruc'])
                ->timeout((int) config('services.apis_net_pe.timeout', 6))
                ->get(rtrim((string) config('services.apis_net_pe.url'), '/') . '/v2/sunat/ruc', [
                    'numero' => $ruc,
                ]);
        } catch (\Throwable $e) {
            // Timeout o DNS. Se registra para poder diagnosticarlo, pero al
            // usuario solo le importa que puede seguir escribiendo.
            Log::warning('Consulta RUC fallida', ['ruc' => $ruc, 'error' => $e->getMessage()]);

            return ['estado' => 'error'];
        }

        if ($respuesta->status() === 404) {
            return ['estado' => 'no_encontrado'];
        }

        if (! $respuesta->successful()) {
            Log::warning('Consulta RUC con respuesta no exitosa', [
                'ruc' => $ruc, 'status' => $respuesta->status(),
            ]);

            return ['estado' => 'error'];
        }

        $datos = $respuesta->json();
        $razon = trim((string) ($datos['razonSocial'] ?? ''));

        if ($razon === '') {
            return ['estado' => 'no_encontrado'];
        }

        return [
            'estado'       => 'encontrado',
            'razon_social' => $razon,
            'direccion'    => trim((string) ($datos['direccion'] ?? '')) ?: null,
            // SUNAT devuelve «ACTIVO»/«BAJA DE OFICIO»/etc. Se normaliza a un
            // booleano para que la pantalla pueda avisar de una empresa de baja.
            'activo'       => isset($datos['estado'])
                ? str_contains(mb_strtoupper((string) $datos['estado']), 'ACTIVO')
                : null,
        ];
    }
}
