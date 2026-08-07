<?php

namespace App\Http\Requests\AuthManagement\Role;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user !== null && $user->hasAnyRole(['super', 'admin']);
    }

    public function rules(): array
    {
        return [
            'ids'                 => ['required', 'array', 'min:1', 'max:500'],
            'ids.*'               => ['integer'],
            'deleted_description' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required'                 => __('global.bulk_no_selection'),
            'deleted_description.required' => __('global.delete_reason_required'),
            'deleted_description.min'      => __('global.delete_reason_min_3'),
        ];
    }
}
