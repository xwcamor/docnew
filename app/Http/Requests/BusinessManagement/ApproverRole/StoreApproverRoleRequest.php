<?php

namespace App\Http\Requests\BusinessManagement\ApproverRole;

use App\Http\Requests\BusinessManagement\ApproverRole\Concerns\NormalizesApproverRoleCode;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;

class StoreApproverRoleRequest extends FormRequest
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
        return [
            'code'       => $this->codeRules(),
            'name_es'    => 'required|string|max:60',
            // El nombre en ingles ya no se pide: lo que se traduce es la
            // aplicacion, no lo que escribe el cliente. La columna sigue —hay
            // roles guardados con ella— y el modelo cae al castellano cuando
            // esta vacia, asi que dejarla en nulo no vacia la pantalla inglesa.
            'name_en'    => 'nullable|string|max:60',
            'sort_order' => 'nullable|integer|min:1|max:9999',
            'is_active'  => 'sometimes|boolean',
        ];
    }
}
