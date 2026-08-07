<?php

namespace App\Http\Requests\BusinessManagement\TapChangerModel;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
class UpdateTapChangerModelRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'tap_changer_models';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        $tapChangerModel = $this->route('tapChangerModel');
        if (is_object($tapChangerModel) && $tapChangerModel->is_locked) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        $tapChangerModel   = $this->route('tapChangerModel');
        $tapChangerModelId = is_object($tapChangerModel) ? $tapChangerModel->id : null;

        return [
            // Unicidad de name case + accent insensitive (catalogo GLOBAL, sin
            // tenant), ignorando el propio tapChangerModel y soft-deleted. NO se filtra
            // por tenant_id: la tabla no tiene esa columna (filtrarla revienta
            // en Postgres con un 500 al crear/editar).
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($tapChangerModelId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('tap_changer_models')
                        ->whereNull('deleted_at')
                        ->when($tapChangerModelId, fn ($qq) => $qq->where('id', '!=', $tapChangerModelId));
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('tap_changer_models.name_unique'));
                    }
                },
            ],
            'code'       => [
                'nullable', 'string', 'max:40',
                function ($attribute, $value, $fail) use ($tapChangerModelId) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('tap_changer_models')
                        ->whereNull('deleted_at')
                        ->when($tapChangerModelId, fn ($qq) => $qq->where('id', '!=', $tapChangerModelId))
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('tap_changer_models.code_unique'));
                    }
                },
            ],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
