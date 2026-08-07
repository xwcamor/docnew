<?php

namespace App\Jobs\AutomationManagement\Automations;

use App\Models\Download;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class GenerateAutomationsPdfJob extends BaseAutomationExportJob
{
    protected string $type      = 'pdf';
    protected string $extension = 'pdf';

    protected function executeExport(Download $download): void
    {
        $query       = $this->buildQuery();
        $totalCount  = (clone $query)->count();
        $automations = $query->cursor();

        $title          = $this->options['title']                   ?? __('automations.export_title');
        $orientation    = $this->options['orientation']             ?? 'portrait';
        $paperSize      = $this->options['paper_size']              ?? 'a4';
        $columns        = $this->options['columns']                 ?? [
            'id', 'name', 'description', 'is_active', 'trigger', 'data_source', 'action_type',
            'runs_count', 'failures_count', 'last_run_at', 'next_run_at',
            'created_at', 'updated_at', 'creator',
        ];
        $includeFilters = $this->options['include_filters_summary'] ?? true;
        $filtersSummary = $includeFilters ? $this->buildFiltersSummary() : [];
        $generatedBy    = optional(\App\Models\User::find($this->userId))->name ?? '—';

        $pdf = Pdf::loadView('exports.automations.pdf', [
            'automations'    => $automations,
            'columns'        => $columns,
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
