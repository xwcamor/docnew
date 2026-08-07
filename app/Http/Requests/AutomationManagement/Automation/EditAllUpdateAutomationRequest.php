<?php

namespace App\Http\Requests\AutomationManagement\Automation;

use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // edit_all_max define cuántas filas se pueden tocar en un solo batch.
        // 200 alineado con Regions; suficiente para uso normal.
        $max = (int) config('automations.edit_all_max', 200);

        return [
            'changes'             => "required|array|min:1|max:{$max}",
            'changes.*.id'        => 'required|integer|exists:automations,id,deleted_at,NULL',
            'changes.*.name'      => 'sometimes|required|string|min:1|max:150',
            'changes.*.is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
