<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'code'        => $this->code,
            'language_id' => $this->language_id,
            'is_active'   => (bool) $this->is_active,
            'language'    => $this->whenLoaded('language', fn() => [
                'id'       => $this->language->id,
                'name'     => $this->language->name,
                'iso_code' => $this->language->iso_code,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
