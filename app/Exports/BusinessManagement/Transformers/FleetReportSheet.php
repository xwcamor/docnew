<?php

namespace App\Exports\BusinessManagement\Transformers;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Una pestaña del reporte de flota. Genérica: recibe título + encabezados +
 * filas ya armadas (la lógica scope-safe vive en el job, esto solo pinta).
 */
class FleetReportSheet implements FromArray, WithHeadings, WithTitle, WithEvents
{
    /** Paleta del informe (misma banda que el PDF y el Word). */
    private const AZUL  = '354A5F';   // fondo de la cabecera
    // Un solo borde, OSCURO, para cabecera y datos. El gris claro que se probó
    // antes se confundía con las líneas de fondo de Excel (que además no se
    // imprimen) y la hoja seguía leyéndose como texto suelto.
    private const BORDE = '2A3B4C';

    public function __construct(
        private string $sheetTitle,
        private array $sheetHeadings,
        private array $sheetRows,
    ) {}

    public function array(): array
    {
        return $this->sheetRows;
    }

    public function headings(): array
    {
        return $this->sheetHeadings;
    }

    public function title(): string
    {
        // Excel limita el nombre de pestaña a 31 chars y prohíbe : \ / ? * [ ].
        return mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $this->sheetTitle), 0, 31);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $cols = count($this->sheetHeadings);
                if ($cols === 0) {
                    return;
                }
                $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cols);
                // Encabezado en negrita sobre la banda del informe (#354A5F), la
                // misma del PDF y del Word — no el azul SAP de la interfaz, que
                // a todo lo ancho de una hoja se ve chillón.
                $sheet->getStyle("A1:{$last}1")->getFont()->getColor()->setARGB('FFFFFFFF');
                $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
                $sheet->getStyle("A1:{$last}1")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF' . self::AZUL);
                $sheet->freezePane('A2');

                // Rejilla: la hoja se lee como tabla, no como texto suelto. Es
                // un borde propio, no el gris de fondo de Excel, que no se
                // imprime. Va en toda la zona con datos, incluida la cabecera.
                $filas = max(1, $sheet->getHighestRow());
                $sheet->getStyle("A1:{$last}{$filas}")->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color'       => ['argb' => 'FF' . self::BORDE],
                        ],
                    ],
                ]);

                // Cabeceras de varias líneas (nombre · norma · unidad): sin
                // ajuste de texto Excel muestra solo la primera y el resto se
                // pierde. El alto se fija a mano porque autoSize no calcula
                // altura, y se centra vertical para que no queden pegadas arriba.
                $multilinea = max(array_map(
                    fn ($h) => substr_count((string) $h, "\n"),
                    $this->sheetHeadings,
                ));
                if ($multilinea > 0) {
                    $sheet->getStyle("A1:{$last}1")->getAlignment()
                        ->setWrapText(true)
                        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
                    $sheet->getRowDimension(1)->setRowHeight(16 * ($multilinea + 1));
                }

                // El ancho se calcula a mano cuando la cabecera es multilínea:
                // autoSize mide la cadena entera (con los saltos adentro) y deja
                // columnas absurdamente anchas.
                foreach (range(1, $cols) as $i) {
                    $dim = $sheet->getColumnDimensionByColumn($i);
                    $encabezado = (string) ($this->sheetHeadings[$i - 1] ?? '');
                    if (!str_contains($encabezado, "\n")) {
                        $dim->setAutoSize(true);
                        continue;
                    }
                    $ancho = max(array_map('mb_strlen', explode("\n", $encabezado)));
                    $dim->setWidth(min(26, max(11, $ancho + 2)));
                }
            },
        ];
    }
}
