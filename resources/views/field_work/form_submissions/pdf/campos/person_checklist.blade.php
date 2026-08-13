{{--
    EPP por trabajador (F3 de la v1), en el PDF.

    LA CUADRÍCULA DEL PAPEL, RECUPERADA
    -----------------------------------
    En papel el EPP es una cuadrícula: una fila por trabajador, una columna por
    ítem de protección. La v1 la reproducía tal cual (`show_pdf_page1.erb`) y
    para que entraran los 25 ítems hacía tres cosas: sacaba la hoja en
    HORIZONTAL, escribía el nombre de cada ítem **girado en vertical**
    (`writing-mode: vertical-rl`) a 6 px, y ponía en cada celda un símbolo —✔,
    x, - — con la leyenda al pie.

    Aquí se hacen dos de las tres. La hoja va en horizontal porque el formato lo
    dice —`form_templates.pdf_orientation`, ya no está cableado— y las celdas
    llevan su marca con la leyenda arriba. Lo único que no se copia es girar el
    rótulo: DomPDF no sabe (`writing-mode` y `transform` no existen ahí), así que
    las columnas van NUMERADAS y los números se explican en una leyenda encima,
    que es exactamente lo que ya hacía la inspección de herramientas — y así los
    dos formatos de cuadrícula se leen igual.

    Este parcial estuvo un tiempo pintando un bloque por trabajador con sus
    ítems en tres columnas, porque la hoja era vertical y no cabía otra cosa.
    Funcionaba, pero eran tres páginas para lo que en el papel es una tabla, y
    perdía la vista de conjunto: en una cuadrícula se ve de un vistazo qué
    columna falla en toda la cuadrilla, y en bloques hay que ir y volver.

    Los «No conforme» van en rojo Y con la marca, nunca solo en color, y el
    estado de cada trabajador sigue escrito con palabras al final de su fila:
    una fotocopia en blanco y negro tiene que seguir diciendo lo mismo.

    Todo lo que se pinta viene de `PersonChecklistPdf::datos()`: aquí no se
    decide qué respuesta es mala ni cómo se llama nadie. Llega sin `person_id`,
    sin `legacy_plan_worker_id`, sin `item_id` y con las respuestas ya traducidas
    al idioma del documento.
--}}
@php
    $datos = $campo['datos'] ?? [];
    $trabajadores = $datos['trabajadores'] ?? [];
    $items = $datos['items'] ?? [];
    $grupos = $datos['grupos'] ?? [];

    /* La marca de cada celda y su color. `sin` —nadie respondió ese ítem— cae al
       interrogante, que es lo que hay que distinguir de un «no aplica»: uno es
       una decisión de quien inspeccionó, el otro es que nadie miró. */
    $simbolo = fn (string $tono) => \App\Services\FieldWork\Pdf\Simbolos::de($tono);
    $marca = ['ok' => 'ok', 'bad' => 'bad', 'na' => 'na'];

    /* La palabra del estado, al final de la fila. */
    $palabra = [
        'ok'   => __('form_submissions.pdf.person_checklist.all_ok'),
        'bad'  => __('form_submissions.pdf.person_checklist.status_bad'),
        'warn' => __('form_submissions.pdf.person_checklist.status_pending'),
    ];
@endphp

