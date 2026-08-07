<?php

return [
    'tab'            => 'Fisicoquímico',
    'samples_tab'    => 'Ensayos',
    'trends_tab'     => 'Tendencias',
    'trends_hint'    => 'Evolución de los parámetros fisicoquímicos a lo largo de los ensayos.',
    'trends_no_data' => 'Se necesitan al menos 2 ensayos para ver tendencias.',
    'trend_chart_title' => 'Gráfico de Tendencia (:params)',
    'trend_and'         => 'y',
    'singular'       => 'Ensayo',
    'sample_date'    => 'Fecha',
    'state'          => 'Estado',
    'params'         => 'Parámetros fisicoquímicos',
    'limits_norm'    => 'Límites IEEE C57.106',
    'new_test'       => 'Crear ensayo',
    'edit_test'      => 'Editar ensayo',
    'no_samples'     => 'Sin ensayos todavía. Crea el primero para diagnosticar.',
    'delete_confirm' => '¿Eliminar el ensayo del :date?',
    'sample_date_required' => 'La fecha del ensayo es obligatoria.',

    'created' => 'Ensayo fisicoquímico creado y diagnosticado.',
    'saved'   => 'Ensayo fisicoquímico actualizado.',
    'deleted' => 'Ensayo fisicoquímico eliminado.',

    // Parámetros (código → nombre + unidad).
    'rig'  => 'Rigidez Dieléctrica',
    'ten'  => 'Tensión Interfacial',
    'acid' => 'Número Ácido',
    'wat'  => 'Contenido de Agua',
    'pot'  => 'Factor de Potencia',
    'rig877'  => 'Rigidez Dieléctrica',
    'pot100'  => 'Factor de Potencia',
    // Nombre COMPLETO, con la condición de ensayo pegada. Se usa donde los dos
    // métodos de una misma propiedad aparecen mezclados en una lista y el
    // nombre pelado no alcanza para saber cuál es cuál (traza del diagnóstico).
    // En la tabla de ensayos NO se usa: ahí la condición ya va en su propia
    // línea de la cabecera y repetirla sería ruido.
    'rig_full'    => 'Rigidez Dieléctrica 2.0 mm',
    'rig877_full' => 'Rigidez Dieléctrica 2.54 mm',
    'pot_full'    => 'Factor de Potencia 25 °C',
    'pot100_full' => 'Factor de Potencia 100 °C',
    // Unidad PELADA: ejes de los gráficos y tooltips de celda.
    'rig_unit'  => 'kV',
    'ten_unit'  => 'mN/m',
    'acid_unit' => 'mg KOH/g',
    'wat_unit'  => 'ppm',
    'pot_unit'  => '%',
    'rig877_unit' => 'kV',
    'pot100_unit' => '%',

    // ── Cabecera de la tabla de ensayos (3 líneas: nombre · norma · medida) ──
    // Línea 2: NORMA del método.
    'rig_astm'    => 'ASTM D1816',
    'rig877_astm' => 'ASTM D877',
    'ten_astm'    => 'ASTM D971',
    'acid_astm'   => 'ASTM D974',
    'wat_astm'    => 'ASTM D1533',
    'pot_astm'    => 'ASTM D924',
    'pot100_astm' => 'ASTM D924',

    // Línea 3: MEDIDA con su condición de ensayo. Es lo único que distingue las
    // columnas que comparten nombre: los dos factores de potencia (25 vs 100 °C)
    // y las dos rigideces (la separación de electrodos cambia el valor esperado:
    // D877 es siempre 2.54 mm por norma; D1816 admite 1 mm o 2 mm). Sin clave
    // definida se usa la unidad pelada de arriba.
    'rig_head'    => 'kV/2.0 mm',
    'rig877_head' => 'kV 2.54 mm',
    'pot_head'    => '25 °C. %',
    'pot100_head' => '100 °C. %',

    // Drawer "¿Por qué este resultado?" (traza del diagnóstico)
    'explain' => [
        'open'           => 'Ver cómo se calculó',
        'title'          => '¿Por qué este resultado?',
        'subtitle'       => 'Traza del diagnóstico — los umbrales y pesos viven en datos; el código solo aplica la fórmula.',
        'result'         => 'Resultado',
        'dgaf'           => 'DGAF (promedio ponderado)',
        'rating'         => 'Calificación',
        'reference'      => 'Referencia (de dónde sale)',
        'reference_note' => 'Se eligió la tabla de umbrales según el aceite y la clase de tensión del transformador.',
        'standard'       => 'Norma',
        'standard_value' => 'IEEE C57.106',
        'oil'            => 'Aceite',
        'class'          => 'Clase de tensión',
        'class_low'      => 'Baja (≤ 69 kV)',
        'class_mid'      => 'Media (69–230 kV)',
        'class_high'     => 'Alta (≥ 230 kV)',
        'class_all'      => 'Única',
        'calc'           => 'Cálculo por parámetro',
        'col_param'      => 'Parámetro',
        'col_value'      => 'Medido',
        'col_thresholds' => 'Umbrales',
        'col_score'      => 'Score',
        'col_weight'     => 'Peso',
        'col_contribution' => 'Aporte',
        'dir_higher'     => 'más alto = mejor',
        'dir_lower'      => 'más bajo = mejor',
        'not_measured'   => 'No medido — no penaliza',
        'formula'        => 'Fórmula',
        'formula_expr'   => 'DGAF = Σ(score × peso) ÷ Σ(peso)',
        'semaphore'      => 'Semáforo (dónde cae el DGAF)',
        'your_dgaf'      => 'Tu DGAF',
        'no_rules'       => 'Este aceite no tiene tabla fisicoquímica, así que no se puede diagnosticar.',
        'no_data'        => 'No hay parámetros medidos para diagnosticar.',
        'loading'        => 'Calculando…',
        'ref_title'      => 'Parámetros de referencia',
        'ref_why'        => 'Se evalúan contra su propio límite, pero no entran en el promedio: miden la misma propiedad que un parámetro que ya puntúa (las dos rigideces; el factor de potencia a dos temperaturas), así que sumarlos contaría dos veces lo mismo.',
        'substituted'      => 'en lugar de :main',
        'substituted_note' => 'El informe no trae :main, así que la propiedad se evaluó con este método contra su propia norma. Sustituye al principal, no se suma a él.',
        'no_weight'      => 'no puntúa',
        'limit_min'      => 'Mín',
        'limit_max'      => 'Máx',
        'status_ok'      => 'Cumple',
        'status_near'    => 'Cerca del límite',
        'status_out'     => 'Fuera de norma',
    ],

    'report_open' => 'Reporte PDF',
    'report_help' => 'Descarga un informe PDF con el diagnóstico, las tendencias y la tabla de ensayos.',

    // Bloque de Diagnóstico + Conclusiones (narrativa de la última muestra).
    'diag' => [
        'class_label'  => 'Clase de tensión',
        'class_low'    => 'baja (≤ 69 kV)',
        'class_mid'    => 'media (69–230 kV)',
        'class_high'   => 'alta (≥ 230 kV)',
        'class_na'     => 'sin clase',
        'state'        => 'Estado fisicoquímico: :condition (clase de tensión :class, IEEE C57.106).',
        'params_bad'   => 'Parámetros fuera de rango: :list.',
        'params_ok'    => 'Todos los parámetros medidos están dentro de rango.',
        'params_near'  => 'Parámetros acercándose a su límite, sin pasarlo: :list.',
        'concl_routine'=> 'Aceite en condición aceptable: monitoreo de rutina.',
        'concl_watch'  => 'Uno o más parámetros fuera de rango: evaluar tratamiento/regeneración del aceite y repetir la medición; correlacionar con furanos (papel).',
        'concl_investigate' => 'Aceite en mal estado: programar tratamiento/regeneración o reemplazo del aceite y correlacionar con furanos (papel). Acortar el intervalo de medición hasta normalizar.',
        'concl_approaching' => 'Atención: :list se acerca(n) a su límite (IEEE C57.106) sin pasarlo todavía — conviene vigilar su tendencia en la próxima medición.',
        'concl_source' => 'Conclusión operativa según el estado fisicoquímico (criterio práctico, en línea con IEEE C57.106).',
        'foot'         => 'Estado por parámetro contra los límites de IEEE C57.106, segmentados por aceite y clase de tensión.',
        'no_data'      => 'Sin ensayo fisicoquímico medido: no es posible diagnosticar.',
        'no_oil'       => 'Este transformador no tiene tipo de aceite asignado. Asigna el aceite (mineral, silicona o vegetal) para poder diagnosticar el fisicoquímico.',
        'no_table'     => 'El aceite de este transformador no tiene tabla fisicoquímica (IEEE C57.106), por eso no se puede diagnosticar.',
        'no_params'    => 'La última muestra no tiene parámetros medidos: completa rigidez, acidez, agua, etc. para diagnosticar.',
        'no_table_hint'=> 'No se puede diagnosticar: falta el tipo de aceite del transformador o ese aceite no tiene tabla fisicoquímica.',
    ],
];
