{{--
    PDF firmado de una entrega de formato.

    Solo pinta: todo lo que hay que decidir (que version de la plantilla, que
    firmas entran, que foto se incrusta) ya lo resolvio
    FormSubmissionPdfService. La hoja de estilos se queda en las capacidades
    reales de DomPDF: nada de flex ni de grid, tablas para maquetar y solo las
    core fonts, que son las unicas que existen sin instalar nada.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ $formato['codigo'] ?? __('form_submissions.pdf.title') }} — {{ $pie['identificador'] }}</title>
    <style>
        @page { margin: 26px 28px 52px 28px; }
        body { font-family: Helvetica; font-size: 9pt; color: #32363A; margin: 0; }
        h1, h2, h3 { margin: 0; }

        /* Membrete del workspace */
        .letterhead { border-bottom: 2px solid #354A5F; padding-bottom: 10px; margin-bottom: 12px; }
        .letterhead td { vertical-align: middle; }
        .letterhead__logo { width: 110px; }
        .letterhead__logo img { max-width: 100px; max-height: 54px; }
        .letterhead__name { font-size: 13pt; font-weight: bold; color: #354A5F; }
        .letterhead__address { font-size: 8pt; color: #6A6D70; margin-top: 2px; }
        .letterhead__doc { text-align: right; font-size: 8pt; color: #6A6D70; }
        .letterhead__doc strong { color: #32363A; font-size: 11pt; }

        .disclaimer { background: #F8FAFC; border-left: 3px solid #94A3B8; padding: 6px 10px;
                      font-size: 7.5pt; color: #475569; margin: 0 0 12px 0; }

        /* Bloques */
        .block { margin: 0 0 14px 0; }
        .block__title { background: #0A6ED1; color: #ffffff; font-size: 8.5pt; font-weight: bold;
                        text-transform: uppercase; letter-spacing: 0.06em; padding: 5px 8px; margin: 0 0 6px 0; }
        .block__sub { font-size: 8.5pt; font-weight: bold; color: #354A5F; margin: 8px 0 4px 0; }

        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 3px 6px; border: 1px solid #E5E5E5; font-size: 8.5pt; vertical-align: top; }
        table.kv td.k { background: #F1F5F9; font-weight: bold; width: 22%; color: #475569; }

        table.data { width: 100%; border-collapse: collapse; margin: 0 0 8px 0; }
        table.data thead th { background: #354A5F; color: #ffffff; font-weight: bold; font-size: 8pt;
                              text-align: left; padding: 4px 6px; border: 1px solid #2B3B4C; }
        table.data tbody td { padding: 4px 6px; border: 1px solid #E5E5E5; font-size: 8pt; }
        table.data tbody tr:nth-child(even) td { background: #F8FAFC; }

        .muted { color: #94A3B8; font-style: italic; }
        .req { color: #C8281D; font-weight: bold; }

        .flag { display: inline-block; background: #C8281D; color: #ffffff; font-size: 7pt;
                font-weight: bold; padding: 1px 5px; letter-spacing: 0.04em; }
        .ok { color: #1D7044; font-weight: bold; }

        .evidence { width: 68px; }
        .evidence img { width: 62px; border: 1px solid #CBD5E1; }

        .sheet { page-break-before: always; text-align: center; }
        .sheet img { max-width: 100%; max-height: 940px; }
        .sheet__caption { font-size: 7.5pt; color: #6A6D70; margin-top: 6px; }

        .signers { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .signers td { width: 25%; padding: 4px 8px; text-align: center; vertical-align: bottom; font-size: 8pt; }
        .signers__img { height: 42px; }
        .signers__img img { max-height: 40px; max-width: 130px; }
        .signers__line { border-top: 1px solid #32363A; padding-top: 3px; }
        .signers__rel { font-size: 7pt; color: #6A6D70; text-transform: uppercase; letter-spacing: 0.05em; }
        .signers__name { font-weight: bold; }
        .signers__title { font-size: 7.5pt; color: #475569; }

        /* Pie fijo: el identificador verificable tiene que salir en toda pagina.
           Va maquetado con una tabla y no con floats: un float dentro de un
           elemento fijo hace que DomPDF genere una pagina por cada bloque. */
        #footer { position: fixed; bottom: -34px; left: 0; right: 0; height: 30px;
                  border-top: 1px solid #E5E5E5; padding-top: 4px;
                  font-size: 7pt; color: #6A6D70; }
        #footer table { width: 100%; border-collapse: collapse; }
        #footer td { font-size: 7pt; color: #6A6D70; padding: 0; }
        #footer td.r { text-align: right; }
        .pagenum:after { content: counter(page); }
    </style>
</head>
<body>

<div id="footer">
    <table>
        <tr>
            <td>
                {{ __('form_submissions.pdf.verify_id') }}: <strong>{{ $pie['identificador'] }}</strong>
                · {{ __('form_submissions.pdf.generated_at') }} {{ $pie['generado'] }}
            </td>
            <td class="r">{{ __('form_submissions.pdf.page') }} <span class="pagenum"></span></td>
        </tr>
    </table>
</div>

{{-- 1 · Membrete del workspace --}}
<table class="letterhead">
    <tr>
        @if (!empty($membrete['logo']))
            <td class="letterhead__logo"><img src="{{ $membrete['logo'] }}" alt=""></td>
        @endif
        <td>
            <div class="letterhead__name">{{ $membrete['nombre'] }}</div>
            @if (!empty($membrete['direccion']))
                <div class="letterhead__address">{{ $membrete['direccion'] }}</div>
            @endif
        </td>
        <td class="letterhead__doc">
            <strong>{{ $formato['codigo'] ?? __('form_submissions.pdf.title') }}</strong><br>
            {{ __('form_submissions.pdf.form_version') }}: {{ $formato['version'] }}<br>
            {{ __('form_submissions.pdf.status') }}: {{ $formato['estado'] }}
        </td>
    </tr>
</table>

@if (!empty($membrete['disclaimer']))
    <p class="disclaimer">{{ $membrete['disclaimer'] }}</p>
@endif

{{-- 2 · Cabecera del plan --}}
@if (!empty($plan))
    <div class="block">
        <h2 class="block__title">{{ __('form_submissions.pdf.plan') }}</h2>
        <table class="kv">
            <tr>
                <td class="k">{{ __('form_submissions.pdf.code') }}</td>
                <td>{{ $plan['codigo'] ?: '—' }}</td>
                <td class="k">{{ __('form_submissions.pdf.work_order') }}</td>
                <td>{{ $plan['num_os'] ?: '—' }}</td>
            </tr>
            <tr>
                <td class="k">{{ __('form_submissions.pdf.company') }}</td>
                <td>{{ $plan['empresa'] ?: '—' }}</td>
                <td class="k">{{ __('form_submissions.pdf.tax_id') }}</td>
                <td>{{ $plan['empresa_doc'] ?: '—' }}</td>
            </tr>
            <tr>
                <td class="k">{{ __('form_submissions.pdf.work_type') }}</td>
                <td>{{ $plan['tipo'] ?: '—' }}</td>
                <td class="k">{{ __('form_submissions.pdf.location') }}</td>
                <td>
                    {{ $plan['ubicacion'] ?: '—' }}
                    @if (!empty($plan['puesto'])) · {{ $plan['puesto'] }} @endif
                    @if (!empty($plan['area'])) · {{ $plan['area'] }} @endif
                </td>
            </tr>
            <tr>
                <td class="k">{{ __('form_submissions.pdf.date_start') }}</td>
                <td>{{ $plan['desde'] ?: '—' }}</td>
                <td class="k">{{ __('form_submissions.pdf.date_end') }}</td>
                <td>{{ $plan['hasta'] ?: '—' }}</td>
            </tr>
            <tr>
                <td class="k">{{ __('form_submissions.pdf.description') }}</td>
                <td colspan="3">{{ $plan['descripcion'] }}</td>
            </tr>
            <tr>
                <td class="k">{{ __('form_submissions.pdf.submitted_at') }}</td>
                <td colspan="3">{{ $formato['entregado_en'] ?: '—' }}</td>
            </tr>
        </table>
    </div>
@endif

{{-- 3 · El formato tal como se lleno --}}
@if (!empty($secciones))
    <div class="block">
        <h2 class="block__title">{{ __('form_submissions.pdf.content') }}</h2>

        @foreach ($secciones as $seccion)
            <h3 class="block__sub">{{ $seccion['titulo'] }}</h3>

            @php $pares = array_filter($seccion['campos'], fn ($c) => $c['render'] === 'par'); @endphp

            @if (!empty($pares))
                <table class="kv">
                    @foreach ($pares as $campo)
                        <tr>
                            <td class="k">
                                {{ $campo['etiqueta'] }}@if ($campo['requerido'])<span class="req"> *</span>@endif
                            </td>
                            <td>
                                @if (filled($campo['valor']))
                                    {{ $campo['valor'] }}
                                @else
                                    <span class="muted">{{ __('form_submissions.pdf.no_answer') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @foreach ($seccion['campos'] as $campo)
                @if ($campo['render'] === 'tabla')
                    <h3 class="block__sub">
                        {{ $campo['etiqueta'] }}@if ($campo['requerido'])<span class="req"> *</span>@endif
                    </h3>
                    @if (empty($campo['filas']))
                        <p class="muted">{{ __('form_submissions.pdf.no_answer') }}</p>
                    @else
                        <table class="data">
                            <thead>
                                <tr>
                                    @foreach ($campo['cabeceras'] as $cabecera)
                                        <th>{{ $cabecera }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($campo['filas'] as $fila)
                                    <tr>
                                        @foreach ($fila as $celda)
                                            <td>{{ $celda }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @elseif ($campo['render'] === 'imagenes')
                    <h3 class="block__sub">{{ $campo['etiqueta'] }}</h3>
                    @forelse ($campo['imagenes'] as $imagen)
                        <img src="{{ $imagen }}" alt="" style="max-width: 45%; margin: 0 6px 6px 0;">
                    @empty
                        <p class="muted">{{ __('form_submissions.pdf.no_answer') }}</p>
                    @endforelse
                @endif
            @endforeach
        @endforeach
    </div>
@endif

@if (filled($formato['observaciones'] ?? null))
    <div class="block">
        <h2 class="block__title">{{ __('form_submissions.pdf.observations') }}</h2>
        <p>{{ $formato['observaciones'] }}</p>
    </div>
@endif

{{-- 5 · Bloque de firmas --}}
<div class="block">
    <h2 class="block__title">{{ __('form_submissions.pdf.signatures') }}</h2>

    @if (empty($firmas))
        <p class="muted">{{ __('form_submissions.pdf.no_signatures') }}</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>{{ __('form_submissions.pdf.evidence') }}</th>
                    <th>{{ __('form_submissions.pdf.signer') }}</th>
                    <th>{{ __('form_submissions.pdf.document_number') }}</th>
                    <th>{{ __('form_submissions.pdf.role') }}</th>
                    <th>{{ __('form_submissions.pdf.signed_at') }}</th>
                    <th>{{ __('form_submissions.pdf.col_method') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($firmas as $firma)
                    <tr>
                        <td class="evidence">
                            @if (!empty($firma['foto']))
                                <img src="{{ $firma['foto'] }}" alt="">
                            @else
                                <span class="muted">{{ __('form_submissions.pdf.no_evidence') }}</span>
                            @endif
                        </td>
                        <td>{{ $firma['nombre'] ?: '—' }}</td>
                        <td>{{ $firma['documento'] ?: '—' }}</td>
                        <td>{{ $firma['rol'] }}</td>
                        <td>{{ $firma['hora'] }}</td>
                        <td>
                            {{ $firma['metodo'] }}
                            @if ($firma['pendiente'])
                                <br><span class="flag">{{ __('form_submissions.pdf.pending_review') }}</span>
                            @else
                                <br><span class="ok">{{ __('form_submissions.pdf.reviewed') }}</span>
                            @endif
                            @if ($firma['distancia'] !== null)
                                <br><span class="muted">{{ __('form_submissions.pdf.distance', [
                                    'value' => $firma['distancia'],
                                    'threshold' => $firma['umbral'],
                                ]) }}</span>
                            @endif
                            @if (filled($firma['motivo']))
                                <br><span class="muted">{{ __('form_submissions.pdf.override_reason') }}: {{ $firma['motivo'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- 6 · Firmas formales del workspace --}}
@if (!empty($firmantes))
    <div class="block">
        <h2 class="block__title">{{ __('form_submissions.pdf.formal_signers') }}</h2>
        <table class="signers">
            @foreach (array_chunk($firmantes, 4) as $fila)
                <tr>
                    @foreach ($fila as $firmante)
                        <td>
                            <div class="signers__img">
                                @if (!empty($firmante['firma']))
                                    <img src="{{ $firmante['firma'] }}" alt="">
                                @endif
                            </div>
                            <div class="signers__line">
                                <span class="signers__rel">{{ $firmante['relacion'] }}</span><br>
                                <span class="signers__name">{{ $firmante['nombre'] ?: '—' }}</span><br>
                                <span class="signers__title">{{ $firmante['cargo'] }}</span>
                            </div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>
@endif

{{-- 4 · Adjuntos. Van al final porque en la "HOJA X" el papel es el documento
     y ocupa la pagina entera. --}}
@foreach ($adjuntos as $adjunto)
    <div class="{{ $adjunto['pagina_completa'] ? 'sheet' : '' }}">
        @if (!$adjunto['pagina_completa'])
            <h2 class="block__title">{{ __('form_submissions.pdf.attachments') }}</h2>
        @endif

        @if (!empty($adjunto['imagen']))
            <img src="{{ $adjunto['imagen'] }}" alt="">
        @else
            <p class="muted">{{ __('form_submissions.pdf.attachment_not_embeddable') }}</p>
        @endif

        <p class="sheet__caption">
            {{ __('form_submissions.pdf.attachments') }} #{{ $adjunto['numero'] }}
            · {{ $adjunto['mime'] }}
            @if ($adjunto['subido']) · {{ $adjunto['subido'] }} @endif
            <br>{{ __('form_submissions.pdf.fingerprint') }}: {{ $adjunto['sha256'] }}
        </p>
    </div>
@endforeach

</body>
</html>
