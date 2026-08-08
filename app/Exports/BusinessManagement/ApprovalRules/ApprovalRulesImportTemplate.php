<?php

namespace App\Exports\BusinessManagement\ApprovalRules;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable para imports de approval_rules.
 *
 * Columnas:
 *   - name (obligatorio, max 255, unico per-tenant)
 *   - code (opcional, max 40, identificador tecnico unico per-tenant)
 *
 * No incluye is_active: toda alta importada nace activa (el estado se gestiona desde la UI).
 *
 * No ponemos help-text como filas porque el importer las leeria como datos â€”
 * los tips van en cell comments.
 */
class ApprovalRulesImportTemplate implements FromArray, WithEvents
{
        public function array(): array
    {
        return [
            ['country', 'work_type', 'approver_role', 'priority_level', 'is_required'],
            // Sin tipo de trabajo: la regla vale para todos los tipos del país.
            ['PE', '',      'worker',         1, 1],
            ['PE', '',      'supervisor',     2, 1],
            ['PE', '',      'hse_supervisor', 3, 0],
            // Con tipo: solo para IZAJE, y a más riesgo, una firma más.
            ['PE', 'IZAJE', 'rigging_chief',  4, 1],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Header SAP-blue
                $sheet->getStyle('A1:E1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0A6ED1']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '085CAF']]],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);

                foreach (['A', 'B', 'C', 'D', 'E'] as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                // Tooltip en el header de code (triangulo rojo, no pollutea datos).
                $commentCode = $sheet->getComment('B1');
                $commentCode->setAuthor(__('imports.template_author'));
                $commentCode->getText()->createTextRun(
                    __('approval_rules.work_type_help')
                );
                $commentCode->setWidth('260pt');
                $commentCode->setHeight('60pt');
            },
        ];
    }
}
