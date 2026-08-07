<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un transformador desde el laboratorio.
 *
 * NO es el mismo contrato que el formulario web (StoreTransformerRequest), y la
 * diferencia es deliberada: la web la llena el diagnosticador con la ficha
 * completa del equipo delante, y por eso ahí marca, conmutador, potencia y año
 * son obligatorios. El laboratorio recibe un frasco de aceite y la placa que le
 * dictaron por teléfono; exigirle esos campos termina en datos inventados, que
 * es exactamente cómo el sistema viejo llenó su base de ceros.
 *
 * Se exige lo que el motor de diagnóstico NECESITA para no mentir:
 * identificación (serie + tag), cliente, subestación, tipo de equipo, aceite y
 * tensión. Esos seis eligen el cuadro de reglas. El resto se completa después
 * desde la ficha.
 */
class StoreLabTransformerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'serial' => ['required', 'string', 'max:100'],
            // Misma regla que la web: la serie sola PUEDE repetirse (una serie
            // con varios tags), lo que no se admite es serie+tag repetidos en el
            // mismo workspace.
            'tag' => [
                'required', 'string', 'max:100',
                Rule::unique('transformers', 'tag')
                    ->where(fn ($q) => $q->where('tenant_id', $this->user()?->tenant_id)
                        ->where('serial', $this->input('serial'))
                        ->whereNull('deleted_at')),
            ],

            'customer_id' => ['required_without:customer', 'nullable', 'integer'],
            'customer'    => ['required_without:customer_id', 'nullable', 'string', 'max:255'],

            'customer_substation_id' => ['required_without:substation', 'nullable', 'integer'],
            'substation'             => ['required_without:customer_substation_id', 'nullable', 'string', 'max:255'],

            // Códigos, no ids: el laboratorio no conoce nuestras claves. La
            // traducción y el rechazo de los tipos que no sabemos diagnosticar
            // están en LabTransformerService.
            'transformer_type' => ['required_without:equipment_type', 'nullable', 'string', 'max:60'],
            'equipment_type'   => ['required_without:transformer_type', 'nullable', 'string', 'max:60'],
            'oil_type'         => ['required', 'string', 'max:60'],

            // Tensión: acá es UN número (la clase de tensión) y en el
            // laboratorio son tres devanados. Basta con cualquiera; el servicio
            // se queda con el máximo.
            'voltage_kv'    => ['required_without_all:voltage_kv_hv,voltage_kv_lv,voltage_kv_tv', 'nullable', 'numeric', 'min:0'],
            'voltage_kv_hv' => ['nullable', 'numeric', 'min:0'],
            'voltage_kv_lv' => ['nullable', 'numeric', 'min:0'],
            'voltage_kv_tv' => ['nullable', 'numeric', 'min:0'],

            'phases'           => ['nullable'],
            'power_mva'        => ['nullable', 'numeric', 'min:0'],
            'manufacture_year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'paper_type'       => ['nullable', 'in:kraft,upgraded'],
        ];
    }

    public function messages(): array
    {
        return [
            'tag.unique' => __('transformers.serial_tag_unique'),
        ];
    }
}
