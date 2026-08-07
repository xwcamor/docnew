<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerArea;
use App\Models\CustomerLocation;
use App\Models\CustomerSubstation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * CustomerHierarchyController — CRUD de los nodos de la jerarquía del cliente
 * (Ubicación / Área / Subestación) desde la vista de árbol, vía modales.
 *
 * Genérico por NIVEL: un solo set de métodos despacha a los tres modelos según
 * `level`. Agregar un nivel nuevo = una entrada en LEVELS, sin duplicar lógica.
 * Cada operación redirige de vuelta al "ver" del cliente para refrescar el árbol.
 */
class CustomerHierarchyController extends Controller
{
    /**
     * Mapa de niveles: modelo, columna del padre y modelo del padre (para
     * validar que el padre pertenece a la cadena del cliente).
     */
    private const LEVELS = [
        'location'   => ['model' => CustomerLocation::class,   'parentKey' => 'customer_id',          'parentModel' => Customer::class],
        'area'       => ['model' => CustomerArea::class,       'parentKey' => 'customer_location_id', 'parentModel' => CustomerLocation::class],
        'substation' => ['model' => CustomerSubstation::class, 'parentKey' => 'customer_area_id',     'parentModel' => CustomerArea::class],
    ];

    /** Crea un nodo (level + parent_id + name) bajo el cliente. */
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $this->assertCanWriteHierarchy($customer);
        $level = $this->level($request);
        $data  = $request->validate([
            'parent_id' => ['required', 'integer'],
            'name'      => ['required', 'string', 'max:150'],
        ]);

        $cfg = self::LEVELS[$level];
        $this->assertParentBelongsToCustomer($level, (int) $data['parent_id'], $customer);

        $node = $cfg['model']::create([
            $cfg['parentKey'] => $data['parent_id'],
            'name'            => trim($data['name']),
            'created_by'      => auth()->id(),
        ]);

        // Mantiene la invariante: toda Ubicación nace con un Área y una
        // Subestación; toda Área nace con una Subestación. Así la jerarquía
        // siempre queda lista para colgarle transformadores.
        $this->seedRequiredChildren($level, $node);

        return $this->backToCustomer($customer, __('customers.hierarchy_created'));
    }

    /** Siembra los hijos obligatorios de un nodo recién creado. */
    private function seedRequiredChildren(string $level, $node): void
    {
        $userId = auth()->id();
        if ($level === 'location') {
            $area = $node->areas()->create(['name' => 'General', 'created_by' => $userId]);
            $area->substations()->create(['name' => 'Principal', 'created_by' => $userId]);
        } elseif ($level === 'area') {
            $node->substations()->create(['name' => 'Principal', 'created_by' => $userId]);
        }
    }

    /** Renombra un nodo. */
    public function update(Request $request, Customer $customer, string $level, int $id): RedirectResponse
    {
        $this->assertCanWriteHierarchy($customer);
        $this->ensureLevel($level);
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);

        $node = self::LEVELS[$level]['model']::findOrFail($id);
        $this->assertNodeBelongsToCustomer($level, $node, $customer);
        $node->update(['name' => trim($data['name'])]);

        return $this->backToCustomer($customer, __('customers.hierarchy_saved'));
    }

    /** Borra un nodo (soft-delete con motivo). Sus hijos quedan ocultos con él. */
    public function destroy(Request $request, Customer $customer, string $level, int $id): RedirectResponse
    {
        $this->assertCanWriteHierarchy($customer);
        $this->ensureLevel($level);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $cfg  = self::LEVELS[$level];
        $node = $cfg['model']::findOrFail($id);
        $this->assertNodeBelongsToCustomer($level, $node, $customer);

        // Invariante: no se puede borrar el último hijo de su padre (siempre
        // debe quedar ≥1 Ubicación por cliente, ≥1 Área por Ubicación, ≥1
        // Subestación por Área).
        $siblings = $cfg['model']::where($cfg['parentKey'], $node->{$cfg['parentKey']})->count();
        if ($siblings <= 1) {
            return redirect()
                ->route('business_management.customers.show', $customer->slug)
                ->with('error', __('customers.cannot_delete_last'));
        }

        // saveQuietly evita un audit 'updated' duplicado antes del 'deleted'.
        $node->deleted_description = $data['reason'] ?? null;
        $node->deleted_by = auth()->id();
        $node->saveQuietly();
        $node->delete();

        return $this->backToCustomer($customer, __('customers.hierarchy_deleted'));
    }

    /** Restaura un nodo borrado (UNDO). */
    public function restore(Customer $customer, string $level, int $id): RedirectResponse
    {
        $this->assertCanWriteHierarchy($customer);
        $this->ensureLevel($level);

        $node = self::LEVELS[$level]['model']::onlyTrashed()->findOrFail($id);
        $this->assertNodeBelongsToCustomer($level, $node, $customer);

        $node->deleted_description = null;
        $node->deleted_by = null;
        $node->restore();

        return $this->backToCustomer($customer, __('customers.hierarchy_restored'));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * La jerarquía de un cliente GLOBAL (workspace = Plataforma, tenant_id null)
     * la gestiona SOLO el super. Un admin lo ve (y le asigna transformadores)
     * pero no toca su jerarquía. Defensa además del trait BelongsToTenantOrGlobal,
     * que ya bloquea editar/borrar nodos globales pero no crearlos.
     */
    private function assertCanWriteHierarchy(Customer $customer): void
    {
        abort_if(
            $customer->tenant_id === null && ! (auth()->user()?->hasRole('super') ?? false),
            403,
            'La jerarquía de un cliente global solo la gestiona el super.'
        );
    }

    private function level(Request $request): string
    {
        $level = (string) $request->input('level');
        $this->ensureLevel($level);
        return $level;
    }

    private function ensureLevel(string $level): void
    {
        if (!isset(self::LEVELS[$level])) {
            throw ValidationException::withMessages(['level' => 'Nivel inválido.']);
        }
    }

    /** El padre indicado debe pertenecer a la cadena del cliente. */
    private function assertParentBelongsToCustomer(string $level, int $parentId, Customer $customer): void
    {
        $parent = self::LEVELS[$level]['parentModel']::findOrFail($parentId);
        abort_unless($this->resolveCustomerId($parent) === $customer->id, 403);
    }

    private function assertNodeBelongsToCustomer(string $level, $node, Customer $customer): void
    {
        abort_unless($this->resolveCustomerId($node) === $customer->id, 403);
    }

    /** Sube por la cadena hasta el customer_id, sea cual sea el nivel del nodo. */
    private function resolveCustomerId($node): ?int
    {
        if ($node instanceof Customer)            return $node->id;
        if ($node instanceof CustomerLocation)    return $node->customer_id;
        if ($node instanceof CustomerArea)        return $node->location?->customer_id;
        if ($node instanceof CustomerSubstation)  return $node->area?->location?->customer_id;
        return null;
    }

    private function backToCustomer(Customer $customer, string $message): RedirectResponse
    {
        return redirect()
            ->route('business_management.customers.show', $customer->slug)
            ->with('success', $message);
    }
}
