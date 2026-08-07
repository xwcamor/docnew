<?php

namespace App\Jobs\SystemManagement\Regions;

use App\Models\Download;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class GenerateRegionsPdfJob extends BaseRegionExportJob
{
    protected string $type      = 'pdf';
    protected string $extension = 'pdf';

    protected function executeExport(Download $download): void
    {
        $query      = $this->buildQuery();
        $totalCount = (clone $query)->count();
        $regions    = $query->cursor();

        $title          = $this->options['title']                   ?? __('regions.export_title');
        $orientation    = $this->options['orientation']             ?? 'portrait';
        $paperSize      = $this->options['paper_size']              ?? 'a4';
        $columns        = $this->options['columns']                 ?? ['id', 'name', 'is_active', 'created_at', 'creator'];
        $includeFilters = $this->options['include_filters_summary'] ?? true;
        $filtersSummary = $includeFilters ? $this->buildFiltersSummary() : [];
        $generatedBy    = optional(\App\Models\User::find($this->userId))->name ?? '—';

        $pdf = Pdf::loadView('system_management.regions.pdf.template', [
            'regions'        => $regions,
            'columns'        => $columns,
            'title'          => $title,
            'filtersSummary' => $filtersSummary,
            'generatedBy'    => $generatedBy,
            'totalCount'     => $totalCount,
            // TZ del user que disparó el export — el blade lo aplica con
            // setTimezone() antes de format() para que las fechas reflejen
            // el huso horario del usuario, no UTC.
            'tz'             => $this->userTimezone,
        ])
            ->setPaper($paperSize, $orientation)
            ->setOptions([
                // DomPDF solo conoce las core fonts sin instalar: Helvetica,
                // Times-Roman, Courier, Symbol, ZapfDingbats. `defaultFont` y
                // las `font-family` del HTML tienen que coincidir con una de
                // esas — "sans-serif" o "DejaVu Sans" no se renderizan a
                // menos que la instales en storage/fonts.
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
