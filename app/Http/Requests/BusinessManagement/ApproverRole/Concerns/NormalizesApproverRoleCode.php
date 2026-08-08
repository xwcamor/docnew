<?php

namespace App\Http\Requests\BusinessManagement\ApproverRole\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El codigo de un rol aprobador es una clave, no una etiqueta.
 *
 * Lo escriben personas, pero lo leen las reglas de flujo y el motor de
 * migracion, que lo comparan literalmente. Por eso se normaliza antes de
 * validar —minusculas, sin acentos, espacios a guion bajo— en vez de
 * rechazar «Jefe de Izaje» y dejar al usuario adivinando el formato.
 *
 * La unicidad se comprueba contra el mismo indice parcial que tiene la tabla:
 * unico por `lower(code)` dentro del workspace, contando el global como uno mas.
 */
trait NormalizesApproverRoleCode
{
    protected function normalizeCode(): void
    {
        $code = $this->input('code');
        if (! is_string($code)) {
            return;
        }

        $this->merge([
            'code' => Str::of($code)->trim()->ascii()->lower()
                ->replaceMatches('/[^a-z0-9]+/', '_')
                ->trim('_')
                ->toString(),
        ]);
    }

    /**
     * Reglas del campo `code`. `$ignoreId` excluye el propio registro al editar.
     */
    protected function codeRules(?int $ignoreId = null): array
    {
        return [
            'required', 'string', 'max:30', 'regex:/^[a-z0-9]+(_[a-z0-9]+)*$/',
            function ($attribute, $value, $fail) use ($ignoreId) {
                $existe = DB::table('approver_roles')
                    ->whereNull('deleted_at')
                    // `coalesce(tenant_id, 0)`: el catalogo global convive con
                    // el del workspace, y dos roles no pueden compartir codigo
                    // dentro del mismo ambito.
                    ->whereRaw('coalesce(tenant_id, 0) = ?', [(int) (auth()->user()?->tenant_id ?? 0)])
                    ->whereRaw('lower(code) = ?', [mb_strtolower(trim((string) $value))])
                    ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                    ->exists();

                if ($existe) {
                    $fail(__('approver_roles.code_unique'));
                }
            },
        ];
    }
}
