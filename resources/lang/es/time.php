<?php

/**
 * Unidades de duración, para escribir «2 horas y 30 minutos».
 *
 * Las usa WorkPlan::getWorkedTimeAttribute() al armar el «Tiempo Trabajado».
 * Van con plural (`trans_choice`) porque «1 horas» delata la traducción.
 */
return [
    'days'    => 'día|días',
    'hours'   => 'hora|horas',
    'minutes' => 'minuto|minutos',
    'and'     => 'y',
];
