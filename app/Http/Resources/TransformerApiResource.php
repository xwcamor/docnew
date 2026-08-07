<?php

namespace App\Http\Resources;

use App\Support\Diagnostics\ConditionLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transformer API resource — lo que ve el laboratorio de un transformador.
 *
 * El campo importante es `slug`: es lo que el laboratorio guarda en su
 * `equipment.external_ref` para no volver a buscar por número de serie nunca
 * más. Va acompañado de lo mínimo para que una persona reconozca el equipo en
 * la bandeja de conciliación (serie, tag, cliente, subestación) y del estado de
 * salud, que el laboratorio muestra como referencia.
 *
 * Los ids internos de catálogos NO se exponen: el laboratorio trabaja con
 * códigos (`mineral`, `potencia`), que son estables y legibles. La excepción es
 * la subestación, cuyo id sí hace falta para dar de alta equipos hermanos del
 * mismo cliente.
 */
class TransformerApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug'   => $this->slug,
            'serial' => $this->serial,
            'tag'    => $this->tag,

            'customer' => $this->whenLoaded('customer', fn () => [
                'id'   => $this->customer?->id,
                'name' => $this->customer?->name,
            ]),
            'substation' => $this->whenLoaded('substation', fn () => [
                'id'   => $this->substation?->id,
                'name' => $this->substation?->name,
            ]),

            'transformer_type' => $this->whenLoaded('transformerType', fn () => $this->transformerType?->code),
            'oil_type'         => $this->whenLoaded('oilType', fn () => $this->oilType?->code),

            'voltage_kv' => $this->voltage_kv === null ? null : (float) $this->voltage_kv,
            'power_mva'  => $this->power_mva === null ? null : (float) $this->power_mva,
            'phases'     => $this->phases,

            // Diagnóstico cacheado tras la ingesta. `condition` es la palabra
            // traducida del rating; el rating (0-4) es lo que hay que comparar
            // en el código del laboratorio, porque la palabra es editable.
            'health_index'  => $this->health_index === null ? null : (float) $this->health_index,
            'health_rating' => $this->health_rating === null ? null : (int) $this->health_rating,
            'condition'     => ConditionLabel::for($this->health_rating),
        ];
    }
}
