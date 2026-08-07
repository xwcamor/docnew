<?php

namespace App\Services\Automations\DataSources;

use App\Models\Automation;
use App\Models\Customer;
use App\Services\Automations\Contracts\DataSourceContract;
use App\Services\Automations\Support\FilterApplier;
use Illuminate\Support\Collection;

class CustomersDataSource implements DataSourceContract
{
    public function key(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return __('automations.source_customers');
    }

    public function allowedRoles(): array
    {
        return []; // disponible para todos los roles que puedan crear automatizaciones
    }

    public function fields(): array
    {
        return [
            ['key' => 'name',       'label' => __('customers.name'),    'type' => 'string', 'operators' => ['=', '!=', 'contains']],
            ['key' => 'cod',        'label' => __('customers.cod'),     'type' => 'string', 'operators' => ['=', '!=', 'contains']],
            ['key' => 'is_active',  'label' => __('customers.is_active'),'type' => 'boolean','operators' => ['=']],
            ['key' => 'created_at', 'label' => __('global.created_at'), 'type' => 'date',   'operators' => ['>', '<', '>=', '<=']],
        ];
    }

    public function fetch(Automation $automation): Collection
    {
        // withoutGlobalScopes() quita el scope de tenant (queue sin auth) pero
        // tambien el de SoftDeletes → excluir borrados a mano para no contarlos.
        $query = Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $automation->tenant_id)
            ->whereNull('deleted_at');

        // Pasamos los fields() con type completo para que FilterApplier coerza
        // boolean/date/enum/number del value (defensa ante values legacy/CSV).
        FilterApplier::apply($query, $automation->data_filter ?? [], $this->fields());

        $limit = (int) ($automation->data_filter['limit'] ?? 100);
        return $query->limit($limit)->get();
    }
}
