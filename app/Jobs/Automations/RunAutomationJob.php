<?php

namespace App\Jobs\Automations;

use App\Models\Automation;
use App\Services\Automations\AutomationRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * RunAutomationJob — ejecuta una automation en background.
 *
 * Despachado por el comando automations:tick. Resuelve la automation por ID
 * (en lugar de pasar el modelo serializado) para no arrastrar estado viejo:
 * si entre el dispatch y la ejecución el usuario la edita, queremos la
 * versión actual.
 *
 * No reintenta automáticamente — los fallos quedan registrados en
 * automation_runs y el usuario decide qué hacer desde la UI.
 */
class RunAutomationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $automationId) {}

    public function handle(AutomationRunner $runner): void
    {
        $automation = Automation::find($this->automationId);
        if (!$automation || !$automation->is_active) return;

        $runner->run($automation);
    }
}
