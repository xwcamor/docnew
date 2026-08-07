<?php

namespace App\Jobs\BusinessManagement\Transformers;

use App\Models\Download;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class GenerateTransformersPdfJob extends BaseTransformerExportJob
{
    protected string $type      = 'pdf';
    protected string $extension = 'pdf';

    protected function executeExport(Download $download): void
    {
        $query      = $this->buildQuery();
        $totalCount = (clone $query)->count();
        $transformers  = $query->cursor();

        $title          = $this->options['title']                   ?? __('transformers.export_title');
        $orientation    = $this->options['orientation']             ?? 'portrait';
        $paperSize      = $this->options['paper_size']              ?? 'a4';
        $defs           = \App\Support\Transformers\TransformerExportColumns::definitions($this->userTimezone);
        $columns        = $this->options['columns']                 ?? array_keys($defs);
        $columns        = array_values(array_filter($columns, fn ($k) => isset($defs[$k])));
        if (empty($columns)) {
            $columns = array_keys($defs);
        }
        $includeFilters = $this->options['include_filters_summary'] ?? true;
        $filtersSummary = $includeFilters ? $this->buildFiltersSummary() : [];
        $generatedBy    = optional(\App\Models\User::find($this->userId))->name ?? '—';

        $pdf = Pdf::loadView('business_management.transformers.pdf.template', [
            'transformers'   => $transformers,
            'columns'        => $columns,
            'columnDefs'     => $defs,
            'title'          => $title,
            'filtersSummary' => $filtersSummary,
            'generatedBy'    => $generatedBy,
            'totalCount'     => $totalCount,
            'tz'             => $this->userTimezone,
        ])
            ->setPaper($paperSize, $orientation)
            ->setOptions([
                // DomPDF solo conoce las core fonts sin instalar: Helvetica,
                // Times-Roman, Courier, Symbol, ZapfDingbats.
                'defaultFont'          => 'Helvetica',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'dpi'                  => 110,
            ]);

        $path = 'downloads/' . $download->filename;
        Storage::disk($download->disk)->put($path, $pdf->output());

        $download->update(['path' => $path, 'status' => 'ready']);
    }
}
