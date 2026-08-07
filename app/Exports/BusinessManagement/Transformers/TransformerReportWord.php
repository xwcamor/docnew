<?php

namespace App\Exports\BusinessManagement\Transformers;

use App\Models\Transformer;
use App\Support\Diagnostics\ConditionLabel;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Informe de UN transformador en .docx — el MISMO informe que el PDF, editable.
 *
 * Replica la maqueta del PDF (`pdf/report.blade.php`): misma paleta, mismos
 * tamaños, portada propia y una página por sección numerada. Si se cambia el
 * diseño del PDF, hay que reflejarlo acá.
 *
 * Dos diferencias, y solo dos:
 *   - NO sale el QR ni el código de verificación. Ese código prueba
 *     autenticidad contra el audit log; un archivo editable no puede sostener
 *     esa promesa.
 *   - NO se estampa NINGUNA imagen de firma, ni siquiera de quien tiene
 *     auto-firma activada: queda la línea para firmar a mano.
 */
class TransformerReportWord
{
    /** Paleta del PDF (report.blade.php). Mantener sincronizada. */
    private const AZUL   = '354A5F';   // banda de sección / nombre de empresa
    private const TEXTO  = '2B3138';
    private const TITULO = '1F2937';
    private const SUAVE  = '6A6D70';
    private const TENUE  = '8A9099';
    private const BORDE  = 'D7DCE1';
    private const CLAVE  = 'F2F5F8';   // fondo de celda-etiqueta
    private const ROJO   = 'C8281D';

    /**
     * Cuerpo 9.5pt, igual que el PDF.
     *
     * OJO: OOXML guarda el tamaño en MEDIOS puntos enteros (`w:sz`), así que
     * solo valen múltiplos de 0.5. Un 6.8 escribe `w:sz="13.6"` y el archivo
     * queda inválido (Word lo tolera, LibreOffice lo rechaza de plano).
     */
    private const PT = 9.5;

    /**
     * Ancho útil en twips: A4 (11906) menos los márgenes laterales (850 + 850).
     * Las tablas se dimensionan sobre esto; con un valor menor quedaban
     * angostas y las columnas desparejas.
     */
    private const ANCHO_UTIL = 10206;

    private PhpWord $word;
    private $seccion;
    private int $n = 0;

    /** Imágenes temporales: PhpWord las lee del disco, no acepta data-URI. */
    private array $temporales = [];

    /** Veredicto por prueba (mismo origen que la carátula del PDF). */
    private array $resumen = [];

    /** Redacción compartida con el blade (ReportNarrative). */
    private array $narr = [];

    /** Límites normativos, para la línea "Máx N" de las cabeceras (como el PDF). */
    private array $gasLimits = [];
    private array $fiquisLimits = [];

    public function generate(
        Transformer $transformer,
        array $data,
        array $hi,
        array $charts,
        array $signers,
        string $generatedBy,
        string $generatedAt,
        ?string $tenantLogo = null,
        ?string $hiGauge = null,
        ?string $cromasStandard = null,
        array $gasLimits = [],
        array $fiquisLimits = [],
        ?string $reportCode = null,
    ): string {
        $this->word = new PhpWord();
        $this->word->setDefaultFontName('Helvetica');
        $this->word->setDefaultFontSize(self::PT);

        $this->seccion = $this->word->addSection([
            'marginTop' => 700, 'marginBottom' => 900, 'marginLeft' => 850, 'marginRight' => 850,
        ]);

        // Pie con la misma información que el del PDF (_report_footer): empresa
        // en negrita, dirección al lado y "Página N de M" a la derecha. Se omite
        // el código del informe porque va de la mano del QR, que acá no sale.
        // Una sola línea: empresa+dirección a la izquierda y la paginación
        // pegada al margen derecho. En párrafos separados quedaban apilados; se
        // usa una tabla de dos celdas para que compartan renglón, como el PDF.
        $pie = $this->seccion->addFooter();
        $this->word->addTableStyle('pie', ['cellMargin' => 0, 'alignment' => Jc::START]);
        $tp = $pie->addTable('pie');
        $tp->addRow();

        $izq = $tp->addCell(7000, ['valign' => 'center'])->addTextRun(['spaceAfter' => 0]);
        $empresa = (string) ($transformer->tenant?->name ?? config('app.name'));
        $izq->addText($empresa, ['bold' => true, 'size' => 8, 'color' => self::TEXTO]);

        // El pie SIEMPRE es una línea. La celda mide 7000 twips ≈ 4,86" y a 7pt
        // entran ~118 caracteres; la empresa va a 8pt, así que pesa más. Se
        // recorta la dirección (lo único de largo variable) para que el conjunto
        // entre: si no, el texto largo saltaba al renglón de abajo.
        $presupuesto = 118 - (int) ceil(mb_strlen($empresa) * 1.15);
        $codigo = (string) ($reportCode ?? '');
        $direccion = (string) ($transformer->tenant?->address ?? '');
        $margen = $presupuesto - mb_strlen($codigo) - 6;   // 6 = separadores

        if ($direccion !== '' && $margen > 8) {
            $direccion = mb_strimwidth($direccion, 0, $margen, '…');
        } elseif ($direccion !== '') {
            $direccion = '';   // no entra: se prioriza el código del informe
        }

        $meta = array_filter([$direccion, $codigo]);
        if ($meta) {
            $izq->addText('   |   ' . implode('   |   ', $meta), ['size' => 7, 'color' => self::TENUE]);
        }

        $tp->addCell(self::ANCHO_UTIL - 7000, ['valign' => 'center'])->addPreserveText(
            __('transformers.report.page') . ' {PAGE} ' . __('transformers.report.of') . ' {NUMPAGES}',
            ['size' => 7, 'color' => self::TENUE],
            ['alignment' => Jc::END, 'spaceAfter' => 0],
        );

        $this->gasLimits = $gasLimits;
        $this->fiquisLimits = $fiquisLimits;
        $this->resumen = $data['diagnosisSummary'] ?? [];
        $this->narr = \App\Support\Transformers\ReportNarrative::build($this->resumen);
        $this->portada($transformer, $hi, $generatedBy, $generatedAt, $tenantLogo, $hiGauge);
        $this->cliente($transformer);
        $this->equipo($transformer);
        $this->metodologia($data, $hi, $cromasStandard);
        $this->cromatografia($data, $charts);
        $this->calidadDelAceite($data, $charts);
        $this->furanos($data, $charts);
        $this->factorDePotencia($data);
        $this->conclusiones($data, $hi);
        $this->firmas($signers);
        $this->disclaimer($transformer);

        $semilla = tempnam(sys_get_temp_dir(), 'trwordreport');
        $ruta = $semilla . '.docx';
        @unlink($semilla);   // tempnam CREA el archivo; si no, queda huérfano
        IOFactory::createWriter($this->word, 'Word2007')->save($ruta);

        foreach ($this->temporales as $tmp) {
            @unlink($tmp);
        }
        $this->temporales = [];

        return $ruta;
    }

