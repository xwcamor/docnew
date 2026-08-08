<?php

namespace App\Http\Requests\BusinessManagement\ApprovalRule;

use App\Http\Requests\BusinessManagement\ApprovalRule\Concerns\ValidatesApprovalRule;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;

class StoreApprovalRuleRequest extends FormRequest
{
    use DerivesAttributesFromLang;
    use ValidatesApprovalRule;

    protected $attributeNamespace = 'approval_rules';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->ruleRules();
    }
}
