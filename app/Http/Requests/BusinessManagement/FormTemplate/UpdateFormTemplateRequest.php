<?php

namespace App\Http\Requests\BusinessManagement\FormTemplate;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
class UpdateFormTemplateRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'form_templates';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        $formTemplate = $this->route('formTemplate');
        if (is_object($formTemplate) && $formTemplate->is_locked) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        $formTemplate   = $this->route('formTemplate');
        $formTemplateId = is_object($formTemplate) ? $formTemplate->id : null;

        return [
            // Unicidad de name case + accent insensitive PER-TENANT, ignorando el
            // propio formTemplate y soft-deleted. Se filtra por tenant_id para alinear con
            // el indice unico parcial (tenant_id, name) de la tabla.
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($formTemplateId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('form_templates')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->when($formTemplateId, fn ($qq) => $qq->where('id', '!=', $formTemplateId));
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('form_templates.name_unique'));
                    }
                },
            ],
            'code'       => [
                'nullable', 'string', 'max:40',
                function ($attribute, $value, $fail) use ($formTemplateId) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('form_templates')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->when($formTemplateId, fn ($qq) => $qq->where('id', '!=', $formTemplateId))
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('form_templates.code_unique'));
                    }
                },
            ],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
