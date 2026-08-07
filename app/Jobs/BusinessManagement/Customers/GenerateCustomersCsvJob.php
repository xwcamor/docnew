<?php

namespace App\Jobs\BusinessManagement\Customers;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateCustomersCsvJob extends BaseCustomerExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['id', 'name', 'cod', 'country', 'is_active', 'created_at'];

        $tempFile = tempnam(sys_get_temp_dir(), 'customers_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepcion
        // ocurre durante el chunk loop (OOM, disk lleno, etc.).
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'                 => __('customers.id'),
                'name'               => __('customers.name'),
                'cod'                => __('customers.cod'),
                'country'            => __('customers.country'),
                'address'            => __('customers.address'),
                'is_active'          => __('customers.is_active'),
                'locations_count'    => __('customers.locations'),
                'areas_count'        => __('customers.areas'),
                'substations_count'  => __('customers.substations'),
                'transformers_count' => __('customers.transformers'),
                'slug'               => 'Slug',
                'created_at'         => __('global.created_at'),
                'updated_at'         => __('global.updated_at'),
                'creator'            => __('global.created_by'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            // cursor() es lazy/unbuffered (constante en memoria) y RESPETA el
            // orderBy de la vista (sort/direction), a diferencia de chunkById que
            // forzaba orden por id. Mismo enfoque que los jobs Excel/PDF/Word.
            foreach ($this->buildQuery()->cursor() as $customer) {
                $row = array_map(fn ($col) => match ($col) {
                    'id'                 => $customer->id,
                    'name'               => $customer->name,
                    'cod'                => $customer->cod ?? '',
                    'country'            => $customer->country?->name ?? '',
                    'address'            => $customer->address ?? '',
                    'is_active'          => $customer->is_active ? '1' : '0',
                    'locations_count'    => $customer->locations_count ?? 0,
                    'areas_count'        => $customer->areas_count ?? 0,
                    'substations_count'  => $customer->substations_count ?? 0,
                    'transformers_count' => $customer->transformers_count ?? 0,
                    'slug'               => $customer->slug,
                    'created_at' => $customer->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                    'updated_at' => $customer->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                    'creator'    => $customer->creator?->name ?? '',
                    default      => $customer->{$col} ?? '',
                }, $columns);
                fputcsv($handle, $row);
            }

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
