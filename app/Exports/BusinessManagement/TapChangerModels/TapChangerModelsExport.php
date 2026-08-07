<?php

namespace App\Exports\BusinessManagement\TapChangerModels;

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
class TapChangerModelsExport implements FromCollection, WithEvents, WithTitle
{
    protected $tapChangerModels;
    protected int $count;
    protected array $options;
    protected array $columnDefs;
    protected array $activeColumns;
    protected string $tz;

    public function __construct($tapChangerModels, array $options = [], ?int $count = null)
    {
        // Aceptamos Collection, LazyCollection, array o cualquier iterable. Solo
        // forzamos collect() para arrays â€” Collections/LazyCollections pasan
        // tal cual para preservar el streaming (LazyCollection genera models
        // de a uno; collect() las materializarÃ­a y comeria memoria).
        $this->tapChangerModels = is_array($tapChangerModels) ? collect($tapChangerModels) : $tapChangerModels;
        $this->options   = $options;
        $this->count     = $count !== null
            ? $count
            : (is_countable($tapChangerModels) ? count($tapChangerModels) : iterator_count($tapChangerModels));

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
            'id'         => ['heading' => __('tap_changer_models.id'),        'value' => fn($c, $i) => $c->id],
            'name'       => ['heading' => __('tap_changer_models.name'),      'value' => fn($c, $i) => $c->name],
            'code'       => ['heading' => __('tap_changer_models.code'),      'value' => fn($c, $i) => $c->code],
            'sort_order' => ['heading' => __('tap_changer_models.sort_order'), 'value' => fn($c, $i) => $c->sort_order ?? ''],
            'is_active'  => ['heading' => __('tap_changer_models.is_active'), 'value' => fn($c, $i) => $c->state_text],
            'slug'       => ['heading' => 'Slug',                    'value' => fn($c, $i) => $c->slug],
            'created_at' => ['heading' => __('global.created_at'),   'value' => fn($c, $i) => $c->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT)],
            'updated_at' => ['heading' => __('global.updated_at'),   'value' => fn($c, $i) => $c->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT)],
            'creator'    => ['heading' => __('global.created_by'),   'value' => fn($c, $i) => $c->creator->name ?? 'â€”'],
            'tenant'     => ['heading' => __('tenants.singular'), 'value' => fn($c, $i) => $c->tenant?->name ?? '-'],
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
        $title    = $this->options['title'] ?? __('tap_changer_models.export_title');
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
        foreach ($this->tapChangerModels as $tapChangerModel) {
            $rows->push(array_map(
                fn($k) => $this->columnDefs[$k]['value']($tapChangerModel, $i),
                $this->activeColumns
            ));
            $i++;
        }

        return $rows;
    }

    public function title(): string
    {
        return mb_substr($this->options['title'] ?? __('tap_changer_models.export_title'), 0, 31);
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
