<?php

namespace App\Exports\BusinessManagement\FormTemplates;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;

/**
 * Generates a styled .docx report of FormTemplates using PhpWord directly
 * (no .docx template dependency â€” full programmatic control).
 *
 * Layout follows SAP Fiori Quartz Light (mismo patron que RegionsWord):
 *   - Cover page: title, subtitle, optional "Filtros aplicados" box.
 *   - Data table: header SAP blue (#0A6ED1) con texto blanco, thin gray
 *     borders, alternating row tint (#F8FAFC).
 *   - Footer: "Page X of Y" + app name.
 *
 * Columns dinamicas â€” driven by $options['columns'].
 */
class FormTemplatesWord
{
    /** Map column key â†’ ['heading' => string, 'value' => fn($formTemplate) => mixed] */
    protected array $columnDefs;

    /** SAP Fiori palette */
    private const COLOR_BRAND       = '0A6ED1';
    private const COLOR_BRAND_DARK  = '085CAF';
    private const COLOR_SHELL       = '354A5F';
    private const COLOR_TEXT        = '32363A';
    private const COLOR_TEXT_SOFT   = '6A6D70';
    private const COLOR_BORDER      = 'E5E5E5';
    private const COLOR_ZEBRA       = 'F8FAFC';
    private const COLOR_FILTER_BG   = 'F0F6FB';

