<?php

namespace App\Jobs\AuthManagement\Roles;

use App\Exports\AuthManagement\Roles\RolesExport;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class GenerateRolesExcelJob extends BaseRoleExportJob
{
    protected string $type      = 'excel';
    protected string $extension = 'xlsx';

    protected function executeExport(Download $download): void
    {
        // withCount inyecta `permissions_count` y `users_count` para evitar
        // N+1 al render row-by-row.
        $query  = $this->buildQuery()->withCount(['permissions', 'users']);
        $count  = (clone $query)->count();
        $cursor = $query->cursor();

        $opts = $this->options + [
            'user_id'  => $this->userId,
            'timezone' => $this->userTimezone,
        ];

        $content = Excel::raw(
            new RolesExport($cursor, $opts, $count),
            ExcelFormat::XLSX,
        );

        $path = 'downloads/' . $download->filename;
        Storage::disk($download->disk)->put($path, $content);

        $download->update(['path' => $path, 'status' => 'ready']);
    }
}
