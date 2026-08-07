<?php

namespace App\Jobs\AuthManagement\Users;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateUsersCsvJob extends BaseUserExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['id', 'name', 'email', 'role', 'is_active', 'created_at'];

        $tempFile = tempnam(sys_get_temp_dir(), 'users_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepcion
        // ocurre durante el chunk loop (OOM, disk lleno, etc.).
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'         => __('users.id'),
                'name'       => __('users.name'),
                'email'      => __('users.email'),
                'role'       => __('users.role'),
                'tenant'     => __('users.tenant'),
                'is_active'  => __('users.is_active'),
                'slug'       => 'Slug',
                'created_at' => __('global.created_at'),
                'updated_at' => __('global.updated_at'),
                'creator'    => __('global.created_by'),
                'photo'      => __('users.photo'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            // chunkById usa cursor (WHERE id > X), constante en memoria.
            $this->buildQuery()->chunkById(1000, function ($users) use ($handle, $columns, $tz) {
                foreach ($users as $user) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'         => $user->id,
                        'name'       => $user->name,
                        'email'      => $user->email,
                        'role'       => $user->roles->first()?->name ?? '',
                        'tenant'     => $user->tenant?->name ?? '',
                        'is_active'  => $user->is_active ? '1' : '0',
                        'slug'       => $user->slug,
                        'created_at' => $user->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at' => $user->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'creator'    => $user->creator?->name ?? '',
                        'photo'      => $user->photo ?? '',
                        default      => $user->{$col} ?? '',
                    }, $columns);
                    fputcsv($handle, $row);
                }
            }, 'users.id', 'id');

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
