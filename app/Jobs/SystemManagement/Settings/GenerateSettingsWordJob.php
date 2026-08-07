<?php

namespace App\Jobs\SystemManagement\Settings;

use App\Exports\SystemManagement\Settings\SettingsWord;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;

class GenerateSettingsWordJob extends BaseSettingExportJob
{
    protected string $type      = 'word';
    protected string $extension = 'docx';

    protected function executeExport(Download $download): void
    {
        $query    = $this->buildQuery();
        $count    = (clone $query)->count();
        $settings = $query->cursor();
        $tempFile = tempnam(sys_get_temp_dir(), 'settings_export') . '.docx';

        $opts = $this->options + ['timezone' => $this->userTimezone];

        (new SettingsWord())->generate(
            settings:       $settings,
            filename:       $tempFile,
            options:        $opts,
            filtersSummary: $this->buildFiltersSummary(),
            generatedBy:    optional(\App\Models\User::find($this->userId))->name ?? '—',
            count:          $count,
        );

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        $path = 'downloads/' . $download->filename;
        Storage::disk($download->disk)->put($path, $content);

        $download->update(['path' => $path, 'status' => 'ready']);
    }
}
