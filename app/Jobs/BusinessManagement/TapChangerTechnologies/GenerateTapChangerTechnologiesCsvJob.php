<?php

namespace App\Jobs\BusinessManagement\TapChangerTechnologies;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateTapChangerTechnologiesCsvJob extends BaseTapChangerTechnologyExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['id', 'name', 'code', 'is_active', 'created_at'];

        $tempFile = tempnam(sys_get_temp_dir(), 'tapChangerTechnologies_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepcion
        // ocurre durante el chunk loop (OOM, disk lleno, etc.).
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'         => __('tap_changer_technologies.id'),
                'name'       => __('tap_changer_technologies.name'),
                'code'       => __('tap_changer_technologies.code'),
                'sort_order' => __('tap_changer_technologies.sort_order'),
                'is_active'  => __('tap_changer_technologies.is_active'),
                'slug'       => 'Slug',
                'created_at' => __('global.created_at'),
                'updated_at' => __('global.updated_at'),
                'creator'    => __('global.created_by'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            // chunkById usa cursor (WHERE id > X), constante en memoria.
            $this->buildQuery()->chunkById(1000, function ($tapChangerTechnologies) use ($handle, $columns, $tz) {
                foreach ($tapChangerTechnologies as $tapChangerTechnology) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'         => $tapChangerTechnology->id,
                        'name'       => $tapChangerTechnology->name,
                        'code'       => $tapChangerTechnology->code ?? '',
                        'sort_order' => $tapChangerTechnology->sort_order ?? '',
                        'is_active'  => $tapChangerTechnology->is_active ? '1' : '0',
                        'slug'       => $tapChangerTechnology->slug,
                        'created_at' => $tapChangerTechnology->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at' => $tapChangerTechnology->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'creator'    => $tapChangerTechnology->creator?->name ?? '',
                        default      => $tapChangerTechnology->{$col} ?? '',
                    }, $columns);
                    fputcsv($handle, $row);
                }
            }, 'tap_changer_technologies.id', 'id');

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
