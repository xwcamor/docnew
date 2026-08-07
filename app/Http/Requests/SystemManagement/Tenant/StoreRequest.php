<?php

namespace App\Http\Requests\SystemManagement\Tenant;

use App\Models\Plan;
use App\Rules\UniqueNormalizedName;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'tenants';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Slugs de planes activos desde DB (super gestiona desde módulo
        // Plans). Reemplaza config('features.plans') hardcoded.
        $planSlugs = Plan::activeSlugs() ?: ['free'];

        // Lista de TZ válidos — la misma que usamos en el selector del front.
        $allowedTimezones = \App\Support\Tz::availableTimezones();

        // Limite del logo en KB desde el setting `uploads.tenant_logo_max_mb`.
        $maxLogoKb = \App\Models\Setting::getInt('uploads.tenant_logo_max_mb', 2) * 1024;

        return [
            // Workspace
            'name' => [
                'required', 'string', 'max:255',
                new UniqueNormalizedName('tenants', 'name'),
            ],
            'logo'      => 'nullable|image|mimes:jpg,jpeg,png,webp|max:' . $maxLogoKb,
            // Membrete de los informes PDF (dirección + disclaimer legal de la empresa).
            'address'            => ['nullable', 'string', 'max:255'],
            'report_disclaimer'  => ['nullable', 'string', 'max:2000'],
            'report_approver'    => ['nullable', 'string', 'max:120'],
            'plan'      => 'nullable|in:' . implode(',', $planSlugs),
            'is_active' => ['nullable', 'boolean'],
            // TZ opcional — si no se manda, Tenant::booted() lo auto-fillea
            // desde el country del creator.
            'timezone'  => ['nullable', 'string', 'in:' . implode(',', $allowedTimezones)],

            // Admin del workspace — OBLIGATORIO. Un workspace sin admin es un
            // estado inconsistente: nadie podría gestionar usuarios, asignar
            // perfiles ni operar la cuenta. Forzamos un admin desde el día 0.
            'admin_email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_name'     => ['required', 'string', 'max:255'],
            'admin_password' => ['required', 'string', 'min:6', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'           => __('tenants.name_required'),
            'logo.image'              => __('tenants.logo_image'),
            'logo.mimes'              => __('tenants.logo_mimes'),
            'logo.max'                => __('tenants.logo_max'),
            'admin_email.required'    => __('tenants.admin_email_required'),
            'admin_email.unique'      => __('tenants.admin_email_taken'),
            'admin_name.required'     => __('tenants.admin_name_required'),
            'admin_password.required' => __('tenants.admin_password_required'),
            'admin_password.min'      => __('tenants.admin_password_min'),
        ];
    }
}
