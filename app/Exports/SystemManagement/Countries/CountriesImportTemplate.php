<?php

namespace App\Exports\SystemManagement\Countries;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable para import de Countries. 7 columnas requeridas:
 * name, iso_code, currency, timezone, region, default_locale, is_active.
 *
 * `region` se resuelve por NOMBRE (no por id) — más amigable para el operador.
 * `default_locale` se resuelve por código (es_PE, en_US…).
 */
class CountriesImportTemplate implements FromArray, WithEvents
{
    public function array(): array
    {
        return [
            ['name', 'iso_code', 'currency', 'timezone', 'region', 'default_locale', 'is_active'],
            ['Perú',           'PE', 'PEN', 'America/Lima',     'América del Sur', 'es_PE', '1'],
            ['Estados Unidos', 'US', 'USD', 'America/New_York', 'América del Norte', 'en_US', '1'],
            ['España',         'ES', 'EUR', 'Europe/Madrid',    'Europa',          'es_PE', '0'],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->getStyle('A1:G1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0A6ED1']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '085CAF']]],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);

                foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                $comment = $sheet->getComment('G1');
                $comment->setAuthor(__('imports.template_author'));
                $comment->getText()->createTextRun(
                    __('imports.template_is_active_help')
                );
                $comment->setWidth('260pt');
                $comment->setHeight('60pt');

                $regionComment = $sheet->getComment('E1');
                $regionComment->setAuthor(__('imports.template_author'));
                $regionComment->getText()->createTextRun(
                    'Nombre exacto de la región (ej: "América del Sur"). Debe existir en el catálogo Regiones.'
                );
                $regionComment->setWidth('260pt');
                $regionComment->setHeight('60pt');

                $localeComment = $sheet->getComment('F1');
                $localeComment->setAuthor(__('imports.template_author'));
                $localeComment->getText()->createTextRun(
                    'Código del locale (ej: "es_PE", "en_US"). Debe existir en el catálogo Locales.'
                );
                $localeComment->setWidth('260pt');
                $localeComment->setHeight('60pt');
            },
        ];
    }
}
