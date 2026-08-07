<?php

/*
|--------------------------------------------------------------------------
| Languages module — tunable knobs (mirror de config/regions.php)
|--------------------------------------------------------------------------
*/
return [
    'bulk_async_threshold' => env('LANGUAGES_BULK_ASYNC_THRESHOLD', 200),
    'undo_window_seconds'  => env('LANGUAGES_UNDO_WINDOW', 60),
    'recent_views_keep'    => env('LANGUAGES_RECENTS_KEEP', 10),
    'per_page_options'     => [10, 25, 50, 100, 200],
    'per_page_default'     => 10,
    'edit_all_max'         => 200,

    /**
     * Export — límites por formato (filas máximas que el plan free puede
     * exportar). Mismos racionales que regions.php.
     */
    'export_limits' => [
        'csv'   => env('LANGUAGES_EXPORT_LIMIT_CSV',   0),
        'excel' => env('LANGUAGES_EXPORT_LIMIT_EXCEL', 25000),
        'pdf'   => env('LANGUAGES_EXPORT_LIMIT_PDF',   5000),
        'word'  => env('LANGUAGES_EXPORT_LIMIT_WORD',  10000),
    ],

    'export_job_memory_limit' => env('LANGUAGES_EXPORT_MEMORY', '512M'),
];
