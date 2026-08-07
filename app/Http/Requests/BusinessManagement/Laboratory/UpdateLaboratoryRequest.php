<?php

namespace App\Http\Requests\BusinessManagement\Laboratory;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
class UpdateLaboratoryRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'laboratories';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        $laboratory = $this->route('laboratory');
        if (is_object($laboratory) && $laboratory->is_locked) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        $laboratory   = $this->route('laboratory');
        $laboratoryId = is_object($laboratory) ? $laboratory->id : null;

        return [
            // Unicidad de name case + accent insensitive (catalogo GLOBAL, sin
            // tenant), ignorando el propio laboratory y soft-deleted. NO se filtra
            // por tenant_id: la tabla no tiene esa columna (filtrarla revienta
            // en Postgres con un 500 al crear/editar).
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($laboratoryId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('laboratories')
                        ->whereNull('deleted_at')
                        ->when($laboratoryId, fn ($qq) => $qq->where('id', '!=', $laboratoryId));
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('laboratories.name_unique'));
                    }
                },
            ],
            'code'       => [
                'nullable', 'string', 'max:40',
                function ($attribute, $value, $fail) use ($laboratoryId) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('laboratories')
                        ->whereNull('deleted_at')
                        ->when($laboratoryId, fn ($qq) => $qq->where('id', '!=', $laboratoryId))
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('laboratories.code_unique'));
                    }
                },
            ],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
