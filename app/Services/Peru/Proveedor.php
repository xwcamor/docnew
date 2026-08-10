<?php

namespace App\Services\Peru;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A quien se le pregunta por un RUC o un DNI, y como.
 *
 * Hay dos proveedores en Peru y **no hablan igual**. Ni la URL ni los nombres
 * de los campos coinciden, asi que poner el token de uno apuntando al otro
 * devuelve un 401 y desde fuera parece que «la API no funciona»:
 *
 *   decolecta     api.decolecta.com   /v1/reniec/dni   → first_name, first_last_name…
 *                 el token empieza por `sk_`
 *   apis_net_pe   api.apis.net.pe     /v2/reniec/dni   → nombres, apellidoPaterno…
 *
 * Esto centraliza la diferencia para que {@see ConsultaRuc} y {@see ConsultaDni}
 * no la repitan cada uno a su manera.
 *
 * El contrato con quien llama es siempre el mismo: **degradar sin estorbar**.
 * Sin token, con la API caida o con un numero que no existe, se devuelve un
 * estado y la pantalla deja escribir a mano. En obra se da de alta a la
 * cuadrilla a las seis de la mañana y no se espera a un tercero.
 */
class Proveedor
{
    public const DECOLECTA = 'decolecta';
    public const APIS_NET_PE = 'apis_net_pe';

    /** Donde vive cada uno y como se llaman sus rutas. */
    private const CATALOGO = [
        self::DECOLECTA => [
            'url'   => 'https://api.decolecta.com',
            'dni'   => '/v1/reniec/dni',
            'ruc'   => '/v1/sunat/ruc',
            'refer' => null,
        ],
        self::APIS_NET_PE => [
            'url'   => 'https://api.apis.net.pe',
            'dni'   => '/v2/reniec/dni',
            'ruc'   => '/v2/sunat/ruc',
            // apis.net.pe exige el `Referer` de su pagina de documentacion.
            'refer' => 'https://apis.net.pe',
        ],
    ];

    public function nombre(): string
    {
        $configurado = (string) config('services.peru_lookup.provider', self::DECOLECTA);

        return isset(self::CATALOGO[$configurado]) ? $configurado : self::DECOLECTA;
    }

    public function hayToken(): bool
    {
        return filled(config('services.peru_lookup.token'));
    }

    /**
     * Pregunta por un numero. Devuelve la respuesta, o null si no se pudo.
     *
     * @param  'dni'|'ruc'  $que
     */
    public function preguntar(string $que, string $numero): ?Response
    {
        $proveedor = self::CATALOGO[$this->nombre()];
        $base = rtrim((string) (config('services.peru_lookup.url') ?: $proveedor['url']), '/');

        try {
            return Http::withToken((string) config('services.peru_lookup.token'))
                ->acceptJson()
                ->withHeaders($proveedor['refer'] ? ['Referer' => $proveedor['refer']] : [])
                ->timeout((int) config('services.peru_lookup.timeout', 6))
                ->get($base . $proveedor[$que], ['numero' => $numero]);
        } catch (\Throwable $e) {
            // Timeout o DNS. Se registra para poder diagnosticarlo, pero al
            // usuario solo le importa que puede seguir escribiendo.
            Log::warning('Consulta a ' . $this->nombre() . ' fallida', [
                'que' => $que, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * El primer valor que aparezca de entre varios nombres posibles.
     *
     * Los dos proveedores devuelven lo mismo con otras etiquetas, y hasta el
     * mismo proveedor cambia segun el plan contratado. Se prueban todas en vez
     * de atarse a una.
     *
     * @param  array<string, mixed>  $datos
     * @param  array<int, string>  $claves
     */
    public function campo(array $datos, array $claves): string
    {
        foreach ($claves as $clave) {
            $valor = trim((string) ($datos[$clave] ?? ''));

            if ($valor !== '') {
                return $valor;
            }
        }

        return '';
    }
}
