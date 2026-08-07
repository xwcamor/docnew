<?php

namespace App\Jobs\AutomationManagement\Automations;

use App\Models\Automation;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateAutomationsCsvJob extends BaseAutomationExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? [
            'id', 'name', 'description', 'is_active', 'trigger', 'data_source', 'action_type',
            'runs_count', 'failures_count', 'last_run_at', 'next_run_at',
            'created_at', 'updated_at', 'creator',
        ];

        $tempFile = tempnam(sys_get_temp_dir(), 'automations_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepcion
        // ocurre durante el chunk loop (OOM, disk lleno, etc.).
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'             => __('automations.id'),
                'name'           => __('automations.name'),
                'description'    => __('automations.description'),
                'is_active'      => __('automations.is_active'),
                'trigger'        => __('automations.col_trigger'),
                'data_source'    => __('automations.data_source'),
                'action_type'    => __('automations.action_type'),
                'runs_count'     => __('automations.col_runs'),
                'failures_count' => __('automations.col_failures'),
                'last_run_at'    => __('automations.col_last_run'),
                'next_run_at'    => __('automations.col_next_run'),
                'created_at'     => __('global.created_at'),
                'updated_at'     => __('global.updated_at'),
                'creator'        => __('global.created_by'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            // chunkById usa cursor (WHERE id > X), constante en memoria.
            $this->buildQuery()->chunkById(1000, function ($automations) use ($handle, $columns, $tz) {
                foreach ($automations as $automation) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'             => $automation->id,
                        'name'           => $automation->name,
                        'description'    => $automation->description ?? '',
                        'is_active'      => $automation->is_active ? __('global.active') : __('global.inactive'),
                        'trigger'        => $this->formatTrigger($automation),
                        'data_source'    => $automation->data_source ?? '',
                        'action_type'    => $automation->action_type ?? '',
                        'runs_count'     => (int) $automation->runs_count,
                        'failures_count' => (int) $automation->failures_count,
                        'last_run_at'    => $automation->last_run_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT) ?? '',
                        'next_run_at'    => $automation->next_run_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT) ?? '',
                        'created_at'     => $automation->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at'     => $automation->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'creator'        => $automation->creator?->name ?? '',
                        default          => $automation->{$col} ?? '',
                    }, $columns);
                    fputcsv($handle, $row);
                }
            }, 'automations.id', 'id');

            fclose($handle);
            $handle = null;

            $content = file_get_contents($tempFile);
            $path    = 'downloads/' . $download->filename;

            // Storage::put + Download update en transaccion para no dejar
            // un Download `ready` apuntando a un path inexistente.
            \DB::transaction(function () use ($download, $path, $content) {
                Storage::disk($download->disk)->put($path, $content);
                $download->update(['path' => $path, 'status' => 'ready']);
            });
        } finally {
            if (is_resource($handle)) @fclose($handle);
            if (file_exists($tempFile)) @unlink($tempFile);
        }
    }

    /**
     * Deriva una etiqueta humana del trigger a partir de trigger_type +
     * trigger_config.kind. Replicado (no via trait) en cada Export class
     * para evitar acoplamiento — el patron es pequeno y se entiende inline.
     */
    protected function formatTrigger(Automation $automation): string
    {
        $config = $automation->trigger_config ?? [];
        $kind   = $config['kind'] ?? null;

        return match ($kind) {
            'cron'    => 'Cron: ' . ($config['expression'] ?? '?'),
            'daily'   => __('automations.trigger_kind_daily') . ' ' . ($config['time'] ?? '?'),
            'weekly'  => __('automations.trigger_kind_weekly') . ' ' . __('automations.trigger_day_of_week') . ' ' . ((int) ($config['day'] ?? 1)) . ' ' . ($config['time'] ?? '?'),
            'monthly' => __('automations.trigger_kind_monthly') . ' ' . __('automations.trigger_day_of_month') . ' ' . ($config['day'] ?? '?') . ' ' . ($config['time'] ?? '?'),
            default   => (string) ($automation->trigger_type ?? '—'),
        };
    }
}
