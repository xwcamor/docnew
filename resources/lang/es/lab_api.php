<?php

/**
 * Mensajes de la API del laboratorio (docs/API-LABORATORIO.md).
 *
 * Los consume una máquina, no una persona, pero igual van traducidos: el
 * operador del laboratorio los ve en su bandeja de envíos cuando algo falla, y
 * un mensaje que explica qué hacer ahorra una llamada.
 */
return [

    // ─── Idempotencia ────────────────────────────────────────────────────
    'idempotency_required'    => 'Falta la cabecera Idempotency-Key. Es obligatoria para que un reintento no duplique la muestra.',
    'idempotency_too_long'    => 'La cabecera Idempotency-Key no puede superar los 191 caracteres.',
    'idempotency_reused'      => 'Esta Idempotency-Key ya se usó con un cuerpo distinto. Use una clave nueva para un envío nuevo.',
    'idempotency_in_progress' => 'Hay una petición con esta Idempotency-Key en curso o interrumpida. Reintente en unos minutos: si la primera terminó bien, recibirá su misma respuesta.',

    // ─── Resolución del transformador ────────────────────────────────────
    'transformer_required'  => 'Indique el transformador por slug, o por número de serie / tag / cliente.',
    'transformer_not_found' => 'No hay ningún transformador que coincida. Vincúlelo o créelo desde la bandeja de conciliación.',
    'transformer_ambiguous' => 'Hay :count transformadores que coinciden. Elija uno desde la bandeja de conciliación: la API no adivina.',

    // ─── Alta de transformador ───────────────────────────────────────────
    'unknown_type'       => 'Tipo de equipo desconocido: :code. Tipos que este sistema sabe diagnosticar: :allowed.',
    'unknown_oil'        => 'Tipo de aceite desconocido: :code. Aceites disponibles: :allowed.',
    'unknown_phases'     => 'Número de fases inválido: :value. Valores admitidos: 1, 2 o 3.',
    'substation_required' => 'Falta la subestación del transformador. Mande customer_substation_id, o el nombre en `substation`. En `available_substations` van las del cliente.',
    'substation_unknown'  => 'La subestación :name no existe en este cliente. En `available_substations` van las que sí.',
    'customer_required'   => 'Falta el cliente del transformador.',

    // ─── Ingesta de resultados ───────────────────────────────────────────
    'unknown_kind'      => 'Ensayo desconocido: :kind. Ensayos admitidos: :allowed.',
    'unknown_analyte'   => 'Analito desconocido para el ensayo :kind: :code. No se descarta en silencio para que no se pierda una medición que el cliente pagó.',
    'no_values'         => 'El ensayo :kind no trae ningún valor medido.',
    'missing_required'  => 'Al ensayo :kind le falta :code, que es el que lo diagnostica.',
    'no_tests'          => 'El envío no trae ningún ensayo.',
];
