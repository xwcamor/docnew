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
@endphp

<style>
    /* Las reglas de `.data` en template.blade.php van con el selector entero
       (`table.data tbody td`), así que aquí se repite la cadena en todo lo que
       las corrige: una clase suelta pierde por especificidad y el estilo se
       queda escrito sin efecto, que es peor que no ponerlo. */

    /* Apaga el zebreado heredado de `.data` (ver la nota de arriba). */
    table.data.tc-matrix tbody tr:nth-child(even) td { background: transparent; }

    .tc-legend { width: 100%; border-collapse: collapse; margin: 0 0 6px 0; }
    .tc-legend td { padding: 2px 6px; border: 1px solid #E5E5E5; font-size: 7.5pt; vertical-align: top; }
    .tc-legend__n { font-weight: bold; color: #354A5F; }

    /* La matriz aprieta: con diez puntos de inspección hay dieciséis columnas
       en A4 vertical. De ahí el cuerpo a 7pt y los rótulos alineados al centro. */
    table.data.tc-matrix thead th { text-align: center; }
    table.data.tc-matrix thead th.tc-h { text-align: left; }
    table.data.tc-matrix tbody td { font-size: 7pt; vertical-align: middle; }
    table.data.tc-matrix tbody td.tc-tool { font-size: 7.5pt; }

    .tc-num   { width: 16px; text-align: center; }
    .tc-cell  { text-align: center; }
    .tc-state { text-align: center; }

    /* Los tres tonos. El bueno va en negro: si se pinta todo, no resalta nada. */
    .tc-bad  { color: #C8281D; font-weight: bold; }
    .tc-na   { color: #6A6D70; }
    .tc-warn { color: #B45309; font-weight: bold; }

    /* La corrección de una herramienta, pegada debajo de ella. El fondo la
       separa de las herramientas; no es zebreado, es otra cosa. */
    table.data.tc-matrix tbody tr.tc-fix td { background: #F1F5F9; font-size: 7pt; }
    .tc-fix__k { font-weight: bold; color: #475569; }
    .tc-foot { font-size: 7pt; color: #6A6D70; margin: 0 0 8px 0; }
</style>

<h3 class="block__sub">
    {{ $campo['etiqueta'] }}@if (!empty($campo['requerido']))<span class="req"> *</span>@endif
</h3>

@if (empty($filas))
    <p class="muted">{{ __('form_submissions.pdf.no_answer') }}</p>
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
                                 no es lo mismo que no haberlo respondido. --}}
                            <td class="tc-cell"></td>
                        @elseif ($celda['estado'] === 'vacio')
                            <td class="tc-cell muted">—</td>
                        @else
                            <td class="tc-cell tc-{{ $celda['estado'] }}">{{ $celda['texto'] }}</td>
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

    @if (!empty($datos['hay_sin_responder']))
        <p class="tc-foot">— {{ __('form_submissions.pdf.tool_checklist.unanswered') }}</p>
    @endif
@endif
