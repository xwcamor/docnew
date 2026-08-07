<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;

/**
 * Deriva el campo `code` del `name` (en minúscula) cuando se deja vacío al crear.
 *
 * El `code` es nullable + único; como el `name` es único, el código derivado casi
 * siempre será único, y la regla de unicidad del request lo verifica igual (si en
 * un caso raro choca con un código explícito existente, el usuario ve el error).
 *
 * Uso en un FormRequest: `use DerivesCodeFromName;` y llamar
 * `$this->deriveCodeFromName();` dentro de `prepareForValidation()`.
 */
trait DerivesCodeFromName
{
    protected function deriveCodeFromName(): void
    {
        if (blank($this->input('code')) && filled($this->input('name'))) {
            // Minúscula + trim + espacios (uno o varios) → guion bajo.
            $code = preg_replace('/\s+/', '_', Str::lower(trim((string) $this->input('name'))));
            $this->merge(['code' => $code]);
        }
    }
}
