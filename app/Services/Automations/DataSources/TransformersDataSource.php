<?php

namespace App\Services\Automations\DataSources;

use App\Models\Automation;
use App\Models\Customer;
use App\Models\Transformer;
use App\Services\Automations\Contracts\DataSourceContract;
use App\Services\Automations\Support\FilterApplier;
use Illuminate\Support\Collection;

/**
 * TransformersDataSource — automatiza sobre el parque de transformadores.
 *
 * Caso de uso principal: alertas proactivas de mantenimiento. Ej:
 *   - health_index < 30   → notificar equipos en estado crítico
 *   - health_rating <= 1  → equipos malos/críticos por semáforo
 *   - oil_treated_at < X  → equipos sin tratamiento de aceite hace mucho
 *
 * health_rating va como number (0-4) y no enum: la columna es entera y un
 * enum con values string rompería la comparación en Postgres. La leyenda
 * (0 crítico … 4 excelente) va en el label.
 */
class TransformersDataSource implements DataSourceContract
{
    public function key(): string
    {
        return 'transformers';
    }

    public function label(): string
    {
        return __('automations.source_transformers');
    }

    public function allowedRoles(): array
    {
        return []; // negocio del workspace: disponible para quien pueda crear automatizaciones
    }

    public function fields(): array
    {
        return [
            ['key' => 'serial',           'label' => __('transformers.serial'),           'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'tag',              'label' => __('transformers.tag'),              'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            [
                'key' => 'customer_id', 'label' => __('customers.singular'),
                'type' => 'enum', 'operators' => ['=', '!=', 'in'],
                'options' => $this->customerOptions(),
            ],
            ['key' => 'health_index',     'label' => __('transformers.health_index'),     'type' => 'number',  'operators' => ['>', '<', '>=', '<=', '=']],
            ['key' => 'health_rating',    'label' => __('automations.field_health_rating'),'type' => 'number', 'operators' => ['=', '!=', '>', '<', '>=', '<=']],
            ['key' => 'voltage_kv',       'label' => __('transformers.voltage_kv'),       'type' => 'number',  'operators' => ['=', '>', '<', '>=', '<=']],
            ['key' => 'power_mva',        'label' => __('transformers.power_mva'),        'type' => 'number',  'operators' => ['=', '>', '<', '>=', '<=']],
            ['key' => 'manufacture_year', 'label' => __('transformers.manufacture_year'), 'type' => 'number',  'operators' => ['=', '>', '<', '>=', '<=']],
            ['key' => 'oil_treated_at',   'label' => __('transformers.oil_treated_at'),   'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'created_at',       'label' => __('global.created_at'),             'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }

    /**
     * Opciones de cliente (id → nombre) del tenant del usuario actual, para el
     * dropdown del filtro «por cliente». Valores enteros (la columna es bigint;
     * el <a-select> preserva el tipo → Postgres compara int = int sin casts).
     *
     * Sin contexto de tenant (ej. al correr en queue, sin auth) devuelve [];
     * el FilterApplier hace passthrough del value guardado, así el filtro sigue
     * aplicando por customer_id sin necesitar el catálogo.
     *
     * @return array<int, array{value:int, label:string}>
     */
    protected function customerOptions(): array
    {
        $tenantId = auth()->user()?->tenant_id;
        if (!$tenantId) {
            return [];
        }

        return Customer::query()->withoutGlobalScope('tenantOrGlobal')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => (int) $c->id, 'label' => mb_strtoupper((string) $c->name)])
            ->all();
    }

    public function fetch(Automation $automation): Collection
    {
        // withoutGlobalScopes() quita el scope de tenant (corremos en queue sin
        // auth) PERO tambien el de SoftDeletes → excluimos los borrados a mano.
        // Los trafos no se desactivan (siempre activos); solo se eliminan.
        $query = Transformer::query()->withoutGlobalScopes()
            ->where('tenant_id', $automation->tenant_id)
            ->whereNull('deleted_at');

        FilterApplier::apply($query, $automation->data_filter ?? [], $this->fields());

        $limit = (int) ($automation->data_filter['limit'] ?? 100);
        return $query->limit($limit)->get();
    }
}
