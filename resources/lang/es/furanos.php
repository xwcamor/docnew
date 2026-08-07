<?php

return [
    'tab'            => 'Furanos',
    'samples_tab'    => 'Ensayos',
    'trends_tab'     => 'Tendencias',
    'trends_hint'    => 'Evolución de los compuestos furánicos (ppb) a lo largo de los ensayos.',
    'trends_no_data' => 'Se necesitan al menos 2 ensayos para ver tendencias.',
    'singular'       => 'Ensayo',
    'sample_date'    => 'Fecha',
    'state'          => 'Estado',
    'dp'             => 'DP',
    'dp_full'        => 'Grado de polimerización (Chendong)',
    'compounds'      => 'Compuestos furánicos (ppb)',
    'new_test'       => 'Crear ensayo',
    'edit_test'      => 'Editar ensayo',
    'no_samples'     => 'Sin ensayos todavía. Crea el primero para diagnosticar.',
    'delete_confirm' => '¿Eliminar el ensayo del :date?',
    'fal_required'   => 'El 2-furfuraldehído (FAL) es obligatorio.',
    'sample_date_required' => 'La fecha del ensayo es obligatoria.',

    // Tasa de generación y avisos de interpretación.
    'rate_label'        => 'Tasa de generación 2-FAL',
    'rate_unit'         => 'ppb/año',
    'rate_help'         => 'Variación del 2-FAL por año entre las dos últimas muestras. Indica el ritmo al que se está degradando el papel: una tasa alta y positiva señala envejecimiento acelerado; cerca de cero, estable; negativa suele deberse a tratamiento/cambio de aceite (los furanos se eliminan y el valor baja artificialmente).',
    'oil_warning_title' => 'Aceite tratado o cambiado',
    'oil_warning_body'  => 'El aceite fue tratado/cambiado el :date. Los furanos se eliminan con el tratamiento, así que las muestras posteriores subestiman la edad real del papel.',
    // Recomendación de acción según la última muestra.
    'reco_label'    => 'Recomendación',
    'reco_routine'  => 'Monitoreo de rutina: papel en buen estado, mantener la frecuencia de muestreo habitual.',
    'reco_monitor'  => 'Seguimiento: vigilar la tendencia del 2-FAL y repetir el muestreo antes de lo habitual.',
    'reco_increase' => 'Investigar: aumentar la frecuencia de muestreo y planificar una evaluación del papel; confirmar con DP medido.',
    'reco_critical' => 'Atención prioritaria: riesgo alto de degradación del papel — evaluar inspección/reemplazo y confirmar con DP medido.',
    'reco_rising'   => 'Tendencia en aumento: conviene acortar el intervalo de muestreo.',
    'reco_source'   => 'Recomendación de mantenimiento según la severidad del 2-FAL. Es una guía de acción (criterio práctico, en línea con IEEE C57.104 / CIGRE), no un valor de norma: el diagnóstico lo da la severidad.',
    // Chips de diagnóstico de un vistazo (KPIs en la franja superior).
    'dp_chip'        => 'DP ≈ :dp',
    'dp_chip_help'   => 'Grado de polimerización del papel estimado del 2-FAL (Chendong). Papel nuevo ≈ 1000; fin de vida ≈ 200.',
    'life_chip'      => 'Vida del papel :p % consumida',
    'life_chip_help' => 'Porcentaje de vida mecánica del papel ya consumida, estimado del DP. A mayor porcentaje, papel más degradado.',
    'fal_chip'       => '2-FAL :fal ppb',
    'fal_chip_help'  => 'Concentración de 2-furfuraldehído de la última muestra (el furano que diagnostica la degradación del papel).',
    // Bloque de Diagnóstico + Conclusiones (narrativa de la última muestra).
    'diag' => [
        'title'      => 'Diagnóstico',
        'concl'      => 'Conclusiones y recomendaciones',
        'no_data'    => 'Sin 2-FAL medido en la última muestra: no es posible diagnosticar furanos.',
        'value'      => 'El 2-FAL de la última muestra es :fal ppb (:ppm ppm).',
        'exceeds'    => 'Supera el umbral de papel sano (≈ :safe ppb): indica degradación del aislamiento de celulosa.',
        'within'     => 'Está dentro del rango de papel sano (≤ :safe ppb).',
        'dp'         => 'Grado de polimerización estimado DP ≈ :dp (correlación de Chendong; CIGRE TB 494 / IEEE C57.91), equivalente a ~:life % de la vida mecánica del papel consumida.',
        'state'      => 'Estado del papel según furanos: :condition.',
        'rate'       => 'Tasa de generación de 2-FAL entre las dos últimas muestras: :rate ppb/año.',
        'foot'       => 'El DP mostrado se estima del 2-FAL (correlación de Chendong) y es un valor indicativo. La confirmación definitiva es una medición directa del DP sobre una muestra de papel.',
    ],
    // Relación CO₂/CO de la última cromatografía (chequeo cruzado del papel, IEC 60599).
    'co_ratio_low'    => 'CO₂/CO < 3: posible degradación térmica del papel (celulosa). Refuerza el diagnóstico de furanos.',
    'co_ratio_normal' => 'CO₂/CO entre 3 y 10: rango normal, sin indicio claro de falla en el papel.',
    'co_ratio_high'   => 'CO₂/CO > 10: posible falla de baja temperatura u oxidación del papel.',
    // Mecanismo de degradación según el furano secundario dominante (indicativo).
    'mech_oxidation' => 'posible oxidación',
    'mech_moisture'  => 'posible humedad/hidrólisis',
    'mech_thermal'   => 'posible sobrecalentamiento',
    'mech_abnormal'  => 'patrón anómalo',
    'mech_caveat'    => 'Furano secundario dominante. Indicativo del mecanismo de degradación: la interpretación de los furanos secundarios está menos establecida que la del 2-FAL. Fuente: A. De Pablo, CIGRE Electra 175 (1997) — asociación cualitativa, sin umbrales normados.',
    // Reporte PDF.
    'report_open'      => 'Reporte PDF',
    'report_help'      => 'Descarga un informe PDF con el diagnóstico, las tendencias y la tabla de ensayos.',
    'report_title'     => 'Reporte de Furanos',
    'report_generated' => 'Generado',
    'report_footer'    => 'Diagnóstico estimado a partir del 2-FAL; confirmar con medición directa de DP cuando sea posible.',

    'created' => 'Ensayo de furanos creado y diagnosticado.',
    'saved'   => 'Ensayo de furanos actualizado.',
    'deleted' => 'Ensayo de furanos eliminado.',

    // Compuestos (código → nombre completo, para tooltips de columnas).
    // Abreviaturas según nomenclatura estándar (IEC 61198): prefijo de posición.
    'fal' => '2-furfuraldehído (2FAL)',
    'hme' => '5-hidroximetil-2-furfural (5HMF)',
    'ace' => '2-acetilfurano (2ACF)',
    'mfu' => '5-metil-2-furfuraldehído (5MEF)',
    'fua' => '2-furfurilalcohol (2FOL)',

    // Drawer "¿Por qué este resultado?" (traza del diagnóstico)
    'explain' => [
        'open'       => 'Ver cómo se calculó',
        'title'      => '¿Por qué este resultado?',
        'subtitle'   => 'Traza del diagnóstico — el umbral del 2-FAL y la fórmula del DP viven en datos; el código solo los aplica.',
        'result'     => 'Resultado',
        'no_value'   => 'No hay 2-FAL medido, así que no se puede diagnosticar.',
        'conversion' => 'Conversión',
        'fal_ppb'    => '2-FAL medido',
        'ppm'        => '2-FAL en ppm',
        'dp'         => 'Grado de polimerización (DP)',
        'life'       => 'Vida del papel consumida',
        'life_hint'  => '(DP 1000 nuevo → 200 fin de vida)',
        'dp_formula' => 'DP = (1.51 − log₁₀(2-FAL ppm)) ÷ 0.0035  ·  Chendong',
        'formula'    => 'Fórmula',
        'scale'      => 'Escala (dónde cae el 2-FAL, en ppm)',
        'your_value' => 'Tu 2-FAL',
        'status'     => 'Estado',
        'rating'     => 'Calificación',
        'loading'    => 'Calculando…',
        'reference'        => 'Fuente del diagnóstico',
        'source_formula'   => 'Fórmula del DP',
        'source_scale'     => 'Escala (bandas)',
        'status_confirmed'   => 'Fundamentada',
        'status_unconfirmed' => 'Por confirmar',
        'paper_warning_title' => 'Papel térmicamente mejorado',
        'paper_warning_body'  => 'Este transformador tiene papel térmicamente mejorado, que genera menos 2-FAL por diseño. El resultado y el DP estimado pueden subestimar la degradación real del papel: conviene apoyarse en la medición directa de DP y en las demás pruebas.',
    ],

    // Citas de las fuentes del diagnóstico (mostradas en el drawer). El estado y
    // la URL viven en config/diagnostic_sources.php; el texto se traduce aquí.
    'sources' => [
        'formula_citation' => 'Correlación de Chendong — X. Chendong, "Monitoring paper insulation ageing by measuring furfural contents in oil", 7th ISH, Dresden, 1991. Recogida en CIGRE TB 494 (2012) e IEEE C57.104-2019.',
        'scale_citation'   => 'Bandas de 2-FAL (ppm) ancladas a hitos de grado de polimerización (DP) del papel: <0.1→DP>700 (nuevo) · 0.1-0.5→DP 500-700 · 0.5-2→DP 350-500 · 2-6→DP 200-350 · >6→DP≤200 (fin de vida). Referencia: CIGRE TB 494 (2012) e IEEE C57.91 (DP ~200 = fin de vida del papel).',
    ],
];
