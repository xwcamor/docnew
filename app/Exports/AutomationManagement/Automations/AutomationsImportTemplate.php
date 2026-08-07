<?php

namespace App\Exports\AutomationManagement\Automations;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable para imports de Automations.
 *
 * Columnas:
 *   - name                (obligatorio, max 255)
 *   - description         (opcional)
 *   - is_active           (1/0/true/false, default 1)
 *   - trigger_kind        (cron|daily|weekly|monthly)
 *   - trigger_time        (HH:MM, usado por daily/weekly/monthly)
 *   - trigger_expression  (cron string, solo si kind=cron)
 *   - trigger_day         (entero — day-of-week 0..6 para weekly o day-of-month 1..31 para monthly)
 *   - data_source         (obligatorio, ej. customers / users / subscriptions)
 *   - action_type         (obligatorio, ej. email / in_app)
 *   - action_config_json  (obligatorio, JSON string parseable a array)
 */
class AutomationsImportTemplate implements FromArray, WithEvents
{
    public function array(): array
    {
        return [
            ['name', 'description', 'is_active', 'trigger_kind', 'trigger_time', 'trigger_expression', 'trigger_day', 'data_source', 'action_type', 'action_config_json'],
            ['Recordatorio diario', 'Aviso a admins cada manana', '1', 'daily',  '09:00', '',           '', 'customers', 'email', '{"to":["a@b.com"],"subject":"x","body":"y"}'],
            ['Reporte semanal',     'Email lunes 09:00',          '1', 'cron',   '',      '0 9 * * 1', '', 'customers', 'email', '{"to":["b@b.com"],"subject":"semanal","body":"resumen"}'],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Header SAP-blue
                $sheet->getStyle('A1:J1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0A6ED1']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '085CAF']]],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);

                foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
            },
        ];
    }
}
