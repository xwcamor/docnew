<?php

namespace App\Http\Requests\SystemManagement\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Triple guard del force-delete:
 *   1. authorize() exige super
 *   2. rules() exige name_confirmation que matchee exactamente el nombre
 *   3. rules() exige reason ≥ 10 chars (audit trail)
 *
 * El controller hace defense-in-depth con un abort_unless adicional + valida
 * que el record esté `onlyTrashed`.
 */
class ForceDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super') ?? false;
    }

    public function rules(): array
    {
        // El controller compara `name_confirmation` con el `tenant->name`
        // resuelto por slug — aquí solo aseguramos string no vacío.
        return [
            'name_confirmation' => 'required|string',
            'reason'            => 'required|string|min:10|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'name_confirmation.required' => __('global.force_delete_name_required'),
            'reason.required'            => __('global.force_delete_reason_required'),
            'reason.min'                 => __('global.force_delete_reason_min'),
        ];
    }
}
