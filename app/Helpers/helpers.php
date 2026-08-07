<?php

use Carbon\Carbon;

// ==============================
// FORMATTERS
// ==============================
if (!function_exists('formatDateTime')) {
    function formatDateTime($dateTime)
    {
        return $dateTime
            ? Carbon::parse($dateTime)->format('d-m-Y H:i:s')
            : null;
    }
}

// ==============================
// MENU HELPERS
// ==============================
if (!function_exists('isActiveMenu')) {
    function isActiveMenu($patterns)
    {
        $locale = app()->getLocale();
        foreach ((array) $patterns as $pattern) {
            // chequea con prefijo de locale
            if (request()->is("$locale/$pattern")) {
                return true;
            }
            // chequea también sin prefijo (por si acaso)
            if (request()->is($pattern)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('menuOpenClass')) {
    function menuOpenClass($patterns)
    {
        return isActiveMenu($patterns) ? 'menu-open' : '';
    }
}

if (!function_exists('activeClass')) {
    function activeClass($patterns)
    {
        return isActiveMenu($patterns) ? 'active' : '';
    }
}
