<?php

return [
    // Barra del editor estilo Excel, compartida por todas las pruebas de diagnóstico.
    'editor_hint'    => 'Edita como en Excel: Tab / Enter / flechas para moverte, y pega un rango desde Excel.',
    'save_all'       => 'Guardar',
    'save_help'      => 'Guarda los cambios de la grilla en la base de datos.',
    'discard'        => 'Descartar',
    'discard_help'   => 'Descarta los cambios sin guardar y vuelve a los datos guardados.',
    'import_help'    => 'Cómo pegar muestras desde Excel (clic para ver la guía).',
    'unsaved'        => ':count cambio(s) sin guardar',
    'all_saved'      => 'Todo guardado',
    'delete_row'     => 'Eliminar fila',
    'restore_row'    => 'Restaurar fila',
    'row_needs_data' => 'Hay filas con datos incompletos (falta la fecha o un valor obligatorio). Complétalas antes de guardar.',
    'explain_open'   => 'Ver cómo se calculó este resultado',
    'no_samples'     => 'Sin ensayos todavía. Crea el primero para diagnosticar.',
    'date'           => 'Fecha',
    'date_help'      => 'Fecha (y hora) del muestreo. Clic en el encabezado para ordenar.',
    'report_number'  => 'N° de informe',
    'laboratory'     => 'Laboratorio',
    'add_lab'            => 'Agregar laboratorio',
    'lab_name'          => 'Nombre del laboratorio',
    'lab_name_required' => 'El nombre del laboratorio es obligatorio.',
    'state_help'     => 'Condición del diagnóstico según el semáforo. Clic en el encabezado para ordenar.',
    'samples_help'   => 'Tabla editable de ensayos: un registro por muestreo.',
    'trends_help'    => 'Gráficos de evolución de los valores a lo largo del tiempo.',
    'summary_tab'    => 'Resumen',
    'summary_help'   => 'Diagnóstico, conclusiones y recomendaciones de esta prueba.',
    'state'          => 'Estado',
    'export'         => 'Exportar',
    'export_help'    => 'Descarga las muestras en un archivo Excel (con su diagnóstico).',

    // Condiciones del semáforo (5 niveles). El valor guardado en datos es un
    // CÓDIGO interno ('Muy Bueno'…'Muy Malo'); estas son las etiquetas que ve el
    // usuario, traducibles ES/EN. Alineadas al estándar de Índice de Salud
    // (Excellent/Good/Fair/Poor/Critical).
    'legend'         => 'Semáforo:',
    'cond_muy_bueno' => 'Excelente',
    'cond_bueno'     => 'Bueno',
    'cond_medio'     => 'Regular',
    'cond_malo'      => 'Malo',
    'cond_muy_malo'  => 'Crítico',

    // Línea de estado/urgencia que encabeza la conclusión de cada prueba (escala de
    // acción común). Refuerza la severidad: tono imperativo cuando está comprometido.
    'urgency_routine'     => 'Estado normal: sin acción inmediata requerida.',
    'urgency_watch'       => 'Estado en observación: dar seguimiento y no dejarlo pasar.',
    'urgency_investigate' => 'Estado comprometido: requiere atención y priorizar el análisis.',
    'urgency_critical'    => 'Estado crítico: riesgo alto — intervención imperativa e inmediata; posible salida de servicio.',

    // Mini guía "Importar desde Excel" (popover en la barra del editor).
    'paste_guide_open'  => 'Importar desde Excel',
    'paste_guide_title' => 'Pegar muestras desde Excel',
    'paste_guide_intro' => 'No hace falta un archivo: copia el rango en Excel y pégalo en la grilla.',
    'paste_guide_cols'  => 'Orden de columnas (sin encabezados):',
    'paste_guide_step1' => 'En Excel, ordena las columnas en ese orden y copia el rango (Ctrl+C), sin la fila de títulos.',
    'paste_guide_step2' => 'Aquí, haz clic en la celda Fecha de una fila vacía y pega (Ctrl+V). Las filas se crean solas.',
    'paste_guide_step3' => 'Revisa el diagnóstico en vivo y pulsa Guardar.',
    'paste_guide_date'  => 'La fecha va como AAAA-MM-DD (o con hora: AAAA-MM-DD HH:mm).',

    // Nombres de las pruebas por código (para reportes compartidos en otro idioma;
    // la tabla `tests.name` guarda solo el español). Fallback: el nombre de la BD.
    'test_cromas'  => 'Cromatografía (DGA)',
    'test_furanos' => 'Furanos',
    'test_fiquis'  => 'Fisicoquímico',
    'test_fpot'    => 'Factor de Potencia',

    // Veredicto sintetizado (mini-resumen por prueba + Resumen).
    'verdict_no_data'   => 'Sin datos para diagnosticar.',
    'verdict_no_fault'  => 'Sin falla activa: gases bajo sus valores típicos (IEC 60599).',
    'verdict_fault'     => 'Falla detectada — :type',
    'verdict_ieee_note' => 'IEEE C57.104 marca Condición :n por límites más estrictos; con gases normales NO es una falla real.',
    'verdict_dp'        => 'DP ≈ :dp',
    'verdict_paper_ok'  => 'Papel aislante en buen estado',
    'verdict_paper_bad' => 'Degradación del papel aislante',
    'verdict_life'      => ':p% de vida del papel consumida',
    'verdict_fpot'      => 'Factor de potencia :v %',
    'verdict_fpot_ok'   => 'Aislamiento en buen estado',
    'verdict_fpot_bad'  => 'Factor de potencia elevado',
    'verdict_co_low'    => 'CO₂/CO = :r → posible degradación térmica del papel; conviene revisar (IEC 60599)',
    'verdict_co_high'   => 'CO₂/CO = :r → oxidación/envejecimiento del papel a baja temperatura; bajo riesgo (IEC 60599)',
    'duval_no_fault'    => 'Sin falla activa — Duval no aplica con gases en niveles normales (IEC 60599).',
    'summary_title'     => 'Diagnóstico del transformador',
    'summary_test'      => 'Prueba',
    'summary_verdict'   => 'Veredicto',
    'duplicate_date'    => 'Ya existe una muestra con la fecha :date para este transformador.',
    'batch_too_many'    => 'No se pueden guardar más de :max muestras a la vez.',
    'save_failed'       => 'No se pudo guardar. Revisa los datos e inténtalo de nuevo.',
    // Rótulo de la línea de límite de norma en los gráficos de tendencia.
    'chart_limit'       => 'Límite',
    'sample_date_hint' => 'YYYY-MM-DD (hora opcional)',
];
