<?php

namespace App\Jobs\SystemManagement\Locales;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateLocalesCsvJob extends BaseLocaleExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['id', 'name', 'is_active', 'created_at', 'updated_at'];

        $tempFile = tempnam(sys_get_temp_dir(), 'locales_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepción
        // ocurre durante el chunk loop (OOM, disk lleno, etc.). Sin esto, /tmp
        // se llena gradualmente con 1000s de exports en producción.
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'         => __('locales.id'),
                'name'       => __('locales.name'),
                'code'       => __('locales.code'),
                'language'   => __('locales.language'),
                'is_active'  => __('locales.is_active'),
                'slug'       => 'Slug',
                'created_at' => __('global.created_at'),
                'updated_at' => __('global.updated_at'),
                'creator'    => __('global.created_by'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            $this->buildQuery()->chunkById(1000, function ($locales) use ($handle, $columns, $tz) {
                foreach ($locales as $locale) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'         => $locale->id,
                        'name'       => $locale->name,
                        'code'       => $locale->code,
                        'language'   => $locale->language?->name ?? '',
                        'is_active'  => $locale->is_active ? '1' : '0',
                        'slug'       => $locale->slug,
                        'created_at' => $locale->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at' => $locale->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'creator'    => $locale->creator?->name ?? '',
                        default      => $locale->{$col} ?? '',
                    }, $columns);
                    fputcsv($handle, $row);
                }
            });

            fclose($handle);
            $handle = null;

            $content = file_get_contents($tempFile);
            $path    = 'downloads/' . $download->filename;

            // Storage::put + Download update en transacción: si put() falla
            // (disco lleno) el Download NO queda con `ready` apuntando a un
            // path inexistente. Si la put() pasa pero el update falla (race
            // con otro proceso), removemos el file para no dejar huérfanos.
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
