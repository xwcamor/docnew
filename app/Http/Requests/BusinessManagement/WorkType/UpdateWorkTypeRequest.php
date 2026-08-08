<?php

namespace App\Http\Requests\BusinessManagement\WorkType;

use App\Http\Requests\BusinessManagement\WorkType\Concerns\ValidatesWorkType;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkTypeRequest extends FormRequest
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
        $tipo = $this->route('workType');

        return $this->workTypeRules(is_object($tipo) ? $tipo->id : null);
    }
}
