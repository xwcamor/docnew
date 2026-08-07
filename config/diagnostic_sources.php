<?php

/**
 * Fuentes / referencias de los diagnósticos, mostradas en el drawer
 * "¿Por qué este resultado?". Cada prueba declara, por fórmula y por escala:
 * URL opcional + estado de validación. El TEXTO de la cita NO va aquí: vive en
 * los archivos de idioma (resources/lang/{es,en}/<prueba>.php → `sources.*`)
 * para que el drawer respete el idioma activo del usuario.
 *
 * status:
 *   'confirmed'   → respaldado por una fuente externa citable.
 *   'unconfirmed' → heredado del sistema anterior, sin norma externa documentada.
 *
 * Por ahora solo furanos (las demás pruebas se irán completando a medida que se
 * confirme la fuente de cada una).
 */

return [
    'furanos' => [
        'formula' => [
            'url'    => null,
            'status' => 'confirmed',
        ],
        'scale' => [
            'url'    => null,
            'status' => 'confirmed',
        ],
    ],
];
