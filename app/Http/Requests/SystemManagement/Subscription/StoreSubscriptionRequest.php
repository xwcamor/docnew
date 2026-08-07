<?php

namespace App\Http\Requests\SystemManagement\Subscription;

use App\Models\Plan;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation para creación manual de subscription (super desde UI).
 *
 * Acepta dos modos:
 *   - kind=paid:  starts_at + ends_at + amount + payment_method
 *   - kind=trial: starts_at + trial_days (calcula ends_at = starts + N días)
 *
 * El `plan` debe ser un plan activo y NO puede ser `free`: bajo el modelo A2,
 * `free` es la AUSENCIA de suscripción (el piso), no algo a lo que se
 * suscribe. Un tenant sin suscripción vigente ya es `free` por derivación.
 */
class StoreSubscriptionRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'subscriptions';

    public function authorize(): bool
    {
        return true;  // Route protegida por role:super upstream.
    }

    public function rules(): array
    {
        $kind = $this->input('kind', 'paid');

        // Planes activos excepto `free` — no se "suscribe" a free (free es el
        // piso, equivale a la ausencia de suscripción).
        $subscribablePlans = array_values(array_filter(
            Plan::activeSlugs(),
            fn ($slug) => $slug !== 'free',
        )) ?: ['pro'];

        return [
            'kind'           => ['required', Rule::in(['paid', 'trial'])],
            'plan'           => ['required', 'string', Rule::in($subscribablePlans)],
            'starts_at'      => ['nullable', 'date'],

            // Paid: ends_at obligatorio, amount + method recomendados.
            'ends_at'        => [Rule::requiredIf($kind === 'paid'), 'nullable', 'date', 'after:starts_at'],
            'amount_paid'    => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency'       => ['nullable', 'string', 'size:3'],
            'payment_method' => ['nullable', 'string', 'in:manual,bank_transfer,stripe,paddle,cash,other'],

            // Trial: cuántos días.
            'trial_days'     => [Rule::requiredIf($kind === 'trial'), 'nullable', 'integer', 'min:1', 'max:365'],

            'notes'          => ['nullable', 'string', 'max:2000'],
        ];
    }
}
