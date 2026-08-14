<?php

namespace App\Http\Requests\BusinessManagement\WorkType\Concerns;

use App\Models\FormTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Lo que hace válido a un tipo de trabajo, en un solo sitio para que crear y
 * editar no se separen con el tiempo.
 *
 * Tres comprobaciones son de dominio y no de formato:
 *
 *  - el código no se repite dentro del mismo país y workspace. Hay un índice
 *    único parcial que lo garantiza, pero SOLO en PostgreSQL: en cualquier otro
 *    motor la única defensa es esta, y un «Izaje» duplicado parte en dos la
 *    configuración de documentos sin que nadie se entere;
 *  - un documento exigido tiene que ser del mismo país que el tipo. Uno de otro
 *    país no se le puede pedir nunca a un plan de éste;
 *  - un documento en borrador no se puede marcar como obligatorio. No se puede
 *    llenar, así que exigirlo es bloquear el plan por una pantalla que revienta
 *    al abrirla.
 *
 * Las tres se juzgan sobre lo que CAMBIA. Un documento que el tipo ya exigía
 * igual pasa tal cual aunque hoy esté inactivo o despublicado: si no, quien
 * quisiera precisamente quitarlo se encontraría la ficha atascada en un error
 * que no provocó.
 */
trait ValidatesWorkType
{
    /** @var array<int, bool>|null */
    protected ?array $formatosPrevios = null;

    protected function prepareForValidation(): void
    {
        // El código y el nombre se guardan tal cual se escriben («Izaje», no
        // «izaje»), pero sin los espacios de sobra que llegan de copiar y pegar.
        foreach (['code', 'name'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => trim($this->input($campo))]);
            }
        }
    }

    protected function workTypeRules(?int $ignoreId = null): array
    {
        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],

            // El nombre es lo que se lee en pantalla; el código es la sigla y
            // sigue siendo la identidad (único por país, ver abajo). El nombre
            // no exige unicidad: dos nombres parecidos no rompen nada, dos
            // códigos iguales sí.
            'name' => ['required', 'string', 'max:255'],

            'code' => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($ignoreId) {
                    $repetido = DB::table('work_types')
                        ->whereNull('deleted_at')
                        ->where('country_id', (int) $this->input('country_id'))
                        ->whereRaw('lower(code) = ?', [mb_strtolower((string) $value)])
                        ->whereRaw('coalesce(tenant_id, 0) = ?', [(int) (auth()->user()?->tenant_id ?? 0)])
                        ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                        ->exists();

                    if ($repetido) {
                        $fail(__('work_types.duplicate_code'));
                    }
                },
            ],

            'is_active' => ['sometimes', 'boolean'],

            ...$this->formTemplateRules(),
        ];
    }

    /**
     * Las reglas de la matriz de documentos, sueltas para que la ficha pueda
     * guardar sólo el pivote sin arrastrar el resto del formulario.
     */
    protected function formTemplateRules(): array
    {
        return [
            'form_templates'               => ['sometimes', 'array'],
            'form_templates.*.id'          => ['required', 'integer'],
            'form_templates.*.is_required' => ['required', 'boolean'],

            // La comprobación cruzada va sobre el array entero: necesita ver el
            // país del tipo y todos los ids de una vez, no fila a fila.
            'form_templates.*' => [
                function ($attribute, $value, $fail) {
                    $id        = (int) ($value['id'] ?? 0);
                    $exigido   = filter_var($value['is_required'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    $plantilla = FormTemplate::query()->find($id);

                    if (! $plantilla) {
                        $fail(__('work_types.form_template_unknown'));

                        return;
                    }

                    // Ya lo exigía igual: no es una decisión nueva, no se juzga.
                    $previos = $this->formatosYaExigidos();
                    $igual   = array_key_exists($id, $previos) && $previos[$id] === $exigido;
                    if ($igual) {
                        return;
                    }

                    if (! $plantilla->is_active) {
                        $fail(__('work_types.form_template_unknown'));

                        return;
                    }

                    if ((int) $plantilla->country_id !== (int) $this->countryIdParaFormatos()) {
                        $fail(__('work_types.form_template_other_country', ['code' => $plantilla->code]));

                        return;
                    }

                    if ($exigido && $plantilla->status !== 'published') {
                        $fail(__('work_types.form_template_draft_required', ['code' => $plantilla->code]));
                    }
                },
            ],
        ];
    }

    /**
     * Lo que el tipo exige AHORA MISMO: id => obligatorio.
     *
     * En el alta no hay nada previo. Se resuelve una sola vez: la regla corre
     * una vez por fila del formulario.
     *
     * @return array<int, bool>
     */
    protected function formatosYaExigidos(): array
    {
        if ($this->formatosPrevios !== null) {
            return $this->formatosPrevios;
        }

        $tipo = $this->route('workType');

        return $this->formatosPrevios = is_object($tipo)
            ? $tipo->formTemplates()->get(['form_templates.id'])
                ->mapWithKeys(fn ($f) => [(int) $f->id => (bool) $f->pivot->is_required])
                ->all()
            : [];
    }

    /**
     * El país contra el que se validan los documentos.
     *
     * En el alta y la edición largas viene en el propio formulario; al guardar
     * sólo la matriz desde la ficha, el formulario no lo manda y sale del
     * registro que se está tocando.
     */
    protected function countryIdParaFormatos(): int
    {
        if ($this->filled('country_id')) {
            return (int) $this->input('country_id');
        }

        $tipo = $this->route('workType');

        return is_object($tipo) ? (int) $tipo->country_id : 0;
    }
}
