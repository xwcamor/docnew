{{--
    IHM — Inspección de Herramientas Manuales y Eléctricas Portátiles (F4).

    La matriz del papel de la v1: una fila por herramienta, una columna por punto
    de inspección y, al final, en qué quedó. Lo que en `show_pdf_page1.erb` era
    la abreviatura girada (`ihm_items.short_name`) aquí es el número de columna,
    explicado en la leyenda de arriba — DomPDF no gira texto y las abreviaturas
    ya no existen en la plantilla.

    Todo lo que se pinta viene resuelto de `ToolChecklistPdf::datos()`: aquí no
    se decide qué respuesta es mala ni qué corrección falta, sólo cómo se lee.

    Dos cosas del papel de la v1 que NO se copian, a propósito:

      · las veinte filas en blanco que rellenaban la hoja para escribir a mano
        (`20.times do` en la vista de allá). Este documento se firma con la cara
        y se archiva; una hoja con huecos invita a añadir a bolígrafo lo que
        nadie firmó.
      · el zebreado. Se está quitando del PDF entero, y como esta tabla reusa
        `.data` hay que apagarlo aquí con la misma especificidad — si no, la
        regla `table.data tbody tr:nth-child(even)` gana.

    Lo que SÍ se recuperó del papel es la marca por celda con su leyenda: la
    palabra entera («Cumple») gastaba en cada columna el ancho de la más larga,
    y son diez o veinte columnas. Los símbolos y la regla de qué símbolo lleva
    cada respuesta están en `App\Services\FieldWork\Pdf\Simbolos`, compartidos
    con el EPP, que es la otra cuadrícula.
--}}
@php
    $datos  = $campo['datos'] ?? [];
    $puntos = $datos['puntos'] ?? [];
    $filas  = $datos['filas'] ?? [];

    /* La palabra que acompaña al color. Nunca el color solo: en una fotocopia
       en blanco y negro, o para quien no distingue el rojo, un «no conforme»
       que sólo se pinta no existe (docs/UI.md §5). */
    $palabra = [
        'ok'   => __('form_submissions.pdf.tool_checklist.conforming'),
        'bad'  => __('form_submissions.pdf.tool_checklist.nonconforming'),
        'warn' => __('form_submissions.pdf.tool_checklist.incomplete'),
    ];

    /* La marca de cada celda y su color. `vacio` —nadie respondió ese punto—
       cae al interrogante por el `default` de `Simbolos::de()`, que es justo lo
       que hay que distinguir de un «no aplica»: uno es una decisión de quien
       inspeccionó y el otro es que nadie miró. */
    $simbolo = fn (string $estado) => \App\Services\FieldWork\Pdf\Simbolos::de($estado);
    $marca = ['ok' => 'ok', 'bad' => 'bad', 'na' => 'na'];
@endphp

