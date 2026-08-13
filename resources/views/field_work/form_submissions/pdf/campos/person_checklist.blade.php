{{--
    EPP por trabajador (F3 de la v1), en el PDF.

    POR QUÉ NO ES LA CUADRÍCULA DEL PAPEL
    -------------------------------------
    En papel el EPP es una cuadrícula: una fila por trabajador, una columna por
    item de protección. La v1 la reproducía tal cual (`show_pdf_page1.erb`) y
    para que entraran los 25 items hacía tres cosas: sacaba la página en
    HORIZONTAL, escribía el nombre de cada item **girado en vertical**
    (`writing-mode: vertical-rl`) a 6 px, y ponía en cada celda un símbolo —✔,
    x, - — con la leyenda al pie.

    Aquí no se puede y tampoco conviene:

      · La hoja es A4 VERTICAL y la fija el servicio para todo el documento
        (`setPaper('a4', 'portrait')`): este campo es una sección más de un PDF
        que empieza con el membrete y acaba con las firmas. Girar la hoja por un
        campo partiría el documento en dos.
      · DomPDF no sabe girar texto: no hay `writing-mode` ni `transform`. Sin
        eso, 25 columnas en 539 pt son 21 pt por columna para rótulos como
        «Resp. con filtro para humos». Sale ilegible o no sale.
      · Y aunque saliera: la cuadrícula de la v1 obliga a leer un símbolo y
        bajar a la leyenda para saber qué significa. Cuando lo que se busca al
        abrir el documento es «qué salió mal y a quién», eso es trabajo de más.

    LO QUE SE HACE EN SU LUGAR
    --------------------------
    Un bloque por trabajador con sus items repartidos en tres columnas, y antes
    un índice de una línea por persona con su estado. El índice es lo que
    devuelve la vista de conjunto que se pierde al dejar la cuadrícula — es la
    misma solución que tomó la pantalla de llenado con `RowNavigator.vue`, por
    el mismo motivo, y así el papel y la tablet se leen igual.

    Los «No conforme» van en rojo Y con la palabra escrita, nunca solo en color:
    este documento se imprime en blanco y negro y se fotocopia. Nada de zebra en
    las filas (se está quitando de todo el PDF): el color aquí significa una
    cosa y solo una.

    Recibe `$campo['datos']` ya resuelto por `PersonChecklistPdf::datos()`: sin
    `person_id`, sin `legacy_plan_worker_id`, sin `item_id` y con las respuestas
    ya traducidas al idioma del documento.
--}}
@php
    $datos = $campo['datos'] ?? [];
    $trabajadores = $datos['trabajadores'] ?? [];
    $columnas = 3;
@endphp

