<?php

namespace App\Exports\SystemManagement\Languages;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable. Incluye `iso_code` como columna (particularidad
 * de Language). El sample muestra el formato ISO 639-1 / BCP-47 esperado.
 */
class LanguagesImportTemplate implements FromArray, WithEvents
{
    public function array(): array
    {
        return [
            ['name', 'iso_code', 'is_active'],
            ['Español', 'es', '1'],
            ['English', 'en', '1'],
            ['Português (Brasil)', 'pt_BR', '0'],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Header SAP-blue
                $sheet->getStyle('A1:C1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0A6ED1']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '085CAF']]],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);

                foreach (['A', 'B', 'C'] as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                // Help tooltips en headers (triángulo rojo, no pollutea datos).
                $isoComment = $sheet->getComment('B1');
                $isoComment->setAuthor(__('imports.template_author'));
                $isoComment->getText()->createTextRun(
                    __('languages.iso_code_help') ?? 'ISO 639-1 (es) o BCP-47 short (es_AR)'
                );
                $isoComment->setWidth('220pt');
                $isoComment->setHeight('60pt');

                $activeComment = $sheet->getComment('C1');
                $activeComment->setAuthor(__('imports.template_author'));
                $activeComment->getText()->createTextRun(
                    __('imports.template_is_active_help')
                );
                $activeComment->setWidth('260pt');
                $activeComment->setHeight('60pt');
            },
        ];
    }
}
