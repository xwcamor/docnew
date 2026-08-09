<?php

namespace App\Http\Requests\BusinessManagement\Nationality;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreNationalityRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'nationalities';

    public function authorize(): bool
    {
        // La ruta ya pasa por permission:nationalities.create — aqui solo se validan
        // los datos, igual que en el resto de modulos.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Los espacios de los extremos son un error de tecleo con guantes, no
        // parte del nombre: dos filas que solo se diferencian en un espacio se
        // leen igual en pantalla y confunden a quien elige.
        if ($this->has('code')) {
            $this->merge(['code' => trim((string) $this->input('code'))]);
        }
    }

    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            // Al dar de alta, la nacionalidad nace en el workspace de quien la
            // crea (o global, si la crea un super): ahi es donde se busca la
            // repetida.
            'code' => ['required', 'string', 'max:60',
                $this->nacionalidadRepetida(null, $this->user()?->tenant_id)],
            'is_active' => 'sometimes|boolean',
        ];
    }

    /**
     * «Ya existe esa nacionalidad» tiene que significar una que quien la
     * escribe PUEDA VER, y significar lo mismo escrita de dos maneras.
     *
     * Dos cosas se perdieron al clonar este modulo, y son las mismas que se
     * arreglaron en `Position`:
     *
     * 1. **El workspace.** `Rule::unique` consulta la tabla cruda, sin el scope
     *    de `BelongsToTenantOrGlobal`. Una nacionalidad privada de OTRA empresa
     *    impedia dar de alta el mismo nombre aqui, con un error que nombraba
     *    una fila que este usuario no ve en ningun sitio y que no puede abrir,
     *    renombrar ni borrar — un callejon sin salida que ademas cuenta que esa
     *    otra empresa la usa. El conjunto correcto es el que se ve en el
     *    selector: las propias mas las globales de la plataforma.
     * 2. **Mayusculas y tildes.** Se comparaba tal cual, asi que «Peruana»,
     *    «PERUANA» y «Peruána» convivian: tres filas que en el desplegable de
     *    la ficha de la persona se leen igual, que es justo lo que el `trim()`
     *    de arriba trata de evitar con los espacios.
     *
     * @param  int|null  $ignorarId  la propia fila, al editar
     * @param  int|null  $tenantId   el workspace contra el que se compara; null = solo las globales
     */
    protected function nacionalidadRepetida(?int $ignorarId, ?int $tenantId): \Closure
    {
        return function ($attribute, $value, $fail) use ($ignorarId, $tenantId) {
            $needle = trim((string) $value);
            if ($needle === '') {
                return;
            }

            $q = DB::table('nationalities')
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
                $q->whereRaw('unaccent(LOWER(code)) = unaccent(LOWER(?))', [$needle]);
            } else {
                $q->whereRaw('LOWER(code) = LOWER(?)', [$needle]);
            }

            if ($q->exists()) {
                $fail(__('nationalities.code_unique'));
            }
        };
    }

    public function messages(): array
    {
        return [
            'code.required' => __('nationalities.code_required'),
        ];
    }
}
