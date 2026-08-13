<?php

namespace App\Http\Requests\BusinessManagement\FormTemplate;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use App\Http\Requests\Concerns\DerivesCodeFromName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
class UpdateFormTemplateRequest extends FormRequest
{
    use DerivesAttributesFromLang;
    use DerivesCodeFromName;

    protected $attributeNamespace = 'form_templates';

    /**
     * Igual que el alta.
     *
     * Esto no estaba, y `code` es NOT NULL: vaciar el campo Código y guardar
     * mandaba `''`, que el middleware de Laravel convierte en `null`, y el
     * update reventaba con un 23502 de Postgres en la cara del usuario. El
     * mismo fallo que tenía el país, en la pantalla de editar.
     */
    protected function prepareForValidation(): void
    {
        $vacio = blank($this->input('code'));

        $this->deriveCodeFromName();

        if ($vacio && filled($this->input('code'))) {
            $this->merge(['code' => mb_substr((string) $this->input('code'), 0, 40)]);
        }
    }

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        $formTemplate = $this->route('formTemplate');
        if (is_object($formTemplate) && $formTemplate->is_locked) {
            return false;
        }
        return true;
    }

    /**
     * Un documento publicado no cambia de forma de llenarse.
     *
     * Pasar de «con campos» a «sólo foto del papel» —o al revés— con entregas
     * ya hechas cambiaría qué se le exige a una entrega que ya está cerrada. La
     * pantalla lo saca deshabilitado y dice por qué; esto es la otra mitad.
     */
    public function withValidator($validator): void
    {
        $formTemplate = $this->route('formTemplate');

        $validator->after(function ($v) use ($formTemplate) {
            if (! is_object($formTemplate) || ! $this->has('kind')) {
                return;
            }
            if ($formTemplate->status !== 'draft' && $this->input('kind') !== $formTemplate->kind) {
                $v->errors()->add('kind', __('form_templates.kind_locked_published'));
            }
        });
    }

    public function rules(): array
    {
        $formTemplate   = $this->route('formTemplate');
        $formTemplateId = is_object($formTemplate) ? $formTemplate->id : null;

        return [
            // Unicidad de name case + accent insensitive PER-TENANT, ignorando el
            // propio formTemplate y soft-deleted. Se filtra por tenant_id para alinear con
            // el indice unico parcial (tenant_id, name) de la tabla.
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($formTemplateId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('form_templates')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->when($formTemplateId, fn ($qq) => $qq->where('id', '!=', $formTemplateId));
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
            // NOT NULL en la tabla: ver `prepareForValidation()`.
            'code'       => [
                'required', 'string', 'max:40',
                function ($attribute, $value, $fail) use ($formTemplateId) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('form_templates')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->when($formTemplateId, fn ($qq) => $qq->where('id', '!=', $formTemplateId))
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
            // Cómo se llena. Sólo se admite mientras esté en borrador: cambiarlo
            // en uno publicado alteraría la forma de rellenar un documento que
            // ya tiene entregas. Ver `withValidator()`.
            'kind'       => ['sometimes', 'required', Rule::in(\App\Models\FormTemplate::KINDS)],
            // Cómo se imprime. A diferencia de `kind`, esto SÍ se cambia en un
            // documento publicado: no altera lo que se le pide a nadie ni lo que
            // hay guardado, sólo cómo se coloca la hoja al imprimirlo. Que un
            // formato con entregas viejas salga mal impreso y no se pueda
            // arreglar sin sacar una versión nueva no tendría sentido.
            'pdf_orientation' => ['sometimes', 'nullable', Rule::in(\App\Models\FormTemplate::ORIENTACIONES)],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
