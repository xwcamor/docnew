<?php

namespace App\Services\Diagnostics;

/**
 * HealthIndexResult — el resultado del Índice de Salud (HI) de un transformador.
 *
 * Objeto de solo lectura. El HI combina las pruebas que TIENEN datos con la
 * fórmula de Hitachi y PESO DINÁMICO: las pruebas faltantes no se cuentan (no
 * castigan). Por eso un trafo con solo cromatografía perfecta da 100, no menos.
 */
class HealthIndexResult
{
    public function __construct(
        public readonly ?float $index,        // 0-100, o null si no hay ninguna prueba con datos
        public readonly ?string $condition,   // "Muy Bueno"… o null
        public readonly ?string $color,       // green | yellow | red | null
        public readonly array $components = [], // code => ['name','weight','rating','present']
        public readonly array $missing = [],    // códigos de pruebas habilitadas SIN datos
    ) {}

    /** ¿El HI se calculó con menos pruebas de las habilitadas? (diagnóstico parcial) */
    public function isPartial(): bool
    {
        return !empty($this->missing);
    }

    /** ¿Hubo al menos una prueba con datos para calcular? */
    public function hasData(): bool
    {
        return $this->index !== null;
    }

    public function toArray(): array
    {
        return [
            'index'      => $this->index === null ? null : round($this->index, 2),
            'condition'  => $this->condition,
            'color'      => $this->color,
            'components' => $this->components,
            'missing'    => $this->missing,
            'partial'    => $this->isPartial(),
        ];
    }
}
