<?php

namespace App\Exports\BusinessManagement\People;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable para imports de personas.
 *
 * Columnas:
 *   - doc_type (opcional — DNI si no viene)
 *   - num_doc  (obligatorio: es lo que identifica a la persona)
 *   - name     (obligatorio)
 *   - lastname (obligatorio)
 *
 * No incluye is_active: toda alta importada nace activa (el estado se gestiona desde la UI).
 *
 * No ponemos help-text como filas porque el importer las leeria como datos —
 * los tips van en cell comments.
 */
class PeopleImportTemplate implements FromArray, WithEvents
{
        public function array(): array
    {
        return [
            ['doc_type', 'num_doc', 'name', 'lastname'],
            ['DNI', '45871236', 'Juan Carlos', 'Pérez Gómez'],
            ['CE', '001234567', 'María', 'Salazar Ríos'],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Header SAP-blue
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

                // Tooltip en el header del documento (triangulo rojo, no
                // pollutea datos): es la columna que decide si la fila crea o
                // actualiza a alguien.
                $commentCode = $sheet->getComment('B1');
                $commentCode->setAuthor(__('imports.template_author'));
                $commentCode->getText()->createTextRun(
                    __('people.num_doc_help')
                );
                $commentCode->setWidth('260pt');
                $commentCode->setHeight('60pt');
            },
        ];
    }
}
