<?php

namespace App\Jobs\SystemManagement\Countries;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateCountriesCsvJob extends BaseCountryExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['id', 'name', 'is_active', 'created_at', 'updated_at'];

        $tempFile = tempnam(sys_get_temp_dir(), 'countries_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepción
        // ocurre durante el chunk loop (OOM, disk lleno, etc.). Sin esto, /tmp
        // se llena gradualmente con 1000s de exports en producción.
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'             => __('countries.id'),
                'name'           => __('countries.name'),
                'iso_code'       => __('countries.iso_code'),
                'currency'       => __('countries.currency'),
                'timezone'       => __('countries.timezone'),
                'region'         => __('countries.region'),
                'default_locale' => __('countries.default_locale'),
                'is_active'      => __('countries.is_active'),
                'slug'           => 'Slug',
                'created_at'     => __('global.created_at'),
                'updated_at'     => __('global.updated_at'),
                'creator'        => __('global.created_by'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            $this->buildQuery()->chunkById(1000, function ($countries) use ($handle, $columns, $tz) {
                foreach ($countries as $country) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'             => $country->id,
                        'name'           => $country->name,
                        'iso_code'       => $country->iso_code,
                        'currency'       => $country->currency,
                        'timezone'       => $country->timezone,
                        'region'         => $country->region?->name ?? '',
                        'default_locale' => $country->defaultLocale?->code ?? '',
                        'is_active'      => $country->is_active ? '1' : '0',
                        'slug'           => $country->slug,
                        'created_at'     => $country->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at'     => $country->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'creator'        => $country->creator?->name ?? '',
                        default          => $country->{$col} ?? '',
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
