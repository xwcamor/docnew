{{-- Disclaimer legal del tenant: se imprime UNA sola vez, al final del informe,
     como contenido en flujo normal. Al no ser un elemento fijo, DomPDF lo
     reacomoda solo y lo empuja a la página siguiente si no entra — nunca se
     superpone con el contenido ni con el footer. `page-break-inside: avoid`
     evita que el bloque se parta a la mitad. --}}
@php
    $rdTenant = $tenant ?? null;
    $rdDisc   = $rdTenant?->report_disclaimer;
    $rdSafe   = $safe ?? fn ($s) => (string) $s;
@endphp
@if (!empty($rdDisc))
    <div class="rdisc">
        <div class="rdisc__t">{{ $rdSafe(__('transformers.report.disclaimer_title')) }}</div>
        <p class="rdisc__b">{{ $rdSafe($rdDisc) }}</p>
    </div>
    <style>
        .rdisc { margin-top: 14px; padding: 8px 10px; border: 1px solid #E5E5E5; border-left: 3px solid #C8281D; border-radius: 4px; background: #FAFAFA; page-break-inside: avoid; }
        .rdisc__t { font-size: 7pt; font-weight: bold; color: #C8281D; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 3px; }
        .rdisc__b { font-size: 7pt; color: #6A6D70; text-align: justify; line-height: 1.35; margin: 0; }
    </style>
@endif
