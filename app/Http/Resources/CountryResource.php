<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API B2B completa (estilo Stripe). Solo escondemos detalles internos
 * sin valor afuera: slug, created_by, deleted_*.
 */
class CountryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'iso_code'          => $this->iso_code,
            'currency'          => $this->currency,
            'timezone'          => $this->timezone,
            'region_id'         => $this->region_id,
            'default_locale_id' => $this->default_locale_id,
            'is_active'         => (bool) $this->is_active,
            'region'            => $this->whenLoaded('region', fn() => [
                'id'   => $this->region->id,
                'name' => $this->region->name,
            ]),
            'default_locale'    => $this->whenLoaded('defaultLocale', fn() => [
                'id'   => $this->defaultLocale->id,
                'code' => $this->defaultLocale->code,
                'name' => $this->defaultLocale->name,
            ]),
            'created_at'        => $this->created_at?->toIso8601String(),
            'updated_at'        => $this->updated_at?->toIso8601String(),
        ];
    }
}
