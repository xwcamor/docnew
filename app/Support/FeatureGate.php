<?php

namespace App\Support;

use App\Models\User;

/**
 * FeatureGate — consulta si un feature está habilitado para un usuario,
 * basándose en su plan y la config `features.php`.
 *
 * Uso:
 *   if (FeatureGate::allows('export_unlimited_rows', $user)) { ... }
 *
 *   abort_unless(
 *       FeatureGate::allows('webhook_exports', $request->user()),
 *       402,
 *       'Upgrade required'
 *   );
 *
 * Sin user → falso (no anónimo puede usar features pagas).
 * Sin gating configurado para esa feature → todos los planes la usan.
 */
class FeatureGate
{
    public static function allows(string $feature, ?User $user): bool
    {
        $config = config("features.features.{$feature}", null);

        // null en config = sin gating (todos lo usan, incluso anónimos)
        if ($config === null) return true;

        // Array vacío = nadie lo usa (feature deshabilitada)
        if (is_array($config) && empty($config)) return false;

        if (!$user) return false;

        return in_array(self::planOf($user), $config, true);
    }

    /**
     * Plan actual del usuario, derivado del tenant al que pertenece.
     * Multi-tenant SaaS: el plan es por workspace, no por usuario individual
     * (un user del tenant Pro tiene Pro automáticamente).
     *
     * El plan del tenant se deriva de su suscripción vigente
     * (Tenant::currentPlan(), que cae a 'free' si no hay suscripción).
     *
     * Fallbacks (defense-in-depth):
     *   tenant->currentPlan() → user->plan (legacy/sin tenant) → default
     */
    public static function planOf(User $user): string
    {
        if ($user->tenant) {
            return $user->tenant->currentPlan();
        }
        if (isset($user->plan) && $user->plan) {
            return $user->plan;
        }
        return config('features.default_plan', 'free');
    }
}
