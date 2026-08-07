<?php

namespace App\Http\Requests\SystemManagement\Region;

use Illuminate\Foundation\Http\FormRequest;

class BulkRestoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El controller hace un abort_unless(super, 403) adicional como
        // defense-in-depth; este authorize() está aquí por consistencia con el
        // patrón de FormRequest (la lógica real vive en el middleware role).
        return $this->user()?->hasRole('super') ?? false;
    }

    public function rules(): array
    {
        return [
            'ids'   => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => __('global.bulk_no_selection'),
        ];
    }
}
