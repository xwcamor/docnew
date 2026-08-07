<?php

namespace App\Exports\AuthManagement\Users;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable para imports de Users.
 *
 * Columnas:
 *   - name      (obligatorio, max 255)
 *   - email     (obligatorio, max 255, unico global)
 *   - password  (opcional; si vacio se genera uno aleatorio)
 *   - role_name (opcional; default 'user'; debe existir en el tenant
 *                o ser un rol global asignable como 'admin')
 *   - is_active (opcional, 1/0/true/false/si/no/activo/inactivo)
 *
 * Tips se ponen como comments en headers, no como filas (sino el importer
 * los lee como datos).
 */
class UsersImportTemplate implements FromArray, WithEvents
{
    public function array(): array
    {
        return [
            ['name', 'email', 'password', 'role_name', 'is_active'],
            ['Ana Garcia',     'ana.garcia@example.com',    '',            'admin', '1'],
            ['Luis Ramirez',   'luis.ramirez@example.com',  'TempPass!23', 'user',  '1'],
            ['Marta Bermudez', 'marta.b@example.com',       '',            '',      '0'],
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

                // Tooltips en headers (triangulos rojos, no pollutea datos).
                $commentName = $sheet->getComment('A1');
                $commentName->setAuthor(__('imports.template_author'));
                $commentName->getText()->createTextRun(
                    'Nombre completo del usuario. Obligatorio. Maximo 255 caracteres.'
                );
                $commentName->setWidth('240pt');
                $commentName->setHeight('48pt');

                $commentEmail = $sheet->getComment('B1');
                $commentEmail->setAuthor(__('imports.template_author'));
                $commentEmail->getText()->createTextRun(
                    'Correo electronico. Obligatorio. Unico en todo el sistema. Es la clave de dedup.'
                );
                $commentEmail->setWidth('280pt');
                $commentEmail->setHeight('60pt');

                $commentPassword = $sheet->getComment('C1');
                $commentPassword->setAuthor(__('imports.template_author'));
                $commentPassword->getText()->createTextRun(
                    'Si dejas password vacio se genera uno aleatorio. El usuario debera resetearlo en su primer login. Minimo 8 caracteres si lo especificas.'
                );
                $commentPassword->setWidth('320pt');
                $commentPassword->setHeight('80pt');

                $commentRole = $sheet->getComment('D1');
                $commentRole->setAuthor(__('imports.template_author'));
                $commentRole->getText()->createTextRun(
                    'Nombre exacto del rol a asignar (case insensitive). Opcional — si vacio se asigna "user". Si el rol no existe en tu workspace o no es asignable, la fila se rechaza.'
                );
                $commentRole->setWidth('340pt');
                $commentRole->setHeight('100pt');

                $commentActive = $sheet->getComment('E1');
                $commentActive->setAuthor(__('imports.template_author'));
                $commentActive->getText()->createTextRun(
                    __('imports.template_is_active_help')
                );
                $commentActive->setWidth('260pt');
                $commentActive->setHeight('60pt');
            },
        ];
    }
}
