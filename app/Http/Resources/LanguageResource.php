<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API B2B completa (estilo Stripe). Solo escondemos detalles internos
 * sin valor afuera: slug, created_by, deleted_*.
 */
class LanguageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'iso_code'   => $this->iso_code,
            'is_active'  => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
