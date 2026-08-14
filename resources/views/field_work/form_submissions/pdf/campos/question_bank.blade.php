{{--
    El banco de preguntas del PTF — «Pare, Tome 5».

    Lo que este parcial arregla respecto del volcado generico: alli salian tres
    columnas —«Pregunta id | Question | Answer»— con el identificador interno
    delante y dos cabeceras en ingles dentro de un documento en castellano, y
    las diecisiete respuestas en gris, todas iguales.

    Aqui la tabla se parece a la de la v1 (`f2_documents/show_pdf_page1.erb`:
    numero, enunciado y la marca de la respuesta) y le añade lo unico que aquel
    PDF no decia en ningun sitio: **cuantos «No» hay y en que preguntas**. En el
    PTF un «No» es una observacion —alguien contesto que no a una pregunta de
    seguridad antes de empezar— y ese es el motivo por el que este papel se
    guarda. Tenia que verse antes de leer las diecisiete filas.

    El color nunca va solo (docs/UI.md §5): la respuesta observada lleva la
    palabra al lado, porque este documento se imprime en blanco y negro mas
    veces de las que se mira en pantalla, y en gris el rojo no existe.

    Todo lo que se pinta ya viene resuelto en `$campo['datos']`
    (App\Services\FieldWork\Pdf\QuestionBankPdf); aqui no se decide nada.
--}}
@php
    // Con los valores por defecto delante: un PDF que revienta al pintar es
    // peor que uno que dice «sin respuesta», y este documento se genera
    // tambien en lote, cuando se descarga el expediente entero de un plan.
    $qb = ($campo['datos'] ?? []) + [
        'preguntas' => [], 'grupos' => [], 'total' => 0, 'respondidas' => 0, 'sin_responder' => 0,
        'observaciones' => 0, 'observadas' => [], 'hay_fuera_de_catalogo' => false,
    ];

    // Sin grupos declarados llega un unico grupo anonimo con todo dentro, y la
    // tabla sale exactamente como salia la lista plana.
    $grupos = $qb['grupos'] ?: [['titulo' => null, 'image' => null, 'indices' => array_keys($qb['preguntas'])]];

    // El tono lo decide el servicio con la misma regla que cuenta las
    // observaciones de la ficha del plan; aqui solo se traduce a clase.
    $clases = ['bad' => 'qb-bad', 'na' => 'qb-na', 'ok' => 'qb-ok'];
@endphp

