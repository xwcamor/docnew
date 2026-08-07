<?php

namespace App\Services\Automations\Contracts;

use App\Models\Automation;
use Illuminate\Support\Collection;

/**
 * ActionContract — acción que una automation puede ejecutar.
 *
 * Cada action declara:
 *   - key(): identificador único usado en automations.action_type
 *   - label(): nombre para la UI
 *   - configSchema(): forma del JSON action_config (qué campos espera).
 *     Se usa en el frontend para renderizar los inputs adecuados.
 *   - execute(): hace el trabajo (mandar email, crear notif, etc.) y
 *     devuelve un string corto resumen (queda en automation_runs.output_summary).
 *
 * El parámetro $data puede ser null si la action no necesita registros previos
 * (ej. "envía un email recordatorio sin data" — útil para alertas time-based).
 */
interface ActionContract
{
    public function key(): string;

    public function label(): string;

    /** @return array<string, array{type: string, label: string, required?: bool}> */
    public function configSchema(): array;

    public function execute(Automation $automation, ?Collection $data): string;
}
