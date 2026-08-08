<?php

namespace App\Http\Requests\BusinessManagement\WorkType;

use App\Http\Requests\BusinessManagement\WorkType\Concerns\ValidatesWorkType;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkTypeRequest extends FormRequest
{
    use DerivesAttributesFromLang;
    use ValidatesWorkType;

    protected $attributeNamespace = 'work_types';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        // Va aqui y no en el controlador porque `authorize()` corre ANTES de
        // validar: si no, un cuerpo invalido devolveria «falta el nombre» en
        // vez de «esta bloqueado», que es lo que pasa de verdad.
        $workType = $this->route('workType');
        if (is_object($workType) && $workType->is_locked) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        $tipo = $this->route('workType');

        return $this->workTypeRules(is_object($tipo) ? $tipo->id : null);
    }
}
