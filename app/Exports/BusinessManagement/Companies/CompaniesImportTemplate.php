<?php

namespace App\Exports\BusinessManagement\Companies;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable para imports de empresas contratistas.
 *
 * Columnas:
 *   - name          (obligatorio, max 255, nombre corto — unico per-tenant)
 *   - num_doc       (obligatorio, max 20 — el RUC; unico por pais en el workspace)
 *   - complete_name (opcional, max 255 — la razon social del documento)
 *
 * La razon social faltaba en la plantilla y es la que figura en el documento de
 * la empresa: sin ella el import dejaba a todas las contratistas con la razon
 * social igual al nombre corto y no habia manera de rellenarla en lote.
 *
 * No incluye is_active: toda alta importada nace activa (el estado se gestiona desde la UI).
 *
 * No ponemos help-text como filas porque el importer las leeria como datos —
 * los tips van en cell comments.
 */
class CompaniesImportTemplate implements FromArray, WithEvents
{
        public function array(): array
    {
        return [
            ['name', 'num_doc', 'complete_name'],
            // Nombres y numeros de mentira, a proposito. Una plantilla que se
            // descarga con clientes de verdad dentro es un fichero que acaba en
            // el correo de cualquiera, y ademas invita a subirla tal cual.
            ['ACME', '20123456789', 'Acme Servicios Generales S.A.C.'],
            ['GLOBEX', '20987654321', 'Globex Contratistas S.A.C.'],
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

                // Tooltip en el header del RUC (triangulo rojo, no pollutea datos).
                $commentCode = $sheet->getComment('B1');
                $commentCode->setAuthor(__('imports.template_author'));
                $commentCode->getText()->createTextRun(
                    __('companies.num_doc_help')
                );
                $commentCode->setWidth('260pt');
                $commentCode->setHeight('60pt');

                // Y otro en la razon social: es opcional, y si falta se copia
                // el nombre corto. Mejor decirlo que dejarlo adivinar.
                $commentName = $sheet->getComment('C1');
                $commentName->setAuthor(__('imports.template_author'));
                $commentName->getText()->createTextRun(__('companies.complete_name_help'));
                $commentName->setWidth('260pt');
                $commentName->setHeight('60pt');
            },
        ];
    }
}
