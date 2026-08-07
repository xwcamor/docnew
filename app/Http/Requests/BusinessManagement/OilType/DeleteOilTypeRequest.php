<?php

namespace App\Http\Requests\BusinessManagement\OilType;

use Illuminate\Foundation\Http\FormRequest;

class DeleteOilTypeRequest extends FormRequest
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
