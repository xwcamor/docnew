<?php

namespace App\Http\Requests\BusinessManagement\TapChangerBrand;

use Illuminate\Foundation\Http\FormRequest;

class ForceDeleteTapChangerBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super') ?? false;
    }

    public function rules(): array
    {
        return [
            'name_confirmation' => 'required|string',
            'reason'            => 'required|string|min:10|max:500',
        ];
    }
}
