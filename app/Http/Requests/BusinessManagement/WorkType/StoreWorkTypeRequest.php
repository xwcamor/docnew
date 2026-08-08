<?php

namespace App\Http\Requests\BusinessManagement\WorkType;

use App\Http\Requests\BusinessManagement\WorkType\Concerns\ValidatesWorkType;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkTypeRequest extends FormRequest
{
    use DerivesAttributesFromLang;
    use ValidatesWorkType;

    protected $attributeNamespace = 'work_types';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->workTypeRules();
    }
}
