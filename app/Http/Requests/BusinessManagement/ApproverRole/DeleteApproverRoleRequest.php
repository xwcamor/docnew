<?php

namespace App\Http\Requests\BusinessManagement\ApproverRole;

use Illuminate\Foundation\Http\FormRequest;

class DeleteApproverRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Que el rol se pueda borrar (que nadie lo use, que no sea del sistema)
        // lo decide el servicio: aquí solo se exige el motivo.
        return true;
    }

    public function rules(): array
    {
        return [
            'deleted_description' => 'required|string|min:3|max:1000',
        ];
    }
}
