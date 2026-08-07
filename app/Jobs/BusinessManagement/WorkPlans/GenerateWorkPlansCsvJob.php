<?php

namespace App\Jobs\BusinessManagement\WorkPlans;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateWorkPlansCsvJob extends BaseWorkPlanExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['id', 'code', 'num_os', 'company', 'work_type', 'date_start', 'is_done', 'created_at'];

        $tempFile = tempnam(sys_get_temp_dir(), 'work_plans_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepcion
        // ocurre durante el chunk loop (OOM, disk lleno, etc.).
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'            => __('work_plans.id'),
                'code'          => __('work_plans.code'),
                'num_os'        => __('work_plans.num_os'),
                'description'   => __('work_plans.description'),
                'company'       => __('work_plans.company'),
                'work_type'     => __('work_plans.work_type'),
                'work_location' => __('work_plans.work_location'),
                'workstation'   => __('work_plans.workstation'),
                'work_area'     => __('work_plans.work_area'),
                'date_start'    => __('work_plans.date_start'),
                'date_end'      => __('work_plans.date_end'),
                'is_done'       => __('work_plans.is_done'),
                'is_locked'     => __('work_plans.is_locked'),
                'people_count'  => __('work_plans.people_count'),
                'registered_by' => __('work_plans.registered_by'),
                'slug'       => 'Slug',
                'created_at' => __('global.created_at'),
                'updated_at' => __('global.updated_at'),
                'creator'    => __('global.created_by'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            // chunkById usa cursor (WHERE id > X), constante en memoria.
            $this->buildQuery()->chunkById(1000, function ($work_plans) use ($handle, $columns, $tz) {
                foreach ($work_plans as $workPlan) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'            => $workPlan->id,
                        'code'          => $workPlan->code ?? '',
                        'num_os'        => $workPlan->num_os ?? '',
                        'description'   => $workPlan->description ?? '',
                        'company'       => $workPlan->company?->name ?? '',
                        'work_type'     => $workPlan->workType?->code ?? '',
                        'work_location' => $workPlan->workLocation?->name ?? '',
                        'workstation'   => $workPlan->workstation?->name ?? '',
                        'work_area'     => $workPlan->workArea?->name ?? '',
                        'date_start'    => $workPlan->date_start?->format('Y-m-d') ?? '',
                        'date_end'      => $workPlan->date_end?->format('Y-m-d') ?? '',
                        'is_done'       => $workPlan->is_done ? '1' : '0',
                        'is_locked'     => $workPlan->is_locked ? '1' : '0',
                        'people_count'  => $workPlan->people_count ?? '',
                        'registered_by' => $workPlan->user?->name ?? '',
                        'slug'       => $workPlan->slug,
                        'created_at' => $workPlan->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at' => $workPlan->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'creator'    => $workPlan->creator?->name ?? '',
                        default      => $workPlan->{$col} ?? '',
                    }, $columns);
                    fputcsv($handle, $row);
                }
            }, 'work_plans.id', 'id');

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
}
