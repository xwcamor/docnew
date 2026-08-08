<?php

namespace App\Http\Requests\BusinessManagement\WorkPlan;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreWorkPlanRequest extends FormRequest
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
        return true;
    }

    /**
     * El país no se pide en el formulario: un plan pertenece al país del
     * usuario que lo registra, igual que en el sistema v1.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('country_id')) {
            $this->merge(['country_id' => $this->user()?->country_id]);
        }
        if ($this->filled('code')) {
            $this->merge(['code' => trim((string) $this->code)]);
        }
    }

    public function rules(): array
    {
        return [
            // El código NO se pide: lo pone el sistema al guardar (correlativo
            // del día, ver WorkPlanCodeGenerator). Se sigue aceptando si viene
            // —importaciones, datos migrados— y entonces sí se valida único,
            // porque el índice parcial UPPER(code) de la tabla lo exige igual.
            'code' => [
                'nullable', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    $exists = DB::table('work_plans')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->whereRaw('UPPER(code) = UPPER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('work_plans.code_unique'));
                    }
                },
            ],
            'num_os'           => ['nullable', 'string', 'max:255'],
            'description'      => ['required', 'string', 'max:5000'],
            'country_id'       => ['required', 'integer', Rule::exists('countries', 'id')],
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
