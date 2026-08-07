<?php

/*
|--------------------------------------------------------------------------
| Settings module — tunable knobs
|--------------------------------------------------------------------------
|
| Constantes y umbrales del módulo, expuestos como config para que se
| puedan ajustar sin tocar código (con env vars o por entorno).
|
| Cuando clones este módulo a patients/transformers/etc., copie este
| archivo y ajuste los valores según el dominio.
*/
return [
    /**
     * Bulk operations — umbral por encima del cual la operación se
     * dispatcha a queue en lugar de ejecutar inline. Súbelo si tu queue
     * worker es lento o quieres que más bulks corran sync.
     */
    'bulk_async_threshold' => env('SETTINGS_BULK_ASYNC_THRESHOLD', 200),

    /**
     * Undo después de delete — segundos durante los cuales el usuario
     * puede hacer click en "Deshacer" para restaurar lo eliminado.
     */
    'undo_window_seconds' => env('SETTINGS_UNDO_WINDOW', 60),

    /**
     * Recent items — cuántos registros vistos guardar por usuario.
     * El track auto-poda cuando se supera este número.
     */
    'recent_views_keep' => env('SETTINGS_RECENTS_KEEP', 10),

    /**
     * Per-page options — valores aceptados en el listado. El frontend
     * solo permite estos en el dropdown del paginator.
     */
    'per_page_options' => [10, 25, 50, 100, 200],

    /**
     * Default per-page — el que arranca al entrar al módulo.
     */
    'per_page_default' => 10,

    /**
     * Edit All — máximo de filas editables a la vez en el batch. Subir
     * con cuidado: cada validación de duplicados hace 1 SELECT, así que
     * 200 implica ~200 queries de validación.
     */
    'edit_all_max' => 200,

    /**
     * Export — límites por formato (filas máximas que el plan free puede
     * exportar). El backend rechaza con 422 si se excede; el frontend
     * deshabilita el formato en el ExportDialog con tooltip explicativo.
     *
     * Razones de cada límite:
     *  - CSV:  streaming via chunkById, constante en memoria → sin límite.
     *  - Excel: PhpSpreadsheet bloata x5-10 el dataset en RAM. 25k filas
     *           consume ~150 MB. Más alto rompe droplets pequeños.
     *  - PDF:   dompdf renderiza todo el HTML antes de paginar. >5k es
     *           ilegible (un PDF de 1000 páginas no sirve para nadie) Y
     *           consume mucha memoria.
     *  - Word:  PhpWord template manipulation, similar a Excel.
     *
     * El plan premium (cuando exista) puede desbloquear chunked ZIP
     * exports vía feature flag. Ver `config/features.php`.
     */
    'export_limits' => [
        'csv'   => env('SETTINGS_EXPORT_LIMIT_CSV',   0),     // 0 = sin límite (streaming)
        'excel' => env('SETTINGS_EXPORT_LIMIT_EXCEL', 25000),
        'pdf'   => env('SETTINGS_EXPORT_LIMIT_PDF',   5000),
        'word'  => env('SETTINGS_EXPORT_LIMIT_WORD',  10000),
    ],

    /**
     * Memory limit para los jobs de export. Sobreescribe el `memory_limit`
     * de PHP solo dentro del worker que ejecuta el job, para que un export
     * grande no afecte al resto del proceso. 512M cubre Excel/PDF/Word
     * dentro de los límites de arriba con margen.
     */
    'export_job_memory_limit' => env('SETTINGS_EXPORT_MEMORY', '512M'),
];
