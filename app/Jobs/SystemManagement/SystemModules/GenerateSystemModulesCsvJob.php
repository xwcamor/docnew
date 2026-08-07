<?php

namespace App\Jobs\SystemManagement\SystemModules;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateSystemModulesCsvJob extends BaseSystemModuleExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['id', 'name', 'is_active', 'created_at', 'updated_at'];

        $tempFile = tempnam(sys_get_temp_dir(), 'system_modules_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepción
        // ocurre durante el chunk loop (OOM, disk lleno, etc.). Sin esto, /tmp
        // se llena gradualmente con 1000s de exports en producción.
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'         => __('system_modules.id'),
                'name'       => __('system_modules.name'),
                'is_active'  => __('system_modules.is_active'),
                'slug'       => 'Slug',
                'created_at' => __('global.created_at'),
                'updated_at' => __('global.updated_at'),
                'creator'    => __('global.created_by'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            // chunkById usa cursor (WHERE id > X), constante en memoria.
            $this->buildQuery()->chunkById(1000, function ($system_modules) use ($handle, $columns, $tz) {
                foreach ($system_modules as $system_module) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'         => $system_module->id,
                        'name'       => $system_module->name,
                        'is_active'  => $system_module->is_active ? '1' : '0',
                        'slug'       => $system_module->slug,
                        'created_at' => $system_module->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at' => $system_module->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'creator'    => $system_module->creator?->name ?? '',
                        default      => $system_module->{$col} ?? '',
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
