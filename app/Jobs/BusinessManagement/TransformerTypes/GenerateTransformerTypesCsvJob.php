<?php

namespace App\Jobs\BusinessManagement\TransformerTypes;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateTransformerTypesCsvJob extends BaseTransformerTypeExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['id', 'name', 'code', 'is_active', 'created_at'];

        $tempFile = tempnam(sys_get_temp_dir(), 'transformerTypes_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepcion
        // ocurre durante el chunk loop (OOM, disk lleno, etc.).
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'         => __('transformer_types.id'),
                'name'       => __('transformer_types.name'),
                'code'       => __('transformer_types.code'),
                'sort_order' => __('transformer_types.sort_order'),
                'is_active'  => __('transformer_types.is_active'),
                'slug'       => 'Slug',
                'created_at' => __('global.created_at'),
                'updated_at' => __('global.updated_at'),
                'creator'    => __('global.created_by'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            // chunkById usa cursor (WHERE id > X), constante en memoria.
            $this->buildQuery()->chunkById(1000, function ($transformerTypes) use ($handle, $columns, $tz) {
                foreach ($transformerTypes as $transformerType) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'         => $transformerType->id,
                        'name'       => $transformerType->name,
                        'code'       => $transformerType->code ?? '',
                        'sort_order' => $transformerType->sort_order ?? '',
                        'is_active'  => $transformerType->is_active ? '1' : '0',
                        'slug'       => $transformerType->slug,
                        'created_at' => $transformerType->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at' => $transformerType->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'creator'    => $transformerType->creator?->name ?? '',
                        default      => $transformerType->{$col} ?? '',
                    }, $columns);
                    fputcsv($handle, $row);
                }
            }, 'transformer_types.id', 'id');

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
