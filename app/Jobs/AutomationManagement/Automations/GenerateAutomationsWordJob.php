<?php

namespace App\Jobs\AutomationManagement\Automations;

use App\Exports\AutomationManagement\Automations\AutomationsWord;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;

class GenerateAutomationsWordJob extends BaseAutomationExportJob
{
    protected string $type      = 'word';
    protected string $extension = 'docx';

    protected function executeExport(Download $download): void
    {
        $query       = $this->buildQuery();
        $count       = (clone $query)->count();
        $automations = $query->cursor();
        $tempFile    = tempnam(sys_get_temp_dir(), 'automations_export') . '.docx';

        $opts = $this->options + ['timezone' => $this->userTimezone];

        (new AutomationsWord())->generate(
            automations:    $automations,
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
