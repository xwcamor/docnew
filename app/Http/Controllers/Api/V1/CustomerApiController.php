<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\BusinessManagement\Customer\BulkDeleteCustomerRequest;
use App\Http\Requests\BusinessManagement\Customer\BulkSetActiveCustomerRequest;
use App\Http\Requests\BusinessManagement\Customer\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Rules\UniqueNormalizedName;
use App\Services\BusinessManagement\CustomerService;
use Illuminate\Http\Request;

/**
 * Customers API — external integrations.
 *
 * Auth:        Sanctum bearer token tied to a workspace's system user.
 * Tenant:      Auto-scoped via BelongsToTenant on the User model — every
 *              query is filtered to the tenant of the token's owner.
 * Abilities:   Required per route (customers:read / customers:write /
 *              customers:delete).
 *
 * Reuses:
 *   - Customer::filter()       (same scope as the Inertia index)
 *   - CustomerService          (same create/update/delete logic as the UI)
 *   - Auditable trait          (every action logs an audit entry automatically)
 *
 * Es el patron de referencia para futuras APIs. Cuando agregues un modulo
 * nuevo con `make:module` y quieras exponerlo via API, clonalo de aquí.
 */
class CustomerApiController extends ApiController
{
    /**
     * List customers
     *
     * Returns a paginated list of customers filtered by the given criteria.
     * Supports two pagination modes — offset (default, with `page=N`) and
     * cursor (opt-in via `cursor=`, recommended for datasets > 100k rows).
     *
     * @group Customers
     *
     * @queryParam name string Buscar por nombre (case + accent insensitive). No-example
     * @queryParam cod string Filtrar por código exacto. No-example
     * @queryParam country_id integer Filtrar por id de país. No-example
     * @queryParam is_active boolean Filtrar por estado activo (true/false). No-example
     * @queryParam created_from date Fecha desde (formato YYYY-MM-DD). No-example
     * @queryParam created_to date Fecha hasta (formato YYYY-MM-DD). No-example
     * @queryParam sort string Columna por la que ordenar (name, created_at, etc.). No-example
     * @queryParam direction string Dirección de ordenamiento (asc | desc). No-example
     * @queryParam per_page integer Items por página (1-200). Default 25. No-example
     * @queryParam page integer Número de página para paginación offset. No-example
     * @queryParam cursor string Token de paginación tipo cursor — solo se usa cuando está siguiendo una respuesta previa que devolvió `next_cursor`. Déjelo vacío para la primera página. No-example
     *
     * @response 200 {
     *   "data": [
     *     {"id": 1, "name": "Acme S.A.", "cod": "CLI-001", "is_active": true, "created_at": "2025-01-01T00:00:00+00:00", "updated_at": "2025-01-01T00:00:00+00:00"}
     *   ],
     *   "meta": {"current_page": 1, "total": 50, "per_page": 25}
     * }
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 25);
        $perPage = min(max($perPage, 1), 200);

        // Modo cursor si el cliente pidió explícitamente con ?cursor o ?paginate=cursor.
        $useCursor = $request->has('cursor') || $request->get('paginate') === 'cursor';

        $query = Customer::filter($request)->with('country:id,name,iso_code');

        if ($useCursor) {
            // Validar cursor antes de usarlo. Si viene malformado (ej. el valor
            // ejemplo de la doc) devolvemos 400 limpio en lugar de un 500.
            $rawCursor = $request->get('cursor');
            if ($rawCursor !== null && $rawCursor !== '') {
                try {
                    \Illuminate\Pagination\Cursor::fromEncoded($rawCursor);
                } catch (\Throwable $e) {
                    return response()->json([
                        'message' => 'Invalid cursor parameter. Leave it empty to fetch the first page.',
                    ], 400);
                }
            }
            $customers = $query->cursorPaginate($perPage);
        } else {
            $customers = $query->paginate($perPage);
        }

        return CustomerResource::collection($customers);
    }

    /**
     * Get a single customer
     *
     * @group Customers
     *
     * @urlParam slug string required Slug del cliente (22 caracteres aleatorios, devueltos por el endpoint de listado). No-example
     *
     * @response 200 {
     *   "data": {"id": 1, "name": "Acme S.A.", "cod": "CLI-001", "is_active": true, "created_at": "2025-01-01T00:00:00+00:00", "updated_at": "2025-01-01T00:00:00+00:00"}
     * }
     * @response 404 {"message": "Not Found"}
     */
    public function show(string $slug)
    {
        $customer = Customer::where('slug', $slug)->with('country:id,name,iso_code')->firstOrFail();
        return new CustomerResource($customer);
    }

    /**
     * Create a customer
     *
     * Name uniqueness is case + accent insensitive within the workspace —
     * "Acme S.A." y "ACME S.A." se consideran duplicados.
     *
     * @group Customers
     *
     * @bodyParam name string required Nombre del cliente (máx 255). No-example
     * @bodyParam cod string Código del cliente (máx 60). No-example
     * @bodyParam country_id integer Id del país. No-example
     * @bodyParam is_active boolean Estado activo. Default true. No-example
     *
     * @response 201 {
     *   "data": {"id": 12, "name": "Acme S.A.", "cod": "CLI-001", "is_active": true, "created_at": "...", "updated_at": "..."}
     * }
     * @response 422 {"message": "The given data was invalid.", "errors": {"name": ["This customer already exists."]}}
     */
    public function store(StoreCustomerRequest $request, CustomerService $service)
    {
        // Compartimos el FormRequest con el web — misma rule (case + accent
        // insensitive via UniqueNormalizedName), mismas validaciones. Sin esta
        // consistencia API y UI podrian divergir en que consideran duplicado.
        $customer = $service->create($request->validated());

        return $this->created(new CustomerResource($customer));
    }

