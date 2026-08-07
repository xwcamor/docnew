<?php

namespace App\Http\Requests\BusinessManagement\TransformerType;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
class UpdateTransformerTypeRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'transformer_types';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $transformerType   = $this->route('transformerType');
        $transformerTypeId = is_object($transformerType) ? $transformerType->id : null;

        return [
            // Unicidad de name case + accent insensitive (catalogo GLOBAL, sin
            // tenant), ignorando el propio transformerType y soft-deleted. NO se filtra
            // por tenant_id: la tabla no tiene esa columna (filtrarla revienta
            // en Postgres con un 500 al crear/editar).
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($transformerTypeId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('transformer_types')
                        ->whereNull('deleted_at')
                        ->when($transformerTypeId, fn ($qq) => $qq->where('id', '!=', $transformerTypeId));
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('transformer_types.name_unique'));
                    }
                },
            ],
            'code'       => [
                'nullable', 'string', 'max:40',
                function ($attribute, $value, $fail) use ($transformerTypeId) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('transformer_types')
                        ->whereNull('deleted_at')
                        ->when($transformerTypeId, fn ($qq) => $qq->where('id', '!=', $transformerTypeId))
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('transformer_types.code_unique'));
                    }
                },
            ],
            'shape'      => ['sometimes', 'nullable', 'in:tank,pole,dry'],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
