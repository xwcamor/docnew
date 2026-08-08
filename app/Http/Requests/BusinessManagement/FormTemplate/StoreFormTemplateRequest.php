<?php

namespace App\Http\Requests\BusinessManagement\FormTemplate;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Http\Requests\Concerns\DerivesCodeFromName;
use Illuminate\Support\Facades\DB;
class StoreFormTemplateRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'form_templates';

    use DerivesCodeFromName;

    protected function prepareForValidation(): void
    {
        $vacio = blank($this->input('code'));

        $this->deriveCodeFromName();

        // El código se deriva del nombre cuando se deja en blanco, y el nombre
        // de un documento es largo de verdad — «AST (Análisis de Seguridad en
        // el Trabajo)» pasa de 40. Sin recortar, quien no escribe código recibe
        // un error de longitud sobre un campo que ni ha tocado.
        if ($vacio && filled($this->input('code'))) {
            $this->merge(['code' => mb_substr((string) $this->input('code'), 0, 40)]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Unicidad case + accent insensitive. form_templates es PER-TENANT: el nombre
            // es unico dentro del workspace del actor (no cross-tenant). Se filtra
            // por tenant_id para alinear con el indice unico parcial de la tabla.
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('form_templates')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id);
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
            // `code` es NOT NULL en la tabla, no nullable: se deriva del nombre
            // cuando se deja en blanco, así que exigirlo aquí no le pide nada
            // más al usuario y cierra el hueco por el que se colaba un null.
            'code'       => [
                'required', 'string', 'max:40',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('form_templates')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('form_templates.code_unique'));
                    }
                },
            ],
            // El país es obligatorio: la columna es NOT NULL y el formulario no
            // lo pedía, así que crear un formato desde la pantalla reventaba con
            // un 23502 de Postgres. Es el mismo campo que llevan los catálogos.
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            // Cómo se llena el documento. La columna existía y la pantalla no
            // dejaba elegirla, así que todo nacía `structured` — y un
            // `structured` sin campos no se puede publicar. Como todavía no hay
            // pantalla para definir campos (PENDIENTES #15), eso dejaba el
            // módulo sin ninguna salida: nada de lo que se creara aquí llegaba
            // nunca a un plan.
            'kind'       => ['sometimes', 'required', Rule::in(\App\Models\FormTemplate::KINDS)],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
