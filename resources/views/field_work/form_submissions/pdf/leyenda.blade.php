{{--
    La leyenda de una cuadricula: que significa cada marca.

    Va SIEMPRE encima de la tabla que explica, nunca al pie: al pie hay que
    haber leido ya la cuadricula entera para enterarse de que se estaba leyendo.

    Recibe `$leyenda`, que es lo que devuelve `Simbolos::leyenda()` — las
    palabras son las de la propia plantilla del cliente, no una traduccion
    nuestra.
--}}
<p class="leyenda">
    @foreach ($leyenda as $entrada)
        <span class="sym sym--{{ $entrada['tono'] }}">{{ $entrada['simbolo'] }}</span> {{ $entrada['texto'] }}@if (!$loop->last)<span class="leyenda__sep"> · </span>@endif
    @endforeach
</p>
