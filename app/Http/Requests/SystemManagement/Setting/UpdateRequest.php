<?php

namespace App\Http\Requests\SystemManagement\Setting;

use App\Rules\UniqueNormalizedName;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'settings';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('key')) {
            $this->merge(['key' => strtolower(trim($this->key))]);
        }
    }

    public function rules(): array
    {
        $setting = $this->route('setting');

        return [
            'key' => [
                'required', 'string', 'max:100',
                'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/',
                Rule::unique('settings', 'key')->ignore($setting?->id)->whereNull('deleted_at'),
            ],
            'name' => [
                'required', 'string', 'max:255',
                new UniqueNormalizedName('settings', 'name', ignoreId: $setting?->id),
            ],
            'type'        => ['required', Rule::in(\App\Models\Setting::TYPES)],
            'value'       => ['nullable', 'string', $this->valueMatchesType()],
            'group'       => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_secret'   => ['nullable', 'boolean'],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('settings.name_required'),
            'key.required'  => __('settings.key_required'),
            'key.regex'     => __('settings.key_regex'),
            'key.unique'    => __('settings.key_unique'),
            'type.required' => __('settings.type_required'),
            'type.in'       => __('settings.type_invalid'),
        ];
    }

    /**
     * El `value` se guardaba como texto sin mirar el `type`, así que un ajuste
     * declarado `int` aceptaba «no sé» y `Setting::getInt()` lo leía como 0.
     * Aquí se exige que el valor CUADRE con su tipo y, para los ajustes que el
     * sistema lee de verdad, que caiga dentro de un margen con sentido
     * (`Setting::VALUE_LIMITS`).
     *
     * El caso que motiva el margen: `docufiz.num_doc_minimum` en 0 convierte el
     * buscador de personas en un volcado del padrón.
     */
    private function valueMatchesType(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $type = (string) $this->input('type');
            $key  = (string) $this->input('key');
            $raw  = is_string($value) ? trim($value) : $value;

            // Vacío = «sin valor». Se permite: el ajuste queda sin poner y el
            // código que lo lee usa su valor por defecto.
            if ($raw === null || $raw === '') return;

            $limits = \App\Models\Setting::limitsFor($key);

            if ($type === 'int') {
                if (!preg_match('/^-?\d+$/', (string) $raw)) {
                    $fail(__('settings.value_not_int'));
                    return;
                }
            } elseif ($type === 'bool') {
                if (!in_array(mb_strtolower((string) $raw), ['1', '0', 'true', 'false'], true)) {
                    $fail(__('settings.value_not_bool'));
                    return;
                }
            } elseif ($type === 'json') {
                json_decode((string) $raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $fail(__('settings.value_not_json'));
                    return;
                }
            } elseif ($limits && !empty($limits['decimal'])) {
                // Ajuste numérico guardado como texto (el umbral facial).
                if (!is_numeric($raw)) {
                    $fail(__('settings.value_not_number'));
                    return;
                }
            }

            if (!$limits) return;

            if (!is_numeric($raw)) return;  // tipos no numéricos: nada que acotar

            $n = (float) $raw;
            if ($n < $limits['min'] || $n > $limits['max']) {
                $fail(__('settings.value_out_of_range', [
                    'key' => $key,
                    'min' => $this->formatLimit($limits['min'], $limits),
                    'max' => $this->formatLimit($limits['max'], $limits),
                ]));
            }
        };
    }

    private function formatLimit(int|float $n, array $limits): string
    {
        return !empty($limits['decimal']) ? number_format((float) $n, 2, ',', '') : (string) (int) $n;
    }
}
