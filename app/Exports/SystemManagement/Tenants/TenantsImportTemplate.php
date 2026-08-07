<?php

namespace App\Exports\SystemManagement\Tenants;

use App\Models\Plan;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Plantilla XLSX descargable para import de Tenants. 3 columnas:
 * name, plan, is_active.
 *
 * Los slugs de plan validos se leen de la tabla `plans` (DB) — si el
 * super crea un plan nuevo, la plantilla lo refleja sin tocar codigo.
 *
 * `logo` se sube por separado vía form upload, no via import masivo.
 */
class TenantsImportTemplate implements FromArray, WithEvents
{
    /** Slugs de planes activos — single source desde DB. */
    private function planSlugs(): array
    {
        return Plan::activeSlugs() ?: ['free'];
    }

    public function array(): array
    {
        $slugs = $this->planSlugs();
        $rows  = [['name', 'plan', 'is_active']];

        // Una fila de ejemplo por plan activo (hasta 4 para no saturar).
        $examples = ['Acme Inc', 'Globex', 'Contoso', 'Initech'];
        foreach (array_slice($slugs, 0, count($examples)) as $i => $slug) {
            $rows[] = [$examples[$i], $slug, $i % 2 === 0 ? '1' : '0'];
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

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

                $planComment = $sheet->getComment('B1');
                $planComment->setAuthor(__('imports.template_author'));
                $planComment->getText()->createTextRun(
                    'Plan del workspace: ' . implode(' | ', $this->planSlugs()) . '. Default: free.'
                );
                $planComment->setWidth('260pt');
                $planComment->setHeight('60pt');

                $typeComment = $sheet->getComment('C1');
                $typeComment->setAuthor(__('imports.template_author'));
                $typeComment->getText()->createTextRun(
                    'Tipo: business (empresa con varios users) | personal (freelancer). Default: business.'
                );
                $typeComment->setWidth('260pt');
                $typeComment->setHeight('60pt');

                $activeComment = $sheet->getComment('D1');
                $activeComment->setAuthor(__('imports.template_author'));
                $activeComment->getText()->createTextRun(__('imports.template_is_active_help'));
                $activeComment->setWidth('260pt');
                $activeComment->setHeight('60pt');
            },
        ];
    }
}
