<?php

namespace App\Http\Requests\AuthManagement\Role;

use Illuminate\Foundation\Http\FormRequest;

class BulkRestoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // bulk_restore es operacion super only — alineado con bulk_restore
        // de Customers/Regions/Automations.
        return $this->user()?->hasRole('super') ?? false;
    }

    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => __('global.bulk_no_selection'),
        ];
    }
}
