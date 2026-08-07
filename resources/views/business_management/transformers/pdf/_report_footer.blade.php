{{-- Footer dibujado 100% con el canvas de dompdf (page_text/line), NO en flujo.
     Razón: un footer `position:fixed` deja que dompdf desborde la última fila de
     una tabla por debajo de él (la pisa). Dibujándolo fuera de flujo y reservando
     el margen inferior del @page, el contenido nunca entra en la banda del footer.

     Se repite en CADA página (page_text corre por página) y resuelve el número
     real con {PAGE_NUM}/{PAGE_COUNT}. Requiere isPhpEnabled=true (lo activa el
     controller). El disclaimer legal NO va aquí (largo variable): se imprime una
     vez al final como contenido en flujo (_report_disclaimer.blade.php). --}}
@php
    $rfTenant  = $tenant ?? null;
    $rfCompany = $rfTenant?->name ?: \App\Models\Setting::get('app.name', '');
    $rfAddr    = $rfTenant?->address;
    $rfSafe    = $safe ?? fn ($s) => (string) $s;
    $rfLeft    = trim($rfSafe($rfCompany));
    $rfMeta    = trim(implode('   |   ', array_filter([$rfSafe($rfAddr), $rfSafe($reportCode ?? ''), !empty($verifyCode) ? '#' . $verifyCode : ''])));
    $rfPage    = $rfSafe(__('transformers.report.page'));
    $rfOf      = $rfSafe(__('transformers.report.of'));
@endphp
<script type="text/php">
    if (isset($pdf)) {
        $w = $pdf->get_width();
        $h = $pdf->get_height();
        $mx = 32;                         // mismo margen lateral que el @page
        $lineY = $h - 40;                 // línea roja del footer
        $textY = $h - 33;                 // texto del footer
        $red  = array(0.784, 0.156, 0.114);
        $dark = array(0.196, 0.212, 0.227);
        $grey = array(0.416, 0.427, 0.439);

        $bold = $fontMetrics->getFont("Helvetica", "bold");
        $norm = $fontMetrics->getFont("Helvetica", "normal");

        // Línea superior roja del footer (en cada página).
        $pdf->page_line($mx, $lineY, $w - $mx, $lineY, $red, 0.6);

        // Izquierda: empresa (bold) + dirección/código (gris).
        $company = "{{ $rfLeft }}";
        $meta    = "{{ $rfMeta }}";
        $pdf->page_text($mx, $textY, $company, $bold, 8, $dark);
        $cw = $fontMetrics->getTextWidth($company, $bold, 8);
        if ($meta !== "") {
            $pdf->page_text($mx + $cw + 10, $textY, $meta, $norm, 7, $grey);
        }

        // Derecha: "Página N de M" alineado al margen derecho.
        $label  = "{{ $rfPage }} {PAGE_NUM} {{ $rfOf }} {PAGE_COUNT}";
        $sample = "{{ $rfPage }} 00 {{ $rfOf }} 00";
        $tw = $fontMetrics->getTextWidth($sample, $norm, 7);
        $pdf->page_text($w - $mx - $tw, $textY, $label, $norm, 7, $grey);
    }
</script>
