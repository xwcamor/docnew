<?php

namespace App\Http\Requests\SystemManagement\Language;

use App\Http\Requests\Concerns\ValidatesEditAllUniqueness;
use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateRequest extends FormRequest
{
    use ValidatesEditAllUniqueness;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $max = (int) config('languages.edit_all_max', 200);

        return [
            'changes'              => "required|array|min:1|max:{$max}",
            'changes.*.id'         => 'required|integer|exists:languages,id,deleted_at,NULL',
            'changes.*.name'       => 'sometimes|required|string|min:1|max:255',
            'changes.*.iso_code'   => 'sometimes|required|string|min:1|max:10|regex:/^[a-z]{2}(_[A-Z]{2})?$/',
            'changes.*.is_active'  => 'sometimes|nullable|boolean',
        ];
    }

    /**
     * La unicidad, que en «Editar todo» no la comprobaba nadie: el valor
     * repetido llegaba al índice de Postgres y salía un 500 con el lote
     * entero perdido. El porqué y la forma exacta, en el trait.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(fn ($v) => $this->comprobarUnicidadDelLote($v, [
            ['campo' => 'name', 'tabla' => 'languages', 'mensaje' => 'languages.name_unique'],
            ['campo' => 'iso_code', 'tabla' => 'languages', 'mensaje' => 'languages.iso_code_unique', 'tildes' => false],
        ]));
    }
}
