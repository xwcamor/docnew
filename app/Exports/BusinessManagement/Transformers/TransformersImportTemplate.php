<?php

namespace App\Exports\BusinessManagement\Transformers;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable para imports de Transformers (completa).
 *
 * Las columnas de catálogo/cliente se resuelven por `code` o `name` (o `cod`
 * para cliente). Vacío = se deja sin asignar. Los tips van como comentarios de
 * celda en los encabezados (el importer ignora comentarios, no filas de ayuda).
 */
class TransformersImportTemplate implements FromArray, WithEvents
{
    /** Encabezados (orden = columnas A..O). */
    private const HEADERS = [
        'serial', 'tag', 'customer', 'oil_type', 'transformer_type', 'brand',
        'tap_changer_type', 'connection_type', 'preservation', 'voltage_kv',
        'power_mva', 'manufacture_year', 'paper_type', 'phases', 'oil_treated_at',
    ];

    /** Comentario de ayuda por columna (clave = letra). */
    private const HINTS = [
        'C' => 'Cliente: por su código (cod) o nombre. Debe existir en tu workspace.',
        'D' => 'Tipo de aceite: por código o nombre (ej. Mineral, Silicona).',
        'E' => 'Tipo de transformador: por código o nombre (ej. Potencia, Distribución).',
        'F' => 'Marca: por código o nombre (ej. Acme). Debe existir en el catálogo.',
        'G' => 'Tipo de conmutador: por código o nombre. Opcional.',
        'H' => 'Grupo de conexión: por código o nombre (ej. Dyn5, Yy0). Opcional.',
        'I' => 'Sistema de preservación: por código o nombre. Opcional.',
        'J' => 'Tensión en kV (numérico). Ej: 220',
        'K' => 'Potencia en MVA (numérico). Ej: 100',
        'L' => 'Año de fabricación (1900..actual). Ej: 2010',
        'M' => 'Tipo de papel: kraft o upgraded.',
        'N' => 'Número de fases: 1, 2 o 3.',
        'O' => 'Último tratamiento de aceite (fecha AAAA-MM-DD). Ej: 2022-05-01',
    ];

    public function array(): array
    {
        return [
            self::HEADERS,
            ['SN-00001', 'TR01', 'RUC-12345',   'Mineral',  'Potencia',     'Acme',   '', 'Dyn5', '', '220', '100', '2010', 'kraft',    '3', '2022-05-01'],
            ['SN-00002', 'TR02', 'Cliente Demo', 'Silicona', 'Distribución', 'Globex', '', 'Yy0',  '', '60',  '25',  '2015', 'upgraded', '3', ''],
            ['SN-00003', 'TR03', '',             '',         '',             '',        '', '',     '', '',    '',    '',     '',         '',  ''],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastCol = 'O';

                $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0A6ED1']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '085CAF']]],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);

                foreach (range('A', $lastCol) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                // Comentarios de ayuda en los encabezados de catálogo/formato.
                foreach (self::HINTS as $col => $hint) {
                    $sheet->getComment("{$col}1")->getText()->createTextRun($hint);
                    $sheet->getComment("{$col}1")->setWidth('220pt')->setHeight('60pt');
                }
            },
        ];
    }
}
