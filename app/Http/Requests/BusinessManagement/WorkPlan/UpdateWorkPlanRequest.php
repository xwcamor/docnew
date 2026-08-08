<?php

namespace App\Http\Requests\BusinessManagement\WorkPlan;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateWorkPlanRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'work_plans';

    // Los FK se rotulan con el mismo texto que el label del formulario, para
    // que el error diga "Empresa" y no "company id".
    protected $attributeOverrides = [
        'company_id'       => 'work_plans.company',
        'work_type_id'     => 'work_plans.work_type',
        'work_location_id' => 'work_plans.work_location',
        'workstation_id'   => 'work_plans.workstation',
        'work_area_id'     => 'work_plans.work_area',
    ];

    public function authorize(): bool
    {
        // Dos cierres distintos: el candado administrativo del trait Lockable,
        // y el cierre del propio trabajo del día. Cualquiera de los dos deja el
        // plan en solo lectura.
        $workPlan = $this->route('workPlan');

        if (is_object($workPlan) && ($workPlan->is_locked || $workPlan->is_closed)) {
            return false;
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => trim((string) $this->code)]);
        }
    }

    public function rules(): array
    {
        $workPlan   = $this->route('workPlan');
        $workPlanId = is_object($workPlan) ? $workPlan->id : null;

        return [
            // Unicidad del código por tenant, ignorando el propio plan y los
            // soft-deleted — alinea con el índice parcial UPPER(code).
            'code' => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($workPlanId) {
                    $exists = DB::table('work_plans')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->when($workPlanId, fn ($qq) => $qq->where('id', '!=', $workPlanId))
                        ->whereRaw('UPPER(code) = UPPER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('work_plans.code_unique'));
                    }
                },
            ],
            'num_os'           => ['nullable', 'string', 'max:255'],
            'description'      => ['required', 'string', 'max:5000'],
            'company_id'       => ['required', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'work_type_id'     => ['required', 'integer', Rule::exists('work_types', 'id')->whereNull('deleted_at')],
            'work_location_id' => ['required', 'integer', Rule::exists('work_locations', 'id')->whereNull('deleted_at')],
            // Obligatorios, como en el sistema anterior: alli `workstation_id`,
            // `area_id` y `date_start` son NOT NULL. Un plan sin sitio o sin
            // hora de inicio no describe ningun trabajo, y ademas el codigo del
            // plan se construye con la fecha de inicio.
            'workstation_id'   => ['required', 'integer', Rule::exists('workstations', 'id')->whereNull('deleted_at')],
            'work_area_id'     => ['required', 'integer', Rule::exists('work_areas', 'id')->whereNull('deleted_at')],
            'date_start'       => ['required', 'date'],
            // Los dos unicos opcionales, y por un motivo: la orden de servicio
            // puede no existir, y la hora de fin no se sabe hasta que el
            // trabajo termina. Exigirla al crear haria imposible crear un plan.
            'date_end'         => ['nullable', 'date', 'after_or_equal:date_start'],
            // `is_done` ya no se manda desde el formulario: lo calcula
            // WorkPlanCompletionService cuando el plan esta completo. Se sigue
            // aceptando porque la edicion masiva lo usa.
            'is_done'          => ['sometimes', 'boolean'],
        ];
    }
}
