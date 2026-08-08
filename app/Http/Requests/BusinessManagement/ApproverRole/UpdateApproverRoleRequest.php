<?php

namespace App\Http\Requests\BusinessManagement\ApproverRole;

use App\Http\Requests\BusinessManagement\ApproverRole\Concerns\NormalizesApproverRoleCode;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;

class UpdateApproverRoleRequest extends FormRequest
{
    use DerivesAttributesFromLang;
    use NormalizesApproverRoleCode;

    protected $attributeNamespace = 'approver_roles';

    protected function prepareForValidation(): void
    {
        $this->normalizeCode();
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rol = $this->route('approverRole');

        return [
            'code'       => $this->codeRules(is_object($rol) ? $rol->id : null),
            'name_es'    => 'required|string|max:60',
            'name_en'    => 'required|string|max:60',
            'sort_order' => 'nullable|integer|min:1|max:9999',
            'is_active'  => 'sometimes|boolean',
        ];
    }
}
