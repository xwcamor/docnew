<?php

/*
|--------------------------------------------------------------------------
| Companies module — tunable knobs
|--------------------------------------------------------------------------
|
| Clon de config/regions.php adaptado al modulo Companies (per-tenant).
| Ajusta los valores via env sin tocar codigo.
*/
return [
    /**
     * Cual de las empresas del catalogo es la del propio workspace, por su
     * NUMERO DE DOCUMENTO.
     *
     * Se marca en Ajustes → Mi empresa, y de eso depende a quien se le pregunta
     * que aprueba: los roles en obra son de la gente de la empresa que contrata,
     * no de las contratistas. Pero `setup:project --datos` rehace la base entera,
     * asi que ese ajuste habia que volver a ponerlo a mano cada vez. Puesto aqui,
     * la carga lo deja marcado sola.
     *
     * Va el DOCUMENTO y no el id a proposito: el id cambia con cada base —el de
     * desarrollo no es el de produccion, y `migrate:fresh` lo mueve— mientras que
     * el RUC de una empresa es el mismo en todas partes. Es la misma razon por la
     * que las empresas se buscan por documento al migrarlas.
     *
     * Vacio, no se marca nada y se sigue preguntando en la pantalla. Esto es un
     * ajuste de la INSTALACION, no del producto: el nombre de un cliente no se
     * escribe en el codigo.
     */
    'own_doc' => env('WORKSPACE_COMPANY_DOC'),

    /**
     * Bulk operations — umbral por encima del cual la operacion se
     * dispatcha a queue en lugar de ejecutar inline.
     */
    'bulk_async_threshold' => env('COMPANIES_BULK_ASYNC_THRESHOLD', 200),

    /**
     * Undo despues de delete — segundos durante los cuales el usuario
     * puede hacer click en "Deshacer" para restaurar lo eliminado.
     */
    'undo_window_seconds' => env('COMPANIES_UNDO_WINDOW', 60),

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
        'csv'   => env('COMPANIES_EXPORT_LIMIT_CSV',   0),
        'excel' => env('COMPANIES_EXPORT_LIMIT_EXCEL', 25000),
        'pdf'   => env('COMPANIES_EXPORT_LIMIT_PDF',   5000),
        'word'  => env('COMPANIES_EXPORT_LIMIT_WORD',  10000),
    ],

    /**
     * Memory limit para los jobs de export. Sobreescribe el `memory_limit`
     * de PHP solo dentro del worker que ejecuta el job.
     */
    'export_job_memory_limit' => env('COMPANIES_EXPORT_MEMORY', '512M'),
];
