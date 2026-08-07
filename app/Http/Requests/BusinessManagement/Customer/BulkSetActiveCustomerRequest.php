<?php

namespace App\Http\Requests\BusinessManagement\Customer;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetActiveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Un usuario acotado a su cartera asignada es solo-lectura en Clientes.
        return empty($this->user()?->assignedCustomerIds())
            && ($this->user()?->can('customers.edit') ?? false);
    }

    public function rules(): array
    {
        return [
            'ids'       => 'required|array|min:1|max:500',
            'ids.*'     => 'integer',
            'is_active' => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required'       => __('global.bulk_no_selection'),
            'is_active.required' => __('customers.is_active_required'),
        ];
    }
}
