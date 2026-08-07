<?php

namespace App\Exports\BusinessManagement\Transformers;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Reporte de flota en Excel: un libro con varias pestañas (Resumen +
 * una por prueba + Diagnósticos). Las pestañas llegan ya armadas desde el
 * job (scope-safe). Este formato es EL recomendado para "todos los datos
 * crudos": Excel aguanta el volumen; PDF/Word no.
 *
 * @param array<int, array{title:string, headings:array, rows:array}> $sheets
 */
class FleetReportExport implements WithMultipleSheets
{
    use Exportable;

    public function __construct(private array $sheets) {}

    public function sheets(): array
    {
        return array_map(
            fn ($s) => new FleetReportSheet($s['title'], $s['headings'], $s['rows']),
            $this->sheets,
        );
    }
}
