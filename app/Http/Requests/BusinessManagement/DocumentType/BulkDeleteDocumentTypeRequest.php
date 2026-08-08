<?php

namespace App\Http\Requests\BusinessManagement\DocumentType;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El middleware permission:document_types.delete ya gatea la ruta — este
        // authorize() esta aqui por consistencia con el patron FormRequest.
        return $this->user()?->can('document_types.delete') ?? false;
    }

    public function rules(): array
    {
        return [
            'ids'                 => 'required|array|min:1|max:500',
            'ids.*'               => 'integer',
            'deleted_description' => 'required|string|min:3|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'deleted_description.required' => __('document_types.deleted_description_required'),
            'deleted_description.min'      => __('document_types.deleted_description_min'),
        ];
    }
}
