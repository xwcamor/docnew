<?php

namespace App\Jobs\SystemManagement\Settings;

use App\Exports\SystemManagement\Settings\SettingsExport;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class GenerateSettingsExcelJob extends BaseSettingExportJob
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
            new SettingsExport($cursor, $opts, $count),
            ExcelFormat::XLSX,
        );

        $path = 'downloads/' . $download->filename;
        Storage::disk($download->disk)->put($path, $content);

        $download->update(['path' => $path, 'status' => 'ready']);
    }
}
