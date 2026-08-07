<?php

namespace App\Exports\BusinessManagement\WorkPlans;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable para imports de planes de trabajo.
 *
 * Columnas:
 *   - code          (obligatorio, identifica el plan — unico per-tenant)
 *   - num_os        (opcional, orden de servicio del cliente)
 *   - description   (obligatorio al crear)
 *   - company       (RUC o nombre exacto de la empresa contratista)
 *   - work_type     (codigo del tipo de trabajo)
 *   - work_location (nombre de la sede)
 *   - date_start    (YYYY-MM-DD, opcional)
 *
 * No incluye estado: un plan importado nace pendiente — las firmas se levantan
 * en obra, nunca desde un Excel.
 *
 * No ponemos help-text como filas porque el importer las leeria como datos —
 * los tips van en cell comments.
 */
class WorkPlansImportTemplate implements FromArray, WithEvents
{
    /** Ultima columna de la plantilla (7 campos: A..G). */
    private const LAST_COL = 'G';

    public function array(): array
    {
        return [
            ['code', 'num_os', 'description', 'company', 'work_type', 'work_location', 'date_start'],
            ['PE24-0412-0458', 'OS-2024-1187', 'Mantenimiento preventivo de celda de media tensión', '20512345678', 'ELECTRICO', 'Sede Lima Norte', '2024-04-12'],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Header SAP-blue
                $sheet->getStyle('A1:' . self::LAST_COL . '1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0A6ED1']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '085CAF']]],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);

                foreach (range('A', self::LAST_COL) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                // Tooltips en los headers que se prestan a confusion (triangulo
                // rojo, no ensucia los datos).
                foreach ([
                    'A1' => __('work_plans.code_help'),
                    'D1' => __('work_plans.company_help'),
                    'E1' => __('work_plans.work_type_help'),
                    'F1' => __('work_plans.work_location_help'),
                ] as $cell => $text) {
                    $comment = $sheet->getComment($cell);
                    $comment->setAuthor(__('imports.template_author'));
                    $comment->getText()->createTextRun($text);
                    $comment->setWidth('260pt');
                    $comment->setHeight('60pt');
                }
            },
        ];
    }
}
