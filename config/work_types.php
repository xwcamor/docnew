<?php

/*
|--------------------------------------------------------------------------
| WorkTypes module — tunable knobs
|--------------------------------------------------------------------------
|
| Los tipos de trabajo son un catalogo corto por definicion —un puñado por
| pais—, asi que estos topes son techo, no expectativa.
*/
return [
    /**
     * Bulk operations — umbral por encima del cual la operacion se
     * dispatcha a queue en lugar de ejecutar inline.
     */
    'bulk_async_threshold' => env('WORK_TYPES_BULK_ASYNC_THRESHOLD', 200),

    /**
     * Undo despues de delete — segundos durante los cuales el usuario
     * puede hacer click en "Deshacer" para restaurar lo eliminado.
     */
    'undo_window_seconds' => env('WORK_TYPES_UNDO_WINDOW', 60),

    /**
     * Per-page options — valores aceptados en el listado.
     */
    'per_page_options' => [10, 25, 50, 100, 200],

    /**
     * Default per-page — el que arranca al entrar al modulo.
     */
    'per_page_default' => 25,
];
