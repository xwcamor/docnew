<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer API resource. Expone los campos del dominio que el integrador
 * necesita ver sin filtrar internals: slug (route key), tenant_id (auto-scope
 * por trait, no lo elige el cliente), created_by, deleted_*.
 */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'cod'        => $this->cod,
            'country_id' => $this->country_id,
            'country'    => $this->whenLoaded('country', fn () => [
                'id'       => $this->country->id,
                'name'     => $this->country->name,
                'iso_code' => $this->country->iso_code,
            ]),
            'is_active'  => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
