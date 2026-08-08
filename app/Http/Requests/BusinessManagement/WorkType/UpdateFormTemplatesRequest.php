<?php

namespace App\Http\Requests\BusinessManagement\WorkType;

use App\Http\Requests\BusinessManagement\WorkType\Concerns\ValidatesWorkType;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guardar SOLO la matriz de formatos, desde la ficha.
 *
 * Existe aparte del formulario largo porque cambiar qué papeles exige un tipo
 * es lo que más se hace y lo que menos tiene que ver con editar su código: pasar
 * por el formulario entero para mover un interruptor invita a tocar de paso el
 * país, que es un cambio de otra magnitud.
 */
class UpdateFormTemplatesRequest extends FormRequest
{
    use DerivesAttributesFromLang;
    use ValidatesWorkType;

    protected $attributeNamespace = 'work_types';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            ...$this->formTemplateRules(),
            // `present` y no `sometimes`: aquí la lista vacía es una orden
            // legítima —«este tipo ya no exige ningún formato»— y hay que poder
            // distinguirla de un formulario que no mandó el campo.
            'form_templates' => ['present', 'array'],
        ];
    }
}
