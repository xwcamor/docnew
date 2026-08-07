<?php

namespace App\Jobs\SystemManagement\Regions;

use App\Exports\SystemManagement\Regions\RegionsExport;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class GenerateRegionsExcelJob extends BaseRegionExportJob
{
    protected string $type      = 'excel';
    protected string $extension = 'xlsx';

    protected function executeExport(Download $download): void
    {
        $query  = $this->buildQuery();
        $count  = (clone $query)->count();
        $cursor = $query->cursor();

        // Inyectamos el TZ del user en las options para que el RegionsExport
        // formatee created_at/updated_at en el TZ correcto. user_id queda
        // como fallback si algún día se llama al Export sin pasar tz.
        $opts = $this->options + [
            'user_id'  => $this->userId,
            'timezone' => $this->userTimezone,
        ];

        $content = Excel::raw(
            new RegionsExport($cursor, $opts, $count),
            ExcelFormat::XLSX,
        );

        $path = 'downloads/' . $download->filename;
        Storage::disk($download->disk)->put($path, $content);

        $download->update(['path' => $path, 'status' => 'ready']);
    }
}
