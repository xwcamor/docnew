<?php

namespace App\Support\Transformers;

use App\Support\Diagnostics\ConditionLabel;

/**
 * Redacción del informe: las frases de diagnóstico y las conclusiones por prueba.
 *
 * Vivía dentro del blade del PDF, así que el informe en Word no podía usarlas y
 * terminaban reescribiéndose a mano (y saliendo distintas). Acá es UNA sola
 * fuente: el blade y `TransformerReportWord` imprimen exactamente lo mismo.
 *
 * No decide nada: solo traduce a texto lo que ya resolvieron los motores de
 * diagnóstico (el `diagnosisSummary` del payload).
 */
class ReportNarrative
{
    /**
     * @param  array  $summary  `diagnosisSummary` del payload.
     * @return array{
     *   cromas: array{diag: string[], concl: string[], level: int},
     *   fiquis: array{diag: string[], concl: string[], level: int},
     *   furanos: array{diag: string[], concl: string[], level: int},
     *   fpot: array{diag: string[], concl: string[], level: int}
     * }
     */
    public static function build(array $summary): array
    {
        return [
            'cromas'  => self::cromas($summary['cromas'] ?? []),
            'fiquis'  => self::fiquis($summary['fiquis'] ?? []),
            'furanos' => self::furanos($summary['furanos'] ?? []),
            'fpot'    => self::fpot($summary['fpot'] ?? []),
        ];
    }

    /** Encabezado de urgencia de cada conclusión (escala de acción común). */
    public static function urgency(int $level): string
    {
        return __('diagnostics.urgency_' . (['routine', 'watch', 'investigate', 'critical'][$level] ?? 'routine'));
    }

    private static function cond(?string $c): string
    {
        return $c === null ? '—' : ConditionLabel::forCondition($c);
    }

    private static function cromas(array $v): array
    {
        $diag = [];
        $concl = [];

        if (($v['has_data'] ?? false) && ($v['condition'] ?? null) !== null) {
            $diag[] = __('cromas.diag.state', ['score' => $v['score'] ?? '—', 'condition' => self::cond($v['condition'])]);
            $diag[] = ($v['active_fault'] ?? false) ? __('cromas.diag.fault') : __('cromas.diag.no_fault');

            if (($v['active_fault'] ?? false) && !empty($v['fault_zone'])) {
                $diag[] = __('cromas.diag.fault_duval', ['zone' => __('cromas.duval.' . $v['fault_zone'])]);
            }
            if (($v['active_fault'] ?? false) && !empty($v['over'])) {
                $diag[] = __('cromas.diag.gases_over', ['gases' => implode(', ', array_filter(array_column($v['over'], 'field')))]);
            }
            if (!empty($v['approaching'])) {
                $diag[] = __('cromas.diag.gases_near', ['gases' => implode(', ', $v['approaching'])]);
            }

            if ($v['active_fault'] ?? false) {
                $concl[] = __('cromas.diag.concl_fault');
                if (($v['rating'] ?? 4) >= 3) {
                    $concl[] = __('cromas.diag.concl_fault_mild', ['condition' => self::cond($v['condition'])]);
                }
            } else {
                $concl[] = (($v['rating'] ?? 4) >= 3) ? __('cromas.diag.concl_routine') : __('cromas.diag.concl_watch');
            }
            if (!empty($v['approaching'])) {
                $concl[] = __('cromas.diag.concl_approaching', ['gases' => implode(', ', $v['approaching'])]);
            }
        }

        return ['diag' => $diag, 'concl' => $concl, 'level' => (int) ($v['action_level'] ?? 0)];
    }

    private static function fiquis(array $v): array
    {
        $diag = [];
        $concl = [];

        if (($v['has_data'] ?? false) && ($v['condition'] ?? null) !== null) {
            $diag[] = __('fiquis.diag.state', [
                'condition' => self::cond($v['condition']),
                'class'     => $v['class'] ? __('fiquis.diag.class_' . $v['class']) : __('fiquis.diag.class_na'),
            ]);

            $bad = collect($v['params'] ?? [])->filter(fn ($p) => ($p['score'] ?? 1) >= 3);
            $diag[] = $bad->count()
                ? __('fiquis.diag.params_bad', ['list' => $bad->map(fn ($p) => __('fiquis.' . $p['key']) . ' (' . $p['value'] . ')')->implode(', ')])
                : __('fiquis.diag.params_ok');

            if (!empty($v['approaching'])) {
                $diag[] = __('fiquis.diag.params_near', ['list' => implode(', ', $v['approaching'])]);
            }

            $lvl = (int) ($v['action_level'] ?? 0);
            $concl[] = __('fiquis.diag.' . ($lvl >= 2 ? 'concl_investigate' : ($lvl === 1 ? 'concl_watch' : 'concl_routine')));
            if (!empty($v['approaching'])) {
                $concl[] = __('fiquis.diag.concl_approaching', ['list' => implode(', ', $v['approaching'])]);
            }
        }

        return ['diag' => $diag, 'concl' => $concl, 'level' => (int) ($v['action_level'] ?? 0)];
    }

    private static function furanos(array $v): array
    {
        $diag = [];
        $concl = [];

        if ($v['has_data'] ?? false) {
            if (($v['dp'] ?? null) !== null) {
                $diag[] = __('transformers.report.furanos_dp_line', ['dp' => $v['dp'], 'life' => $v['life_percent'] ?? '—']);
            }
            if (($v['condition'] ?? null) !== null) {
                $diag[] = __('transformers.report.furanos_state_line', ['condition' => self::cond($v['condition'])]);
                // El PDF resuelve la recomendación por nivel de acción.
                $concl[] = __('furanos.' . (['reco_routine', 'reco_monitor', 'reco_increase', 'reco_critical'][$v['action_level'] ?? 0] ?? 'reco_routine'));
            }
        }

        return ['diag' => $diag, 'concl' => $concl, 'level' => (int) ($v['action_level'] ?? 0)];
    }

    private static function fpot(array $v): array
    {
        $diag = [];
        $concl = [];

        if (($v['has_data'] ?? false) && ($v['condition'] ?? null) !== null) {
            $diag[] = __('fpot.diag.value', ['v' => $v['value'] ?? '—', 'condition' => self::cond($v['condition'])]);

            if (($v['exceeds'] ?? false) && ($v['limit'] ?? null) !== null) {
                $diag[] = __('fpot.diag.over', ['limit' => $v['limit']]);
            } elseif (($v['near'] ?? false) && ($v['limit'] ?? null) !== null) {
                $diag[] = __('fpot.diag.near', ['limit' => $v['limit']]);
            }

            $lvl = (int) ($v['action_level'] ?? 0);
            $concl[] = __('fpot.diag.' . ($lvl >= 2 ? 'concl_investigate' : ($lvl === 1 ? 'concl_watch' : 'concl_routine')));
            if (($v['near'] ?? false) && (($v['rating'] ?? 4) >= 3) && ($v['limit'] ?? null) !== null) {
                $concl[] = __('fpot.diag.concl_approaching', ['limit' => $v['limit']]);
            }
        }

        return ['diag' => $diag, 'concl' => $concl, 'level' => (int) ($v['action_level'] ?? 0)];
    }
}
