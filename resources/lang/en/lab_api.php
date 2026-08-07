<?php

/**
 * Laboratory API messages (docs/API-LABORATORIO.md).
 *
 * A machine reads them, but they are translated anyway: the lab operator sees
 * them in the outbound queue when something fails, and a message that says what
 * to do saves a phone call.
 */
return [

    // ─── Idempotency ─────────────────────────────────────────────────────
    'idempotency_required'    => 'Missing Idempotency-Key header. It is required so a retry does not duplicate the sample.',
    'idempotency_too_long'    => 'The Idempotency-Key header cannot exceed 191 characters.',
    'idempotency_reused'      => 'This Idempotency-Key was already used with a different body. Use a new key for a new submission.',
    'idempotency_in_progress' => 'A request with this Idempotency-Key is in progress or was interrupted. Retry in a few minutes: if the first one succeeded you will get its same response.',

    // ─── Transformer resolution ──────────────────────────────────────────
    'transformer_required'  => 'Identify the transformer by slug, or by serial / tag / customer.',
    'transformer_not_found' => 'No transformer matches. Link it or create it from the reconciliation tray.',
    'transformer_ambiguous' => ':count transformers match. Pick one from the reconciliation tray: the API does not guess.',

    // ─── Transformer creation ────────────────────────────────────────────
    'unknown_type'       => 'Unknown equipment type: :code. Types this system can diagnose: :allowed.',
    'unknown_oil'        => 'Unknown oil type: :code. Available oils: :allowed.',
    'unknown_phases'     => 'Invalid number of phases: :value. Allowed values: 1, 2 or 3.',
    'substation_required' => 'Missing the transformer substation. Send customer_substation_id, or its name in `substation`. The customer ones are listed in `available_substations`.',
    'substation_unknown'  => 'Substation :name does not exist for this customer. The valid ones are listed in `available_substations`.',
    'customer_required'   => 'Missing the transformer customer.',

    // ─── Result ingestion ────────────────────────────────────────────────
    'unknown_kind'      => 'Unknown test: :kind. Accepted tests: :allowed.',
    'unknown_analyte'   => 'Unknown analyte for test :kind: :code. It is not dropped silently so a measurement the customer paid for is not lost.',
    'no_values'         => 'Test :kind carries no measured value.',
    'missing_required'  => 'Test :kind is missing :code, which is what diagnoses it.',
    'no_tests'          => 'The submission carries no test.',
];
