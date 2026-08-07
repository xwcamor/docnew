<?php

namespace App\Jobs\BusinessManagement\Transformers;

use App\Exports\BusinessManagement\Transformers\TransformersWord;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;

class GenerateTransformersWordJob extends BaseTransformerExportJob
{
    protected string $type      = 'word';
    protected string $extension = 'docx';

    protected function executeExport(Download $download): void
    {
        $query     = $this->buildQuery();
        $count     = (clone $query)->count();
        $transformers = $query->cursor();
        $tempFile  = tempnam(sys_get_temp_dir(), 'transformers_export') . '.docx';

        $opts = $this->options + ['timezone' => $this->userTimezone];

        (new TransformersWord())->generate(
            transformers:      $transformers,
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
