<?php

/*
|--------------------------------------------------------------------------
| Automations module — tunable knobs
|--------------------------------------------------------------------------
|
| Constantes y umbrales del módulo, expuestos como config para que se
| puedan ajustar sin tocar código (con env vars o por entorno).
*/
return [
    /**
     * Bulk operations — umbral por encima del cual la operación se
     * dispatcha a queue en lugar de ejecutar inline. Override via Setting
     * global `bulk.async_threshold` o env.
     */
    'bulk_async_threshold' => env('AUTOMATIONS_BULK_ASYNC_THRESHOLD', 200),

    /**
     * Undo después de delete — segundos durante los cuales el usuario
     * puede hacer click en "Deshacer" para restaurar lo eliminado.
     */
    'undo_window_seconds' => env('AUTOMATIONS_UNDO_WINDOW', 60),

    /**
     * Edit All — máximo de filas editables a la vez en el batch.
     */
    'edit_all_max' => 200,

    /**
     * Per-page options — valores aceptados en el listado.
     */
    'per_page_options' => [10, 25, 50, 100, 200],

    /**
     * Default per-page — el que arranca al entrar al módulo.
     */
    'per_page_default' => 10,

    /**
     * Memory cap explícito para los jobs de export. 512M cubre Excel de hasta
     * ~50k filas; PDF/Word son más pesados por celda. Override por env.
     */
    'export_job_memory_limit' => env('AUTOMATIONS_EXPORT_MEMORY_LIMIT', '512M'),

    /**
     * Límites por formato de export. 0 = sin límite (CSV streaming). Estos
     * defaults son fallback — Setting::getExportLimit() permite override en
     * runtime via super desde la pantalla de Settings.
     */
    'export_limits' => [
        'csv'   => 0,
        'excel' => 50000,
        'pdf'   => 5000,
        'word'  => 5000,
    ],
];
