<?php

namespace App\Exports\SystemManagement\Locales;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable para import de Locales. 4 columnas requeridas:
 * name, code, language, is_active.
 *
 * `language` se resuelve por ISO code (es, en, pt) o por nombre exacto.
 */
class LocalesImportTemplate implements FromArray, WithEvents
{
    public function array(): array
    {
        return [
            ['name', 'code', 'language', 'is_active'],
            ['Español (Perú)',      'es_PE', 'es', '1'],
            ['English (US)',        'en_US', 'en', '1'],
            ['Português (Brasil)',  'pt_BR', 'pt', '0'],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->getStyle('A1:D1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0A6ED1']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '085CAF']]],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);

                foreach (['A', 'B', 'C', 'D'] as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $codeComment = $sheet->getComment('B1');
                $codeComment->setAuthor(__('imports.template_author'));
                $codeComment->getText()->createTextRun(
                    'Código BCP-47: 2 letras minúsculas opcionalmente seguidas de _ y 2 letras mayúsculas (es, es_PE, en_US).'
                );
                $codeComment->setWidth('260pt');
                $codeComment->setHeight('60pt');

                $langComment = $sheet->getComment('C1');
                $langComment->setAuthor(__('imports.template_author'));
                $langComment->getText()->createTextRun(
                    'ISO code del idioma maestro (es, en, pt, fr, de…). Debe existir en el catálogo Idiomas.'
                );
                $langComment->setWidth('260pt');
                $langComment->setHeight('60pt');

                $activeComment = $sheet->getComment('D1');
                $activeComment->setAuthor(__('imports.template_author'));
                $activeComment->getText()->createTextRun(__('imports.template_is_active_help'));
                $activeComment->setWidth('260pt');
                $activeComment->setHeight('60pt');
            },
        ];
    }
}
