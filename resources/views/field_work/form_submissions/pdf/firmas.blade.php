{{--
    Una tabla de firmas: trabajadores, aprobadores o la entrega en si.

    Es la misma tabla tres veces con dos diferencias —el rotulo de encima y si
    lleva columna de rol— asi que va en un parcial. Antes era una sola tabla con
    las tres cosas revueltas y ordenadas por hora, que responde a «cuando se
    firmo» cuando lo que se pregunta al abrir el documento es «quien estuvo» y
    «quien lo autorizo».

    Recibe:
      · $titulo   rotulo del grupo
      · $filas    lo que devuelve FormSubmissionPdfService::firmas()
      · $conRol   si lleva la columna «Rol». Solo los aprobadores: ahi la
                  palabra informa —«Supervisor HSE»— y en los trabajadores
                  serian quince veces «Trabajador» gastando ancho.
--}}
@php
    $conRol = $conRol ?? false;
    $columnas = $conRol ? 6 : 5;
@endphp

{{-- El rotulo va DENTRO de la tabla, como primera fila de la cabecera, y no en
     un `<h3>` encima. Dos motivos, los dos de paginacion: un titulo suelto se
     queda huerfano al pie de una pagina con su tabla en la siguiente —pasaba—,
     y DomPDF repite el `<thead>` cuando una tabla se parte, asi que en una
     cuadrilla de treinta personas la pagina dos vuelve a decir «Trabajadores»
     en vez de empezar con nombres sin contexto. --}}
<div class="firmas__grupo-wrap">
    <table class="data firmas">
    <thead>
        <tr>
            <th class="firmas__grupo" colspan="{{ $columnas }}">{{ $titulo }}</th>
        </tr>
        <tr>
            <th>{{ __('form_submissions.pdf.signature') }}</th>
            <th>{{ __('form_submissions.pdf.signer') }}</th>
            @if ($conRol)
                <th>{{ __('form_submissions.pdf.approver_role') }}</th>
            @endif
            <th>{{ __('form_submissions.pdf.document_number') }}</th>
            <th>{{ __('form_submissions.pdf.signed_at') }}</th>
            <th>{{ __('form_submissions.pdf.col_method') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($filas as $firma)
            <tr>
                {{-- La firma trazada, que es lo que se espera ver al lado de un
                     nombre en un documento firmado. Sin permiso para verla la
                     celda queda vacia y no dice «Sin firma»: la firma existe, lo
                     que pasa es que este lector no la ve, y decir lo contrario
                     seria mentir en un documento de seguridad. --}}
                <td class="firma">
                    @if (!empty($firma['firma']))
                        <img src="{{ $firma['firma'] }}" alt="">
                    @endif
                </td>
                <td>{{ $firma['nombre'] ?: '—' }}</td>
                @if ($conRol)
                    <td>{{ $firma['rol'] ?: '—' }}</td>
                @endif
                <td>{{ $firma['documento'] ?: '—' }}</td>
                <td>{{ $firma['hora'] ?: '—' }}</td>
                <td>
                    {{ $firma['metodo'] }}
                    @if ($firma['pendiente'])
                        <br><span class="flag">{{ __('form_submissions.pdf.pending_review') }}</span>
                    @else
                        <br><span class="ok">{{ __('form_submissions.pdf.reviewed') }}</span>
                    @endif
                    @if ($firma['coincidencia'] !== null)
                        <br><span class="muted">{{ __('form_submissions.pdf.match', [
                            'value' => $firma['coincidencia'],
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
</div>
