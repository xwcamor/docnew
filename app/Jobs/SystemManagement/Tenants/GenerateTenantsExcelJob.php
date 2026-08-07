<?php

namespace App\Jobs\SystemManagement\Tenants;

use App\Exports\SystemManagement\Tenants\TenantsExport;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class GenerateTenantsExcelJob extends BaseTenantExportJob
{
    protected string $type      = 'excel';
    protected string $extension = 'xlsx';

    protected function executeExport(Download $download): void
    {
        $query  = $this->buildQuery();
        $count  = (clone $query)->count();
        $cursor = $query->cursor();

        $opts = $this->options + [
            'user_id'  => $this->userId,
            'timezone' => $this->userTimezone,
        ];

        $content = Excel::raw(
            new TenantsExport($cursor, $opts, $count),
            ExcelFormat::XLSX,
        );

        $path = 'downloads/' . $download->filename;
        Storage::disk($download->disk)->put($path, $content);

        $download->update(['path' => $path, 'status' => 'ready']);
    }
}
