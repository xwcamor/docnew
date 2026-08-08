<?php

namespace App\Jobs\BusinessManagement\ApproverRoles;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateApproverRolesCsvJob extends BaseApproverRoleExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['code', 'name_es', 'name_en', 'sort_order', 'is_active', 'created_at'];

        $tempFile = tempnam(sys_get_temp_dir(), 'approver_roles_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepcion
        // ocurre durante el chunk loop (OOM, disk lleno, etc.).
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'         => __('approver_roles.id'),
                'code'       => __('approver_roles.code'),
                'name_es'    => __('approver_roles.name_es'),
                'name_en'    => __('approver_roles.name_en'),
                'sort_order' => __('approver_roles.sort_order'),
                'is_active'  => __('approver_roles.is_active'),
                'slug'       => 'Slug',
                'created_at' => __('global.created_at'),
                'updated_at' => __('global.updated_at'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            // chunkById usa cursor (WHERE id > X), constante en memoria.
            $this->buildQuery()->chunkById(1000, function ($approver_roles) use ($handle, $columns, $tz) {
                foreach ($approver_roles as $approverRole) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'         => $approverRole->id,
                        'code'       => $approverRole->code ?? '',
                        'name_es'    => $approverRole->name_es,
                        'name_en'    => $approverRole->name_en,
                        'sort_order' => $approverRole->sort_order ?? '',
                        'is_active'  => $approverRole->is_active ? '1' : '0',
                        'slug'       => $approverRole->slug,
                        'created_at' => $approverRole->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at' => $approverRole->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        default      => $approverRole->{$col} ?? '',
                    }, $columns);
                    fputcsv($handle, $row);
                }
            }, 'approver_roles.id', 'id');

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
