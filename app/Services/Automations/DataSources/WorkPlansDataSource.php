<?php

namespace App\Services\Automations\DataSources;

use App\Models\Automation;
use App\Models\WorkPlan;
use App\Services\Automations\Contracts\DataSourceContract;
use App\Services\Automations\Support\FilterApplier;
use Illuminate\Support\Collection;

/**
 * Los planes de trabajo como fuente de una automatizacion.
 *
 * Es la unica fuente del dominio de obra que existe. Hasta que se añadio, un
 * supervisor de HSE entraba a Automatizaciones y las dos fuentes disponibles
 * eran «Clientes» —un modulo que ya ni sale en el menu— y «Suscripciones»
 * —facturacion, y solo para el super—. La regla que de verdad quiere
 * («avisame de los planes de ayer que quedaron sin terminar») no se podia
 * armar.
 *
 * Los campos son los que se pueden preguntar sin mirar otra tabla. Empresa,
 * sede y tipo de trabajo se dejan fuera a proposito: son claves ajenas y en el
 * constructor de filtros saldrian como un numero que nadie sabe de memoria.
 *
 * @see docs/AUTOMATIONS.md §8
 */
class WorkPlansDataSource implements DataSourceContract
{
    public function key(): string
    {
        return 'work_plans';
    }

    public function label(): string
    {
        return __('automations.source_work_plans');
    }

    public function allowedRoles(): array
    {
        return []; // cualquiera que pueda crear automatizaciones
    }

    public function fields(): array
    {
        return [
            ['key' => 'code',        'label' => __('work_plans.code'),        'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'num_os',      'label' => __('work_plans.num_os'),      'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'description', 'label' => __('work_plans.description'), 'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'date_start',  'label' => __('work_plans.date_start'),  'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'date_end',    'label' => __('work_plans.date_end'),    'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'is_done',     'label' => __('work_plans.is_done'),     'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'is_closed',   'label' => __('work_plans.is_closed'),   'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at',  'label' => __('global.created_at'),      'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }

    public function fetch(Automation $automation): Collection
    {
        // El job corre en la cola, sin sesion: el scope de workspace no aplica
        // solo y hay que ponerlo a mano. `withoutGlobalScopes()` tambien quita
        // el de borrados, asi que se excluyen aparte.
        $query = WorkPlan::query()->withoutGlobalScopes()
            ->where('tenant_id', $automation->tenant_id)
            ->whereNull('deleted_at');

        FilterApplier::apply($query, $automation->data_filter ?? [], $this->fields());

        $limit = (int) ($automation->data_filter['limit'] ?? 100);

        return $query->orderByDesc('date_start')->limit($limit)->get();
    }
}
