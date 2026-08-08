<?php

namespace App\Http\Requests\BusinessManagement\WorkType;

use Illuminate\Foundation\Http\FormRequest;

class DeleteWorkTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'deleted_description' => 'required|string|min:3|max:1000',
        ];
    }
}
