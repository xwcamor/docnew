<?php

namespace App\Http\Requests\BusinessManagement\Person;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
class UpdatePersonRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'people';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        $person = $this->route('person');
        if (is_object($person) && $person->is_locked) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        $person   = $this->route('person');
        $personId = is_object($person) ? $person->id : null;

        return [
            // Unicidad de name case + accent insensitive PER-TENANT, ignorando el
            // propio person y soft-deleted. Se filtra por tenant_id para alinear con
            // el indice unico parcial (tenant_id, name) de la tabla.
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($personId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('people')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->when($personId, fn ($qq) => $qq->where('id', '!=', $personId));
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('people.name_unique'));
                    }
                },
            ],
            'num_doc'       => [
                'nullable', 'string', 'max:40',
                function ($attribute, $value, $fail) use ($personId) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('people')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->when($personId, fn ($qq) => $qq->where('id', '!=', $personId))
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('people.num_doc_unique'));
                    }
                },
            ],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
