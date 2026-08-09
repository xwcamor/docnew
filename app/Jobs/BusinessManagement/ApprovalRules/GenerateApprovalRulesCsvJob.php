<?php

namespace App\Jobs\BusinessManagement\ApprovalRules;

use App\Models\Download;
use Illuminate\Support\Facades\Storage;

/**
 * Export streaming a CSV. A diferencia de Excel/PDF/Word (cargan en memoria),
 * este job escribe fila por fila con `fputcsv` y `chunkById(1000)`. Soporta
 * cualquier volumen sin OOM-ear.
 */
class GenerateApprovalRulesCsvJob extends BaseApprovalRuleExportJob
{
    protected string $type      = 'csv';
    protected string $extension = 'csv';

    protected function executeExport(Download $download): void
    {
        $columns = $this->options['columns'] ?? ['name', 'country', 'work_type', 'approver_role', 'priority_level', 'is_required', 'is_active'];

        $tempFile = tempnam(sys_get_temp_dir(), 'approval_rules_csv') . '.csv';
        $handle   = fopen($tempFile, 'w');

        // try/finally garantiza cleanup del tempfile incluso si una excepcion
        // ocurre durante el chunk loop (OOM, disk lleno, etc.).
        try {
            // BOM para que Excel detecte UTF-8 al abrir.
            fwrite($handle, "\xEF\xBB\xBF");

            $headings = [
                'id'             => __('approval_rules.id'),
                'name'           => __('approval_rules.name'),
                'country'        => __('approval_rules.country'),
                'work_type'      => __('approval_rules.work_type'),
                'approver_role'  => __('approval_rules.approver_role'),
                'priority_level' => __('approval_rules.priority_level'),
                'is_required'    => __('approval_rules.is_required'),
                'is_active'      => __('approval_rules.is_active'),
                'slug'           => 'Slug',
                'created_at'     => __('global.created_at'),
                'updated_at'     => __('global.updated_at'),
            ];
            fputcsv($handle, array_map(fn ($k) => $headings[$k] ?? $k, $columns));

            $tz = $this->userTimezone;
            // chunkById usa cursor (WHERE id > X), constante en memoria.
            $this->buildQuery()->chunkById(1000, function ($approval_rules) use ($handle, $columns, $tz) {
                foreach ($approval_rules as $approvalRule) {
                    $row = array_map(fn ($col) => match ($col) {
                        'id'             => $approvalRule->id,
                        'country'        => $approvalRule->country?->name ?? '',
                        // Vacío en el CSV = todos los tipos, igual que en la
                        // plantilla de importación: el archivo se puede reimportar.
                        'work_type'      => $approvalRule->workType?->code ?? '',
                        'approver_role'  => $approvalRule->approver_role,
                        'priority_level' => $approvalRule->priority_level,
                        'is_required'    => $approvalRule->is_required ? '1' : '0',
                        'is_active'      => $approvalRule->is_active ? '1' : '0',
                        'slug'           => $approvalRule->slug,
                        'created_at'     => $approvalRule->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        'updated_at'     => $approvalRule->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT),
                        default      => $approvalRule->{$col} ?? '',
                    }, $columns);
                    fputcsv($handle, $row);
                }
            }, 'approval_rules.id', 'id');

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
