<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * RestrictedToAssignedCustomers — 3ª capa de aislamiento (sobre BelongsToTenant).
 *
 * Si el usuario autenticado tiene clientes ASIGNADOS (tabla customer_user),
 * limita las consultas del modelo a esos clientes. Si NO tiene asignaciones
 * (super, admin, y todos los usuarios actuales), no aplica nada → ve todo su
 * tenant. Es opt-in puro: nadie existente cambia hasta que se le asignen
 * clientes explícitamente.
 *
 * El modelo declara en qué columna vive el customer_id vía
 * `customerScopeColumn()` (Transformer = 'customer_id', Customer = 'id').
 */
trait RestrictedToAssignedCustomers
{
    public static function bootRestrictedToAssignedCustomers(): void
    {
        static::addGlobalScope('assigned_customers', function (Builder $builder) {
            // Igual que el scope de tenant: nada durante resolución de auth ni
            // en consola/queues/login.
            if (! auth()->hasUser()) {
                return;
            }

            $user = auth()->user();
            if (! method_exists($user, 'assignedCustomerIds')) {
                return;
            }

            $ids = $user->assignedCustomerIds();
            if (empty($ids)) {
                return; // sin asignaciones = sin restricción (ve todo su tenant)
            }

            $model = $builder->getModel();
            $col   = $model->customerScopeColumn();
            $table = $model->getTable();
            $builder->whereIn("{$table}.{$col}", $ids);
        });
    }

    /** Columna que referencia al cliente. Override en Customer ('id'). */
    public function customerScopeColumn(): string
    {
        return 'customer_id';
    }
}
