<?php

namespace App\Services\Automations;

use App\Models\Automation;
use App\Models\AutomationRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AutomationRunner — orquesta una ejecución completa.
 *
 * Flujo:
 *   1. Crea fila en automation_runs con status='running'.
 *   2. Si hay data_source, la resuelve y ejecuta fetch().
 *   3. Resuelve el action y lo ejecuta pasando los datos.
 *   4. Actualiza la fila con status='success' + output_summary.
 *   5. Incrementa runs_count y last_run_at en la automation.
 *
 * Si algo falla en cualquier paso, status='failed' y error_message tiene
 * el detalle. failures_count incrementa. La automation NO se desactiva
 * automáticamente — el usuario decide si la pausa o la deja seguir intentando.
 */
class AutomationRunner
{
    public function __construct(
        protected DataSourceRegistry $sources,
        protected ActionRegistry $actions,
    ) {}

    public function run(Automation $automation): AutomationRun
    {
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'tenant_id'     => $automation->tenant_id,
            'started_at'    => now(),
            'status'        => 'running',
        ]);

        try {
            // 1. Si hay data_source, traer los registros.
            $data = null;
            if ($automation->data_source) {
                $source = $this->sources->resolve($automation->data_source);
                $data   = $source->fetch($automation);
            }

            // 2. Ejecutar action.
            $action  = $this->actions->resolve($automation->action_type);
            $summary = $action->execute($automation, $data);

            // 3. Marcar run como exitoso.
            $run->update([
                'finished_at'     => now(),
                'status'          => 'success',
                'records_matched' => $data?->count(),
                'output_summary'  => $summary,
            ]);

            // 4. Stats en la automation. `increment` corre como
            // `UPDATE ... SET runs_count = runs_count + 1` — atómico a nivel
            // BD. Dos ejecuciones concurrentes ya no se pisan la cuenta.
            $automation->forceFill(['last_run_at' => now()])->save();
            $automation->increment('runs_count');

            return $run;
        } catch (Throwable $e) {
            Log::error('Automation run failed', [
                'automation_id' => $automation->id,
                'error'         => $e->getMessage(),
            ]);

            $run->update([
                'finished_at'   => now(),
                'status'        => 'failed',
                'error_message' => substr($e->getMessage(), 0, 1000),
            ]);

            $automation->increment('failures_count');

            return $run;
        }
    }
}
