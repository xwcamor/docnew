<?php

/*
|--------------------------------------------------------------------------
| Customers module — tunable knobs
|--------------------------------------------------------------------------
|
| Clon de config/regions.php adaptado al modulo Customers (per-tenant).
| Ajusta los valores via env sin tocar codigo.
*/
return [
    /**
     * Bulk operations — umbral por encima del cual la operacion se
     * dispatcha a queue en lugar de ejecutar inline.
     */
    'bulk_async_threshold' => env('CUSTOMERS_BULK_ASYNC_THRESHOLD', 200),

    /**
     * Undo despues de delete — segundos durante los cuales el usuario
     * puede hacer click en "Deshacer" para restaurar lo eliminado.
     */
    'undo_window_seconds' => env('CUSTOMERS_UNDO_WINDOW', 60),

    /**
     * Recent items — cuantos registros vistos guardar por usuario.
     */
    'recent_views_keep' => env('CUSTOMERS_RECENTS_KEEP', 10),

    /**
     * Per-page options — valores aceptados en el listado.
     */
    'per_page_options' => [10, 25, 50, 100, 200],

    /**
     * Default per-page — el que arranca al entrar al modulo.
     */
    'per_page_default' => 25,

    /**
     * Edit All — maximo de filas editables a la vez en el batch.
     */
    'edit_all_max' => 200,

    /**
     * Export — limites por formato. Mismo razonamiento que Regions:
     *  - CSV: streaming, sin limite.
     *  - Excel: PhpSpreadsheet bloata x5-10 en RAM. 25k filas ~150 MB.
     *  - PDF:  dompdf renderiza todo el HTML antes de paginar.
     *  - Word: PhpWord similar a Excel.
     */
    'export_limits' => [
        'csv'   => env('CUSTOMERS_EXPORT_LIMIT_CSV',   0),
        'excel' => env('CUSTOMERS_EXPORT_LIMIT_EXCEL', 25000),
        'pdf'   => env('CUSTOMERS_EXPORT_LIMIT_PDF',   5000),
        'word'  => env('CUSTOMERS_EXPORT_LIMIT_WORD',  10000),
    ],

    /**
     * Memory limit para los jobs de export. Sobreescribe el `memory_limit`
     * de PHP solo dentro del worker que ejecuta el job.
     */
    'export_job_memory_limit' => env('CUSTOMERS_EXPORT_MEMORY', '512M'),
];
