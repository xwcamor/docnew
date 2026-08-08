<?php

/**
 * Duration units, so a worked time reads "2 hours and 30 minutes".
 *
 * Used by WorkPlan::getWorkedTimeAttribute(). Pluralised through
 * `trans_choice` — "1 hours" gives the translation away.
 */
return [
    'days'    => 'day|days',
    'hours'   => 'hour|hours',
    'minutes' => 'minute|minutes',
    'and'     => 'and',
];
