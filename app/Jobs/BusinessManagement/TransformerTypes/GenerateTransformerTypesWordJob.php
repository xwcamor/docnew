<?php

namespace App\Jobs\BusinessManagement\TransformerTypes;

use App\Exports\BusinessManagement\TransformerTypes\TransformerTypesWord;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;

class GenerateTransformerTypesWordJob extends BaseTransformerTypeExportJob
{
    protected string $type      = 'word';
    protected string $extension = 'docx';

    protected function executeExport(Download $download): void
    {
        $query     = $this->buildQuery();
        $count     = (clone $query)->count();
        $transformerTypes = $query->cursor();
        $tempFile  = tempnam(sys_get_temp_dir(), 'transformerTypes_export') . '.docx';

        $opts = $this->options + ['timezone' => $this->userTimezone];

        (new TransformerTypesWord())->generate(
            transformerTypes:      $transformerTypes,
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
