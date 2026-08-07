<?php

namespace App\Support;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Tz — resolución y formateo centralizado de timezone para mostrar fechas.
 *
 * La BD siempre guarda en UTC (config/app.php). Esta clase resuelve el TZ
 * efectivo del user según la jerarquía:
 *   1. user.timezone
 *   2. user.tenant.timezone
 *   3. user.country.timezone
 *   4. config('app.timezone') (= UTC)
 *
 * Y formatea con el shape que el proyecto adoptó:
 *   - datetime: 'd-m-Y H:i'  → "15-05-2026 16:02"
 *   - date:     'd-m-Y'      → "15-05-2026"
 *   - time:     'H:i'        → "16:02"
 *
 * Uso:
 *   Tz::for($user)                              // → "America/Lima"
 *   Tz::format($automation->created_at, $user)  // → "15-05-2026 16:02"
 *   Tz::formatDate($customer->created_at, $u)   // → "15-05-2026"
 *
 * En queue jobs no hay auth() — pasar siempre el $user explícito.
 */
class Tz
{
    /** Formato datetime estándar del proyecto. Cambiarlo aquí impacta global. */
    public const DATETIME_FORMAT = 'd-m-Y H:i';
    public const DATE_FORMAT     = 'd-m-Y';
    public const TIME_FORMAT     = 'H:i';

    /** Cache de TZ resueltos por user_id para evitar lookups repetidos. */
    protected static array $cache = [];

    /**
     * Devuelve el TZ efectivo del user. Null-safe: si no hay user, devuelve
     * el TZ de la app (UTC).
     */
    public static function for(?User $user): string
    {
        if (!$user) return config('app.timezone', 'UTC');

        if (isset(self::$cache[$user->id])) {
            return self::$cache[$user->id];
        }

        $tz = self::resolveFor($user);
        self::$cache[$user->id] = $tz;
        return $tz;
    }

    /**
     * Resuelve sin cache. Útil cuando se cambia el TZ del user/tenant en
     * runtime y necesitamos refrescar (ej. después de profile.update).
     */
    public static function forUncached(?User $user): string
    {
        if (!$user) return config('app.timezone', 'UTC');
        return self::resolveFor($user);
    }

    /** Limpia el cache de un user específico (o todos si se omite). */
    public static function forget(?int $userId = null): void
    {
        if ($userId === null) { self::$cache = []; return; }
        unset(self::$cache[$userId]);
    }

    /**
     * Formatea un datetime en el TZ efectivo del user.
     *
     * @param mixed $value     Carbon | DateTime | string ISO | null
     * @param ?User $user      User cuyo TZ usar. Si null, usa auth() o app TZ.
     * @param bool  $withTime  true = "d-m-Y H:i", false = "d-m-Y"
     */
    public static function format(mixed $value, ?User $user = null, bool $withTime = true): ?string
    {
        $carbon = self::toCarbon($value);
        if (!$carbon) return null;

        $tz = self::for($user ?? self::currentUser());
        return $carbon->setTimezone($tz)->format($withTime ? self::DATETIME_FORMAT : self::DATE_FORMAT);
    }

    /** Atajo: formato solo fecha. */
    public static function formatDate(mixed $value, ?User $user = null): ?string
    {
        return self::format($value, $user, withTime: false);
    }

    /** Atajo: formato solo hora ("16:02"). */
    public static function formatTime(mixed $value, ?User $user = null): ?string
    {
        $carbon = self::toCarbon($value);
        if (!$carbon) return null;

        $tz = self::for($user ?? self::currentUser());
        return $carbon->setTimezone($tz)->format(self::TIME_FORMAT);
    }

    /**
     * Lista de timezones disponibles para selectores de UI. Filtra solo las
     * regiones humanas (no UTC, no Etc/, no obsoletas como GMT+X) para que
     * el dropdown no tenga 400 opciones.
     */
    public static function availableTimezones(): array
    {
        $all = \DateTimeZone::listIdentifiers();
        return array_values(array_filter($all, function ($tz) {
            // Aceptamos solo Region/City (ej. America/Lima, Europe/Madrid).
            // Excluimos: Etc/*, GMT+/-*, UTC duplicate, deprecated.
            if (str_starts_with($tz, 'Etc/')) return false;
            if ($tz === 'UTC') return true; // dejamos UTC como opción explícita
            return str_contains($tz, '/');
        }));
    }

    // ─── Internos ────────────────────────────────────────────────────────────

    protected static function resolveFor(User $user): string
    {
        // 1. User propio
        if (!empty($user->timezone)) return $user->timezone;

        // 2. Tenant del user
        if ($user->tenant_id) {
            $tenant = $user->tenant ?? \App\Models\Tenant::withTrashed()->find($user->tenant_id);
            if (!empty($tenant?->timezone)) return $tenant->timezone;
        }

        // 3. Country del user
        if ($user->country_id) {
            $country = \App\Models\Country::find($user->country_id);
            if (!empty($country?->timezone)) return $country->timezone;
        }

        // 4. App default
        return config('app.timezone', 'UTC');
    }

    protected static function currentUser(): ?User
    {
        try {
            return auth()->user();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') return null;
        if ($value instanceof Carbon) return $value;
        if ($value instanceof DateTimeInterface) return Carbon::instance($value);
        try {
            return Carbon::parse((string) $value, 'UTC');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
