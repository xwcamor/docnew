<?php

namespace App\Http\Requests\SystemManagement\Tenant;

use App\Rules\UniqueNormalizedName;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update de un tenant — name / logo / is_active.
 *
 * El `plan` NO se edita desde aquí: el plan se deriva de la suscripción
 * vigente. Los cambios de plan van por el tab Suscripción
 * (create / renew / cancel / suspend). Si llega un `plan` en el payload, se
 * ignora — no está en las reglas.
 */
class UpdateRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'tenants';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = $this->route('tenant');
        $allowedTimezones = \App\Support\Tz::availableTimezones();

        return [
            'name' => [
                'required', 'string', 'max:255',
                new UniqueNormalizedName('tenants', 'name', ignoreId: $tenant?->id),
            ],
            // logo viene como string (path) en update o file en upload-replace.
            // El controller decide cómo procesar — aquí solo validamos el tipo.
            'logo'      => 'nullable',
            // Membrete de los informes PDF (dirección + disclaimer legal de la empresa).
            'address'            => ['nullable', 'string', 'max:255'],
            'report_disclaimer'  => ['nullable', 'string', 'max:2000'],
            'report_approver'    => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            // Editable por super desde el form. nullable porque podría
            // dejarse vacío y el booted() lo auto-derive.
            'timezone'  => ['nullable', 'string', 'in:' . implode(',', $allowedTimezones)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => __('tenants.name_required'),
            'is_active.required' => __('tenants.is_active_required'),
        ];
    }
}
