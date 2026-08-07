<?php

namespace App\Exports\AutomationManagement\Automations;

use App\Models\Automation;
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
 * Clon de CustomersExport con columnas especificas del dominio Automations
 * (trigger derivado, runs/failures, last/next run, sin slug ni country).
 */
class AutomationsExport implements FromCollection, WithEvents, WithTitle
{
    protected $automations;
    protected int $count;
    protected array $options;
    protected array $columnDefs;
    protected array $activeColumns;
    protected string $tz;

    public function __construct($automations, array $options = [], ?int $count = null)
    {
        // Aceptamos Collection, LazyCollection, array o cualquier iterable.
        // Solo collect() arrays — Collections/LazyCollections pasan tal cual
        // para preservar streaming sin materializar.
        $this->automations = is_array($automations) ? collect($automations) : $automations;
        $this->options     = $options;
        $this->count       = $count !== null
            ? $count
            : (is_countable($automations) ? count($automations) : iterator_count($automations));

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
            'id'             => ['heading' => __('automations.id'),             'value' => fn($a, $i) => $a->id],
            'name'           => ['heading' => __('automations.name'),           'value' => fn($a, $i) => $a->name],
            'description'    => ['heading' => __('automations.description'),    'value' => fn($a, $i) => mb_substr((string) ($a->description ?? ''), 0, 200)],
            'is_active'      => ['heading' => __('automations.is_active'),      'value' => fn($a, $i) => $a->is_active ? __('global.active') : __('global.inactive')],
            'trigger'        => ['heading' => __('automations.col_trigger'),    'value' => fn($a, $i) => $this->formatTrigger($a)],
            'data_source'    => ['heading' => __('automations.data_source'),    'value' => fn($a, $i) => (string) ($a->data_source ?? '—')],
            'action_type'    => ['heading' => __('automations.action_type'),    'value' => fn($a, $i) => (string) ($a->action_type ?? '—')],
            'runs_count'     => ['heading' => __('automations.col_runs'),       'value' => fn($a, $i) => (int) $a->runs_count],
            'failures_count' => ['heading' => __('automations.col_failures'),   'value' => fn($a, $i) => (int) $a->failures_count],
            'last_run_at'    => ['heading' => __('automations.col_last_run'),   'value' => fn($a, $i) => $a->last_run_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT) ?? '—'],
            'next_run_at'    => ['heading' => __('automations.col_next_run'),   'value' => fn($a, $i) => $a->next_run_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT) ?? '—'],
            'created_at'     => ['heading' => __('global.created_at'),          'value' => fn($a, $i) => $a->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT)],
            'updated_at'     => ['heading' => __('global.updated_at'),          'value' => fn($a, $i) => $a->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT)],
            'creator'        => ['heading' => __('global.created_by'),          'value' => fn($a, $i) => $a->creator?->name ?? '—'],
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
        $title    = $this->options['title'] ?? __('automations.export_title');
        $count    = $this->count;
        $subtitle = sprintf(
            '%s · %s · %s',
            __('global.generated_at'),
            now()->setTimezone($this->tz)->format(\App\Support\Tz::DATETIME_FORMAT),
            trans_choice('global.records_in_report', $count, ['count' => $count])
        );

        $rows = collect();

        // Row 1 — title
        $rows->push([$title]);
        // Row 2 — subtitle
        $rows->push([$subtitle]);
        // Row 3 — spacer
        $rows->push(['']);
        // Row 4 — header
        $rows->push(array_map(fn($k) => $this->columnDefs[$k]['heading'], $this->activeColumns));

        // Row 5+ — data
        $i = 0;
        foreach ($this->automations as $automation) {
            $rows->push(array_map(
                fn($k) => $this->columnDefs[$k]['value']($automation, $i),
                $this->activeColumns
            ));
            $i++;
        }

        return $rows;
    }

    public function title(): string
    {
        return mb_substr($this->options['title'] ?? __('automations.export_title'), 0, 31);
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

    /**
     * Deriva una etiqueta humana del trigger a partir de trigger_type +
     * trigger_config.kind (cron / daily / weekly / monthly).
     */
    protected function formatTrigger(Automation $automation): string
    {
        $config = $automation->trigger_config ?? [];
        $kind   = $config['kind'] ?? null;

        return match ($kind) {
            'cron'    => 'Cron: ' . ($config['expression'] ?? '?'),
            'daily'   => __('automations.trigger_kind_daily') . ' ' . ($config['time'] ?? '?'),
            'weekly'  => __('automations.trigger_kind_weekly') . ' ' . __('automations.trigger_day_of_week') . ' ' . ((int) ($config['day'] ?? 1)) . ' ' . ($config['time'] ?? '?'),
            'monthly' => __('automations.trigger_kind_monthly') . ' ' . __('automations.trigger_day_of_month') . ' ' . ($config['day'] ?? '?') . ' ' . ($config['time'] ?? '?'),
            default   => (string) ($automation->trigger_type ?? '—'),
        };
    }
}
