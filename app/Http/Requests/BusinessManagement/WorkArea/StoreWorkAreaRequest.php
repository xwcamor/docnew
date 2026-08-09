<?php

namespace App\Http\Requests\BusinessManagement\WorkArea;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreWorkAreaRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'work_areas';

    public function authorize(): bool
    {
        // La ruta ya pasa por permission:work_areas.create — aqui solo se validan
        // los datos, igual que en el resto de modulos.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Los espacios de los extremos son un error de tecleo con guantes, no
        // parte del nombre: dos filas que solo se diferencian en un espacio se
        // leen igual en pantalla y confunden a quien elige.
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            // Al dar de alta, el area nace en el workspace de quien la crea
            // (o global, si la crea un super): ahi es donde se busca la repetida.
            'name' => ['required', 'string', 'max:120',
                $this->areaRepetida(null, $this->user()?->tenant_id)],
            'is_active' => 'sometimes|boolean',
        ];
    }

    /**
     * «Ya existe esa area» tiene que significar una que quien la escribe PUEDA
     * VER, y significar lo mismo escrita de dos maneras.
     *
     * Dos cosas se perdieron al clonar este modulo de `Brand`, que es donde
     * esta el patron de la casa:
     *
     * 1. **El workspace.** `Rule::unique` consulta la tabla cruda, sin el scope
     *    de `BelongsToTenantOrGlobal`. Un area privada de OTRA empresa impedia
     *    dar de alta el mismo nombre aqui, con un error que nombraba una fila
     *    que este usuario no ve en ningun sitio y que no puede tocar. El
     *    conjunto correcto es el que se ve en el selector del plan: las propias
     *    mas las globales de la plataforma.
     * 2. **Mayusculas y tildes.** Se comparaba tal cual, asi que «Almacen»,
     *    «ALMACEN» y «Almacén» convivian: tres filas que en el desplegable del
     *    plan se leen igual, que es justo lo que el `trim()` de arriba trata de
     *    evitar con los espacios.
     *
     * @param  int|null  $ignorarId  la propia fila, al editar
     * @param  int|null  $tenantId   el workspace contra el que se compara; null = solo las globales
     */
    protected function areaRepetida(?int $ignorarId, ?int $tenantId): \Closure
    {
        return function ($attribute, $value, $fail) use ($ignorarId, $tenantId) {
            $needle = trim((string) $value);
            if ($needle === '') {
                return;
            }

            $q = DB::table('work_areas')
                ->whereNull('deleted_at')
                ->where('country_id', $this->input('country_id'))
                // Las propias y las globales: lo mismo que ve el selector.
                ->where(function ($w) use ($tenantId) {
                    $w->whereNull('tenant_id');
                    if ($tenantId !== null) {
                        $w->orWhere('tenant_id', $tenantId);
                    }
                });

            if ($ignorarId !== null) {
                $q->where('id', '!=', $ignorarId);
            }

            if (DB::getDriverName() === 'pgsql') {
                $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
            } else {
                $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
            }

            if ($q->exists()) {
                $fail(__('work_areas.name_unique'));
            }
        };
    }

    public function messages(): array
    {
        return [
            'name.required' => __('work_areas.name_required'),
        ];
    }
}
