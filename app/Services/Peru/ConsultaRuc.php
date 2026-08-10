<?php

namespace App\Services\Peru;

use Illuminate\Support\Facades\Log;

/**
 * Consulta de RUC en SUNAT.
 *
 * Es lo unico del sistema anterior con valor de negocio real que no tenia
 * sustituto: al teclear un RUC de 11 digitos, la v1 llamaba a SUNAT y rellenaba
 * sola la razon social
 * (`services_management/peru/search_codes_controller.rb:55-74`). Sin eso, el
 * RUC y la razon social se teclean a mano — y son justo el par que un
 * inspector contrasta contra el registro oficial.
 *
 * Quien contesta —Decolecta o apis.net.pe— y con que nombres lo hace lo resuelve
 * {@see Proveedor}. Degradar bien es parte del contrato: sin token configurado,
 * con la API caida o con un RUC que no existe, esto NO revienta ni bloquea el
 * alta. Devuelve un estado y la pantalla sigue dejando escribir a mano, igual
 * que hacia la v1.
 */
class ConsultaRuc
{
    /** Solo Peru tiene RUC de 11 digitos; el gate por pais lo hace quien llama. */
    public const LARGO_RUC = 11;

    public function __construct(private readonly Proveedor $proveedor)
    {
    }

    /**
     * @return array{estado:'encontrado'|'no_encontrado'|'sin_configurar'|'error', razon_social?:string, direccion?:string, activo?:bool}
     */
    public function buscar(string $ruc): array
    {
        $ruc = preg_replace('/\D/', '', $ruc) ?? '';

        if (strlen($ruc) !== self::LARGO_RUC) {
            return ['estado' => 'no_encontrado'];
        }

        if (! $this->proveedor->hayToken()) {
            return ['estado' => 'sin_configurar'];
        }

        $respuesta = $this->proveedor->preguntar('ruc', $ruc);

        if ($respuesta === null) {
            return ['estado' => 'error'];
        }

        // 404 en apis.net.pe, 422 en Decolecta: los dos son «ese numero no esta».
        if (in_array($respuesta->status(), [404, 422], true)) {
            return ['estado' => 'no_encontrado'];
        }

        if (! $respuesta->successful()) {
            Log::warning('Consulta RUC con respuesta no exitosa', [
                'ruc' => $ruc, 'status' => $respuesta->status(),
            ]);

            return ['estado' => 'error'];
        }

        $datos = $respuesta->json() ?? [];

        // Decolecta la llama `razon_social`; apis.net.pe, `razonSocial`.
        $razon = $this->proveedor->campo($datos, ['razon_social', 'razonSocial', 'nombre_o_razon_social']);

        if ($razon === '') {
            return ['estado' => 'no_encontrado'];
        }

        $estado = $this->proveedor->campo($datos, ['estado', 'estado_del_contribuyente']);

        return [
            'estado'       => 'encontrado',
            'razon_social' => $razon,
            'direccion'    => $this->proveedor->campo($datos, ['direccion', 'direccion_completa']) ?: null,
            // SUNAT devuelve «ACTIVO»/«BAJA DE OFICIO»/etc. Se normaliza a un
            // booleano para que la pantalla pueda avisar de una empresa de baja.
            'activo'       => $estado === '' ? null : str_contains(mb_strtoupper($estado), 'ACTIVO'),
        ];
    }
}