<style>
    /* Todo prefijado `pc-`: lo compartido (.data, .block__sub, .muted, .req)
       vive en template.blade.php y esto no lo pisa. */
    .pc-index { width: 100%; border-collapse: collapse; margin: 0 0 10px 0; }
    .pc-index th { background: #354A5F; color: #ffffff; font-weight: bold; font-size: 8pt;
                   text-align: left; padding: 4px 6px; border: 1px solid #2B3B4C; }
    .pc-index td { padding: 3px 6px; border: 1px solid #E5E5E5; font-size: 8pt; }
    .pc-index td.pc-num { width: 22px; text-align: right; color: #6A6D70; }
    .pc-index td.pc-state { width: 34%; }

    /* Un trabajador entero, sin partirse entre dos páginas si cabe. */
    .pc-worker { page-break-inside: avoid; margin: 0 0 10px 0; }
    .pc-worker__head { background: #F1F5F9; border: 1px solid #E5E5E5; border-bottom: none;
                       padding: 4px 6px; font-size: 8.5pt; }
    .pc-worker__name { font-weight: bold; color: #354A5F; }
    .pc-worker__doc { color: #6A6D70; font-size: 7.5pt; }

    .pc-items { width: 100%; border-collapse: collapse; }
    .pc-items td { border: 1px solid #E5E5E5; font-size: 7.5pt; padding: 2px 5px;
                   vertical-align: top; }
    .pc-items td.pc-item { width: 22%; }
    .pc-items td.pc-answer { width: 11.33%; }

    /* Color Y palabra: el rojo se apoya en el texto, no al revés. */
    .pc-bad { color: #C8281D; font-weight: bold; }
    .pc-na  { color: #6A6D70; }

    .pc-correction { width: 100%; border-collapse: collapse; margin: 0 0 2px 0; }
    .pc-correction td { border: 1px solid #E5E5E5; font-size: 7.5pt; padding: 3px 6px;
                        vertical-align: top; }
    .pc-correction td.pc-correction__k { width: 22%; background: #FBFCFD; font-weight: bold;
                                         color: #475569; }
</style>

<h3 class="block__sub">
    {{ $campo['etiqueta'] }}@if ($campo['requerido'])<span class="req"> *</span>@endif
</h3>

@if (empty($trabajadores))
    <p class="muted">{{ __('form_submissions.pdf.no_answer') }}</p>
@else
    <p class="muted" style="margin: 0 0 6px 0;">
        {{ __('form_submissions.pdf.person_checklist.summary', [
            'people' => count($trabajadores),
            'issues' => $datos['no_conformes'] ?? 0,
        ]) }}
    </p>

    {{-- Índice. Con un solo trabajador sobra: su bloque ya lo dice todo, igual
         que en la pantalla de llenado. --}}
    @if (count($trabajadores) > 1)
        <table class="pc-index">
            <thead>
                <tr>
                    <th colspan="2">{{ __('form_submissions.pdf.person_checklist.worker') }}</th>
                    <th>{{ __('form_submissions.pdf.person_checklist.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($trabajadores as $trabajador)
                    <tr>
                        <td class="pc-num">{{ $trabajador['numero'] }}</td>
                        <td>{{ $trabajador['nombre'] }}</td>
                        <td class="pc-state">
                            @if ($trabajador['no_conformes'] > 0)
                                <span class="pc-bad">{{ trans_choice(
                                    'form_submissions.pdf.person_checklist.issues',
                                    $trabajador['no_conformes'],
                                    ['count' => $trabajador['no_conformes']],
                                ) }}</span>
                            @elseif ($trabajador['sin_responder'] > 0)
                                <span class="muted">{{ trans_choice(
                                    'form_submissions.pdf.person_checklist.pending',
                                    $trabajador['sin_responder'],
                                    ['count' => $trabajador['sin_responder']],
                                ) }}</span>
                            @else
                                <span class="ok">{{ __('form_submissions.pdf.person_checklist.all_ok') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @foreach ($trabajadores as $trabajador)
        @php
            /*
             * Reparto en columnas por VERTICALES, no por filas: el catálogo va
             * agrupado de hecho —cabeza, cuerpo, manos, oídos, vías
             * respiratorias, pies— y leyendo hacia abajo esos grupos se
             * mantienen juntos, como en el papel. Con `array_chunk` a secas se
             * intercalarían.
             */
            $lineas = $trabajador['items'];
            $alto = (int) ceil(count($lineas) / $columnas);
            $verticales = $alto > 0 ? array_chunk($lineas, $alto) : [];
        @endphp

        <div class="pc-worker">
            <div class="pc-worker__head">
                <span class="pc-worker__name">{{ $trabajador['numero'] }}. {{ $trabajador['nombre'] }}</span>
                @if (filled($trabajador['documento']))
                    <span class="pc-worker__doc"> · {{ $trabajador['documento'] }}</span>
                @endif
                @if ($trabajador['no_conformes'] > 0)
                    <span class="pc-bad"> · {{ trans_choice(
                        'form_submissions.pdf.person_checklist.issues',
                        $trabajador['no_conformes'],
                        ['count' => $trabajador['no_conformes']],
                    ) }}</span>
                @elseif ($trabajador['sin_responder'] > 0)
                    <span class="muted"> · {{ trans_choice(
                        'form_submissions.pdf.person_checklist.pending',
                        $trabajador['sin_responder'],
                        ['count' => $trabajador['sin_responder']],
                    ) }}</span>
                @else
                    <span class="ok"> · {{ __('form_submissions.pdf.person_checklist.all_ok') }}</span>
                @endif
            </div>

            @if (empty($lineas))
                <p class="muted">{{ __('form_submissions.pdf.no_answer') }}</p>
            @else
                <table class="pc-items">
                    @for ($f = 0; $f < $alto; $f++)
                        <tr>
                            @for ($c = 0; $c < $columnas; $c++)
                                @php $linea = $verticales[$c][$f] ?? null; @endphp

                                @if ($linea === null)
                                    {{-- Los 25 items no reparten exactos en tres columnas: el
                                         hueco se pinta vacío para que la rejilla no se descuadre. --}}
                                    <td class="pc-item">&nbsp;</td>
                                    <td class="pc-answer">&nbsp;</td>
                                @else
                                    <td class="pc-item">{{ $linea['item'] }}</td>
                                    <td class="pc-answer">
                                        @if ($linea['respuesta'] === null)
                                            {{-- Sin respuesta: en gris y con la raya. No se pinta
                                                 como «No aplica», que es una decisión de quien
                                                 inspecciona y no un item que nadie miró. --}}
                                            <span class="muted">—</span>
                                        @elseif ($linea['tono'] === \App\Services\FieldWork\FormFindingsService::MALA)
                                            <span class="pc-bad">{{ $linea['respuesta'] }}</span>
                                        @elseif ($linea['tono'] === \App\Services\FieldWork\FormFindingsService::NO_APLICA)
                                            <span class="pc-na">{{ $linea['respuesta'] }}</span>
                                        @else
                                            {{ $linea['respuesta'] }}
                                        @endif
                                    </td>
                                @endif
                            @endfor
                        </tr>
                    @endfor
                </table>
            @endif

            {{-- Las tres columnas finales del papel. Solo salen cuando dicen
                 algo: en la v1 iban siempre y estaban vacías en casi todas las
                 filas, que es como se pierden de vista justo cuando las hay. --}}
            @if (! empty($trabajador['correccion']))
                <table class="pc-correction">
                    @foreach ($trabajador['correccion'] as $dato)
                        <tr>
                            <td class="pc-correction__k">{{ $dato['etiqueta'] }}</td>
                            <td>{{ $dato['valor'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    @endforeach
@endif
