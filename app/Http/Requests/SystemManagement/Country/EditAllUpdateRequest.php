<?php

namespace App\Http\Requests\SystemManagement\Country;

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
        $max = (int) config('countries.edit_all_max', 200);

        return [
            'changes'                       => "required|array|min:1|max:{$max}",
            'changes.*.id'                  => 'required|integer|exists:countries,id,deleted_at,NULL',
            'changes.*.name'                => 'sometimes|required|string|min:1|max:255',
            'changes.*.iso_code'            => 'sometimes|nullable|string|size:2|regex:/^[A-Za-z]{2}$/',
            'changes.*.currency'            => 'sometimes|nullable|string|size:3|regex:/^[A-Za-z]{3}$/',
            // La zona horaria se valida contra IANA, igual que en el formulario.
            // Aquí no se validaba y en «Editar todo» la zona es una caja de texto
            // libre, así que se guardaba tal cual: `Nowhere/Nothing` entraba en la
            // base sin una queja. El fallo no sale al guardar, sale después y en
            // otra pantalla: App\Support\Tz resuelve la zona de quien no tiene una
            // propia por la de su país, y Carbon::setTimezone con una zona que no
            // existe lanza InvalidTimeZoneException — un 500 en cada pantalla que
            // pinte una fecha, por un dedazo en un listado.
            'changes.*.timezone'            => ['sometimes', 'required', 'string', 'max:64', $this->zonaIana()],
            'changes.*.region_id'           => 'sometimes|nullable|integer|exists:regions,id,deleted_at,NULL',
            'changes.*.default_locale_id'   => 'sometimes|nullable|integer|exists:locales,id,deleted_at,NULL',
            'changes.*.is_active'           => 'sometimes|nullable|boolean',
        ];
    }

    /** Misma comprobación que Store/UpdateRequest: la zona tiene que existir. */
    protected function zonaIana(): \Closure
    {
        return function ($attr, $value, $fail) {
            if (! in_array($value, \DateTimeZone::listIdentifiers(), true)) {
                $fail(__('countries.timezone_invalid'));
            }
        };
    }
}
