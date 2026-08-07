<?php

namespace App\Exports\AuthManagement\Users;

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
 * Clon de CustomersExport con columnas adicionales `email`, `role`, `tenant`
 * y `photo`. role/tenant resuelven relaciones eager-loaded.
 */
class UsersExport implements FromCollection, WithEvents, WithTitle
{
    protected $users;
    protected int $count;
    protected array $options;
    protected array $columnDefs;
    protected array $activeColumns;
    protected string $tz;

    public function __construct($users, array $options = [], ?int $count = null)
    {
        // Aceptamos Collection, LazyCollection, array o cualquier iterable.
        // Solo collect() arrays — Collections/LazyCollections pasan tal cual
        // para preservar streaming sin materializar.
        $this->users   = is_array($users) ? collect($users) : $users;
        $this->options = $options;
        $this->count   = $count !== null
            ? $count
            : (is_countable($users) ? count($users) : iterator_count($users));

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
            'id'         => ['heading' => __('users.id'),         'value' => fn($u, $i) => $u->id],
            'name'       => ['heading' => __('users.name'),       'value' => fn($u, $i) => $u->name],
            'email'      => ['heading' => __('users.email'),      'value' => fn($u, $i) => $u->email],
            'role'       => ['heading' => __('users.role'),       'value' => fn($u, $i) => $u->roles->first()?->name ?? '—'],
            'tenant'     => ['heading' => __('users.tenant'),     'value' => fn($u, $i) => $u->tenant?->name ?? '—'],
            'is_active'  => ['heading' => __('users.is_active'),  'value' => fn($u, $i) => $u->state_text],
            'slug'       => ['heading' => 'Slug',                 'value' => fn($u, $i) => $u->slug],
            'created_at' => ['heading' => __('global.created_at'),'value' => fn($u, $i) => $u->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT)],
            'updated_at' => ['heading' => __('global.updated_at'),'value' => fn($u, $i) => $u->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT)],
            'creator'    => ['heading' => __('global.created_by'),'value' => fn($u, $i) => $u->creator->name ?? '—'],
            'photo'      => ['heading' => __('users.photo'),      'value' => fn($u, $i) => $u->photo ?? ''],
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
        $title    = $this->options['title'] ?? __('users.export_title');
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
        foreach ($this->users as $user) {
            $rows->push(array_map(
                fn($k) => $this->columnDefs[$k]['value']($user, $i),
                $this->activeColumns
            ));
            $i++;
        }

        return $rows;
    }

    public function title(): string
    {
        return mb_substr($this->options['title'] ?? __('users.export_title'), 0, 31);
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
