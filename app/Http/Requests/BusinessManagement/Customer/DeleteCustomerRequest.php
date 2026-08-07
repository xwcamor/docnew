<?php

namespace App\Http\Requests\BusinessManagement\Customer;

use Illuminate\Foundation\Http\FormRequest;

class DeleteCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Solo-lectura en Clientes para un usuario acotado a su cartera asignada.
        if (! empty($this->user()?->assignedCustomerIds())) {
            return false;
        }
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        $customer = $this->route('customer');
        if (is_object($customer) && $customer->is_locked) {
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