    // ─── Portada (equivale a .cover del PDF) ─────────────────────────────────

    private function portada(Transformer $t, array $hi, string $by, string $at, ?string $logo, ?string $gauge): void
    {
        $s = $this->seccion;

        // El PDF muestra el logo O el nombre de la empresa, nunca los dos:
        // `@if ($tenantLogo) <img> @else <div class="cover__company"> @endif`.
        $puesto = false;
        if ($logo && ($archivo = $this->imagenTemporal($logo))) {
            try {
                $s->addImage($archivo, ['height' => 60, 'alignment' => Jc::CENTER]);
                $s->addTextBreak(1);
                $puesto = true;
            } catch (\Throwable $e) { /* si el logo falla, queda el nombre */ }
        }
        if (!$puesto) {
            $s->addText(
                mb_strtoupper((string) ($t->tenant?->name ?? config('app.name'))),
                ['bold' => true, 'size' => 19, 'color' => self::AZUL],
                ['alignment' => Jc::CENTER, 'spaceAfter' => 600],
            );
        }
        $s->addText(
            mb_strtoupper(__('transformers.report.title')),
            ['bold' => true, 'size' => 23, 'color' => self::TITULO],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 60],
        );
        // Regla roja bajo el título, como en la portada del PDF.
        $s->addText('──────────', ['size' => 12, 'bold' => true, 'color' => self::ROJO], ['alignment' => Jc::CENTER, 'spaceAfter' => 280]);

