<?php

namespace App\Services\Diagnostics;

/**
 * DiagnosticResult — el resultado de evaluar una prueba sobre una muestra.
 *
 * Objeto de solo lectura que devuelve el motor. Contiene el score numérico,
 * la condición textual (semáforo), el color, el rating 0-4 para el índice de
 * salud, y un detalle por gas (para mostrar en pantalla / depurar).
 */
class DiagnosticResult
{
    public function __construct(
        public readonly float $score,
        public readonly ?string $condition,
        public readonly ?string $color,
        public readonly ?float $hiRating,
        public readonly array $detail = [],      // por variable: score y peso aplicados
        public readonly array $missing = [],     // gases esperados que no vinieron
    ) {}

    public function isComplete(): bool
    {
        return empty($this->missing);
    }

    public function toArray(): array
    {
        return [
            'score'     => round($this->score, 4),
            'condition' => $this->condition,
            'color'     => $this->color,
            'hi_rating' => $this->hiRating,
            'detail'    => $this->detail,
            'missing'   => $this->missing,
            'complete'  => $this->isComplete(),
        ];
    }
}
