{{--
    La matriz de riesgo del AST y del PTF, como el AST de la v1.

    La v1 pinta una fila por ACTIVIDAD y mete todos sus peligros dentro de la
    misma celda, en `<ul>` paralelos: una lista de peligros, otra de riesgos,
    otra de controles, y hay que leerlas contando posiciones para saber qué
    control va con qué peligro (`show_pdf_page1.erb`). Con textos de largos
    distintos las listas se desalinean solas y la fila deja de decir la verdad.
    Encima obliga al truco del `each_slice(5)` del controlador, que parte una
    actividad de seis peligros en dos filas repetidas para que quepa.

    Aquí es un BLOQUE por actividad y una FILA por peligro: mismas columnas y
    mismo orden que la tabla vieja —Peligro, Riesgo, Control, Probabilidad,
    Severidad, Nivel, con las tres últimas agrupadas bajo «Evaluación», que es
    como está el papel—, pero cada peligro entero en su renglón. Se acabó el
    contar posiciones, y una actividad larga simplemente sigue en la página
    siguiente con su cabecera repetida.

    Se pinta con `$campo['datos']`, que ya viene resuelto de `RiskMatrixPdf`:
    aquí no se agrupa, no se traduce ninguna clave interna y no se calcula
    ningún nivel. Esto solo pinta.
--}}
@once
    <style>
        /* Anchos en porcentaje y no en cm: el AST sale apaisado (26 cm útiles)
           y el PTF vertical (17 cm), y es la misma tabla. El control se lleva
           la columna más ancha porque es la frase larga —«Señalizar el área y
           mantener personal fuera del radio de giro»—; probabilidad, severidad
           y nivel son palabras sueltas de catálogo. */
        .rm-tabla th.rm-c-peligro { width: 21%; }
        .rm-tabla th.rm-c-riesgo  { width: 21%; }
        .rm-tabla th.rm-c-control { width: 26%; }
        .rm-tabla th.rm-c-prob    { width: 11%; }
        .rm-tabla th.rm-c-sev     { width: 11%; }
        .rm-tabla th.rm-c-nivel   { width: 10%; }

        /* Centradas, como en todo el documento: el rotulo es el sombrero de la
           columna y no la primera fila de datos. Las tres de texto largo
           —peligro, riesgo, control— tambien, aunque su contenido vaya a la
           izquierda: mezclar las dos alineaciones en la misma cabecera es lo
           que hacia que la tabla se viera torcida. */

        /* Una fila partida entre dos páginas deja el peligro arriba y su
           control abajo, que es exactamente el error que este formato viene a
           arreglar. El bloque entero NO lleva `avoid`: una actividad de quince
           peligros es más alta que una página y DomPDF responde a eso dejando
           la página en blanco. La cabecera del `<thead>` se repite sola al
           cambiar de página, así que la tabla partida se sigue leyendo. */
        .rm-tabla tbody tr { page-break-inside: avoid; }
        .rm-tabla tbody td.rm-eval { text-align: center; }

        /* La cabecera de la actividad, pegada a su tabla: el nombre a la
           izquierda y el resumen a la derecha, maquetado con tabla porque en
           DomPDF no hay flex. */
        .rm-act { width: 100%; border-collapse: collapse; margin: 10px 0 3px 0; page-break-after: avoid; }
        .rm-act td { padding: 3px 6px; border: none; vertical-align: bottom; }
        .rm-act__num { font-size: 7pt; color: #6A6D70; text-transform: uppercase; letter-spacing: 0.05em; }
        .rm-act__nombre { font-size: 10pt; font-weight: bold; color: #354A5F; }
        .rm-act__resumen { text-align: right; font-size: 7.5pt; color: #6A6D70; }

        /* El nivel va con color Y PALABRA, nunca solo color (docs/UI.md §5):
           al sol de una obra el matiz se pierde, y hay quien no distingue el
           rojo del verde. El tono lo decide `RiskMatrixPdf` por la posición de
           la banda, no por su nombre, para que un formato con bandas propias
           salga en color igual. */
        .rm-nivel { text-align: center; font-weight: bold; }
        .rm-nivel--bad  { background: #FDECEA; color: #C8281D; }
        .rm-nivel--warn { background: #FEF3C7; color: #92400E; }
        .rm-nivel--ok   { background: #E7F3EC; color: #1D7044; }
        .rm-nivel--off  { background: #F1F5F9; color: #6A6D70; }

        /* El número de la matriz, que es lo que la v1 imprimía a secas. Se
           queda —el supervisor lo coteja con la tabla de riesgos de la pared—
           pero debajo de la palabra y en pequeño: un «6» solo no dice si es
           bueno o malo, y en esta escala el 6 es peor que el 20. */
        .rm-valor { display: block; font-weight: normal; font-size: 7pt; }

        .rm-pill { font-weight: bold; padding: 1px 4px; }
        .rm-pill--bad  { background: #FDECEA; color: #C8281D; }
        .rm-pill--warn { background: #FEF3C7; color: #92400E; }
        .rm-pill--ok   { background: #E7F3EC; color: #1D7044; }
        .rm-pill--off  { background: #F1F5F9; color: #6A6D70; }

        .rm-resumen { font-size: 8pt; color: #475569; margin: 0 0 4px 0; }
        .rm-vacia { margin: 0 0 8px 6px; }
    </style>
@endonce

@php
    $datos = $campo['datos'] ?? [];
    $prefijo = 'form_submissions.pdf.risk_matrix.';

    /**
     * La palabra de la banda.
     *
     * VIENE RESUELTA EN EL DATO (`nivel_label`), no se reconstruye aquí. Antes
     * esto hacía `__('…level_' . $clave)`, o sea que las bandas eran
     * configurables **siempre que se llamaran alto, medio y bajo**: una empresa
     * con `critico / moderado / aceptable` leía «Critico» a secas. Ahora el
     * rótulo lo pone la plantilla —traducido si el cliente lo tradujo— y
     * `BandasDeRiesgo` sólo cae a la traducción del producto para las cuatro
     * plantillas migradas, que no lo traen.
     *
     * Sin banda es «sin evaluar», que no es lo mismo que una banda sin nombre.
     */
    $nombreNivel = fn (?string $rotulo) => $rotulo ?? __($prefijo . 'not_assessed');
@endphp

<h3 class="block__sub">{{ $campo['etiqueta'] }}</h3>

@if (empty($datos['actividades']))
    <p class="muted">—</p>
@else
    {{-- El recuento por nivel, arriba del todo. Es lo primero que mira quien
         firma y en la v1 no está en ninguna parte: hay que recorrer las
         veinticinco filas de la columna «Riesgo/Impacto» comparando números. --}}
    <p class="rm-resumen">
        {{ __($prefijo . 'total_hazards', ['count' => $datos['total']]) }}
        @foreach ($datos['niveles'] as $nivel)
            · <span class="rm-pill rm-pill--{{ $nivel['tono'] }}">{{ $nombreNivel($nivel['label'] ?? $nivel['clave']) }}: {{ $nivel['cuenta'] }}</span>
        @endforeach
        {{-- Un peligro declarado y sin puntuar es un agujero en el documento,
             no un cero: se cuenta aparte porque en el desglose por nivel no
             aparece en ninguna banda y se perdería de vista. --}}
        @if ($datos['total'] > $datos['evaluados'])
            · <span class="rm-pill rm-pill--off">{{ __($prefijo . 'not_assessed_count', [
                'count' => $datos['total'] - $datos['evaluados'],
            ]) }}</span>
        @endif
        @if ($datos['incompletos'] > 0)
            · <span class="req">{{ __($prefijo . 'incomplete_count', ['count' => $datos['incompletos']]) }}</span>
        @endif
    </p>

    @foreach ($datos['actividades'] as $actividad)
        <table class="rm-act">
            <tr>
                <td>
                    <span class="rm-act__num">{{ __($prefijo . 'activity_n', ['n' => $actividad['numero']]) }}</span><br>
                    <span class="rm-act__nombre">
                        {{-- La actividad sin nombre se dice, no se deja en blanco:
                             una línea vacía parece un fallo de impresión. --}}
                        {{ $actividad['actividad'] ?? __($prefijo . 'unnamed_activity') }}
                    </span>
                </td>
                <td class="rm-act__resumen">
                    @if ($actividad['total'] > 0)
                        {{ __($prefijo . 'hazards_rated', [
                            'done' => $actividad['evaluados'], 'total' => $actividad['total'],
                        ]) }}
                        @if ($actividad['nivel_peor'])
                            · <span class="rm-pill rm-pill--{{ $actividad['tono_peor'] }}">
                                {{ __($prefijo . 'worst', ['level' => $nombreNivel($actividad['nivel_peor_label'] ?? $actividad['nivel_peor'])]) }}
                            </span>
                        @endif
                    @endif
                </td>
            </tr>
        </table>

        @if ($actividad['peligros'] === [])
            {{-- Actividad declarada y sin peligros. Es una fila legítima —en la
                 v1 es una `F1DocumentActivity` sin `f1_document_dangers`— y sale
                 dicho con todas las letras: en un documento de seguridad, un
                 hueco donde debería haber peligros se lee como un olvido de
                 impresión y no como lo que es. --}}
            <p class="muted rm-vacia">{{ __($prefijo . 'no_hazards') }}</p>
        @else
            <table class="data rm-tabla">
                <thead>
                    <tr>
                        <th class="rm-c-peligro" rowspan="2">{{ __($prefijo . 'col_danger') }}</th>
                        <th class="rm-c-riesgo" rowspan="2">{{ __($prefijo . 'col_risk') }}</th>
                        <th class="rm-c-control" rowspan="2">{{ __($prefijo . 'col_control') }}</th>
                        {{-- El grupo «Evaluación» de la tabla vieja: las tres
                             columnas que salen de la matriz y no se teclean. --}}
                        <th colspan="3">{{ __($prefijo . 'evaluation') }}</th>
                    </tr>
                    <tr>
                        <th class="rm-c-prob">{{ __($prefijo . 'col_probability') }}</th>
                        <th class="rm-c-sev">{{ __($prefijo . 'col_severity') }}</th>
                        <th class="rm-c-nivel">{{ __($prefijo . 'col_level') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($actividad['peligros'] as $peligro)
                        <tr>
                            {{-- Probabilidad y severidad van centradas como en la
                                 v1: son palabras cortas de catálogo, no frases. --}}
                            @foreach (['peligro' => '', 'riesgo' => '', 'control' => '',
                                       'probabilidad' => 'rm-eval', 'severidad' => 'rm-eval'] as $columna => $clase)
                                <td class="{{ $clase }}">
                                    @if (filled($peligro[$columna]))
                                        {{ $peligro[$columna] }}
                                    @elseif (in_array($columna, $peligro['faltan'], true))
                                        {{-- La misma regla del servidor
                                             (`exigirPeligroEntero`): una fila que
                                             se empezó a puntuar va entera. Un
                                             borrador se puede imprimir, y un
                                             peligro puntuado «alto» sin decir qué
                                             control se puso tiene que verse. --}}
                                        <span class="req">{{ __($prefijo . 'missing') }}</span>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                            @endforeach

                            <td class="rm-nivel rm-nivel--{{ $peligro['tono'] ?? 'off' }}">
                                {{ $nombreNivel($peligro['nivel_label'] ?? $peligro['nivel']) }}
                                @if ($peligro['valor'] !== null)
                                    <span class="rm-valor">{{ $peligro['valor'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
@endif