    public function generate(
        $form_templates,
        string $filename,
        array $options = [],
        array $filtersSummary = [],
        string $generatedBy = 'â€”',
        ?int $count = null,
    ): void {
        $tz = $options['timezone'] ?? config('app.timezone', 'UTC');

        // Si no nos pasaron el count, lo derivamos. NUNCA hacer count() sobre
        // una LazyCollection que ya vamos a iterar despuÃ©s â€” la materializa.
        // El Job pasa el count siempre; este fallback es solo para compat.
        $count = $count !== null
            ? $count
            : (is_countable($form_templates) ? count($form_templates) : iterator_count($form_templates));

        $this->columnDefs = [
            'id'         => ['heading' => __('form_templates.id'),        'value' => fn($c) => (string) $c->id],
            'name'       => ['heading' => __('form_templates.name'),      'value' => fn($c) => (string) $c->name],
            'code'       => ['heading' => __('form_templates.code'),      'value' => fn($c) => (string) $c->code],
            // `sort_order` no existe en esta tabla: salia siempre vacia.
            'version'    => ['heading' => __('form_templates.version'), 'value' => fn($c) => (string) $c->version],
            'kind'       => ['heading' => __('form_templates.kind'),      'value' => fn($c) => __('form_templates.kind_' . $c->kind)],
            'status'     => ['heading' => __('form_templates.status'),    'value' => fn($c) => __('form_templates.status_' . $c->status)],
            'is_active'  => ['heading' => __('form_templates.is_active'), 'value' => fn($c) => $c->state_text],
            'slug'       => ['heading' => 'Slug',                    'value' => fn($c) => (string) $c->slug],
            'created_at' => ['heading' => __('global.created_at'),   'value' => fn($c) => $c->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT) ?? ''],
            'updated_at' => ['heading' => __('global.updated_at'),   'value' => fn($c) => $c->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT) ?? ''],
            'creator'    => ['heading' => __('global.created_by'),   'value' => fn($c) => $c->creator->name ?? 'â€”'],
            // Workspace (tenant): el controller solo la habilita para super.
            'tenant'     => ['heading' => __('tenants.singular'),    'value' => fn($c) => (string) ($c->tenant?->name ?? '—')],
        ];

        $title         = $options['title']         ?? __('form_templates.export_title');
        $requestedCols = $options['columns']       ?? array_keys($this->columnDefs);
        $columns       = array_values(array_filter($requestedCols, fn($k) => isset($this->columnDefs[$k])));
        if (empty($columns)) {
            $columns = array_keys($this->columnDefs);
        }

        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(10);

        $phpWord->setDefaultParagraphStyle([
            'spaceAfter' => 0,
            'lineHeight' => 1.25,
        ]);

        $section = $phpWord->addSection([
            'marginTop'    => 1000,
            'marginBottom' => 1000,
            'marginLeft'   => 900,
            'marginRight'  => 900,
        ]);

        // â”€â”€ Footer with page numbers + app name â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $footer = $section->addFooter();
        $footerTable = $footer->addTable(['borderTopSize' => 6, 'borderTopColor' => self::COLOR_BORDER]);
        $footerTable->addRow();
        $footerTable->addCell(6000)
            ->addText(
                config('app.name') . ' Â· ' . now()->setTimezone($tz)->format(\App\Support\Tz::DATE_FORMAT),
                ['size' => 8, 'color' => self::COLOR_TEXT_SOFT]
            );
        $cellRight = $footerTable->addCell(3000, ['valign' => 'top']);
        $rightP = $cellRight->addTextRun(['alignment' => Jc::END]);
        $rightP->addText(__('global.page') . ' ', ['size' => 8, 'color' => self::COLOR_TEXT_SOFT]);
        $rightP->addField('PAGE');
        $rightP->addText(' / ', ['size' => 8, 'color' => self::COLOR_TEXT_SOFT]);
        $rightP->addField('NUMPAGES');

        // â”€â”€ COVER â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $formTemplateTable = $section->addTable([
            'cellMargin'   => 200,
            'borderSize'   => 0,
            'unit'         => \PhpOffice\PhpWord\SimpleType\TblWidth::TWIP,
        ]);
        $formTemplateTable->addRow(800);
        $formTemplateCell = $formTemplateTable->addCell(9000, [
            'bgColor' => self::COLOR_SHELL,
            'valign'  => 'center',
        ]);
        $formTemplateCell->addText($title, [
            'name' => 'Calibri', 'size' => 22, 'bold' => true, 'color' => 'FFFFFF',
        ], ['spaceAfter' => 60]);
        $formTemplateCell->addText(
            __('global.generated_at') . ': ' . now()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT) . ' Â· ' . trans_choice('global.records_in_report', $count, ['count' => $count]),
            ['size' => 10, 'color' => 'CBD5E1']
        );

        $section->addTextBreak(1);

        $section->addText(
            __('global.created_by') . ': ' . $generatedBy,
            ['size' => 9, 'color' => self::COLOR_TEXT_SOFT, 'italic' => true]
        );

        if (!empty($filtersSummary) && ($options['include_filters_summary'] ?? true)) {
            $section->addTextBreak(1);

            $filterTable = $section->addTable([
                'cellMargin' => 180,
                'borderSize' => 0,
            ]);
            $filterTable->addRow();
            $filterCell = $filterTable->addCell(9000, [
                'bgColor'           => self::COLOR_FILTER_BG,
                'borderLeftSize'    => 24,
                'borderLeftColor'   => self::COLOR_BRAND,
            ]);
            $filterCell->addText(mb_strtoupper(__('global.filters_applied')), [
                'size'  => 8,
                'bold'  => true,
                'color' => self::COLOR_BRAND,
            ], ['spaceAfter' => 80]);
            foreach ($filtersSummary as $f) {
                $line = $filterCell->addTextRun(['spaceAfter' => 40]);
                $line->addText($f['label'] . ': ', ['size' => 9, 'bold' => true, 'color' => self::COLOR_TEXT]);
                $line->addText($f['value'],          ['size' => 9, 'color' => self::COLOR_TEXT]);
            }
        }

        $section->addTextBreak(1);

        // â”€â”€ DATA TABLE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        if ($count === 0) {
            $section->addText(
                __('global.no_matching_records'),
                ['size' => 10, 'italic' => true, 'color' => self::COLOR_TEXT_SOFT],
                ['alignment' => Jc::CENTER, 'spaceBefore' => 400]
            );
        } else {
            $phpWord->addTableStyle('FormTemplatesTable', [
                'borderSize'      => 4,
                'borderColor'     => self::COLOR_BORDER,
                'cellMargin'      => 80,
                'alignment'       => JcTable::CENTER,
                'unit'            => \PhpOffice\PhpWord\SimpleType\TblWidth::AUTO,
            ]);

            $table = $section->addTable('FormTemplatesTable');

            // Header row
            $table->addRow(420, ['tblHeader' => true]);
            foreach ($columns as $col) {
                $cell = $table->addCell(null, [
                    'bgColor'     => self::COLOR_BRAND,
                    'valign'      => 'center',
                    'borderColor' => self::COLOR_BRAND_DARK,
                    'borderSize'  => 4,
                ]);
                $cell->addText(
                    $this->columnDefs[$col]['heading'],
                    ['bold' => true, 'color' => 'FFFFFF', 'size' => 10],
                    ['alignment' => Jc::START, 'spaceAfter' => 0]
                );
            }

            // Data rows (zebra)
            foreach ($form_templates as $i => $formTemplate) {
                $table->addRow(360);
                $isEven = $i % 2 === 1;
                $rowBg  = $isEven ? self::COLOR_ZEBRA : 'FFFFFF';

                foreach ($columns as $col) {
                    $cell = $table->addCell(null, [
                        'bgColor'     => $rowBg,
                        'valign'      => 'center',
                        'borderColor' => self::COLOR_BORDER,
                        'borderSize'  => 4,
                    ]);
                    $value = $this->columnDefs[$col]['value']($formTemplate);

                    if ($col === 'is_active') {
                        $color = $formTemplate->is_active ? '1D7044' : 'C8281D';
                        $cell->addText($value, ['size' => 9, 'bold' => true, 'color' => $color]);
                    } else {
                        $cell->addText((string) $value, ['size' => 9, 'color' => self::COLOR_TEXT]);
                    }
                }
            }
        }

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($filename);
    }
}
