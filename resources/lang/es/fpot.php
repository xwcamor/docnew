<?php

return [
    'tab'         => 'Factor de Potencia',
    'samples_tab' => 'Ensayos',
    'trends_tab'  => 'Tendencias',
    'singular'    => 'Ensayo',
    'plural'      => 'Ensayos',
    'title'       => 'Ensayos de Factor de Potencia',
    'sample_date' => 'Fecha',
    'value'       => 'Factor de potencia (%)',
    'value_help'  => 'Factor de potencia del aislamiento (num_fac), en %.',
    'temperature' => 'Temperatura (°C)',
    'condition'   => 'Condición',
    'state'       => 'Estado',
    'add'         => 'Crear ensayo',
    'edit'        => 'Editar ensayo',
    'new_test'    => 'Crear ensayo',
    'edit_test'   => 'Editar ensayo',
    'empty'       => 'Sin ensayos todavía. Crea el primero para diagnosticar.',
    'no_samples'  => 'Sin ensayos todavía. Crea el primero para diagnosticar.',
    'trend_title' => 'Tendencia del Factor de Potencia (%)',
    'trends_hint' => 'Evolución del factor de potencia (%) a lo largo de los ensayos.',
    'trends_no_data' => 'Se necesitan al menos 2 ensayos para ver tendencias.',
    'delete_confirm' => '¿Eliminar el ensayo del :date?',
    'sample_date_required' => 'La fecha del ensayo es obligatoria.',

    'created' => 'Ensayo de factor de potencia creado y diagnosticado.',
    'saved'   => 'Ensayo de factor de potencia actualizado.',
    'deleted' => 'Ensayo de factor de potencia eliminado.',

    // Drawer "¿Por qué este resultado?" (traza del diagnóstico)
    'explain' => [
        'open'       => 'Ver cómo se calculó',
        'title'      => '¿Por qué este resultado?',
        'subtitle'   => 'Traza del diagnóstico — la escala vive en datos; el valor medido entra tal cual (sin conversión).',
        'result'     => 'Resultado',
        'no_value'   => 'No hay factor de potencia medido, así que no se puede diagnosticar.',
        'value'      => 'Factor de potencia (%)',
        'scale'      => 'Escala (dónde cae el valor)',
        'your_value' => 'Tu valor',
        'rating'     => 'Calificación',
        'loading'    => 'Calculando…',
    ],

    // Bloque de Diagnóstico + Conclusiones (narrativa de la última muestra).
    'diag' => [
        'value'        => 'Factor de potencia medido: :v % → estado :condition.',
        'over'         => 'Supera el valor de referencia (:limit %): indica humedad o envejecimiento del aislamiento.',
        'near'         => 'Está en la última banda aceptable, cerca del valor de referencia (:limit %), sin superarlo.',
        'concl_approaching' => 'Atención: el factor de potencia se acerca al valor de referencia (:limit %) sin superarlo — repetir la medición y vigilar la tendencia.',
        'concl_routine'=> 'Dentro de lo esperado: monitoreo de rutina.',
        'concl_watch'  => 'Valor elevado: repetir la medición y vigilar la tendencia; correlacionar con humedad y acidez (fisicoquímico).',
        'concl_investigate' => 'Factor de potencia fuera de rango: programar tratamiento/regeneración del aceite y correlacionar con humedad y acidez (fisicoquímico). Acortar el intervalo de medición.',
        'concl_source' => 'Conclusión operativa según la escala de factor de potencia configurada.',
        'foot'         => 'El factor de potencia (tan δ) crece con la humedad y el envejecimiento del aislamiento. Se evalúa contra la escala configurada del sistema.',
        'no_data'      => 'Sin medición de factor de potencia: no es posible diagnosticar.',
    ],
];
