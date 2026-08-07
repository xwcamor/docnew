<?php

namespace App\Jobs\AuthManagement\Roles;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 *
 * permissions_count y users_count se cuentan per-row via relationships — en
 * lugar de withCount para evitar materializar todo el dataset. Costo: 2 queries
 * extra por chunk de 1000 — trivial vs el ahorro de memoria.
 */
class GenerateRolesCsvJob extends BaseRoleExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['id', 'name', 'description', 'is_active', 'permissions_count', 'users_count', 'tenant', 'created_at'];

        $tempFile = tempnam(sys_get_temp_dir(), 'roles_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepcion
        // ocurre durante el chunk loop (OOM, disk lleno, etc.).
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'                => 'N°',
                'name'              => __('roles.name'),
                'description'       => __('roles.description'),
                'is_active'         => __('roles.is_active'),
                'permissions_count' => __('roles.permissions_count'),
                'users_count'       => __('roles.users_count'),
                'tenant'            => __('roles.tenant'),
                'slug'              => 'Slug',
                'created_at'        => __('global.created_at'),
                'updated_at'        => __('global.updated_at'),
                'creator'           => __('global.created_by'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            // chunkById usa cursor (WHERE id > X), constante en memoria.
            $this->buildQuery()->chunkById(1000, function ($roles) use ($handle, $columns, $tz) {
                foreach ($roles as $role) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'                => $role->id,
                        'name'              => $role->name,
                        'description'       => $role->description ?? '',
                        'is_active'         => $role->is_active ? '1' : '0',
                        'permissions_count' => $role->permissions()->count(),
                        'users_count'       => $role->users()->count(),
                        'tenant'            => $role->tenant?->name ?? '—',
                        'slug'              => $role->slug,
                        'created_at'        => $role->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at'        => $role->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'creator'           => $role->creator?->name ?? '',
                        default             => $role->{$col} ?? '',
                    }, $columns);
                    fputcsv($handle, $row);
                }
            }, 'roles.id', 'id');

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
