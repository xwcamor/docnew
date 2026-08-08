<?php

namespace App\Http\Requests\BusinessManagement\DocumentType;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetActiveDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('document_types.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'ids'       => 'required|array|min:1|max:500',
            'ids.*'     => 'integer',
            'is_active' => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'is_active.required' => __('document_types.is_active_required'),
        ];
    }
}
