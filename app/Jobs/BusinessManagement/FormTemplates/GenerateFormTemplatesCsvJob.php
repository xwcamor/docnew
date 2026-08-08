<?php

namespace App\Jobs\BusinessManagement\FormTemplates;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateFormTemplatesCsvJob extends BaseFormTemplateExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['id', 'name', 'code', 'is_active', 'created_at'];

        $tempFile = tempnam(sys_get_temp_dir(), 'form_templates_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepcion
        // ocurre durante el chunk loop (OOM, disk lleno, etc.).
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'         => __('form_templates.id'),
                'name'       => __('form_templates.name'),
                'code'       => __('form_templates.code'),
                // Era `sort_order`: ni la columna existe en esta tabla ni existia
                // la clave `form_templates.sort_order`, asi que el CSV salia con
                // la cabecera literal sin traducir y la celda vacia. Ademas el
                // frontend pide `version`, que aqui no estaba contemplada.
                'version'    => __('form_templates.version'),
                'kind'       => __('form_templates.kind'),
                'status'     => __('form_templates.status'),
                'is_active'  => __('form_templates.is_active'),
                'slug'       => 'Slug',
                'created_at' => __('global.created_at'),
                'updated_at' => __('global.updated_at'),
                'creator'    => __('global.created_by'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            // chunkById usa cursor (WHERE id > X), constante en memoria.
            $this->buildQuery()->chunkById(1000, function ($form_templates) use ($handle, $columns, $tz) {
                foreach ($form_templates as $formTemplate) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'         => $formTemplate->id,
                        'name'       => $formTemplate->name,
                        'code'       => $formTemplate->code ?? '',
                        'version'    => (string) $formTemplate->version,
                        'kind'       => __('form_templates.kind_' . $formTemplate->kind),
                        'status'     => __('form_templates.status_' . $formTemplate->status),
                        'is_active'  => $formTemplate->is_active ? '1' : '0',
                        'slug'       => $formTemplate->slug,
                        'created_at' => $formTemplate->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at' => $formTemplate->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'creator'    => $formTemplate->creator?->name ?? '',
                        default      => $formTemplate->{$col} ?? '',
                    }, $columns);
                    fputcsv($handle, $row);
                }
            }, 'form_templates.id', 'id');

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
