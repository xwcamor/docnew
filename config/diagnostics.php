<?php

/**
 * Parámetros OPERATIVOS de diagnóstico (no normas, no umbrales clínicos).
 *
 * Aquí viven los cortes de GUÍA — cosas que orientan una acción de mantenimiento
 * pero que NO salen textualmente de una norma. Se separan del código (no se
 * "mandrakean" en un match) para poder ajustarlos sin reprogramar, y se
 * documentan como lo que son: heurística alineada al espíritu de IEEE C57.104 /
 * CIGRE, con cortes elegidos por nosotros.
 *
 * Lo CLÍNICO (escalas de severidad, DP, semáforo) NO va aquí: eso vive en datos
 * (result_scales, *_rules.json) y está respaldado por norma.
 */
return [

    // Techo de la escala de calificación de salud (rating 0..max). El estándar
    // de Índice de Salud (IEEE/CIGRE, A-E) usa 5 niveles → max = 4. La fórmula
    // del HI normaliza dividiendo entre este techo: Σ(peso×rating)/Σ(peso×max).
    // Se deja como parámetro (en vez de un 4 clavado) para no encerrar la escala;
    // cambiar esto exige reasignar los hi_rating de las bandas en consecuencia.
    'max_rating' => 4,

    'furanos' => [
        // Mapeo del rating de la última muestra de 2-FAL (4 mejor … 0 peor) a una
        // acción de mantenimiento. Guía operativa, NO umbrales normados.
        //   rating >= routine  → rutina
        //   rating >= monitor  → vigilar
        //   rating >= increase → subir frecuencia + evaluar papel
        //   por debajo         → crítico (inspección/reemplazo + confirmar DP)
        'recommendation_cutoffs' => [
            'routine'  => 3.0,
            'monitor'  => 2.0,
            'increase' => 1.0,
        ],

        // Tasa de generación de 2-FAL (ppb/año) por encima de la cual la tendencia
        // se marca como "en aumento" → se recomienda acortar el intervalo de muestreo.
        // Valor elegido por nosotros (el principio es de IEEE C57.104, el número no).
        'rate_rising_eps' => 1.0,
    ],

];
