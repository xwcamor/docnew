<?php

namespace App\Jobs\BusinessManagement\Transformers\Concerns;

use App\Models\Transformer;
use App\Models\User;

/**
 * Captura el scope del dispatcher (tenant + clientes asignados) al construir el
 * job — el worker no tiene sesión — y arma la query de flota scope-safe con un
 * filtro opcional por cliente. Compartido por los jobs del reporte de flota.
 */
trait BuildsFleetQuery
{
    protected ?int $fleetTenantId = null;
    protected array $fleetAllowedCustomerIds = [];

    protected function captureFleetScope(int $userId): void
    {
        $user = User::find($userId);
        $this->fleetTenantId           = $user?->tenant_id;
        $this->fleetAllowedCustomerIds = $user?->assignedCustomerIds() ?? [];
    }

    protected function fleetQuery(?int $customerId)
    {
        $q = Transformer::query()->withoutGlobalScope('tenant');
        if ($this->fleetTenantId !== null) {
            $q->where('transformers.tenant_id', $this->fleetTenantId);
        }
        if (!empty($this->fleetAllowedCustomerIds)) {
            $q->whereIn('customer_id', $this->fleetAllowedCustomerIds);
        }
        if ($customerId) {
            $q->where('customer_id', $customerId);
        }
        return $q;
    }
}
