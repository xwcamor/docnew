<?php

namespace App\Jobs\BusinessManagement\People;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GeneratePeopleCsvJob extends BasePersonExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['id', 'lastname', 'name', 'doc_type', 'num_doc', 'is_active', 'created_at'];

        $tempFile = tempnam(sys_get_temp_dir(), 'people_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepcion
        // ocurre durante el chunk loop (OOM, disk lleno, etc.).
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'         => __('people.id'),
                'name'       => __('people.name'),
                'lastname'      => __('people.lastname'),
                'doc_type'      => __('people.doc_type'),
                'num_doc'       => __('people.num_doc'),
                'country'       => __('people.country'),
                'roles'         => __('people.roles'),
                'companies'     => __('people.companies'),
                'companies_count' => __('people.companies_count'),
                'has_biometric' => __('people.biometric'),
                'is_active'  => __('people.is_active'),
                'slug'       => 'Slug',
                'created_at' => __('global.created_at'),
                'updated_at' => __('global.updated_at'),
                'creator'    => __('global.created_by'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            // chunkById usa cursor (WHERE id > X), constante en memoria.
            $this->buildQuery()->chunkById(1000, function ($people) use ($handle, $columns, $tz) {
                $people = $this->taparDocumento($people);

                foreach ($people as $person) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'         => $person->id,
                        'name'       => $person->name,
                        'lastname'      => $person->lastname ?? '',
                        'doc_type'      => $person->doc_type ?? '',
                        'num_doc'       => $person->num_doc ?? '',
                        'country'       => $person->country?->name ?? '',
                        'roles'         => $person->roles->where('is_active', true)->map(fn ($r) => __('people.role_' . $r->role))->join(', '),
                        'companies'     => $person->companyLinks->map(fn ($l) => $l->company?->name)->filter()->join(', '),
                        'companies_count' => $person->company_links_count ?? '',
                        'has_biometric' => ($person->active_biometrics_count ?? 0) > 0 ? '1' : '0',
                        'is_active'  => $person->is_active ? '1' : '0',
                        'slug'       => $person->slug,
                        'created_at' => $person->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at' => $person->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'creator'    => $person->creator?->name ?? '',
                        default      => $person->{$col} ?? '',
                    }, $columns);
                    fputcsv($handle, $row);
                }
            }, 'people.id', 'id');

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
