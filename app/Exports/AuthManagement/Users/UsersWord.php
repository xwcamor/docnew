<?php

namespace App\Exports\AuthManagement\Users;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;

/**
 * Generates a styled .docx report of Users using PhpWord directly
 * (no .docx template dependency — full programmatic control).
 *
 * Layout follows SAP Fiori Quartz Light (mismo patron que CustomersWord):
 *   - Cover page: title, subtitle, optional "Filtros aplicados" box.
 *   - Data table: header SAP blue (#0A6ED1) con texto blanco, thin gray
 *     borders, alternating row tint (#F8FAFC).
 *   - Footer: "Page X of Y" + app name.
 *
 * Columns dinamicas — driven by $options['columns'].
 */
class UsersWord
{
    /** Map column key → ['heading' => string, 'value' => fn($user) => mixed] */
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
        $users,
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
            : (is_countable($users) ? count($users) : iterator_count($users));

        $this->columnDefs = [
            'id'         => ['heading' => __('users.id'),         'value' => fn($u) => (string) $u->id],
            'name'       => ['heading' => __('users.name'),       'value' => fn($u) => (string) $u->name],
            'email'      => ['heading' => __('users.email'),      'value' => fn($u) => (string) $u->email],
            'role'       => ['heading' => __('users.role'),       'value' => fn($u) => (string) ($u->roles->first()?->name ?? '—')],
            'tenant'     => ['heading' => __('users.tenant'),     'value' => fn($u) => (string) ($u->tenant?->name ?? '—')],
            'is_active'  => ['heading' => __('users.is_active'),  'value' => fn($u) => $u->state_text],
            'slug'       => ['heading' => 'Slug',                 'value' => fn($u) => (string) $u->slug],
            'created_at' => ['heading' => __('global.created_at'),'value' => fn($u) => $u->created_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT) ?? ''],
            'updated_at' => ['heading' => __('global.updated_at'),'value' => fn($u) => $u->updated_at?->copy()->setTimezone($tz)->format(\App\Support\Tz::DATETIME_FORMAT) ?? ''],
            'creator'    => ['heading' => __('global.created_by'),'value' => fn($u) => $u->creator->name ?? '—'],
            'photo'      => ['heading' => __('users.photo'),      'value' => fn($u) => (string) ($u->photo ?? '')],
        ];

        $title         = $options['title']         ?? __('users.export_title');
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
        $brandTable = $section->addTable([
            'cellMargin'   => 200,
            'borderSize'   => 0,
            'unit'         => \PhpOffice\PhpWord\SimpleType\TblWidth::TWIP,
        ]);
        $brandTable->addRow(800);
        $brandCell = $brandTable->addCell(9000, [
            'bgColor' => self::COLOR_SHELL,
            'valign'  => 'center',
        ]);
        $brandCell->addText($title, [
            'name' => 'Calibri', 'size' => 22, 'bold' => true, 'color' => 'FFFFFF',
        ], ['spaceAfter' => 60]);
        $brandCell->addText(
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
            $phpWord->addTableStyle('UsersTable', [
                'borderSize'      => 4,
                'borderColor'     => self::COLOR_BORDER,
                'cellMargin'      => 80,
                'alignment'       => JcTable::CENTER,
                'unit'            => \PhpOffice\PhpWord\SimpleType\TblWidth::AUTO,
            ]);

            $table = $section->addTable('UsersTable');

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
            foreach ($users as $i => $user) {
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
                    $value = $this->columnDefs[$col]['value']($user);

                    if ($col === 'is_active') {
                        $color = $user->is_active ? '1D7044' : 'C8281D';
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
