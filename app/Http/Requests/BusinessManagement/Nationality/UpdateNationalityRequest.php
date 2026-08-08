<?php

namespace App\Http\Requests\BusinessManagement\Nationality;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNationalityRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'nationalities';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => trim((string) $this->input('code'))]);
        }

        // Un interruptor apagado no viaja como `false`: viaja ausente. Si se
        // deja pasar, la regla `sometimes` conserva el valor anterior y lo que
        // alguien acaba de desmarcar sigue encendido despues de guardar.
        foreach (['is_active'] as $interruptor) {
            $this->merge([$interruptor => $this->boolean($interruptor)]);
        }
    }

    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:60',
                Rule::unique('nationalities', 'code')
                    ->where(fn ($q) => $q->where('country_id', $this->input('country_id'))
                        ->whereNull('deleted_at'))->ignore($this->route('nationality')?->id)],
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => __('nationalities.code_required'),
            'code.unique'   => __('nationalities.code_unique'),
        ];
    }
}
