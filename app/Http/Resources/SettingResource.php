<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API B2B completa (estilo Stripe). Los settings son data de dominio, exponemos
 * todos los campos. `value` se enmascara cuando `is_secret = true` — el cliente
 * sigue viendo metadata (key/type/group) pero no el contenido sensible.
 */
class SettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'key'         => $this->key,
            'type'        => $this->type,
            'value'       => $this->is_secret ? null : $this->value,
            'group'       => $this->group,
            'description' => $this->description,
            'is_secret'   => (bool) $this->is_secret,
            'is_active'   => (bool) $this->is_active,
            'created_at'  => $this->created_at?->toIso8601String(),
            'updated_at'  => $this->updated_at?->toIso8601String(),
        ];
    }
}
