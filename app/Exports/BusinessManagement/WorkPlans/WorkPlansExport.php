<?php

namespace App\Exports\BusinessManagement\WorkPlans;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * XLSX styled SAP Fiori (title + subtitle + header azul + zebra rows).
 * Columnas dinamicas via $options['columns'].
 *
 * Clon de RegionsExport con columnas adicionales `cod` y `country` (relacion).
 */
class WorkPlansExport implements FromCollection, WithEvents, WithTitle
{
    protected $work_plans;
    protected int $count;
    protected array $options;
    protected array $columnDefs;
    protected array $activeColumns;
    protected string $tz;

    public function __construct($work_plans, array $options = [], ?int $count = null)
    {
        // Aceptamos Collection, LazyCollection, array o cualquier iterable. Solo
        // forzamos collect() para arrays â€” Collections/LazyCollections pasan
        // tal cual para preservar el streaming (LazyCollection genera models
        // de a uno; collect() las materializarÃ­a y comeria memoria).
        $this->work_plans = is_array($work_plans) ? collect($work_plans) : $work_plans;
        $this->options   = $options;
        $this->count     = $count !== null
            ? $count
            : (is_countable($work_plans) ? count($work_plans) : iterator_count($work_plans));

        if (!empty($options['timezone'])) {
            $this->tz = $options['timezone'];
        } else {
            $user = !empty($options['user_id'])
                ? \App\Models\User::withoutGlobalScopes()->find($options['user_id'])
                : null;
            $this->tz = \App\Support\Tz::for($user);
        }

        $tz = $this->tz;
        $this->columnDefs = [
            'id'            => ['heading' => __('work_plans.id'),            'value' => fn($c, $i) => $c->id],
            'code'          => ['heading' => __('work_plans.code'),          'value' => fn($c, $i) => $c->code],
            'num_os'        => ['heading' => __('work_plans.num_os'),        'value' => fn($c, $i) => $c->num_os ?? ''],
            'description'   => ['heading' => __('work_plans.description'),   'value' => fn($c, $i) => $c->description],
            'company'       => ['heading' => __('work_plans.company'),       'value' => fn($c, $i) => $c->company?->name ?? '—'],
            'work_type'     => ['heading' => __('work_plans.work_type'),     'value' => fn($c, $i) => $c->workType?->code ?? '—'],
            'work_location' => ['heading' => __('work_plans.work_location'), 'value' => fn($c, $i) => $c->workLocation?->name ?? '—'],
            'workstation'   => ['heading' => __('work_plans.workstation'),   'value' => fn($c, $i) => $c->workstation?->name ?? '—'],
            'work_area'     => ['heading' => __('work_plans.work_area'),     'value' => fn($c, $i) => $c->workArea?->name ?? '—'],
            'date_start'    => ['heading' => __('work_plans.date_start'),    'value' => fn($c, $i) => $c->date_start?->format('Y-m-d') ?? ''],
            'date_end'      => ['heading' => __('work_plans.date_end'),      'value' => fn($c, $i) => $c->date_end?->format('Y-m-d') ?? ''],
            'is_done'       => ['heading' => __('work_plans.is_done'),       'value' => fn($c, $i) => $c->state_text],
            'is_closed'     => ['heading' => __('work_plans.is_closed'),     'value' => fn($c, $i) => $c->is_closed ? __('global.yes') : __('global.no')],
            'people_count'  => ['heading' => __('work_plans.people_count'),  'value' => fn($c, $i) => $c->people_count ?? 0],
            'registered_by' => ['heading' => __('work_plans.registered_by'), 'value' => fn($c, $i) => $c->user?->name ?? '—'],
            'slug'       => ['heading' => 'Slug',                    'value' => fn($c, $i) => $c->slug],
            'created_at' => ['heading' => __('global.created_at'),   'value' => fn($c, $i) => $c->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT)],
            'updated_at' => ['heading' => __('global.updated_at'),   'value' => fn($c, $i) => $c->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT)],
            'creator'    => ['heading' => __('global.created_by'),   'value' => fn($c, $i) => $c->creator->name ?? 'â€”'],
            // Workspace (tenant): el controller solo la habilita para super.
            'tenant'     => ['heading' => __('tenants.singular'),    'value' => fn($c, $i) => $c->tenant?->name ?? '—'],
        ];

        $requested = $options['columns'] ?? array_keys($this->columnDefs);
        $this->activeColumns = array_values(array_filter(
            $requested,
            fn($k) => isset($this->columnDefs[$k])
        ));

        if (empty($this->activeColumns)) {
            $this->activeColumns = array_keys($this->columnDefs);
        }
    }

    /** Filas 1-3: titulo/subtitulo/spacer. Fila 4: header. Fila 5+: data. */
    public function collection()
    {
        $title    = $this->options['title'] ?? __('work_plans.export_title');
        $count    = $this->count;
        $subtitle = sprintf(
            '%s Â· %s Â· %s',
            __('global.generated_at'),
            now()->setTimezone($this->tz)->format(\App\Support\Tz::DATETIME_FORMAT),
            trans_choice('global.records_in_report', $count, ['count' => $count])
        );

        $rows = collect();

        // Row 1 â€” title
        $rows->push([$title]);
        // Row 2 â€” subtitle
        $rows->push([$subtitle]);
        // Row 3 â€” spacer
        $rows->push(['']);
        // Row 4 â€” header
        $rows->push(array_map(fn($k) => $this->columnDefs[$k]['heading'], $this->activeColumns));

        // Row 5+ â€” data
        $i = 0;
        foreach ($this->work_plans as $workPlan) {
            $rows->push(array_map(
                fn($k) => $this->columnDefs[$k]['value']($workPlan, $i),
                $this->activeColumns
            ));
            $i++;
        }

        return $rows;
    }

    public function title(): string
    {
        return mb_substr($this->options['title'] ?? __('work_plans.export_title'), 0, 31);
    }

    /**
     * Apply styling, autofilter, freeze pane, and auto-width.
     * We do this via AfterSheet because we need full PhpSpreadsheet access.
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet      = $event->sheet->getDelegate();
                $colCount   = count($this->activeColumns);
                $lastColLet = $sheet->getCellByColumnAndRow($colCount, 1)->getColumn();
                $headerRow  = 4;
                $dataStart  = 5;
                $dataEnd    = $dataStart + $this->count - 1;

                // Row 1: title
                if ($colCount > 1) {
                    $sheet->mergeCells("A1:{$lastColLet}1");
                }
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '32363A']],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(28);

                // Row 2: subtitle
                if ($colCount > 1) {
                    $sheet->mergeCells("A2:{$lastColLet}2");
                }
                $sheet->getStyle('A2')->applyFromArray([
                    'font' => ['size' => 10, 'color' => ['rgb' => '6A6D70']],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(2)->setRowHeight(18);

                // Row 4: header (SAP blue)
                $sheet->getStyle("A{$headerRow}:{$lastColLet}{$headerRow}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0A6ED1']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '085CAF']]],
                ]);
                $sheet->getRowDimension($headerRow)->setRowHeight(26);

                // Data rows + zebra tint
                if ($dataEnd >= $dataStart) {
                    $sheet->getStyle("A{$dataStart}:{$lastColLet}{$dataEnd}")->applyFromArray([
                        'font' => ['size' => 10, 'color' => ['rgb' => '32363A']],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E5E5']]],
                        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                    ]);

                    for ($row = $dataStart; $row <= $dataEnd; $row++) {
                        if (($row - $dataStart) % 2 === 1) {
                            $sheet->getStyle("A{$row}:{$lastColLet}{$row}")->applyFromArray([
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
                            ]);
                        }
                        $sheet->getRowDimension($row)->setRowHeight(20);
                    }
                }

                // Auto-fit columns
                for ($i = 1; $i <= $colCount; $i++) {
                    $letter = $sheet->getCellByColumnAndRow($i, 1)->getColumn();
                    $sheet->getColumnDimension($letter)->setAutoSize(true);
                }

                if (($this->options['autofilter'] ?? true) && $dataEnd >= $headerRow) {
                    $sheet->setAutoFilter("A{$headerRow}:{$lastColLet}{$dataEnd}");
                }
                if ($this->options['freeze_header'] ?? true) {
                    $sheet->freezePane('A' . ($headerRow + 1));
                }
            },
        ];
    }
}
