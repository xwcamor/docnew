<?php

namespace App\Support\Diagnostics;

/**
 * Colores del diagnóstico, editables por super (global) con override por tenant
 * — mismo patrón híbrido que las etiquetas/semáforos (RuleData → tenant→global→
 * JSON). Hoy el front (utils/severity.js) tiene los HEX clavados como fallback;
 * esta clase resuelve el override y se inyecta como prop compartida de Inertia
 * para que severity.js los lea (con el .js como última red de seguridad).
 *
 * Dos grupos:
 *  - `sema`  → semáforo de condición (token green/lime/yellow/orange/red → HEX).
 *  - `stops` → gradiente de severidad de celda (good/warn/bad → HEX), que el .js
 *              interpola verde→ámbar→rojo.
 */
class DiagnosticColors
{
    public const KEY = 'diagnostic_colors';

    /** Tokens de cada grupo (orden fijo; el editor y el .js dependen de esto). */
    public const SEMA_TOKENS  = ['green', 'lime', 'yellow', 'orange', 'red'];
    public const STOP_TOKENS  = ['good', 'warn', 'bad'];

    /** Default de fábrica (igual a los HEX clavados en severity.js). */
    public const DEFAULTS = [
        'sema'  => [
            'green'  => '#1D7044',
            'lime'   => '#5AA82E',
            'yellow' => '#E9A23B',
            'orange' => '#E2661E',
            'red'    => '#C8281D',
        ],
        'stops' => [
            'good' => '#2E933C',
            'warn' => '#E9A23B',
            'bad'  => '#C8281D',
        ],
    ];

    private const HEX = '/^#[0-9a-fA-F]{6}$/';

    /**
     * Mapa completo y válido para el tenant/scope actual: el override (si hay)
     * mezclado sobre el default. Siempre devuelve los 5 + 3 tokens con HEX válido
     * (un valor corrupto cae al default, nunca rompe el render).
     *
     * @return array{sema: array<string,string>, stops: array<string,string>}
     */
    public static function all(): array
    {
        $stored = RuleData::get(self::KEY);

        return [
            'sema'  => self::mergeGroup('sema', is_array($stored['sema'] ?? null) ? $stored['sema'] : []),
            'stops' => self::mergeGroup('stops', is_array($stored['stops'] ?? null) ? $stored['stops'] : []),
        ];
    }

    /** Mezcla un grupo sobre su default, validando que cada valor sea HEX. */
    private static function mergeGroup(string $group, array $override): array
    {
        $tokens = $group === 'sema' ? self::SEMA_TOKENS : self::STOP_TOKENS;
        $out = [];
        foreach ($tokens as $token) {
            $v = $override[$token] ?? null;
            $out[$token] = (is_string($v) && preg_match(self::HEX, $v)) ? $v : self::DEFAULTS[$group][$token];
        }
        return $out;
    }
}
