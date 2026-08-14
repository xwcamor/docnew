<?php

namespace App\Exports\BusinessManagement\WorkPlans;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;

/**
 * Generates a styled .docx report of WorkPlans using PhpWord directly
 * (no .docx template dependency — full programmatic control).
 *
 * Layout follows SAP Fiori Quartz Light (mismo patron que RegionsWord):
 *   - Cover page: title, subtitle, optional "Filtros aplicados" box.
 *   - Data table: header SAP blue (#0A6ED1) con texto blanco, thin gray
 *     borders, alternating row tint (#F8FAFC).
 *   - Footer: "Page X of Y" + app name.
 *
 * Columns dinamicas — driven by $options['columns'].
 */
class WorkPlansWord
{
    /** Map column key → ['heading' => string, 'value' => fn($workPlan) => mixed] */
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
        $work_plans,
        string $filename,
        array $options = [],
        array $filtersSummary = [],
        string $generatedBy = '—',
        ?int $count = null,
    ): void {
        $tz = $options['timezone'] ?? config('app.timezone', 'UTC');

        // Si no nos pasaron el count, lo derivamos. NUNCA hacer count() sobre
        // una LazyCollection que ya vamos a iterar después — la materializa.
        // El Job pasa el count siempre; este fallback es solo para compat.
        $count = $count !== null
            ? $count
            : (is_countable($work_plans) ? count($work_plans) : iterator_count($work_plans));

        $this->columnDefs = [
            'id'            => ['heading' => __('work_plans.id'),            'value' => fn($c) => (string) $c->id],
            'code'          => ['heading' => __('work_plans.code'),          'value' => fn($c) => (string) $c->code],
            'num_os'        => ['heading' => __('work_plans.num_os'),        'value' => fn($c) => (string) ($c->num_os ?? '')],
            'description'   => ['heading' => __('work_plans.description'),   'value' => fn($c) => (string) $c->description],
            'company'       => ['heading' => __('work_plans.company'),       'value' => fn($c) => (string) ($c->company?->name ?? '—')],
            'work_type'     => ['heading' => __('work_plans.work_type'),     'value' => fn($c) => (string) ($c->workType?->label ?? '—')],
            'work_location' => ['heading' => __('work_plans.work_location'), 'value' => fn($c) => (string) ($c->workLocation?->name ?? '—')],
            'workstation'   => ['heading' => __('work_plans.workstation'),   'value' => fn($c) => (string) ($c->workstation?->name ?? '—')],
            'work_area'     => ['heading' => __('work_plans.work_area'),     'value' => fn($c) => (string) ($c->workArea?->name ?? '—')],
            'date_start'    => ['heading' => __('work_plans.date_start'),    'value' => fn($c) => (string) ($c->date_start?->format('Y-m-d') ?? '')],
            'date_end'      => ['heading' => __('work_plans.date_end'),      'value' => fn($c) => (string) ($c->date_end?->format('Y-m-d') ?? '')],
            'is_done'       => ['heading' => __('work_plans.is_done'),       'value' => fn($c) => $c->state_text],
            'is_closed'     => ['heading' => __('work_plans.is_closed'),     'value' => fn($c) => $c->is_closed ? __('global.yes') : __('global.no')],
            'people_count'  => ['heading' => __('work_plans.people_count'),  'value' => fn($c) => (string) ($c->people_count ?? 0)],
            'registered_by' => ['heading' => __('work_plans.registered_by'), 'value' => fn($c) => (string) ($c->user?->name ?? '—')],
            'slug'       => ['heading' => 'Slug',                    'value' => fn($c) => (string) $c->slug],
            'created_at' => ['heading' => __('global.created_at'),   'value' => fn($c) => $c->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT) ?? ''],
            'updated_at' => ['heading' => __('global.updated_at'),   'value' => fn($c) => $c->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT) ?? ''],
            'creator'    => ['heading' => __('global.created_by'),   'value' => fn($c) => $c->creator->name ?? '—'],
            // Workspace (tenant): el controller solo la habilita para super.
            'tenant'     => ['heading' => __('tenants.singular'),    'value' => fn($c) => (string) ($c->tenant?->name ?? '—')],
        ];

        $title         = $options['title']         ?? __('work_plans.export_title');
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

        // ── Footer with page numbers + app name ─────────────────────────
        $footer = $section->addFooter();
        $footerTable = $footer->addTable(['borderTopSize' => 6, 'borderTopColor' => self::COLOR_BORDER]);
        $footerTable->addRow();
        $footerTable->addCell(6000)
            ->addText(
                config('app.name') . ' · ' . now()->setTimezone($tz)->format(\App\Support\Tz::DATE_FORMAT),
                ['size' => 8, 'color' => self::COLOR_TEXT_SOFT]
            );
        $cellRight = $footerTable->addCell(3000, ['valign' => 'top']);
        $rightP = $cellRight->addTextRun(['alignment' => Jc::END]);
        $rightP->addText(__('global.page') . ' ', ['size' => 8, 'color' => self::COLOR_TEXT_SOFT]);
        $rightP->addField('PAGE');
        $rightP->addText(' / ', ['size' => 8, 'color' => self::COLOR_TEXT_SOFT]);
        $rightP->addField('NUMPAGES');

        // ── COVER ────────────────────────────────────────────────────────
        $workPlanTable = $section->addTable([
            'cellMargin'   => 200,
            'borderSize'   => 0,
            'unit'         => \PhpOffice\PhpWord\SimpleType\TblWidth::TWIP,
        ]);
        $workPlanTable->addRow(800);
        $workPlanCell = $workPlanTable->addCell(9000, [
            'bgColor' => self::COLOR_SHELL,
            'valign'  => 'center',
        ]);
        $workPlanCell->addText($title, [
            'name' => 'Calibri', 'size' => 22, 'bold' => true, 'color' => 'FFFFFF',
        ], ['spaceAfter' => 60]);
        $workPlanCell->addText(
            __('global.generated_at') . ': ' . now()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT) . ' · ' . trans_choice('global.records_in_report', $count, ['count' => $count]),
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

        // ── DATA TABLE ──────────────────────────────────────────────────
        if ($count === 0) {
            $section->addText(
                __('global.no_matching_records'),
                ['size' => 10, 'italic' => true, 'color' => self::COLOR_TEXT_SOFT],
                ['alignment' => Jc::CENTER, 'spaceBefore' => 400]
            );
        } else {
            $phpWord->addTableStyle('WorkPlansTable', [
                'borderSize'      => 4,
                'borderColor'     => self::COLOR_BORDER,
                'cellMargin'      => 80,
                'alignment'       => JcTable::CENTER,
                'unit'            => \PhpOffice\PhpWord\SimpleType\TblWidth::AUTO,
            ]);

            $table = $section->addTable('WorkPlansTable');

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
            foreach ($work_plans as $i => $workPlan) {
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
                    $value = $this->columnDefs[$col]['value']($workPlan);

                    // Verde si el plan está terminado, rojo si sigue pendiente.
                    if ($col === 'is_done') {
                        $color = $workPlan->is_done ? '1D7044' : 'C8281D';
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
