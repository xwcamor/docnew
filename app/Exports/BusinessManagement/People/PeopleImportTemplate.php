<?php

namespace App\Exports\BusinessManagement\People;

use App\Models\Company;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Position;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Plantilla XLSX descargable para imports de personas.
 *
 * Columnas:
 *   - doc_type    (opcional — DNI si no viene)
 *   - num_doc     (obligatorio: es lo que identifica a la persona)
 *   - name        (obligatorio en el alta)
 *   - lastname    (obligatorio en el alta)
 *   - company     (obligatorio en el alta — RUC o nombre de la empresa)
 *   - position    (obligatorio en el alta — el cargo del catalogo)
 *   - roles       (opcional — por defecto Trabajador)
 *   - nationality (opcional — pais)
 *   - birthdate   (opcional)
 *
 * La empresa y el cargo se añadieron porque sin ellos la persona importada
 * nacia sin vinculo y no se la podia meter en ningun plan: son obligatorios en
 * el formulario y en la v1 son NOT NULL en `workers`.
 *
 * Las filas de ejemplo salen del catalogo del usuario que descarga, no de una
 * lista escrita aqui: una plantilla que trae «CE» a un pais donde ese tipo no
 * existe, o «Contratista SAC» a quien no la tiene dada de alta, es una
 * plantilla que el propio sistema rechaza en cuanto se sube tal cual.
 *
 * La columna del documento va formateada como TEXTO. Sin eso Excel la trata
 * como numero y se come el cero de delante: `06842865` —un DNI real de la base
 * maestra— se guarda como 6842865 y deja de existir.
 *
 * No incluye is_active: toda alta importada nace activa (el estado se gestiona desde la UI).
 *
 * No ponemos help-text como filas porque el importer las leeria como datos —
 * los tips van en cell comments.
 */
class PeopleImportTemplate implements FromArray, WithEvents
{
    /** Las columnas, en orden. La primera fila del fichero es esta. */
    private const COLUMNAS = [
        'doc_type', 'num_doc', 'name', 'lastname',
        'company', 'position', 'roles', 'nationality', 'birthdate',
    ];

    public function array(): array
    {
        $pais = Auth::user()?->country_id;

        $tipos = DocumentType::query()
            ->where('country_id', $pais)->where('is_active', true)
            ->orderByRaw("CASE WHEN code = 'DNI' THEN 0 ELSE 1 END")
            ->pluck('code')->all() ?: ['DNI', 'CE'];

        $empresa = Company::query()->orderBy('id')->first();
        $cargos  = Position::query()->orderBy('id')->pluck('code')->all();
        $aqui    = Country::find($pais);

        // El RUC identifica sin ambigüedad; el nombre solo si la empresa lo
        // tiene. Sin empresas dadas de alta se deja en blanco a proposito: es
        // mejor una celda vacia que un nombre inventado que va a fallar.
        $comoSeLlama = $empresa?->num_doc ?: $empresa?->name;

        return [
            self::COLUMNAS,
            [
                $tipos[0], '45871236', 'Juan Carlos', 'Pérez Gómez',
                $comoSeLlama, $cargos[0] ?? null, __('people.role_worker'),
                $aqui?->name, '1990-05-14',
            ],
            [
                $tipos[1] ?? $tipos[0], '45871237', 'María', 'Salazar Ríos',
                $comoSeLlama, $cargos[1] ?? ($cargos[0] ?? null),
                __('people.role_supervisor') . ', ' . __('people.role_hse_supervisor'),
                $aqui?->name, '',
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count(self::COLUMNAS));
                $filas  = $sheet->getHighestRow();

                // Header SAP-blue
                $sheet->getStyle("A1:{$ultima}1")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0A6ED1']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '085CAF']]],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(26);

                foreach (range('A', $ultima) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                // El documento, como texto: es lo unico que impide que Excel se
                // coma el cero de delante en cuanto alguien teclee en la celda.
                //
                // El formato se pone en la COLUMNA, no en un rango de celdas.
                // Estilar «B2:B1000» le da estilo a mil celdas, y una celda con
                // estilo existe: el fichero salia con 998 filas vacias y el
                // propio importador devolvia 998 errores de «falta el numero de
                // documento» al volver a subirlo.
                $sheet->getStyle("B2:B{$filas}")
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_TEXT);

                $sheet->getColumnDimension('B')->setXfIndex($sheet->getCell('B2')->getXfIndex());

                // Tooltips en los headers que deciden algo (triangulo rojo, no
                // pollutea datos).
                $tips = [
                    'B1' => __('people.num_doc_help'),
                    'E1' => __('people.company_help'),
                    'F1' => __('people.position_help'),
                    'G1' => __('people.roles_help'),
                    'H1' => __('people.nationality_help'),
                ];

                foreach ($tips as $celda => $texto) {
                    $comment = $sheet->getComment($celda);
                    $comment->setAuthor(__('imports.template_author'));
                    $comment->getText()->createTextRun($texto);
                    $comment->setWidth('260pt');
                    $comment->setHeight('70pt');
                }
            },
        ];
    }
}
