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
 * Columnas (el docblock decia «name obligatorio / code opcional»: venia
 * clonado de Brand y no correspondia con lo que el importador lee):
 *   - name           opcional, max 255. Como se llama la firma en obra
 *                    («Supervisor Autorizante - HITACHI»). Sin nombre, la
 *                    pantalla cae al rol generico.
 *   - country        obligatorio, codigo ISO del pais
 *   - work_type      opcional, codigo del tipo; vacio = todos los tipos
 *   - approver_role  obligatorio, codigo del catalogo de roles aprobadores
 *   - priority_level obligatorio, 1..20
 *   - is_required    1 / 0
 *
 * No incluye is_active: toda alta importada nace activa (el estado se gestiona desde la UI).
 *
 * No ponemos help-text como filas porque el importer las leeria como datos —
 * los tips van en cell comments.
 */
class ApprovalRulesImportTemplate implements FromArray, WithEvents
{
        public function array(): array
    {
        return [
            ['name', 'country', 'work_type', 'approver_role', 'priority_level', 'is_required'],
            // Sin tipo de trabajo: la regla vale para todos los tipos del país.
            ['Supervisor Ejecutante',             'PE', '',      'worker',         1, 1],
            ['Supervisor Autorizante - HITACHI',  'PE', '',      'supervisor',     2, 1],
            ['Supervisor de Seguridad - HITACHI', 'PE', '',      'hse_supervisor', 3, 0],
            // Con tipo: solo para IZAJE, y a más riesgo, una firma más.
            ['Jefe de Izaje',                     'PE', 'IZAJE', 'rigging_chief',  4, 1],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Header SAP-blue
                $sheet->getStyle('A1:F1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0A6ED1']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '085CAF']]],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);

                foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                // Tooltips en los headers (triangulo rojo, no pollutean datos).
                // A1 = name, C1 = work_type.
                foreach (['A1' => __('approval_rules.name_help'), 'C1' => __('approval_rules.work_type_help')] as $celda => $texto) {
                    $comentario = $sheet->getComment($celda);
                    $comentario->setAuthor(__('imports.template_author'));
                    $comentario->getText()->createTextRun($texto);
                    $comentario->setWidth('260pt');
                    $comentario->setHeight('60pt');
                }
            },
        ];
    }
}
