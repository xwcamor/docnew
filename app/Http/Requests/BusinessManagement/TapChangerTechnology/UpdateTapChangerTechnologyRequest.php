<?php

namespace App\Http\Requests\BusinessManagement\TapChangerTechnology;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
class UpdateTapChangerTechnologyRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'tap_changer_technologies';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        $tapChangerTechnology = $this->route('tapChangerTechnology');
        if (is_object($tapChangerTechnology) && $tapChangerTechnology->is_locked) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        $tapChangerTechnology   = $this->route('tapChangerTechnology');
        $tapChangerTechnologyId = is_object($tapChangerTechnology) ? $tapChangerTechnology->id : null;

        return [
            // Unicidad de name case + accent insensitive (catalogo GLOBAL, sin
            // tenant), ignorando el propio tapChangerTechnology y soft-deleted. NO se filtra
            // por tenant_id: la tabla no tiene esa columna (filtrarla revienta
            // en Postgres con un 500 al crear/editar).
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($tapChangerTechnologyId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('tap_changer_technologies')
                        ->whereNull('deleted_at')
                        ->when($tapChangerTechnologyId, fn ($qq) => $qq->where('id', '!=', $tapChangerTechnologyId));
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('tap_changer_technologies.name_unique'));
                    }
                },
            ],
            'code'       => [
                'nullable', 'string', 'max:40',
                function ($attribute, $value, $fail) use ($tapChangerTechnologyId) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('tap_changer_technologies')
                        ->whereNull('deleted_at')
                        ->when($tapChangerTechnologyId, fn ($qq) => $qq->where('id', '!=', $tapChangerTechnologyId))
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('tap_changer_technologies.code_unique'));
                    }
                },
            ],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
