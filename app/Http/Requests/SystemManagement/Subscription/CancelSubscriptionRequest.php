<?php

namespace App\Http\Requests\SystemManagement\Subscription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            // mode=cancel: deja usar hasta ends_at | mode=suspend: corta acceso ya.
            'mode'   => ['required', Rule::in(['cancel', 'suspend'])],
        ];
    }
}
