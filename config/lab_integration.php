<?php

/**
 * Integración con el sistema de laboratorio (TR LAB) — mapa de traducción.
 *
 * El laboratorio envía los resultados por CÓDIGO DE ANALITO (`h2`, `acid`,
 * `rig`, `fal`…), que es como los tiene en su propia tabla `analytes`. Acá
 * viven las equivalencias con nuestras columnas. Está en config y no en el
 * controlador por la misma razón que las reglas de diagnóstico están en datos:
 * cuando el laboratorio agregue un parámetro, esto es una línea, no un `if`.
 *
 * OJO con `pot`: significa DOS cosas distintas según el ensayo. En
 * fisicoquímico es el factor de potencia del ACEITE (columna `fiquis.pot`); en
 * factor de potencia es el del AISLAMIENTO del transformador (`fpots.value`).
 * Los desambigua el `kind` del ensayo, nunca el código suelto.
 *
 * Documentación del contrato: docs/API-LABORATORIO.md
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Ensayos aceptados y a qué columna va cada analito
    |--------------------------------------------------------------------------
    |
    | Un código que no esté en `values` se RECHAZA con 422. No se descarta en
    | silencio a propósito: un dato que el laboratorio midió y que aquí se pierde
    | sin aviso es peor que un error — el informe del cliente saldría con menos
    | información de la que se pagó.
    */
    'tests' => [

        'chromatography' => [
            'model'    => \App\Models\Chromatographical::class,
            'relation' => 'chromatographicals',
            // Los 9 gases en ppm. El motor tolera que falten (peso dinámico),
            // así que no se exige el juego completo como en el formulario web:
            // un informe de laboratorio real a veces no trae O₂/N₂.
            'values'   => [
                'h2' => 'h2', 'o2' => 'o2', 'n2' => 'n2', 'ch4' => 'ch4',
                'co' => 'co', 'co2' => 'co2', 'c2h4' => 'c2h4',
                'c2h6' => 'c2h6', 'c2h2' => 'c2h2',
            ],
            'required' => [],   // basta con un gas
        ],

        'physicochemical' => [
            'model'    => \App\Models\Fiqui::class,
            'relation' => 'fiquis',
            // rig877 / pot100 son los MÉTODOS ALTERNOS. El laboratorio puede
            // mandarlos por código explícito o dejar que se deduzcan del bloque
            // `methods` (ver 'method_routing' abajo).
            'values'   => [
                'rig' => 'rig', 'ten' => 'ten', 'acid' => 'acid',
                'wat' => 'wat', 'pot' => 'pot',
                'rig877' => 'rig877', 'pot100' => 'pot100',
            ],
            'required' => [],
        ],

        'furanos' => [
            'model'    => \App\Models\Furano::class,
            'relation' => 'furanos',
            'values'   => [
                'fal' => 'fal', 'hme' => 'hme', 'ace' => 'ace',
                'mfu' => 'mfu', 'fua' => 'fua',
            ],
            // El 2-FAL es el que diagnostica (DP de Chendong). Sin él la
            // muestra no aporta nada al índice de salud.
            'required' => ['fal'],
        ],

        'power_factor' => [
            'model'    => \App\Models\Fpot::class,
            'relation' => 'fpots',
            'values'   => [
                'pot' => 'value', 'fpot' => 'value', 'value' => 'value',
                'temp_c' => 'temperature', 'temperature' => 'temperature',
            ],
            'required' => ['value'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Métodos de ensayo → columna (fisicoquímico)
    |--------------------------------------------------------------------------
    |
    | El laboratorio manda UN código (`rig`, `pot`) y describe el método aparte,
    | en `methods`. Acá el método está en la COLUMNA: `rig` es D1816 y `rig877`
    | es D877; `pot` es a 25 °C y `pot100` a 100 °C. Sin esta traducción una
    | rigidez medida con D877 se guardaría como si fuera D1816 y se compararía
    | contra un umbral que no le corresponde (los kV de las dos normas no son
    | comparables — ver CLAUDE.md, sección fiquis).
    |
    | `match` se busca dentro del texto de `standard`, sin distinguir mayúsculas.
    */
    'method_routing' => [
        'rig' => [
            ['match' => 'D877', 'column' => 'rig877'],
            ['match' => 'D1816', 'column' => 'rig'],
        ],
        // El factor de potencia se rutea por temperatura, no por norma (la
        // norma es D924 en los dos casos). >= 60 °C se considera el ensayo
        // "en caliente" (el nominal es 100 °C; se deja margen por si el
        // laboratorio informa la temperatura real de la celda).
        'pot' => [
            ['temp_from' => 60, 'column' => 'pot100'],
            ['temp_to'   => 60, 'column' => 'pot'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fases: entero (laboratorio) → texto (nuestra columna)
    |--------------------------------------------------------------------------
    |
    | `transformers.phases` es un string de una lista cerrada. El laboratorio
    | usa un entero. La traducción es exacta y sin default: un 6 no es "three".
    */
    'phases' => [
        1 => 'single',
        2 => 'two',
        3 => 'three',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotencia
    |--------------------------------------------------------------------------
    |
    | Ventana de retención de las claves. Pasada la ventana, la misma clave
    | vuelve a ser "nueva" — por eso conviene que sea holgada respecto del
    | backoff del laboratorio (su último reintento es a las 6 horas).
    */
    'idempotency_ttl_days' => 30,
];
