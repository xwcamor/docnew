<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\StoreLabResultRequest;
use App\Http\Requests\Api\V1\StoreLabTransformerRequest;
use App\Http\Resources\TransformerApiResource;
use App\Services\Lab\LabResultService;
use App\Services\Lab\LabTransformerService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * API del laboratorio — recibe los resultados de ensayo del sistema de
 * laboratorio (TR LAB) y los diagnostica.
 *
 * Reemplaza lo que hoy hace el laboratorio viejo: abrir una segunda conexión
 * contra nuestra base e insertar filas a mano. Ese camino no tiene idempotencia
 * (reintentar duplica), empareja equipos con un `find_by` por número de serie
 * (toma el primero si hay repetidos) y no corre el motor de diagnóstico, así
 * que el índice de salud se queda viejo hasta que alguien lo recalcula.
 *
 * Auth:      token Sanctum del usuario de sistema del workspace, igual que
 *            CustomerApiController (el patrón de referencia).
 * Tenant:    automático por BelongsToTenant — un laboratorio nunca ve ni toca
 *            la flota de otro workspace.
 * Abilities: transformers:read (búsqueda) · transformers:write (alta) ·
 *            lab:write (resultados).
 *
 * Documentación del contrato, ejemplos y errores: docs/API-LABORATORIO.md
 */
class LabResultApiController extends ApiController
{
    /**
     * Buscar transformador
     *
     * Devuelve los candidatos que coinciden con lo que el laboratorio conoce del
     * equipo. Puede devolver cero, uno o varios: la API NUNCA elige. Si hay
     * ambigüedad la resuelve una persona en la bandeja de conciliación del
     * laboratorio, y lo que se guarda de vuelta es el `slug`.
     *
     * @group Laboratorio
     *
     * @queryParam serial string Número de serie (coincidencia parcial). No-example
     * @queryParam tag string Tag o identificador de placa (coincidencia parcial). No-example
     * @queryParam customer string Nombre o id del cliente. No-example
     *
     * @response 200 {
     *   "data": [
     *     {"slug": "aBc...", "serial": "12345", "tag": "TR-01",
     *      "customer": {"id": 3, "name": "Minera X"},
     *      "substation": {"id": 12, "name": "SE Norte"},
     *      "health_index": 78.4, "health_rating": 3, "condition": "Bueno"}
     *   ]
     * }
     */
    public function lookup(Request $request, LabTransformerService $service)
    {
        $data = $request->validate([
            'serial'   => ['nullable', 'string', 'max:100'],
            'tag'      => ['nullable', 'string', 'max:100'],
            'customer' => ['nullable', 'string', 'max:255'],
        ]);

        // Sin ningún criterio devolvería la flota entera, que no es lo que este
        // endpoint es: para listar hay pantallas.
        if (! array_filter($data, fn ($v) => $v !== null && $v !== '')) {
            return $this->error(__('lab_api.transformer_required'), 422, 'lookup_criteria_required');
        }

        $candidates = $service->lookup(
            $data['serial'] ?? null,
            $data['tag'] ?? null,
            $data['customer'] ?? null,
        );

        return TransformerApiResource::collection($candidates);
    }

    /**
     * Alta de transformador
     *
     * Solo para cuando el laboratorio recibe la muestra de un equipo que este
     * sistema no conoce Y un operador lo confirmó. Nunca automático: un
     * transformador fantasma nacido de un error de tipeo ensucia la flota y el
     * tablero.
     *
     * @group Laboratorio
     *
     * @bodyParam serial string required Número de serie. No-example
     * @bodyParam tag string required Tag de placa. Único junto con la serie. No-example
     * @bodyParam customer_id integer Id del cliente (o `customer` con el nombre). No-example
     * @bodyParam customer_substation_id integer Id de la subestación (o `substation` con el nombre). No-example
     * @bodyParam transformer_type string required Código del tipo: potencia, distribucion, horno. No-example
     * @bodyParam oil_type string required Código del aceite: mineral, silicona, vegetal_soya… No-example
     * @bodyParam voltage_kv number Tensión en kV; o voltage_kv_hv / _lv / _tv (se toma la mayor). No-example
     * @bodyParam phases integer Número de fases: 1, 2 o 3. No-example
     *
     * @response 201 {"data": {"slug": "aBc...", "serial": "12345", "tag": "TR-01"}}
     * @response 422 {"message": "Tipo de equipo desconocido: bushing…", "errors": {}}
     */
    public function storeTransformer(StoreLabTransformerRequest $request, LabTransformerService $service)
    {
        $data = $request->validated();

        try {
            $transformer = $service->create($data);
        } catch (ValidationException $e) {
            // La jerarquía (sede → área → subestación) no tiene API propia, así
            // que cuando falla por ahí el error VIENE con las subestaciones del
            // cliente. Es la lista que necesita el operador para elegir, servida
            // donde hace falta en vez de abrir un módulo entero.
            $errors = $e->errors();
            if (isset($errors['customer_substation_id']) || isset($errors['substation'])) {
                return response()->json([
                    'message'               => $e->getMessage(),
                    'errors'                => $errors,
                    'available_substations' => $service->substationsFor($service->findCustomer($data)?->id),
                ], 422);
            }

            throw $e;
        }

        return $this->created(new TransformerApiResource($transformer));
    }

    /**
     * Ingesta de resultados
     *
     * Recibe los ensayos de UN informe de laboratorio para UN transformador, los
     * guarda en una sola transacción, los diagnostica con el mismo motor que la
     * interfaz web y recalcula el índice de salud del equipo.
     *
     * La cabecera `Idempotency-Key` es OBLIGATORIA: sin ella un reintento tras
     * un timeout duplicaría la muestra. Reenviar la misma clave con el mismo
     * cuerpo devuelve 200 con la respuesta original, sin crear nada.
     *
     * @group Laboratorio
     *
     * @header Idempotency-Key 6b1f2c3d-...
     *
     * @bodyParam transformer object required Slug, o serie/tag/cliente. No-example
     * @bodyParam lab object Datos del informe: report_number, laboratory_code, sampled_at. No-example
     * @bodyParam tests object[] required Ensayos: kind, measured_at, values por código de analito, methods. No-example
     *
     * @response 201 {
     *   "transformer": {"slug": "aBc...", "health_index": 78.4, "health_rating": 3, "condition": "Bueno"},
     *   "created": [{"kind": "chromatography", "id": 9134, "dgaf_score": 1.12, "dgaf_condition": "Muy Bueno"}],
     *   "warnings": []
     * }
     * @response 409 {"message": "Esta Idempotency-Key ya se usó con un cuerpo distinto…", "code": "idempotency_key_reused"}
     * @response 422 {"message": "Hay 3 transformadores que coinciden…", "errors": {}}
     */
    public function store(StoreLabResultRequest $request, LabTransformerService $transformers, LabResultService $results)
    {
        $data = $request->validated();

        $transformer = $transformers->resolve($data['transformer']);
        $outcome = $results->ingest($transformer, $data['lab'] ?? [], $data['tests']);

        // La transacción la abre el middleware de idempotencia, que también
        // guarda ESTA respuesta dentro de ella: o quedan las muestras y la
        // respuesta, o no queda nada.
        $transformer->refresh();

        return response()->json([
            'transformer' => (new TransformerApiResource($transformer))->toArray($request),
            'created'     => $outcome['created'],
            'warnings'    => $outcome['warnings'],
        ], 201);
    }
}
