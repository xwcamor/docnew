<?php

namespace App\Http\Requests\BusinessManagement\DocumentType;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreDocumentTypeRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'document_types';

    public function authorize(): bool
    {
        // La ruta ya pasa por permission:document_types.create — aqui solo se validan
        // los datos, igual que en el resto de modulos.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Los espacios de los extremos son un error de tecleo con guantes, no
        // parte de la sigla: dos filas que solo se diferencian en un espacio se
        // leen igual en pantalla y confunden a quien elige.
        if ($this->has('code')) {
            $this->merge(['code' => trim((string) $this->input('code'))]);
        }

        // El nombre largo es opcional y el formulario manda cadena vacia cuando
        // se deja en blanco. Vacio y «sin nombre» son la misma cosa: se guarda
        // NULL, o la etiqueta del selector saldria «DNI — » con un guion
        // colgando (ver DocumentType::getLabelAttribute).
        if ($this->has('name')) {
            $nombre = trim((string) $this->input('name'));
            $this->merge(['name' => $nombre === '' ? null : $nombre]);
        }
    }

    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            // Al dar de alta, el tipo nace en el workspace de quien lo crea (o
            // global, si lo crea un super): ahi es donde se busca el repetido.
            'code' => ['required', 'string', 'max:20',
                $this->siglaRepetida(null, $this->user()?->tenant_id)],
            'name' => ['nullable', 'string', 'max:120'],
            // Ayuda de validacion del numero, no condicion de busqueda: el
            // buscador de trabajadores va por coincidencia exacta. El volcado
            // trae dos peruanos con DNI de 7 caracteres, asi que una regla de
            // longitud mal puesta deja gente fuera del sistema.
            'min_length' => ['nullable', 'integer', 'min:1', 'max:40'],
            'max_length' => ['nullable', 'integer', 'min:1', 'max:40', 'gte:min_length'],
            'is_active' => 'sometimes|boolean',
        ];
    }

    /**
     * «Ya existe esa sigla» tiene que significar una que quien la escribe PUEDA
     * VER, y significar lo mismo escrita de dos maneras.
     *
     * Este modulo salio de copiar Nacionalidades, asi que trajo los dos mismos
     * agujeros que se arreglaron en `Position`:
     *
     * 1. **El workspace.** `Rule::unique` consulta la tabla cruda, sin el scope
     *    de `BelongsToTenantOrGlobal`. Un tipo privado de OTRA empresa impedia
     *    dar de alta la misma sigla aqui, con un error que nombraba una fila
     *    que este usuario no ve, no puede abrir y no puede borrar. El conjunto
     *    correcto es el que ve el selector de la ficha de la persona: los
     *    propios mas los globales de la plataforma — que es exactamente el
     *    conjunto que `DocumentType::delPais()` devuelve.
     * 2. **Mayusculas y tildes.** Se comparaba tal cual, asi que «DNI», «dni» y
     *    «Dni» convivian. Aqui pesa mas que en otros catalogos: `people.doc_type`
     *    guarda el TEXTO de la sigla, no la clave ajena, y una persona dada de
     *    alta como «dni» no la encuentra quien busca por «DNI».
     *
     * Ojo: el indice unico de la tabla —`(country_id, code, deleted_at)`— NO
     * cubre esto. Con `deleted_at` en nulo el motor considera cada tupla
     * distinta, asi que entre filas vivas no impide nada. Esta validacion es la
     * unica guarda real.
     *
     * @param  int|null  $ignorarId  la propia fila, al editar
     * @param  int|null  $tenantId   el workspace contra el que se compara; null = solo los globales
     */
    protected function siglaRepetida(?int $ignorarId, ?int $tenantId): \Closure
    {
        return function ($attribute, $value, $fail) use ($ignorarId, $tenantId) {
            $needle = trim((string) $value);
            if ($needle === '') {
                return;
            }

            $q = DB::table('document_types')
                ->whereNull('deleted_at')
                ->where('country_id', $this->input('country_id'))
                // Los propios y los globales: lo mismo que ve el selector.
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
                $fail(__('document_types.code_unique'));
            }
        };
    }

    public function messages(): array
    {
        return [
            'code.required' => __('document_types.code_required'),
            // El mensaje generico de `gte` habla de «mayor o igual que 12».
            // Aqui el error real es otro y se dice con esas palabras: el maximo
            // no puede quedar por debajo del minimo.
            'max_length.gte' => __('document_types.max_length_gte'),
        ];
    }
}
