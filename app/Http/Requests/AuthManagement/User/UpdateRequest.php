<?php

// Namespace
namespace App\Http\Requests\AuthManagement\User;

// Use Illuminates
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Main class
class UpdateRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'users';

    // Authorize
    public function authorize(): bool
    {
        // Allow request
        return true;
    }

    /**
     * Cross-tenant IDOR guard: solo super puede mover usuarios entre workspaces.
     * Para admin (no super) forzamos `tenant_id` al del target user — así no
     * puede cambiar el tenant_id del registro existente vía PUT.
     */
    protected function prepareForValidation(): void
    {
        $actor  = $this->user();
        $target = $this->route('user');
        if ($actor && !$actor->hasRole('super')) {
            $this->merge(['tenant_id' => $target?->tenant_id]);
        }
    }

    // Rules
    public function rules(): array
    {
        // Capture model for Route Model Binding
        $user = $this->route('user');

        // Limite de tamaño KB del setting `uploads.user_photo_max_mb`.
        $maxKb = \App\Models\Setting::getInt('uploads.user_photo_max_mb', 2) * 1024;

        // tenant_id efectivo (lo forzó prepareForValidation si no es super).
        $tenantId = $this->input('tenant_id', $user?->tenant_id);

        // Email único POR TENANT, ignorando soft-deleted y el propio user.
        $emailUnique = Rule::unique('users', 'email')
            ->ignore($user?->id)
            ->where(function ($q) use ($tenantId) {
                $tenantId === null
                    ? $q->whereNull('tenant_id')
                    : $q->where('tenant_id', $tenantId);
            })
            ->whereNull('deleted_at');

        // Validations
        return [
            'name'       => 'required|string|max:255',
            'email'      => ['required', 'email', $emailUnique],
            'password'   => 'nullable|string|min:6',
            'photo'      => 'nullable|image|mimes:jpg,jpeg,png,gif|max:' . $maxKb,
            'country_id' => 'required|integer|exists:countries,id',
            'locale_id'  => 'required|integer|exists:locales,id',
            // Workspace obligatorio: no existen usuarios globales. Admin lo trae
            // forzado por prepareForValidation; super debe elegirlo en el form.
            'tenant_id'  => 'required|integer|exists:tenants,id',
            'is_active'  => 'nullable|boolean',
            'role_id'    => 'required|integer|exists:roles,id',
            'assigned_customer_ids'   => 'nullable|array',
            'assigned_customer_ids.*' => 'integer|exists:customers,id',
        ];
    }

    // Messages
    public function messages(): array
    {
        // Validation Messages
        return [
            'name.max'           => 'El nombre debe tener como máximo 255 caracteres.',
            'email.unique'       => 'El correo electrónico ya existe.',
            'password.min'       => 'La contraseña debe tener al menos 6 caracteres.',
            'photo.image'        => 'El archivo debe ser una imagen válida.',
            'photo.mimes'        => 'La imagen debe ser de tipo: jpg, jpeg, png o gif.',
            'photo.max'          => 'La imagen no debe superar los 2 MB.',
            'country_id.required'=> 'El país es obligatorio.',
            'country_id.exists'  => 'El país seleccionado no es válido.',
            'locale_id.required' => 'El idioma es obligatorio.',
            'locale_id.exists'   => 'El idioma seleccionado no es válido.',
            'tenant_id.required' => __('tenants.required'),
            'tenant_id.exists'   => 'El workspace seleccionado no es válido.',
            'role_id.required'   => 'El perfil es obligatorio.',
            'role_id.exists'     => 'El perfil seleccionado no es válido.',
        ];
    }

}