        $s->addText(
            (string) ($t->serial ?: $t->tag),
            ['bold' => true, 'size' => 13, 'color' => self::AZUL],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 20],
        );
        $s->addText(
            (string) ($t->customer?->name ?? ''),
            ['size' => self::PT, 'color' => self::SUAVE],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 320],
        );

        // Mismo anillo del índice de salud que la carátula del PDF.
        if ($gauge && ($archivo = $this->imagenTemporal($gauge))) {
            try { $s->addImage($archivo, ['height' => 135, 'alignment' => Jc::CENTER]); } catch (\Throwable $e) { /* opcional */ }
        } else {
            $idx = $hi['index'] !== null ? round($hi['index']) : null;
            $s->addText(
                ($idx ?? '—') . ' / 100',
                ['bold' => true, 'size' => 22, 'color' => self::TITULO],
                ['alignment' => Jc::CENTER, 'spaceAfter' => 20],
            );
        }
        $s->addText(
            mb_strtoupper(__('transformers.health_index')),
            ['size' => 7, 'color' => self::TENUE],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 320],
        );

        // Veredicto por prueba, igual que la carátula del PDF.
        if (!empty($this->resumen)) {
            $v = $this->tabla('cvered');
            foreach (['cromas' => 'cromas.tab', 'fiquis' => 'fiquis.tab', 'furanos' => 'furanos.tab', 'fpot' => 'fpot.tab'] as $clave => $lang) {
                $cond = $this->resumen[$clave]['condition'] ?? null;
                if (($this->resumen[$clave]['has_data'] ?? false) !== true) {
                    continue;
                }
                $v->addRow();
                $v->addCell(5100, ['valign' => 'center'])
                    ->addText(__($lang), ['bold' => true, 'size' => 9, 'color' => self::TITULO]);
                $this->celdaEstado($v, 5100, $cond, $this->resumen[$clave]['color'] ?? null);
            }
            $s->addTextBreak(1);
        }

        // Meta de portada con las MISMAS etiquetas del PDF (sin el código del
        // informe: va de la mano del QR, que acá no sale).
        $tabla = $this->tabla('portada');
        $this->filaClaveValor($tabla, __('transformers.serial'), (string) ($t->serial ?: $t->tag ?: '—'));
        $this->filaClaveValor($tabla, __('transformers.report.generated'), $at);
        $this->filaClaveValor($tabla, __('transformers.report.generated_by'), $by);

        $this->saltoDePagina();
    }

    // ─── Secciones numeradas (equivalen a .ssec del PDF) ─────────────────────

    private function cliente(Transformer $t): void
    {
        $this->tituloSeccion(__('transformers.report.client'));
        $tabla = $this->tabla('cli');
        $sub = $t->substation;
        foreach ([
            [__('transformers.report.customer'), $t->customer?->name],
            [__('transformers.report.ruc'), $t->customer?->cod],
            [__('transformers.report.location'), $sub?->area?->location?->name],
            [__('transformers.report.country'), $t->customer?->country?->name],
            [__('transformers.report.area'), $sub?->area?->name],
            [__('transformers.report.substation'), $sub?->name],
            [__('transformers.report.address'), $t->customer?->address],
        ] as [$k, $v]) {
            $this->filaClaveValor($tabla, (string) $k, (string) ($v ?: '—'));
        }
        $this->seccion->addTextBreak(1);
    }

    private function equipo(Transformer $t): void
    {
        $this->tituloSeccion(__('transformers.report.equipment'));
        $tabla = $this->tabla('eq');
        foreach ([
            [__('transformers.serial'), $t->serial],
            [__('transformers.voltage_kv'), $t->voltage_kv],
            [__('transformers.transformer_type'), $t->transformerType?->name],
            [__('transformers.tag'), $t->tag],
            [__('transformers.power_mva'), $t->power_mva],
            [__('transformers.oil_type'), $t->oilType?->name],
            [__('transformers.brand'), $t->brand?->name],
            [__('transformers.manufacture_year'), $t->manufacture_year],
            [__('transformers.tap_changer_type'), $t->tapChangerType?->name],
            [__('transformers.tap_changer_brand'), $t->tapChangerBrand?->name],
            [__('transformers.tap_changer_model'), $t->tapChangerModel?->name],
            [__('transformers.tap_changer_technology'), $t->tapChangerTechnology?->name],
            [__('transformers.paper_type'), $t->paper_type],
            [__('transformers.oil_treated_at'), $t->oil_treated_at?->format('d-m-Y')],
        ] as [$k, $v]) {
            $this->filaClaveValor($tabla, (string) $k, (string) ($v ?: '—'));
        }
        $this->saltoDePagina();
    }

    /**
     * Mismos textos que el PDF (`method_*`), incluidos SOLO para las pruebas que
     * el trafo tiene. Nada redactado acá: si cambia el criterio, cambia el
     * archivo de idioma y los dos informes quedan iguales.
     */
    private function metodologia(array $data, array $hi, ?string $norma): void
    {
        $this->tituloSeccion(__('transformers.report.methodology'));

        if (!empty($data['cromas'] ?? [])) {
            $this->vineta(__('transformers.report.method_cromas', ['standard' => $norma ?? '—']));
        }
        if (!empty($data['fiquis'] ?? [])) {
            $this->vineta(__('transformers.report.method_fiquis'));
        }
        if (!empty($data['furanos'] ?? [])) {
            $this->vineta(__('transformers.report.method_furanos'));
        }
        if (!empty($data['fpots'] ?? [])) {
            $this->vineta(__('transformers.report.method_fpot'));
        }
        if (($hi['index'] ?? null) !== null) {
            $this->vineta(__('transformers.report.method_hi'));
        }

        $this->saltoDePagina();
    }

    private function cromatografia(array $data, array $charts): void
    {
        if (empty($data['cromas'] ?? [])) {
            return;
        }

        $this->tituloSeccion(__('transformers.report.dga'));
        // Mismo orden y misma cabecera que el PDF: nombre corto, símbolo,
        // unidad y el límite de norma ("Máx N").
        $GASES = ['h2' => 'H2', 'ch4' => 'CH4', 'c2h2' => 'C2H2', 'c2h4' => 'C2H4',
                  'c2h6' => 'C2H6', 'co' => 'CO', 'co2' => 'CO2', 'o2' => 'O2', 'n2' => 'N2'];
        $cols = [];
        foreach ($GASES as $g => $sim) {
            $lim = $this->gasLimits[$g] ?? null;
            $cols[] = [
                __('cromas.' . $g . '_short') . "\n" . $sim . "\n" . __('cromas.gas_unit')
                    . ($lim !== null ? "\n" . __('transformers.report.max_short') . ' ' . $lim : ''),
                $g,
            ];
        }
        $this->narrativa('cromas');
        $this->tablaDeMuestras($data['cromas'], $cols);
        $this->graficos($charts, 'cromas');

        // 4.1 Duval — solo si hay gráficos, igual que el PDF.
        // Numeración de subsecciones: se declara ANTES del primer uso. Estaba
        // más abajo y el bloque de Duval ya la incrementaba → el informe moría
        // con "Undefined variable $sub" en cuanto el trafo tenía gráficos Duval.
        $sub = 0;
        $hayDuval = collect($charts)->contains(fn ($c) => ($c['group'] ?? null) === 'duval');
        if ($hayDuval) {
            $this->subSeccion(++$sub, __('cromas.duval_tab'));
            $dv = $this->resumen['cromas'] ?? [];
            if (!empty($dv['duval_tri_zone'])) {
                $this->vineta(__('cromas.duval_tri') . ' 1: ' . __('cromas.zone.' . $dv['duval_tri_zone']));
            }
            if (!empty($dv['duval_pent_zone'])) {
                $this->vineta(__('cromas.duval_pent') . ' 1: ' . __('cromas.zone.' . $dv['duval_pent_zone']));
            }
            $this->graficos($charts, 'duval');
        }

        // 4.2 (o 4.1 si no hubo Duval) — Rogers / Doernenburg.
        $ratios = $this->ratiosDeLaUltima($data);
        if (!empty($ratios)) {
            $this->subSeccion(++$sub, __('transformers.report.ratio_methods'));
            $tr = $this->tabla('ratios');
            $this->cabecera($tr, [
                [__('cromas.tab'), 2800],
                [__('cromas.ratios_help_short'), 4200],
                [__('cromas.ratios.verdict'), 3200],
            ]);
            foreach ($ratios as $fila) {
                $tr->addRow();
                $this->celdaTexto($tr, 2800, $fila[0], true);
                $this->celdaTexto($tr, 4200, $fila[1]);
                $this->celdaTexto($tr, 3200, $fila[2]);
            }
            $this->seccion->addTextBreak(1);
        }

        $this->ieee($data, $sub);
        $this->saltoDePagina();
    }

    /**
     * Subsección IEEE C57.104 (DGA Status), igual que el PDF: el veredicto y el
     * semáforo de las 4 tablas de la norma.
     */
    private function ieee(array $data, int &$sub): void
    {
        $dg = $data['cromasDgaStatus'] ?? null;
        if (!$dg) {
            return;
        }

        $this->subSeccion(++$sub, __('transformers.ieee_dga.pdf_title'));

        if (!empty($dg['label'])) {
            $this->vineta($dg['label'] . ': ' . ($dg['text'] ?? ''));
        }

        // ok / bad / na por tabla, con el mismo color del PDF.
        $estado = [
            ($dg['table1_ok'] ?? null) === true ? 'ok' : (($dg['table1_ok'] ?? null) === false ? 'bad' : 'na'),
            ($dg['table2_exceeded'] ?? false) ? 'bad' : 'ok',
            ($dg['table3_ok'] ?? null) === true ? 'ok' : (($dg['table3_ok'] ?? null) === false ? 'bad' : 'na'),
            ($dg['table4_ok'] ?? null) === true ? 'ok' : (($dg['table4_ok'] ?? null) === false ? 'bad' : 'na'),
        ];
        $HEX = ['ok' => '1D7044', 'bad' => self::ROJO, 'na' => '9AA0A6'];
        $ETQ = [
            'ok'  => __('transformers.ieee_dga.pdf_ok'),
            'bad' => __('transformers.ieee_dga.pdf_bad'),
            'na'  => __('transformers.ieee_dga.pdf_na'),
        ];

        $tabla = $this->tabla('ieee');
        $this->cabecera($tabla, array_map(
            fn ($i) => [__('transformers.ieee_dga.table') . ' ' . $i, (int) (self::ANCHO_UTIL / 4)],
            [1, 2, 3, 4],
        ));
        $tabla->addRow();
        foreach ($estado as $e) {
            $tabla->addCell((int) (self::ANCHO_UTIL / 4), ['bgColor' => $HEX[$e], 'valign' => 'center'])
                ->addText($ETQ[$e], ['bold' => true, 'size' => 8, 'color' => 'FFFFFF'], ['alignment' => Jc::CENTER]);
        }
        $this->seccion->addTextBreak(1);

        // Tabla 4 (tasa de generación): solo si la norma la aplica, igual que el
        // PDF. Faltaban la meta (O2/N2, período, muestras) y las tasas por gas.
        if (empty($dg['table4_applicable'])) {
            $this->notaNorma($dg);

            return;
        }

        $col = ($dg['column'] ?? 'le02') === 'gt02'
            ? __('transformers.ieee_dga.col_gt02')
            : __('transformers.ieee_dga.col_le02');

        // Fechas de las muestras que se usaron para la tasa.
        $porId = [];
        foreach (($dg['samples'] ?? []) as $m) {
            $porId[$m['id']] = $m['date'];
        }
        $usadas = [];
        foreach (($dg['rate']['sample_ids'] ?? []) as $sid) {
            if (isset($porId[$sid])) {
                $usadas[] = $porId[$sid];
            }
        }

        $meta = $this->tabla('ieeemeta');
        $this->filaClaveValor($meta, __('transformers.ieee_dga.column_label'), $col);
        $this->filaClaveValor($meta, __('transformers.ieee_dga.period'), ($dg['rate']['period_months'] ?? '—') . ' ' . __('transformers.ieee_dga.months'));
        $this->filaClaveValor(
            $meta,
            __('transformers.ieee_dga.used_samples'),
            ($dg['rate']['samples'] ?? '—') . ($usadas ? ' — ' . implode(' · ', $usadas) : ''),
        );
        $this->seccion->addTextBreak(1);

        $ancho = (int) (self::ANCHO_UTIL / 4);
        $tasas = $this->tabla('ieeerate');
        $this->cabecera($tasas, [
            ['Gas', $ancho],
            [__('transformers.ieee_dga.per_year'), $ancho],
            [__('transformers.ieee_dga.per_day'), $ancho],
            [__('cromas.state'), $ancho],
        ]);

        foreach (($dg['rate']['per_gas'] ?? []) as $gas => $x) {
            $excede = (bool) ($x['exceeded'] ?? false);
            $tasas->addRow();
            $this->celdaTexto($tasas, $ancho, mb_strtoupper((string) $gas), true);

            // La celda del valor se pinta si excede, como el `.over` del PDF.
            $celda = $tasas->addCell($ancho, $excede ? ['bgColor' => 'FDECEA', 'valign' => 'center'] : ['valign' => 'center']);
            $texto = $celda->addTextRun(['spaceAfter' => 0]);
            $texto->addText((string) ($x['rate'] ?? '—'), ['size' => 8, 'bold' => $excede, 'color' => $excede ? self::ROJO : self::TEXTO]);
            if (($x['limit'] ?? null) !== null) {
                $texto->addText('  ' . __('transformers.report.max_short') . ' ' . $x['limit'], ['size' => 7, 'color' => self::TENUE]);
            }

            $this->celdaTexto($tasas, $ancho, (string) ($x['rate_day'] ?? '—'));
            $tasas->addCell($ancho, ['valign' => 'center'])->addText(
                $excede ? __('transformers.ieee_dga.pdf_bad') : __('transformers.ieee_dga.pdf_ok'),
                ['size' => 8, 'bold' => $excede, 'color' => $excede ? self::ROJO : '1D7044'],
            );
        }
        $this->seccion->addTextBreak(1);
        $this->notaNorma($dg);
    }

    /** Pie de la subsección IEEE: la norma aplicada (el `$dg['source']` del PDF). */
    private function notaNorma(array $dg): void
    {
        if (empty($dg['source'])) {
            return;
        }

        $this->seccion->addText(
            (string) $dg['source'],
            ['size' => 7.5, 'color' => '94A3B8'],
            ['spaceAfter' => 80],
        );
    }

    private function calidadDelAceite(array $data, array $charts): void
    {
        if (empty($data['fiquis'] ?? [])) {
            return;
        }

        $this->tituloSeccion(__('transformers.report.oil_quality'));
        // Cabecera de 3 líneas del PDF: nombre · norma ASTM · medida, más el
        // límite si la norma lo define.
        $cols = collect($data['fiquisColumns'] ?? [])->map(function ($c) {
            $fl = $this->fiquisLimits[$c['key']] ?? null;
            $lim = $fl
                ? "\n" . __('transformers.report.' . ($fl['dir'] === 'max' ? 'max_short' : 'min_short')) . ' ' . $fl['value']
                : '';

            // OJO: solo rig y pot tienen `_head` (la condición de ensayo). Para
            // el resto el PDF muestra la unidad. Si se pide una clave que no
            // existe, Laravel devuelve la clave cruda y se ve "fiquis.acid_head".
            $head = \Illuminate\Support\Facades\Lang::has('fiquis.' . $c['key'] . '_head')
                ? __('fiquis.' . $c['key'] . '_head')
                : ($c['unit'] ?? '');

            return [
                __('fiquis.' . $c['key'])
                    . "\n" . __('fiquis.' . $c['key'] . '_astm')
                    . ($head !== '' ? "\n" . $head : '') . $lim,
                $c['key'],
            ];
        })->all();
        $this->narrativa('fiquis');
        $this->tablaDeMuestras($data['fiquis'], $cols);
        $this->graficos($charts, 'fiquis');
        $this->saltoDePagina();
    }

    private function furanos(array $data, array $charts): void
    {
        if (empty($data['furanos'] ?? [])) {
            return;
        }

        $this->tituloSeccion(__('furanos.tab'));
        $this->narrativa('furanos');
        $this->tablaDeMuestras($data['furanos'], [['2-FAL', 'fal'], [__('furanos.dp'), 'dp']]);
        $this->graficos($charts, 'furanos', porFila: 1);
        $this->saltoDePagina();
    }

    private function factorDePotencia(array $data): void
    {
        if (empty($data['fpots'] ?? [])) {
            return;
        }

        $this->tituloSeccion(__('fpot.tab'));
        $this->narrativa('fpot');
        $this->tablaDeMuestras($data['fpots'], [
            [__('fpot.value'), 'value'],
            [__('fpot.temperature'), 'temperature'],
        ]);
        $this->saltoDePagina();
    }

    /**
     * Conclusiones IGUALES a las del PDF: un párrafo por prueba encabezado por
     * su nivel de urgencia. El texto sale de ReportNarrative, la misma fuente
     * que usa el blade — antes acá había una tabla inventada.
     */
    private function conclusiones(array $data, array $hi): void
    {
        $this->tituloSeccion(__('transformers.report.conclusions'));

        $algo = false;
        foreach (['cromas' => 'cromas.tab', 'fiquis' => 'fiquis.tab', 'furanos' => 'furanos.tab', 'fpot' => 'fpot.tab'] as $clave => $lang) {
            $n = $this->narr[$clave] ?? null;
            if (empty($n['concl'])) {
                continue;
            }
            $algo = true;

            $p = $this->seccion->addTextRun(['spaceAfter' => 100, 'alignment' => Jc::BOTH]);
            $p->addText(__($lang) . ': ', ['bold' => true, 'size' => self::PT, 'color' => self::TEXTO]);
            // Urgencia resaltada: naranja investigar, rojo crítico (como $urgStyle).
            $p->addText(
                \App\Support\Transformers\ReportNarrative::urgency($n['level']) . ' ',
                ['bold' => $n['level'] >= 2, 'size' => self::PT, 'color' => $this->colorUrgencia($n['level'])],
            );
            $p->addText(implode(' ', $n['concl']), ['size' => self::PT, 'color' => self::TEXTO]);
        }

        if (!$algo) {
            $this->seccion->addText('—', ['size' => self::PT, 'color' => self::TEXTO]);
        }
    }

    /** Mismo resalte de urgencia que $urgStyle del blade. */
    private function colorUrgencia(int $nivel): string
    {
        return match ($nivel) {
            3 => self::ROJO,
            2 => 'E2661E',
            default => self::TEXTO,
        };
    }

    /** Viñetas de diagnóstico de una prueba (los $cDiag/$fDiag/... del PDF). */
    private function narrativa(string $clave): void
    {
        foreach (($this->narr[$clave]['diag'] ?? []) as $linea) {
            $this->vineta($linea);
        }
    }

    private function firmas(array $signers): void
    {
        if (empty($signers)) {
            return;
        }

        $this->seccion->addTextBreak(3);

        foreach ($signers as $f) {
            // Línea PARA FIRMAR A MANO. Nunca la imagen de la firma.
            $this->seccion->addText(
                '________________________________',
                ['size' => self::PT, 'color' => self::TEXTO],
                ['alignment' => Jc::CENTER, 'spaceAfter' => 20],
            );
            $this->seccion->addText(
                mb_strtoupper((string) ($f['relation'] ?? '')),
                ['bold' => true, 'size' => 8.5, 'color' => self::TEXTO],
                ['alignment' => Jc::CENTER, 'spaceAfter' => 0],
            );
            $this->seccion->addText(
                (string) ($f['name'] ?? ''),
                ['size' => 8, 'color' => self::SUAVE],
                ['alignment' => Jc::CENTER, 'spaceAfter' => 0],
            );
            if (!empty($f['title'])) {
                $this->seccion->addText(
                    (string) $f['title'],
                    ['size' => 7, 'color' => self::TENUE],
                    ['alignment' => Jc::CENTER, 'spaceAfter' => 0],
                );
            }
            $this->seccion->addTextBreak(2);
        }
    }

    /** Disclaimer legal del workspace, una sola vez al final (como el PDF). */
    private function disclaimer(Transformer $t): void
    {
        $texto = $t->tenant?->report_disclaimer;
        if (blank($texto)) {
            return;
        }

        $this->seccion->addTextBreak(1);
        $this->seccion->addText(
            __('transformers.report.disclaimer_title'),
            ['bold' => true, 'size' => 8, 'color' => self::AZUL],
            ['spaceBefore' => 120, 'spaceAfter' => 40],
        );
        $this->seccion->addText(
            (string) $texto,
            ['size' => 7, 'color' => self::TENUE],
            ['alignment' => Jc::BOTH, 'spaceBefore' => 120],
        );
    }

    // ─── Bloques reutilizables ───────────────────────────────────────────────

    /** Banda azul con el número de sección, como .ssec del PDF. */
    private function tituloSeccion(string $texto): void
    {
        $this->n++;
        $tabla = $this->tabla('band' . $this->n, sinBorde: true);
        $tabla->addRow();
        $tabla->addCell(self::ANCHO_UTIL, ['bgColor' => self::AZUL, 'valign' => 'center'])
            ->addText(
                $this->n . '. ' . mb_strtoupper($texto),
                ['bold' => true, 'size' => 11, 'color' => 'FFFFFF'],
                ['spaceBefore' => 40, 'spaceAfter' => 40],
            );
        $this->seccion->addTextBreak(1);
    }

    /** Subsección numerada (4.1, 4.2…), como el `.ssec` anidado del PDF. */
    private function subSeccion(int $sub, string $texto): void
    {
        $this->seccion->addText(
            $this->n . '.' . $sub . ' ' . $texto,
            ['bold' => true, 'size' => 10, 'color' => self::AZUL],
            ['spaceBefore' => 120, 'spaceAfter' => 60],
        );
    }

    /**
     * Rogers y Doernenburg de la muestra más reciente, como la tabla del PDF.
     *
     * @return array<int,array{0:string,1:string,2:string}>
     */
    private function ratiosDeLaUltima(array $data): array
    {
        $r = collect($data['cromas'] ?? [])->first()['ratios'] ?? null;
        if (!$r) {
            return [];
        }

        $filas = [];
        foreach (['rogers', 'doernenburg'] as $metodo) {
            $m = $r[$metodo] ?? null;
            if (!$m) {
                continue;
            }
            $cocientes = collect($m['ratios'] ?? [])
                ->map(fn ($x) => $x['label'] . ' = ' . $x['value'])->implode(' · ');
            $filas[] = [
                __('cromas.ratios.' . $metodo),
                $cocientes ?: '—',
                $m['fault'] ? __('cromas.ratios.fault.' . $m['fault']) : __('cromas.ratios.na'),
            ];
        }

        return $filas;
    }

    /** Viñeta, como el <ul class="narr"> del PDF. */
    private function vineta(string $texto): void
    {
        $this->seccion->addListItem(
            $texto,
            0,
            ['size' => 8.5, 'color' => self::TEXTO],
            ['listType' => \PhpOffice\PhpWord\Style\ListItem::TYPE_BULLET_FILLED],
            ['spaceAfter' => 60],
        );
    }

    /** @param array<int,array{0:string,1:string}> $columnas [etiqueta, clave] */
    private function tablaDeMuestras(array $muestras, array $columnas): void
    {
        if (empty($columnas)) {
            return;
        }

        $tabla = $this->tabla('m' . $this->n);
        $ancho = (int) max(600, floor(self::ANCHO_UTIL / (count($columnas) + 2)));

        $cab = [[__('cromas.sample_date'), $ancho]];
        foreach ($columnas as [$etiqueta]) {
            $cab[] = [$etiqueta, $ancho];
        }
        // El PDF rotula esta columna "Estado" (cromas.state), no "Condición".
        $cab[] = [__('cromas.state'), $ancho];
        $this->cabecera($tabla, $cab);

        foreach (array_values($muestras) as $m) {
            $tabla->addRow();
            // La fecha ya viene lista del payload: sin hora si es medianoche, CON
            // hora si el ensayo la tiene. Recortarla a 10 caracteres borraba esa
            // hora real.
            $this->celdaTexto($tabla, $ancho, (string) ($m['sample_date'] ?? '') ?: '—');
            foreach ($columnas as [, $clave]) {
                $v = $m[$clave] ?? null;
                $this->celdaTexto($tabla, $ancho, $v === null || $v === '' ? '—' : (string) $v);
            }
            $this->celdaEstado($tabla, $ancho, $m['condition'] ?? null, $m['color'] ?? null);
        }
        $this->seccion->addTextBreak(1);
    }

    /**
     * Gráficos en rejilla de DOS por fila, como el `.charts td { width:50% }` del
     * PDF. A ancho completo se veían enormes y no se parecían al informe.
     *
     * @param  int  $porFila  1 para los que el PDF muestra centrados y solos.
     */
    private function graficos(array $charts, string $grupo, int $porFila = 2): void
    {
        $imagenes = collect($charts)
            ->filter(fn ($c) => ($c['group'] ?? null) === $grupo && is_string($c['dataURL'] ?? null))
            ->values();

        if ($imagenes->isEmpty()) {
            return;
        }

        // Ancho útil 9200 twips ≈ 460 pt. Con 2 por fila, cada uno ~215 pt
        // menos el aire de la celda.
        $anchoCelda = (int) floor(self::ANCHO_UTIL / $porFila);
        $anchoImg   = $porFila === 1 ? 300 : 210;

        $tabla = $this->tabla('g' . $grupo . $this->n, sinBorde: true);
        foreach ($imagenes->chunk($porFila) as $fila) {
            $tabla->addRow();
            foreach ($fila as $img) {
                $celda = $tabla->addCell($anchoCelda, ['valign' => 'top']);
                if (!empty($img['label'])) {
                    // .chart-cap del PDF: 6.8pt, negrita, azul, mayúsculas.
                    $celda->addText(
                        mb_strtoupper((string) $img['label']),
                        ['size' => 7, 'bold' => true, 'color' => self::AZUL],
                        ['alignment' => Jc::CENTER, 'spaceAfter' => 20],
                    );
                }
                $archivo = $this->imagenTemporal((string) $img['dataURL']);
                if ($archivo === null) {
                    continue;
                }
                try {
                    $celda->addImage($archivo, ['width' => $anchoImg, 'alignment' => Jc::CENTER]);
                } catch (\Throwable $e) {
                    // Un gráfico ilegible no puede tumbar el informe entero.
                }
            }
            // Celdas vacías para cerrar la última fila incompleta.
            for ($i = $fila->count(); $i < $porFila; $i++) {
                $tabla->addCell($anchoCelda);
            }
        }
        $this->seccion->addTextBreak(1);
    }

    // ─── Utilidades ──────────────────────────────────────────────────────────

    private function saltoDePagina(): void
    {
        $this->seccion->addPageBreak();
    }

    private function tabla(string $nombre, bool $sinBorde = false)
    {
        $this->word->addTableStyle($nombre, $sinBorde
            ? ['cellMargin' => 60, 'alignment' => Jc::START, 'width' => 100 * 50, 'unit' => \PhpOffice\PhpWord\SimpleType\TblWidth::PERCENT]
            : [
                'borderColor' => self::BORDE, 'borderSize' => 6, 'cellMargin' => 70,
                'alignment' => Jc::START,
                // Ancho completo de la caja de texto: con anchos fijos las tablas
                // quedaban angostas y las columnas desparejas.
                'width' => 100 * 50, 'unit' => \PhpOffice\PhpWord\SimpleType\TblWidth::PERCENT,
            ]);

        return $this->seccion->addTable($nombre);
    }

    /** @param array<int,array{0:string,1:int}> $columnas */
    private function cabecera($tabla, array $columnas): void
    {
        $tabla->addRow();
        foreach ($columnas as [$texto, $ancho]) {
            $celda = $tabla->addCell($ancho, ['bgColor' => self::AZUL, 'valign' => 'center']);
            // Las cabeceras del PDF son de varias líneas (nombre/símbolo/unidad/
            // límite): se respetan como párrafos dentro de la celda.
            foreach (explode("\n", $texto) as $i => $linea) {
                // La línea del límite va en ámbar claro, como el `.lim` del PDF.
                $esLimite = str_starts_with($linea, __('transformers.report.max_short'))
                    || str_starts_with($linea, __('transformers.report.min_short'));
                $celda->addText(
                    $linea,
                    [
                        'bold'  => $i === 0 || $esLimite,
                        'size'  => $i === 0 ? 7.5 : 6.5,
                        'color' => $esLimite ? 'FBD9A6' : 'FFFFFF',
                    ],
                    ['spaceAfter' => 0],
                );
            }
        }
    }

    /** Fila etiqueta/valor con el fondo claro que usa el PDF en la etiqueta. */
    private function filaClaveValor($tabla, string $clave, string $valor): void
    {
        $tabla->addRow();
        $tabla->addCell(3400, ['bgColor' => self::CLAVE, 'valign' => 'center'])
            ->addText(mb_strtoupper($clave), ['bold' => true, 'size' => 7.5, 'color' => self::AZUL]);
        $tabla->addCell(6800, ['valign' => 'center'])
            ->addText($valor, ['size' => 8, 'color' => self::TEXTO]);
    }

    /**
     * Celda-badge: el PDF usa pastillas de color (`.pill`) para la condición.
     * Word no tiene bordes redondeados, así que se rellena la celda con el mismo
     * color y el texto va en blanco — misma lectura de un vistazo.
     */
    private function celdaEstado($tabla, int $ancho, ?string $condicion, ?string $color): void
    {
        if ($condicion === null) {
            $this->celdaTexto($tabla, $ancho, '—');

            return;
        }

        $tabla->addCell($ancho, ['bgColor' => $this->hexCondicion($color), 'valign' => 'center'])
            ->addText(
                ConditionLabel::forCondition($condicion),
                ['bold' => true, 'size' => 8, 'color' => 'FFFFFF'],
                ['alignment' => Jc::CENTER],
            );
    }

    /** Mismo mapa de color que el PDF ($HEX del blade). */
    private function hexCondicion(?string $color): string
    {
        return [
            'green'  => '1D7044',
            'lime'   => '5AA82E',
            'yellow' => 'E9A23B',
            'orange' => 'E2661E',
            'red'    => self::ROJO,
        ][$color] ?? '9AA0A6';
    }

    private function celdaTexto($tabla, int $ancho, string $texto, bool $negrita = false): void
    {
        $tabla->addCell($ancho, ['valign' => 'center'])
            ->addText($texto, ['size' => 8, 'bold' => $negrita, 'color' => self::TEXTO]);
    }

    /** Vuelca un data-URI (PNG/JPG) a un archivo temporal. Null si no se puede. */
    private function imagenTemporal(string $dataUri): ?string
    {
        if (!preg_match('#^data:image/(png|jpeg|jpg);base64,#i', $dataUri, $m)) {
            return null;
        }
        $bin = base64_decode(substr($dataUri, strpos($dataUri, ',') + 1), true);
        if ($bin === false || $bin === '') {
            return null;
        }

        $ext = strtolower($m[1]) === 'png' ? '.png' : '.jpg';
        $semilla = tempnam(sys_get_temp_dir(), 'trchart');
        $ruta = $semilla . $ext;
        @unlink($semilla);

        if (file_put_contents($ruta, $bin) === false) {
            return null;
        }
        $this->temporales[] = $ruta;

        return $ruta;
    }
}
