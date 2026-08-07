<?php

namespace App\Http\Requests\AuthManagement\Role;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetActiveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user !== null && $user->hasAnyRole(['super', 'admin']);
    }

    public function rules(): array
    {
        return [
            'ids'       => ['required', 'array', 'min:1', 'max:500'],
            'ids.*'     => ['integer'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required'       => __('global.bulk_no_selection'),
            'is_active.required' => __('roles.is_active_required'),
        ];
    }
}
