<?php

namespace App\Http\Requests\BusinessManagement\WorkPlan;

use Illuminate\Foundation\Http\FormRequest;

class DeleteWorkPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        $workPlan = $this->route('workPlan');
        if (is_object($workPlan) && $workPlan->is_locked) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        return [
            'deleted_description' => 'required|string|min:3|max:1000',
        ];
    }
}
