<?php

namespace App\Jobs\BusinessManagement\Brands;

use App\Exports\BusinessManagement\Brands\BrandsWord;
use App\Models\Download;
use Illuminate\Support\Facades\Storage;

class GenerateBrandsWordJob extends BaseBrandExportJob
{
    protected string $type      = 'word';
    protected string $extension = 'docx';

    protected function executeExport(Download $download): void
    {
        $query     = $this->buildQuery();
        $count     = (clone $query)->count();
        $brands = $query->cursor();
        $tempFile  = tempnam(sys_get_temp_dir(), 'brands_export') . '.docx';

        $opts = $this->options + ['timezone' => $this->userTimezone];

        (new BrandsWord())->generate(
            brands:      $brands,
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
