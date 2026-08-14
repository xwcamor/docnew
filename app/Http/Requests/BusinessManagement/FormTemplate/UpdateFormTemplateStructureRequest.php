<?php

namespace App\Http\Requests\BusinessManagement\FormTemplate;

use App\Models\FormField;
use App\Services\FieldWork\FormTemplateBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El árbol de secciones y campos que manda el editor.
 *
 * Llega entero en cada guardado —la pantalla es de guardar-al-final, como el
 * resto del producto— así que aquí se comprueba el árbol completo, no un
 * cambio suelto.
 *
 * La lista de tipos válidos sale de `FormField::TIPOS` y de ningún otro sitio.
 * Es la misma que pinta el selector: si mañana aparece un tipo nuevo en el
 * modelo, el editor lo ofrece y esta validación lo acepta sin tocar nada.
 */
class UpdateFormTemplateStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): igual que el resto de las puertas del
        // módulo. Esconder el botón no protege nada — el PUT llega igual.
        $formTemplate = $this->route('formTemplate');

        return ! (is_object($formTemplate) && $formTemplate->is_locked);
    }

    public function rules(): array
    {
        return [
            // `present` y no `required`: dejar el documento sin secciones es una
            // orden legítima (se vació el borrador), y `required` rechaza el
            // array vacío.
            'sections'                     => ['present', 'array', 'max:50'],
            'sections.*.id'                => ['nullable', 'integer'],
            // El título del bloque. UNO, no uno por idioma: se guarda en la
            // columna del idioma en el que se está trabajando (ver
            // `FormTemplateStructureService::escribirTexto()`). Pedirle al
            // cliente que escriba cada título dos veces es pedirle que traduzca
            // su propio trabajo.
            //
            // Opcional a propósito: en el papel no todos los bloques llevan
            // título —el EPP y el IHM son una sola tabla sin cabecera— y
            // `FormSection::getLabel()` devuelve cadena vacía en ese caso en vez
            // de inventarse un «Sección 2».
            'sections.*.name'              => ['nullable', 'string', 'max:120'],
            'sections.*.fields'            => ['present', 'array', 'max:100'],
            'sections.*.fields.*.id'       => ['nullable', 'integer'],
            // Igual con la etiqueta del campo: si falta, el accesor cae al
            // código humanizado, que es como se leía antes de que existieran
            // estas columnas.
            'sections.*.fields.*.label'    => ['nullable', 'string', 'max:180'],
            // El código es la clave con la que la entrega guarda la respuesta
            // (`FormSubmissionService` busca por `code`), así que no admite
            // espacios ni acentos: minúsculas, números y guion bajo.
            'sections.*.fields.*.code'     => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'sections.*.fields.*.field_type'  => ['required', 'string', Rule::in(FormField::TIPOS)],
            'sections.*.fields.*.is_required' => ['nullable', 'boolean'],
            'sections.*.fields.*.config'      => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'sections.*.fields.*.code.regex' => __('form_templates.structure_code_format'),
            // `:input` lo sustituye Laravel por el valor que llegó, así que el
            // mensaje nombra el tipo desconocido sin tener que buscarlo aquí.
            'sections.*.fields.*.field_type.in' => __('form_templates.structure_unknown_type', ['type' => ':input']),
        ];
    }

    /**
     * Lo que no cabe en una regla suelta: el código repetido dentro de una
     * sección y la configuración que cada tipo necesita para poder pintarse.
     *
     * Lo segundo lo sabe `FormTemplateBuilder` —es quien conoce qué claves pide
     * cada tipo— y se le pregunta a él en vez de repetir la tabla aquí. Un
     * `select` sin opciones sale en la tablet como un desplegable vacío: el
     * trabajador no puede responder y no hay forma de saber por qué.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            foreach ((array) $this->input('sections', []) as $iSeccion => $seccion) {
                $vistos = [];

                foreach ((array) ($seccion['fields'] ?? []) as $iCampo => $campo) {
                    $code = (string) ($campo['code'] ?? '');
                    $tipo = (string) ($campo['field_type'] ?? '');

                    if ($code !== '' && isset($vistos[$code])) {
                        $v->errors()->add(
                            "sections.{$iSeccion}.fields.{$iCampo}.code",
                            __('form_templates.structure_code_duplicate', ['code' => $code]),
                        );
                    }

                    $vistos[$code] = true;

                    $faltan = $this->configuracionQueFalta($tipo, (array) ($campo['config'] ?? []));

                    if ($faltan !== []) {
                        $v->errors()->add(
                            "sections.{$iSeccion}.fields.{$iCampo}.config",
                            __('form_templates.structure_config_required', [
                                'code' => $code,
                                'keys' => implode(', ', array_map(
                                    fn ($clave) => __("form_templates.field_config_keys.{$clave}"),
                                    $faltan,
                                )),
                            ]),
                        );
                    }
                }
            }
        });
    }

    /** Claves obligatorias del tipo que no vienen, o vienen vacías. */
    private function configuracionQueFalta(string $tipo, array $config): array
    {
        if (! in_array($tipo, FormTemplateBuilder::CONFIG_OBLIGATORIA, true)) {
            return [];
        }

        return array_values(array_filter(
            FormTemplateBuilder::TIPOS[$tipo] ?? [],
            fn ($clave) => blank($config[$clave] ?? null),
        ));
    }
}
