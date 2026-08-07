<?php

namespace App\Jobs\BusinessManagement\Transformers;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateTransformersCsvJob extends BaseTransformerExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $defs    = \App\Support\Transformers\TransformerExportColumns::definitions($this->userTimezone);
        $columns = $this->options['columns'] ?? array_keys($defs);
        // Solo columnas conocidas (defensa ante input inválido).
        $columns = array_values(array_filter($columns, fn ($k) => isset($defs[$k])));
        if (empty($columns)) {
            $columns = array_keys($defs);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'transformers_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepcion
        // ocurre durante el chunk loop (OOM, disk lleno, etc.).
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, array_map(fn ($k) => $defs[$k]['heading'], $columns));

            // chunkById usa cursor (WHERE id > X), constante en memoria.
            $this->buildQuery()->chunkById(1000, function ($transformers) use ($handle, $columns, $defs) {
                foreach ($transformers as $transformer) {
                    $row = array_map(
                        fn ($col) => $defs[$col]['value']($transformer) ?? '',
                        $columns
                    );
                    fputcsv($handle, $row);
                }
            }, 'transformers.id', 'id');

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