    /**
     * Update a customer
     *
     * Partial updates supported via `sometimes`. Only fields included in the
     * body are touched; omitted fields keep their current value.
     *
     * @group Customers
     *
     * @urlParam slug string required Slug del cliente. No-example
     * @bodyParam name string Nuevo nombre (máx 255). No-example
     * @bodyParam cod string Nuevo código (máx 60). No-example
     * @bodyParam country_id integer Nuevo id de país. No-example
     * @bodyParam is_active boolean Estado activo. No-example
     *
     * @response 200 {
     *   "data": {"id": 12, "name": "Acme Corp", "cod": "CLI-002", "is_active": false}
     * }
     * @response 422 {"message": "The given data was invalid.", "errors": {"name": ["This customer already exists."]}}
     */
    public function update(Request $request, string $slug, CustomerService $service)
    {
        $customer = Customer::where('slug', $slug)->firstOrFail();

        // Validacion minima paralela al UpdateCustomerRequest. Mantengo el
        // mismo set de reglas para que API y UI no divirjan.
        $data = $request->validate([
            'name'       => ['sometimes', 'required', 'string', 'max:255',
                             new UniqueNormalizedName('customers', 'name', ignoreId: $customer->id)],
            'cod'        => ['sometimes', 'nullable', 'string', 'max:60'],
            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'is_active'  => ['sometimes', 'boolean'],
        ]);

        $service->update($customer, $data);

        return new CustomerResource($customer->fresh()->load('country:id,name,iso_code'));
    }

    /**
     * Soft-delete a customer
     *
     * Sets `deleted_at` and stores the deletion reason in `deleted_description`
     * for audit. The record can be restored by super/admin from the trash UI.
     *
     * @group Customers
     *
     * @urlParam slug string required Slug del cliente. No-example
     * @bodyParam deleted_description string Motivo de la eliminación (queda en el audit log). No-example
     *
     * @response 204 {}
     * @response 404 {"message": "Not Found"}
     */
    public function destroy(Request $request, string $slug, CustomerService $service)
    {
        $customer = Customer::where('slug', $slug)->firstOrFail();

        $reason = $request->input('deleted_description', __('global.deleted_via_api'));

        $service->delete($customer, $reason);

        return $this->noContent();
    }

    /**
     * Bulk soft-delete
     *
     * Soft-deletes multiple customers in one request. When the batch exceeds
     * the global `bulk.async_threshold` setting (200 by default), the
     * operation is queued and the API returns 202 with `queued: true`.
     *
     * @group Customers — Bulk
     *
     * @bodyParam ids integer[] required Array de ids de customers a eliminar (1-500). No-example
     * @bodyParam deleted_description string required Motivo de la eliminación (3-1000 chars, queda en audit log). No-example
     *
     * @response 200 {
     *   "message": "Deleted",
     *   "queued": false,
     *   "processed": 50
     * }
     * @response 202 {
     *   "message": "Operation queued for 300 records...",
     *   "queued": true,
     *   "count": 300
     * }
     */
    public function bulkDelete(BulkDeleteCustomerRequest $request, CustomerService $service)
    {
        $data   = $request->validated();
        $result = $service->bulkDelete($data['ids'], $data['deleted_description']);

        if ($result['queued']) {
            return response()->json([
                'message' => __('global.bulk_in_queue', ['count' => $result['count']]),
                'queued'  => true,
                'count'   => $result['count'],
            ], 202);
        }

        if (!empty($result['blocked'])) {
            return response()->json([
                'message' => __('global.cannot_delete_has_dependents'),
                'queued'  => false,
                'blocked' => true,
            ], 409);
        }

        return response()->json([
            'message'   => 'Deleted',
            'queued'    => false,
            'processed' => $result['count'],
        ]);
    }

    /**
     * Bulk toggle active
     *
     * Sets `is_active` for many customers at once. Same async semantics as
     * bulk-delete (queues when over threshold). Records already in the
     * desired state are skipped (no audit log noise).
     *
     * @group Customers — Bulk
     *
     * @bodyParam ids integer[] required Array de ids de customers (1-500). No-example
     * @bodyParam is_active boolean required Estado destino (true | false). No-example
     *
     * @response 200 {
     *   "message": "Updated",
     *   "queued": false,
     *   "processed": 30
     * }
     * @response 202 {
     *   "message": "Operation queued for 300 records...",
     *   "queued": true,
     *   "count": 300
     * }
     */
    public function bulkSetActive(BulkSetActiveCustomerRequest $request, CustomerService $service)
    {
        $data   = $request->validated();
        $result = $service->bulkSetActive($data['ids'], (bool) $data['is_active']);

        if ($result['queued']) {
            return response()->json([
                'message' => __('global.bulk_in_queue', ['count' => $result['count']]),
                'queued'  => true,
                'count'   => $result['count'],
            ], 202);
        }

        return response()->json([
            'message'   => 'Updated',
            'queued'    => false,
            'processed' => $result['changed'],
        ]);
    }
}
