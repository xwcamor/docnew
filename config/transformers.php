<?php

/*
|--------------------------------------------------------------------------
| Transformers module — tunable knobs
|--------------------------------------------------------------------------
|
| Clon de config/regions.php adaptado al modulo Transformers (per-tenant).
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
     *  - PDF:  dompdf renderiza TODO el HTML antes de paginar y revienta la RAM
     *          con tablas grandes (a ~2.5k filas agota 512 MB). Por eso el tope
     *          es BAJO: el PDF es para vistas presentables/filtradas, no para
     *          volcar la flota entera — para eso están CSV/Excel.
     *  - Word: PhpWord similar a Excel.
     */
    'export_limits' => [
        'csv'   => env('TRANSFORMERS_EXPORT_LIMIT_CSV',   0),
        'excel' => env('TRANSFORMERS_EXPORT_LIMIT_EXCEL', 25000),
        'pdf'   => env('TRANSFORMERS_EXPORT_LIMIT_PDF',   500),
        'word'  => env('TRANSFORMERS_EXPORT_LIMIT_WORD',  1000),
    ],

    /**
     * Memory limit para los jobs de export. Sobreescribe el `memory_limit`
     * de PHP solo dentro del worker que ejecuta el job. 1 GB da holgura al
     * peor caso de PDF/Excel dentro de los topes de arriba.
     */
    'export_job_memory_limit' => env('TRANSFORMERS_EXPORT_MEMORY', '1024M'),

    /**
     * Sensibilidad de la tendencia de salud: cuánto debe cambiar el DGAF de
     * cromatografía entre las 2 últimas muestras para marcar "empeorando" /
     * "mejorando" (debajo de eso = "estable"). No es umbral de diagnóstico,
     * es una sensibilidad de UX para la alarma temprana.
     */
    'trend_dgaf_epsilon' => env('TRANSFORMERS_TREND_EPSILON', 0.3),

    /**
     * Alertas in-app de salud (campana): avisa al workspace cuando un trafo
     * entra a Malo/Muy Malo o su tendencia pasa a "empeorando". Solo dispara
     * en transiciones (no re-alerta estados sostenidos).
     */
    'health_alerts_enabled' => env('TRANSFORMERS_HEALTH_ALERTS', true),
];
