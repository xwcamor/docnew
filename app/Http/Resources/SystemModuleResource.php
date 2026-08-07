<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'permission_key' => $this->permission_key,
            'is_active'      => (bool) $this->is_active,
            'permissions'    => $this->permissions?->map(fn($p) => [
                'id'   => $p->id,
                'name' => $p->name,
            ])->values()->all() ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
