<?php

namespace App\Http\Requests\BusinessManagement\Person;

use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdatePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('people.edit') ?? false;
    }

    public function rules(): array
    {
        // edit_all_max define cuantas filas se pueden tocar en un solo batch.
        $max = (int) config('people.edit_all_max', 200);

        return [
            'changes'             => "required|array|min:1|max:{$max}",
            'changes.*.id'        => 'required|integer',
            // name aceptado como sometimes (cliente puede mandar solo is_active),
            // pero si viene, NO puede ser empty string ni null. Sin min:1 antes
            // un cliente podía mandar name:"" y el person quedaba sin nombre
            // (rompía unicidad y búsqueda).
            'changes.*.name'      => 'sometimes|required|string|min:1|max:255',
            // El apellido también se corrige en lote: en la migración del v1
            // llegaron muchos nombres y apellidos cruzados.
            'changes.*.lastname'  => 'sometimes|required|string|min:1|max:255',
            'changes.*.is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
