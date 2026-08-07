<?php

namespace App\Exports\AuthManagement\Roles;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable para imports de Roles.
 *
 * Columnas:
 *   - name        (obligatorio, max 120, unique por tenant)
 *   - description (opcional, max 255)
 *   - is_active   (opcional, 1/0/true/false/si/no/activo/inactivo, default 1)
 *   - permissions (opcional, lista separada por comas: "customers.view,customers.edit")
 *
 * No ponemos help-text como filas porque el importer las leeria como datos —
 * los tips van en cell comments.
 */
class RolesImportTemplate implements FromArray, WithEvents
{
    public function array(): array
    {
        return [
            ['name', 'description', 'is_active', 'permissions'],
            ['Vendedor',  'Acceso a clientes y oportunidades',         '1', 'customers.view,customers.edit'],
            ['Operador',  'Edita catalogos y consulta listados',       '1', 'customers.view'],
            ['Auditor',   'Solo lectura — sin permisos de escritura',  '1', ''],
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

                // Tooltips en headers (triangulos rojos, no pollutea datos).
                $commentName = $sheet->getComment('A1');
                $commentName->setAuthor(__('imports.template_author'));
                $commentName->getText()->createTextRun(
                    'Nombre del perfil. Obligatorio, max 120 chars. Unico dentro del workspace (case + acentos insensible).'
                );
                $commentName->setWidth('280pt');
                $commentName->setHeight('70pt');

                $commentDesc = $sheet->getComment('B1');
                $commentDesc->setAuthor(__('imports.template_author'));
                $commentDesc->getText()->createTextRun(
                    'Descripcion corta del perfil. Opcional, max 255 chars.'
                );
                $commentDesc->setWidth('260pt');
                $commentDesc->setHeight('60pt');

                $commentActive = $sheet->getComment('C1');
                $commentActive->setAuthor(__('imports.template_author'));
                $commentActive->getText()->createTextRun(
                    __('imports.template_is_active_help')
                );
                $commentActive->setWidth('260pt');
                $commentActive->setHeight('60pt');

                $commentPerms = $sheet->getComment('D1');
                $commentPerms->setAuthor(__('imports.template_author'));
                $commentPerms->getText()->createTextRun(
                    'Permisos separados por coma, sin espacios: customers.view,customers.edit. Deben existir en el sistema; los desconocidos generan error en la fila.'
                );
                $commentPerms->setWidth('320pt');
                $commentPerms->setHeight('90pt');
            },
        ];
    }
}