<style>
    /* Todo prefijado `pc-`: lo compartido (.data, .sym, .block__sub, .muted)
       vive en template.blade.php y esto no lo pisa. Donde sí hay que corregir
       una regla de `.data` se repite el selector entero, porque una clase suelta
       pierde por especificidad y el estilo se queda escrito sin efecto. */
    .pc-summary { font-size: 8.5pt; color: #46596B; margin: 0 0 6px 0; }

    .pc-legend { width: 100%; border-collapse: collapse; margin: 0 0 6px 0; }
    .pc-legend td { padding: 2px 6px; border: 1px solid #C9D3DC; font-size: 7pt; vertical-align: top; }
    .pc-legend__n { font-weight: bold; color: #1F3B57; }

    /* La cuadrícula aprieta: con veinticinco ítems son veintinueve columnas
       incluso en horizontal. De ahí el cuerpo a 7pt. Las cabeceras van
       centradas como en todo el documento; aquí sólo se aprieta el relleno, que
       con veinticinco columnas es lo que decide si entran o no. */
    table.data.pc-matrix thead th { padding: 3px 2px; }
    table.data.pc-matrix thead th.pc-h { padding: 4px 6px; }
    table.data.pc-matrix tbody td { font-size: 7pt; vertical-align: middle; }
    table.data.pc-matrix tbody td.pc-cell { text-align: center; padding: 3px 2px; }

    /* La fila de grupos: mismo azul que la barra de bloque, para que se lea
       como un piso por encima de los números y no como otra fila de columnas.
       Las divisorias verticales son lo que hace visible dónde acaba un grupo y
       empieza el siguiente, así que se marcan a los lados y no abajo. */
    table.data.pc-matrix thead th.pc-group {
        background: #1F3B57; color: #ffffff; font-size: 7pt; font-weight: bold;
        text-transform: uppercase; letter-spacing: 0.04em; padding: 3px 2px;
        border: 1px solid #ffffff; border-bottom: none;
    }

    .pc-num    { width: 16px; text-align: center; color: #63748A; }
    .pc-worker { font-size: 7.5pt; }
    .pc-doc    { color: #63748A; }
    .pc-state  { text-align: center; }

    /* Los tres tonos del estado. El bueno va en negro: si se pinta todo, no
       resalta nada. */
    .pc-bad  { color: #9E2A22; font-weight: bold; }
    .pc-warn { color: #B45309; font-weight: bold; }

    /* La corrección de un trabajador, pegada debajo de él. El fondo la separa
       de las filas de trabajador; no es zebreado, es otra cosa. */
    table.data.pc-matrix tbody tr.pc-fix td { background: #EEF2F6; font-size: 7pt; }
    .pc-fix__k { font-weight: bold; color: #46596B; }
</style>

<h3 class="block__sub">{{ $campo['etiqueta'] }}</h3>

@if (empty($trabajadores))
    <p class="muted">—</p>
@else
    {{-- El titular. Va en rojo en cuanto hay una sola no conformidad, y la frase
         la nombra: quien abre el documento tiene que saber en la primera línea
         si esta inspección salió limpia o no. --}}
    <p class="pc-summary {{ ($datos['no_conformes'] ?? 0) > 0 ? 'pc-bad' : '' }}">
        {{ __('form_submissions.pdf.person_checklist.summary', [
            'people' => count($trabajadores),
            'issues' => $datos['no_conformes'] ?? 0,
        ]) }}
    </p>

    {{-- Qué ítem es cada número. Sin esto la cabecera de la cuadrícula es una
         fila de cifras que no significan nada. Tres por fila: los rótulos del
         EPP son cortos («Casco», «Lentes») y con dos sobraba media hoja. --}}
    @if (!empty($items))
        <table class="pc-legend">
            @foreach (array_chunk($items, 3) as $trio)
                <tr>
                    @foreach ($trio as $item)
                        <td><span class="pc-legend__n">{{ $item['numero'] }}.</span> {{ $item['texto'] }}</td>
                    @endforeach
                    {{-- Los que falten para completar la fila, para que la tabla
                         no se descuadre. --}}
                    @for ($i = count($trio); $i < 3; $i++)<td></td>@endfor
                </tr>
            @endforeach
        </table>
    @endif

    {{-- Y qué significa cada marca. Encima de la tabla y no al pie: al pie hay
         que haber leído ya la cuadrícula entera para enterarse de qué se estaba
         leyendo. --}}
    @include('field_work.form_submissions.pdf.leyenda', ['leyenda' => $datos['leyenda']])

    <table class="data pc-matrix">
        <thead>
            {{-- Los grupos, encima de sus columnas: cabeza, cara, cuerpo, ojos,
                 manos, oídos, vías respiratorias, pies. Es lo que convierte una
                 fila de veinticinco números en algo que se recorre — se busca
                 «manos» y se miran esas cinco, en vez de leer las veinticinco.

                 Sólo si el formato los declara: los demás checklists no tienen
                 grupos y `PersonChecklistPdf` devuelve la lista vacía. --}}
            @if (!empty($grupos))
                <tr>
                    <th class="pc-num"></th>
                    <th class="pc-h"></th>
                    @foreach ($grupos as $grupo)
                        <th class="pc-group" colspan="{{ $grupo['columnas'] }}">{{ $grupo['nombre'] }}</th>
                    @endforeach
                    <th></th>
                </tr>
            @endif
            <tr>
                <th class="pc-num">{{ __('form_submissions.pdf.person_checklist.number') }}</th>
                <th class="pc-h">{{ __('form_submissions.pdf.person_checklist.worker') }}</th>
                @foreach ($items as $item)
                    <th>{{ $item['numero'] }}</th>
                @endforeach
                <th>{{ __('form_submissions.pdf.person_checklist.status') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($trabajadores as $trabajador)
                <tr>
                    <td class="pc-num">{{ $trabajador['numero'] }}</td>
                    <td class="pc-worker">
                        {{ $trabajador['nombre'] }}
                        @if (filled($trabajador['documento']))
                            <span class="pc-doc">· {{ $trabajador['documento'] }}</span>
                        @endif
                    </td>

                    @foreach ($trabajador['celdas'] as $celda)
                        <td class="pc-cell">
                            <span class="sym sym--{{ $marca[$celda['tono']] ?? 'sin' }}">{{ $simbolo($celda['tono']) }}</span>
                        </td>
                    @endforeach

                    <td class="pc-state pc-{{ $trabajador['estado'] }}">{{ $palabra[$trabajador['estado']] }}</td>
                </tr>

                {{-- Las tres columnas finales del papel, más las respuestas que
                     se quedaron sin ítem. Sólo salen cuando dicen algo: en la v1
                     iban siempre y estaban vacías en casi todas las filas, que
                     es como se pierden de vista justo cuando las hay. --}}
                @if (!empty($trabajador['correccion']) || !empty($trabajador['huerfanas']))
                    <tr class="pc-fix">
                        <td></td>
                        <td colspan="{{ count($items) + 2 }}">
                            @foreach ($trabajador['correccion'] as $dato)
                                <span class="pc-fix__k">{{ $dato['etiqueta'] }}:</span> {{ $dato['valor'] }}
                                @if (!$loop->last || !empty($trabajador['huerfanas']))<span class="muted"> · </span>@endif
                            @endforeach

                            @if (!empty($trabajador['huerfanas']))
                                <span class="pc-fix__k">{{ __('form_submissions.pdf.person_checklist.unknown_item') }}:</span>
                                @foreach ($trabajador['huerfanas'] as $huerfana)
                                    <span class="{{ $huerfana['tono'] === 'bad' ? 'pc-bad' : '' }}">{{ $huerfana['respuesta'] }}</span>@if (!$loop->last), @endif
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
@endif
