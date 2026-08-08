<?php

namespace App\Http\Requests\BusinessManagement\ApprovalRule;

use App\Http\Requests\BusinessManagement\ApprovalRule\Concerns\ValidatesApprovalRule;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;

class UpdateApprovalRuleRequest extends FormRequest
{
    use DerivesAttributesFromLang;
    use ValidatesApprovalRule;

    protected $attributeNamespace = 'approval_rules';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        // Va aqui y no en el controlador porque `authorize()` corre ANTES de
        // validar: si no, un cuerpo invalido devolveria «falta el nombre» en
        // vez de «esta bloqueado», que es lo que pasa de verdad.
        $approvalRule = $this->route('approvalRule');
        if (is_object($approvalRule) && $approvalRule->is_locked) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        $regla = $this->route('approvalRule');

        return $this->ruleRules(is_object($regla) ? $regla->id : null);
    }
}
