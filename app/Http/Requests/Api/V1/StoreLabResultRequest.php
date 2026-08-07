<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Envío de resultados del laboratorio.
 *
 * Acá se valida la FORMA del cuerpo (que sea un envío bien armado). El
 * significado —qué ensayos existen, qué analito va a qué columna, qué es
 * obligatorio en cada prueba— lo resuelve LabResultService contra
 * `config/lab_integration.php`, para que agregar un parámetro nuevo no obligue
 * a tocar dos archivos.
 */
class StoreLabResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Cómo identifica el laboratorio al equipo: el slug que guardó en su
            // `equipment.external_ref`, o los datos con que lo conoce.
            'transformer'          => ['required', 'array'],
            'transformer.slug'     => ['nullable', 'string', 'max:22'],
            'transformer.serial'   => ['nullable', 'string', 'max:100'],
            'transformer.tag'      => ['nullable', 'string', 'max:100'],
            'transformer.customer' => ['nullable', 'string', 'max:255'],

            'lab'                  => ['nullable', 'array'],
            'lab.report_number'    => ['nullable', 'string', 'max:255'],
            'lab.laboratory_code'  => ['nullable', 'string', 'max:120'],
            'lab.laboratory'       => ['nullable', 'string', 'max:120'],
            'lab.laboratory_name'  => ['nullable', 'string', 'max:255'],
            'lab.sample_code'      => ['nullable', 'string', 'max:120'],
            'lab.sampled_at'       => ['nullable', 'date'],
            'lab.issued_at'        => ['nullable', 'date'],

            'tests'               => ['required', 'array', 'min:1'],
            'tests.*.kind'        => ['required', 'string', 'max:40'],
            // Si el ensayo no trae su propia fecha se usa la del muestreo. Una
            // de las dos tiene que estar: la fecha ordena la tendencia del
            // equipo y sin ella la muestra no se puede ubicar en el tiempo.
            'tests.*.measured_at' => ['required_without:lab.sampled_at', 'nullable', 'date'],
            'tests.*.values'      => ['required', 'array', 'min:1'],
            'tests.*.values.*'    => ['nullable', 'numeric'],
            'tests.*.methods'     => ['nullable', 'array'],
        ];
    }
}
