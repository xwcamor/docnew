<?php

namespace App\Http\Requests\SystemManagement\Tenant;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // edit_all_max define cuántas filas se pueden tocar en un solo batch.
        // Por encima de eso forzaríamos N validaciones de unicidad → DB pool burn.
        $max = (int) config('tenants.edit_all_max', 200);
        $planSlugs = Plan::activeSlugs() ?: ['free'];

        return [
            'changes'              => "required|array|min:1|max:{$max}",
            'changes.*.id'         => 'required|integer|exists:tenants,id,deleted_at,NULL',
            'changes.*.name'      => 'sometimes|required|string|min:1|max:255',
            'changes.*.plan'      => 'sometimes|nullable|in:' . implode(',', $planSlugs),
            'changes.*.type'      => 'sometimes|nullable|in:business,personal',
            'changes.*.is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