<style>
    /* Las reglas de `.data` en template.blade.php van con el selector entero
       (`table.data tbody td`), así que aquí se repite la cadena en todo lo que
       las corrige: una clase suelta pierde por especificidad y el estilo se
       queda escrito sin efecto, que es peor que no ponerlo. */

    /* Apaga el zebreado heredado de `.data` (ver la nota de arriba). */
    table.data.tc-matrix tbody tr:nth-child(even) td { background: transparent; }

    .tc-legend { width: 100%; border-collapse: collapse; margin: 0 0 6px 0; }
    .tc-legend td { padding: 2px 6px; border: 1px solid #C9D3DC; font-size: 7.5pt; vertical-align: top; }
    .tc-legend__n { font-weight: bold; color: #1F3B57; }

    /* La matriz aprieta: con diez puntos de inspección hay dieciséis columnas
       en A4 vertical. De ahí el cuerpo a 7pt y los rótulos alineados al centro. */
    table.data.tc-matrix thead th { text-align: center; }
    table.data.tc-matrix thead th.tc-h { text-align: left; }
    table.data.tc-matrix tbody td { font-size: 7pt; vertical-align: middle; }
    table.data.tc-matrix tbody td.tc-tool { font-size: 7.5pt; }

    .tc-num   { width: 16px; text-align: center; }
    .tc-cell  { text-align: center; }
    .tc-state { text-align: center; }

    /* Los tres tonos de la columna «Estado», que sigue llevando la PALABRA:
       ahí sí cabe, y es lo que se busca al recorrer la tabla con el dedo. El
       bueno va en negro: si se pinta todo, no resalta nada. */
    .tc-bad  { color: #9E2A22; font-weight: bold; }
    .tc-na   { color: #63748A; }
    .tc-warn { color: #B45309; font-weight: bold; }

    /* La celda de la cuadrícula: sólo la marca, centrada y sin apreturas. */
    table.data.tc-matrix tbody td.tc-cell { padding: 3px 2px; }

    /* La corrección de una herramienta, pegada debajo de ella. El fondo la
       separa de las herramientas; no es zebreado, es otra cosa. */
    table.data.tc-matrix tbody tr.tc-fix td { background: #EEF2F6; font-size: 7pt; }
    .tc-fix__k { font-weight: bold; color: #46596B; }
    .tc-foot { font-size: 7pt; color: #63748A; margin: 0 0 8px 0; }
</style>

<h3 class="block__sub">{{ $campo['etiqueta'] }}</h3>

@if (empty($filas))
    <p class="muted">—</p>
@else
    {{-- Leyenda de los puntos, dos por fila como en la v1 (`each_slice(2)`).
         Sin ella los números de la cabecera no significan nada. --}}
    @if (!empty($puntos))
        <p class="tc-foot"><strong>{{ __('form_submissions.pdf.tool_checklist.points') }}</strong></p>
        <table class="tc-legend">
            @foreach (array_chunk($puntos, 2) as $pareja)
                <tr>
                    @foreach ($pareja as $punto)
                        <td>
                            <span class="tc-legend__n">{{ $punto['numero'] }}.</span>
                            @if (filled($punto['texto']))
                                {{ $punto['texto'] }}
                            @else
                                <span class="muted">{{ __('form_submissions.pdf.tool_checklist.unnamed_point') }}</span>
                            @endif
                        </td>
                    @endforeach
                    {{-- Impar: la celda que falta, para que la tabla no se descuadre. --}}
                    @if (count($pareja) < 2)<td></td>@endif
                </tr>
            @endforeach
        </table>
    @endif

    {{-- Y qué significa cada marca. Encima de la tabla y no al pie: al pie hay
         que haber leído ya la cuadrícula entera para enterarse de qué se estaba
         leyendo. --}}
    @include('field_work.form_submissions.pdf.leyenda', ['leyenda' => $datos['leyenda']])

    <table class="data tc-matrix">
        <thead>
            <tr>
                <th class="tc-num">{{ __('form_submissions.pdf.tool_checklist.number') }}</th>
                <th class="tc-h">{{ __('form_submissions.pdf.tool_checklist.tool') }}</th>
                @foreach ($puntos as $punto)
                    <th>{{ $punto['numero'] }}</th>
                @endforeach
                <th>{{ __('form_submissions.pdf.tool_checklist.status') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($filas as $fila)
                <tr>
                    <td class="tc-num">{{ $fila['numero'] }}</td>
                    <td class="tc-tool">
                        @if (filled($fila['nombre']))
                            {{ $fila['nombre'] }}
                        @else
                            <span class="muted">{{ __('form_submissions.pdf.tool_checklist.unnamed_tool') }}</span>
                        @endif
                        {{-- Sólo si vienen: el nombre se escribe a mano y casi
                             nunca traen código ni cantidad. --}}
                        @if (filled($fila['codigo']))
                            <span class="muted">· {{ __('form_submissions.pdf.tool_checklist.code') }} {{ $fila['codigo'] }}</span>
                        @endif
                        @if (filled($fila['cantidad']))
                            <span class="muted">· {{ __('form_submissions.pdf.tool_checklist.quantity') }} {{ $fila['cantidad'] }}</span>
                        @endif
                    </td>

                    @foreach ($fila['celdas'] as $celda)
                        @if ($celda['estado'] === 'ausente')
                            {{-- Ese punto no estaba en la lista de esta herramienta:
                                 no es lo mismo que no haberlo respondido, y por eso
                                 la celda queda en blanco y no lleva interrogante. --}}
                            <td class="tc-cell"></td>
                        @else
                            {{-- El `title` no se ve en el PDF, pero el texto sigue
                                 llegando aquí por si alguna vez esta tabla se pinta
                                 en pantalla: la marca no sustituye al dato. --}}
                            <td class="tc-cell">
                                <span class="sym sym--{{ $marca[$celda['estado']] ?? 'sin' }}">{{ $simbolo($celda['estado']) }}</span>
                            </td>
                        @endif
                    @endforeach

                    <td class="tc-state tc-{{ $fila['estado'] }}">{{ $palabra[$fila['estado']] }}</td>
                </tr>

                @if (!empty($fila['correcciones']))
                    <tr class="tc-fix">
                        <td></td>
                        <td colspan="{{ count($puntos) + 2 }}">
                            @foreach ($fila['correcciones'] as $correccion)
                                <span class="tc-fix__k">{{ $correccion['etiqueta'] }}:</span>
                                @if (filled($correccion['valor']))
                                    {{ $correccion['valor'] }}
                                @else
                                    {{-- El hueco se enseña. En una herramienta no
                                         conforme, lo que nadie escribió es el dato. --}}
                                    <span class="muted">{{ __('form_submissions.pdf.tool_checklist.not_recorded') }}</span>
                                @endif
                                @if (!$loop->last)<span class="muted"> · </span>@endif
                            @endforeach
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>


@endif
