<?php

namespace App\Exports\SystemManagement\Settings;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Comment;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable. NO ponemos help-text como filas porque el
 * importer las leería como datos — el tip de is_active va en cell comment.
 */
class SettingsImportTemplate implements FromArray, WithEvents
{
    public function array(): array
    {
        return [
            ['name', 'is_active'],
            [__('imports.template_sample_1'), '1'],
            [__('imports.template_sample_2'), '0'],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Header SAP-blue
                $sheet->getStyle('A1:B1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0A6ED1']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '085CAF']]],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);

                $sheet->getColumnDimension('A')->setAutoSize(true);
                $sheet->getColumnDimension('B')->setAutoSize(true);

                // Help tooltip en cell B1 (triángulo rojo, no pollutea datos).
                $comment = $sheet->getComment('B1');
                $comment->setAuthor(__('imports.template_author'));
                $comment->getText()->createTextRun(
                    __('imports.template_is_active_help')
                );
                $comment->setWidth('260pt');
                $comment->setHeight('60pt');
            },
        ];
    }
}
