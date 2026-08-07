<?php

namespace App\Jobs\SystemManagement\Regions;

use App\Exports\SystemManagement\Regions\RegionsWord;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;

class GenerateRegionsWordJob extends BaseRegionExportJob
{
    protected string $type      = 'word';
    protected string $extension = 'docx';

    protected function executeExport(Download $download): void
    {
        $query    = $this->buildQuery();
        $count    = (clone $query)->count();
        $regions  = $query->cursor();
        $tempFile = tempnam(sys_get_temp_dir(), 'regions_export') . '.docx';

        // Inyectamos el TZ del user en las options para que RegionsWord
        // formatee created_at/updated_at en el huso correcto.
        $opts = $this->options + ['timezone' => $this->userTimezone];

        (new RegionsWord())->generate(
            regions:        $regions,
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
