<?php

namespace App\Jobs\BusinessManagement\Companies;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateCompaniesCsvJob extends BaseCompanyExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['id', 'name', 'num_doc', 'complete_name', 'country', 'is_active', 'created_at'];

        $tempFile = tempnam(sys_get_temp_dir(), 'companies_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepcion
        // ocurre durante el chunk loop (OOM, disk lleno, etc.).
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'         => __('companies.id'),
                'name'       => __('companies.name'),
                'num_doc'       => __('companies.num_doc'),
                'complete_name' => __('companies.complete_name'),
                'country'       => __('companies.country'),
                'people_count'  => __('companies.people_count'),
                'work_plans_count' => __('companies.plans_count'),
                'is_active'  => __('companies.is_active'),
                'slug'       => 'Slug',
                'created_at' => __('global.created_at'),
                'updated_at' => __('global.updated_at'),
                'creator'    => __('global.created_by'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            // chunkById usa cursor (WHERE id > X), constante en memoria.
            $this->buildQuery()->chunkById(1000, function ($companies) use ($handle, $columns, $tz) {
                foreach ($companies as $company) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'         => $company->id,
                        'name'       => $company->name,
                        'num_doc'       => $company->num_doc ?? '',
                        'complete_name' => $company->complete_name ?? '',
                        'country'       => $company->country?->name ?? '',
                        'people_count'  => $company->people_count ?? '',
                        'work_plans_count' => $company->work_plans_count ?? '',
                        // `state_text`, no 1/0: la cabecera dice «Estado» y los otros
                        // tres formatos escriben «Activo»/«Inactivo». El mismo dato
                        // salia distinto segun el boton que pulsaras.
                        'is_active'  => $company->state_text,
                        'slug'       => $company->slug,
                        'created_at' => $company->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at' => $company->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'creator'    => $company->creator?->name ?? '',
                        default      => $company->{$col} ?? '',
                    }, $columns);
                    fputcsv($handle, $row);
                }
            }, 'companies.id', 'id');

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
