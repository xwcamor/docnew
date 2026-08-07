<?php

namespace App\Http\Requests\AuthManagement\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validacion del upload de imports de users. Clon del ImportCustomerRequest.
 */
class ImportUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 10 MB cubre CSVs de ~200k filas; Excel pesa mas por XML inflado.
            'file'    => 'required|file|mimes:xlsx,xls,csv,txt|max:10240',
            'mode'    => 'nullable|in:create_only,update_or_create',
            'dry_run' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => __('imports.file_required'),
            'file.mimes'    => __('imports.file_mimes'),
            'file.max'      => __('imports.file_max'),
        ];
    }
}