<style>
    /* Prefijo qb- para no tocar nada de lo compartido: esto convive en la
       misma pagina con la matriz de riesgo y con la tabla de firmas. */
    .qb-table tr { page-break-inside: avoid; }
    .qb-table td { vertical-align: top; }
    .qb-n { width: 22px; text-align: right; color: #6A6D70; }
    .qb-a { width: 108px; }

    /* La respuesta, con su palabra. El verde y el gris se quedan en el texto;
       el rojo se refuerza con el filete de la izquierda para que la fila se
       encuentre pasando el pulgar por el margen, que es como se busca en una
       hoja de diecisiete preguntas. */
    .qb-ok  { color: #1D7044; font-weight: bold; }
    .qb-bad { color: #C8281D; font-weight: bold; }
    .qb-na  { color: #6A6D70; }
    .qb-obs td.qb-n { border-left: 3px solid #C8281D; color: #C8281D; font-weight: bold; }

    /* Resumen de cabecera: la linea que se lee antes que la tabla. */
    .qb-head { width: 100%; border-collapse: collapse; margin: 0 0 6px 0; }
    .qb-head td { font-size: 8pt; padding: 0; vertical-align: middle; }
    .qb-head td.r { text-align: right; }
    .qb-notice { color: #C8281D; font-weight: bold; font-size: 8pt; margin: 0 0 6px 0; }
    .qb-note { font-size: 7.5pt; margin: 2px 0 0 0; }

    /* El bloque, como el papel de la v1: el icono a la izquierda y el titulo en
       negrita, en una fila que cruza la tabla. El icono es un data URI de la
       config del formato — viaja congelado con cada version. */
    .qb-group td { background: #EEF2F6; font-weight: bold; padding: 4px 6px; }
    .qb-group img { width: 18px; height: 18px; vertical-align: middle; margin-right: 6px; }
</style>

<h3 class="block__sub">{{ $campo['etiqueta'] }}</h3>

@if ($qb['respondidas'] === 0)
    {{-- Ni una contestada: no se pinta la rejilla de diecisiete filas vacias,
         que ocupa media pagina para decir lo mismo que una linea. --}}
    <p class="muted">—</p>
@else
    <table class="qb-head">
        <tr>
            <td>
                {{ __('form_submissions.pdf.question_bank.answered', [
                    'done'  => $qb['respondidas'],
                    'total' => $qb['total'],
                ]) }}
                @if ($qb['sin_responder'])
                    · {{ __('form_submissions.pdf.question_bank.unanswered', ['count' => $qb['sin_responder']]) }}
                @endif
            </td>
            {{-- La cuenta va con su palabra y con los numeros de fila: «2
                 observaciones — preguntas 7, 12» lleva al sitio sin repetir dos
                 enunciados de dos lineas cada uno. --}}
            <td class="r">
                @if ($qb['observaciones'])
                    <span class="qb-bad">{{ trans_choice(
                        'form_submissions.pdf.question_bank.observations',
                        $qb['observaciones'],
                        ['list' => implode(', ', $qb['observadas'])],
                    ) }}</span>
                @else
                    <span class="ok">{{ __('form_submissions.pdf.question_bank.no_observations') }}</span>
                @endif
            </td>
        </tr>
    </table>

    {{-- El aviso solo cuando hay algo que hacer. La v1 lo imprimia siempre, en
         rojo y al pie de la pagina, tambien en el PTF que salio limpio; un
         aviso que aparece siempre deja de leerse, y se lleva por delante la
         atencion del dia que si hay un «No». --}}
    @if ($qb['observaciones'])
        <p class="qb-notice">{{ __('form_submissions.pdf.question_bank.notice') }}</p>
    @endif

    <table class="data qb-table">
        <thead>
            <tr>
                <th class="qb-n">{{ __('form_submissions.pdf.question_bank.number') }}</th>
                <th>{{ __('form_submissions.pdf.question_bank.question') }}</th>
                <th class="qb-a">{{ __('form_submissions.pdf.question_bank.answer') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($grupos as $grupo)
                @if (filled($grupo['titulo']) || filled($grupo['image']))
                    <tr class="qb-group">
                        <td colspan="3">
                            @if ($grupo['image'])<img src="{{ $grupo['image'] }}" alt="">@endif{{ $grupo['titulo'] }}
                        </td>
                    </tr>
                @endif
            @foreach ($grupo['indices'] as $indiceDePregunta)
                @php $pregunta = $qb['preguntas'][$indiceDePregunta] ?? null; @endphp
                @continue($pregunta === null)
                <tr class="{{ $pregunta['observacion'] ? 'qb-obs' : '' }}">
                    <td class="qb-n">{{ $pregunta['numero'] }}</td>
                    <td>
                        @if (filled($pregunta['texto']))
                            {{ $pregunta['texto'] }}
                        @else
                            {{-- Se contesto algo cuyo enunciado no llego. La
                                 respuesta se imprime igual: es lo que dijo
                                 alguien, y borrarla es peor que no saber a
                                 que pregunta contestaba. --}}
                            <span class="muted">{{ __('form_submissions.pdf.question_bank.untitled') }}</span>
                        @endif
                        @if ($pregunta['fuera_de_catalogo'])<span class="muted"> †</span>@endif
                    </td>
                    <td class="qb-a">
                        @if ($pregunta['respuesta'] === null)
                            <span class="muted">—</span>
                        @else
                            <span class="{{ $clases[$pregunta['tono']] ?? 'qb-ok' }}">{{ $pregunta['respuesta'] }}</span>
                            @if ($pregunta['observacion'])
                                <br><span class="flag">{{ __('form_submissions.pdf.question_bank.observation') }}</span>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
            @endforeach
        </tbody>
    </table>

    @if ($qb['hay_fuera_de_catalogo'])
        <p class="muted qb-note">† {{ __('form_submissions.pdf.question_bank.outside_catalog') }}</p>
    @endif
@endif
