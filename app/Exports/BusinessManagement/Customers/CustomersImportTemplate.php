<?php

namespace App\Exports\BusinessManagement\Customers;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable para imports de Customers.
 *
 * Columnas:
 *   - name        (obligatorio, max 255)
 *   - cod         (OBLIGATORIO, max 50, unico por PAIS dentro del tenant)
 *   - country_iso (OBLIGATORIO al crear; 2-3 chars, resuelve a country_id via iso_code)
 *
 * No incluye is_active: toda alta importada nace activa (el estado se gestiona
 * desde la UI). No ponemos help-text como filas porque el importer las leeria
 * como datos —
 * los tips van en cell comments.
 */
class CustomersImportTemplate implements FromArray, WithEvents
{
    public function array(): array
    {
        return [
            ['name', 'cod', 'country_iso'],
            ['Empresa Acme S.A.', '20123456789', 'PE'],
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

                // Tooltips en headers (triangulos rojos, no pollutea datos).
                $commentCod = $sheet->getComment('B1');
                $commentCod->setAuthor(__('imports.template_author'));
                $commentCod->getText()->createTextRun(
                    'Codigo comercial (RUC, RFC, CUIT, NIT, etc). OBLIGATORIO. Unico por PAIS dentro del workspace.'
                );
                $commentCod->setWidth('260pt');
                $commentCod->setHeight('60pt');

                $commentIso = $sheet->getComment('C1');
                $commentIso->setAuthor(__('imports.template_author'));
                $commentIso->getText()->createTextRun(
                    'Codigo ISO del pais (PE, AR, MX, US, etc). OBLIGATORIO. Debe coincidir con un pais activo; si no, la fila se rechaza.'
                );
                $commentIso->setWidth('300pt');
                $commentIso->setHeight('80pt');
            },
        ];
    }
}
