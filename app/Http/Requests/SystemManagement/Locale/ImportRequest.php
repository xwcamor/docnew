<?php

namespace App\Http\Requests\SystemManagement\Locale;

use Illuminate\Foundation\Http\FormRequest;

class ImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 10 MB cubre CSVs de ~200k filas; Excel pesa más por XML inflado.
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
