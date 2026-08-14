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
    <title>{{ $formato['nombre'] ?: __('form_submissions.pdf.title') }} — {{ $pie['identificador'] }}</title>
    <style>
        /*
            LA PALETA
            ---------
            Tres tintas y ya: azul pizarra para la estructura, gris para lo que
            acompaña, y rojo/verde SOLO donde el color significa algo — «no
            conforme», «pendiente de revision», la banda de riesgo.

            Antes habia un azul de pantalla (#0A6ED1) en las barras de bloque y
            un azul distinto (#354A5F) en las cabeceras de tabla, uno al lado
            del otro. En pantalla se toleraba; impreso son dos azules que no
            pegan y que ademas gastan la atencion en decorar. Ahora la barra de
            bloque es la unica tinta fuerte y las cabeceras de tabla van en gris
            claro con texto oscuro: se leen mejor, gastan menos tinta y dejan el
            color para lo que informa.
        */
        @page { margin: 26px 28px 52px 28px; }
        body { font-family: Helvetica; font-size: 9pt; color: #1F2A37; margin: 0; }
        h1, h2, h3 { margin: 0; }

        /* Membrete: marca | TITULO | version. */
        .letterhead { width: 100%; border-collapse: collapse; border-bottom: 2px solid #1F3B57;
                      padding-bottom: 0; margin-bottom: 12px; }
        .letterhead td { vertical-align: middle; padding: 0 0 9px 0; }
        .letterhead__brand { width: 22%; }
        .letterhead__brand img { max-width: 96px; max-height: 46px; }
        .letterhead__org { font-size: 8pt; font-weight: bold; color: #46596B; }
        /* El titulo del formato: lo primero que se lee y lo unico centrado. */
        .letterhead__title { text-align: center; }
        .letterhead__title h1 { font-size: 13pt; font-weight: bold; color: #1F3B57;
                                text-transform: uppercase; letter-spacing: 0.03em; line-height: 1.15; }
        .letterhead__doc { width: 22%; text-align: right; font-size: 8pt; color: #63748A; }
        .letterhead__doc strong { display: block; color: #1F3B57; font-size: 12pt; letter-spacing: 0.04em; }

        .disclaimer { background: #F5F7F9; border-left: 3px solid #A7B4C2; padding: 6px 10px;
                      font-size: 7.5pt; color: #46596B; margin: 0 0 12px 0; }
        .disclaimer--pie { margin: 14px 0 0 0; }

        /* Bloques */
        .block { margin: 0 0 14px 0; }
        .block__title { background: #1F3B57; color: #ffffff; font-size: 8.5pt; font-weight: bold;
                        text-transform: uppercase; letter-spacing: 0.06em; padding: 5px 8px; margin: 0 0 6px 0; }
        .block__sub { font-size: 8.5pt; font-weight: bold; color: #1F3B57; margin: 8px 0 4px 0; }

        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 3px 6px; border: 1px solid #C9D3DC; font-size: 8.5pt; vertical-align: top; }
        table.kv td.k { background: #EEF2F6; font-weight: bold; width: 22%; color: #46596B; }

        table.data { width: 100%; border-collapse: collapse; margin: 0 0 8px 0; }
        /* Cabeceras centradas. Iban a la izquierda, alineadas con el texto de
           su columna, y en una tabla de columnas estrechas —«Nº», «1», «10»,
           «Hora»— el rótulo quedaba pegado al borde izquierdo con el hueco
           entero a la derecha. Centrado, cada rótulo se lee como el sombrero de
           su columna y no como la primera fila de datos. */
        table.data thead th { background: #EEF2F6; color: #1F3B57; font-weight: bold; font-size: 8pt;
                              text-align: center; padding: 4px 6px; border: 1px solid #C9D3DC;
                              border-bottom: 1.5px solid #1F3B57; }
        table.data tbody td { padding: 4px 6px; border: 1px solid #C9D3DC; font-size: 8pt; }
        /* Sin filas alternas. Un AST de quince peligros con la mitad de las
           filas tintadas se lee como si el color significara algo, y no
           significa nada: en este documento el color es el nivel de riesgo y
           el «no conforme». Gastarlo en decorar quita el unico sitio donde
           informa. Las lineas ya separan las filas. */

        .muted { color: #8A97A6; }
        .req { color: #9E2A22; font-weight: bold; }

        /* LAS MARCAS DE LAS CUADRICULAS (✔ ✘ – ?) y su leyenda.
           Van en la hoja comun porque las comparten el EPP y la inspeccion de
           herramientas, y definirlas dos veces acaba en dos tonos distintos.

           La familia es DejaVu Sans y no Helvetica: las core fonts de PDF no
           tienen ni el check ni la equis —ZapfDingbats tampoco sale por DomPDF,
           esta comprobado generando un PDF de prueba— y DejaVu viaja dentro de
           la propia libreria, asi que no hay nada que instalar. Solo la marca
           cambia de fuente; el texto sigue en Helvetica, que aprieta menos. */
        .sym { font-family: 'DejaVu Sans', sans-serif; font-weight: bold; }
        .sym--ok  { color: #1B6B45; }
        .sym--bad { color: #9E2A22; }
        .sym--na  { color: #8A97A6; }
        /* El hueco va en ambar: es «mira esto», no «esto esta mal». */
        .sym--sin { color: #B45309; }

        .leyenda { font-size: 7.5pt; color: #46596B; margin: 0 0 5px 0; }
        .leyenda__sep { color: #A7B4C2; }

        /* El rotulo del grupo de firmas, que va dentro de la cabecera de su
           tabla para que no se quede huerfano al pie de una pagina. Se pinta
           como un subtitulo, no como una cabecera de columna. */
        table.data.firmas thead th.firmas__grupo { background: #ffffff; color: #1F3B57;
            font-size: 8.5pt; text-align: left; border: none; border-bottom: none;
            padding: 6px 0 3px 0; }
        /* Y el grupo entero no se parte si cabe: con dos o tres firmas —lo
           normal— evita que el rotulo se quede al pie de una pagina y la tabla
           empiece en la siguiente. Cuando NO cabe, DomPDF parte igual y ahi es
           donde entra la fila de rotulo del `<thead>`, que se repite. */
        .firmas__grupo-wrap { page-break-inside: avoid; }

        .flag { display: inline-block; background: #9E2A22; color: #ffffff; font-size: 7pt;
                font-weight: bold; padding: 1px 5px; letter-spacing: 0.04em; }
        /* El rotulo del representante de los trabajadores, bajo su nombre en la
           tabla de firmas: informacion, no alarma — gris y en versalitas, no el
           rojo del `.flag`. */
        .flag-rep { font-size: 6.5pt; color: #6A6D70; text-transform: uppercase; letter-spacing: 0.03em; }
        .ok { color: #1B6B45; font-weight: bold; }

        /* La firma trazada es un trazo sobre transparente: se pinta a su
           tamaño natural dentro de la celda, sin marco. Un marco alrededor de
           una firma la convierte en un sello, que es otra cosa. */
        .firma { width: 96px; }
        .firma img { max-width: 90px; max-height: 34px; }

        .sheet { page-break-before: always; text-align: center; }
        .sheet img { max-width: 100%; max-height: 940px; }
        .sheet__caption { font-size: 7.5pt; color: #63748A; margin-top: 6px; }

        .signers { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .signers td { width: 25%; padding: 4px 8px; text-align: center; vertical-align: bottom; font-size: 8pt; }
        .signers__img { height: 42px; }
        .signers__img img { max-height: 40px; max-width: 130px; }
        .signers__line { border-top: 1px solid #1F2A37; padding-top: 3px; }
        .signers__rel { font-size: 7pt; color: #63748A; text-transform: uppercase; letter-spacing: 0.05em; }
        .signers__name { font-weight: bold; }
        .signers__title { font-size: 7.5pt; color: #46596B; }

        /* Pie fijo: el identificador verificable tiene que salir en toda pagina.
           Va maquetado con una tabla y no con floats: un float dentro de un
           elemento fijo hace que DomPDF genere una pagina por cada bloque. */
        #footer { position: fixed; bottom: -34px; left: 0; right: 0; height: 30px;
                  border-top: 1px solid #C9D3DC; padding-top: 4px;
                  font-size: 7pt; color: #63748A; }
        #footer table { width: 100%; border-collapse: collapse; }
        #footer td { font-size: 7pt; color: #63748A; padding: 0; }
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

{{-- 1 · Membrete: la marca a la izquierda, el TITULO en el medio, la version
     a la derecha.

     El titulo era el codigo —«IHM», «EPP»— metido en la esquina derecha entre
     la version y el estado, y el centro lo ocupaba el nombre del workspace. O
     sea que lo primero que se leia de un formato de seguridad no decia que
     formato era: el nombre completo no salia en ninguna parte. Ahora manda el
     nombre, centrado, como en el papel de la v1 y como en cualquier impreso.

     El ESTADO se fue. «Confirmado» dentro del propio documento es informacion
     del sistema, no del formato, y ademas queda congelado en el papel el dia
     que se imprimio: a las dos semanas puede estar mintiendo. Lo que da fe es
     el identificador del pie, que se puede comprobar contra el sistema.

     Y la direccion del workspace tambien. El sitio que importa en un formato de
     obra es donde se hizo el trabajo, y ese va en la cabecera del plan. --}}
<table class="letterhead">
    <tr>
        {{-- El logo O el nombre, nunca los dos. Un logo YA dice de quién es el
             documento —para eso se sube— y repetir el nombre debajo es decirlo
             dos veces en la esquina donde menos sitio hay. El nombre se queda
             como reserva de quien todavía no ha subido logo, que es la única
             manera de que ese membrete no salga en blanco. --}}
        <td class="letterhead__brand">
            @if (!empty($membrete['logo']))
                <img src="{{ $membrete['logo'] }}" alt="{{ $membrete['nombre'] }}">
            @else
                <span class="letterhead__org">{{ $membrete['nombre'] }}</span>
            @endif
        </td>
        <td class="letterhead__title">
            <h1>{{ $formato['nombre'] ?: __('form_submissions.pdf.title') }}</h1>
        </td>
        <td class="letterhead__doc">
            @if (filled($formato['codigo']))
                <strong>{{ $formato['codigo'] }}</strong>
            @endif
            {{ __('form_submissions.pdf.form_version') }} {{ $formato['version'] }}
        </td>
    </tr>
</table>

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
                {{-- La sigla que lleva la empresa en su país; genérico si no la tiene. --}}
                <td class="k">{{ ($plan['empresa_doc_tipo'] ?? null) ?: __('form_submissions.pdf.tax_id') }}</td>
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
                <td colspan="3">{{ $plan['descripcion'] ?: '—' }}</td>
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
            {{-- Solo si la seccion tiene titulo de verdad. Aqui salia «SECCION
                 1», «SECCION 2»: el numero del orden interno de la plantilla
                 disfrazado de encabezado. --}}
            @if (filled($seccion['titulo']))
                <h3 class="block__sub">{{ $seccion['titulo'] }}</h3>
            @endif

            @php $pares = array_filter($seccion['campos'], fn ($c) => $c['render'] === 'par'); @endphp

            @if (!empty($pares))
                <table class="kv">
                    @foreach ($pares as $campo)
                        <tr>
                            {{-- Sin el asterisco de obligatorio. Es una regla
                                 de la pantalla de llenado —dice que hay que
                                 responder— y aqui ya esta respondido o ya no
                                 se va a responder: en el papel solo pone
                                 asteriscos rojos por toda la hoja. --}}
                            <td class="k">{{ $campo['etiqueta'] }}</td>
                            {{-- Y lo vacio, un guion. «Sin respuesta» en
                                 cursiva ocupa cuatro veces mas y dice lo mismo
                                 que la raya que se pone en un formulario de
                                 papel cuando algo no se llena. --}}
                            <td>{{ filled($campo['valor']) ? $campo['valor'] : '—' }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @foreach ($seccion['campos'] as $campo)
                {{-- Los cuatro campos de obra —matriz de riesgo, EPP,
                     herramientas y banco de preguntas— tienen cada uno su
                     parcial, porque cada uno es un formato distinto y ninguno
                     se parece a una tabla de columnas sueltas. Antes caian en
                     el volcador generico de aqui abajo y salian con las claves
                     del JSON de cabecera. --}}
                @if ($campo['render'] === 'campo')
                    @include($campo['parcial'], ['campo' => $campo])
                @elseif ($campo['render'] === 'tabla')
                    <h3 class="block__sub">{{ $campo['etiqueta'] }}</h3>
                    @if (empty($campo['filas']))
                        <p class="muted">—</p>
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
                        <p class="muted">—</p>
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

{{-- 5 · Bloque de firmas, repartidas en TRABAJADORES y APROBADORES.

     Estaban las tres cosas que se firman —la cuadrilla, el flujo de aprobacion
     y la entrega en si— revueltas en una sola tabla ordenada por hora. Eso
     responde a «cuando se firmo», y quien abre el documento pregunta otra cosa:
     quien estuvo en el trabajo, y quien lo autorizo. Son dos preguntas y ahora
     son dos tablas, que ademas es como esta el papel de la v1.

     Un grupo vacio no se pinta: un plan sin flujo de aprobacion no tiene que
     enseñar una tabla en blanco con un rotulo encima. --}}
<div class="block">
    <h2 class="block__title">{{ __('form_submissions.pdf.signatures') }}</h2>

    @if (empty($firmas['trabajadores']) && empty($firmas['aprobadores']) && empty($firmas['entrega']))
        <p class="muted">{{ __('form_submissions.pdf.no_signatures') }}</p>
    @endif

    @if (!empty($firmas['trabajadores']))
        @include('field_work.form_submissions.pdf.firmas', [
            'titulo' => __('form_submissions.pdf.workers'),
            'filas'  => $firmas['trabajadores'],
        ])
    @endif

    @if (!empty($firmas['aprobadores']))
        @include('field_work.form_submissions.pdf.firmas', [
            'titulo' => __('form_submissions.pdf.approvers'),
            'filas'  => $firmas['aprobadores'],
            'conRol' => true,
        ])
    @endif

    {{-- La entrega en si. Casi nunca hay ninguna —lo normal es que firme la
         cuadrilla y el flujo— pero cuando la hay es la firma de quien cerro el
         formato, y perderla por no tener donde ponerla seria peor. --}}
    @if (!empty($firmas['entrega']))
        @include('field_work.form_submissions.pdf.firmas', [
            'titulo' => __('form_submissions.pdf.submission_signature'),
            'filas'  => $firmas['entrega'],
        ])
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

{{-- 7 · El descargo, al final.
     Estaba arriba, debajo del membrete, y ahi es lo primero que se lee de un
     documento cuyo contenido todavia no se ha visto. Un descargo se lee
     DESPUES: dice bajo que condiciones vale lo que acabas de leer, y por eso
     en cualquier informe va al pie. --}}
@if (!empty($membrete['disclaimer']))
    <p class="disclaimer disclaimer--pie">{{ $membrete['disclaimer'] }}</p>
@endif

</body>
</html>
