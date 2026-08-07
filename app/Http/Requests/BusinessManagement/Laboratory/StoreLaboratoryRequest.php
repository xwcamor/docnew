<?php

namespace App\Http\Requests\BusinessManagement\Laboratory;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\DerivesCodeFromName;
use Illuminate\Support\Facades\DB;
class StoreLaboratoryRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'laboratories';

    use DerivesCodeFromName;

    protected function prepareForValidation(): void
    {
        $this->deriveCodeFromName();
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Unicidad case + accent insensitive. laboratories es un CATALOGO GLOBAL
            // (sin tenant): el nombre es unico para todos los workspaces. NO se
            // filtra por tenant_id porque la tabla no tiene esa columna (filtrar
            // por una columna inexistente revienta en Postgres con un 500).
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('laboratories')
                        ->whereNull('deleted_at');
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
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('laboratories')
                        ->whereNull('deleted_at')
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
