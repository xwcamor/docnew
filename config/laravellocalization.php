<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supported Locales
    |--------------------------------------------------------------------------
    */
    'supportedLocales' => [

        'en' => [
            'name'       => 'English',
            'script'     => 'Latn',
            'native'     => 'English',
            'regional'   => 'en_GB',
            'iso_639_1'  => 'en',
            'iso_639_2'  => 'eng',
            'direction'  => 'ltr',
            'plural_forms' => 'nplurals=2; plural=(n != 1);',
        ],

        'es' => [
            'name'       => 'Spanish',
            'script'     => 'Latn',
            'native'     => 'Español',
            'regional'   => 'es_ES',
            'iso_639_1'  => 'es',
            'iso_639_2'  => 'spa',
            'direction'  => 'ltr',
            'plural_forms' => 'nplurals=2; plural=(n != 1);',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Use Accept-Language Header
    |--------------------------------------------------------------------------
    |
    | Determines if the locale should be automatically set from the browser
    |
    */
    'useAcceptLanguageHeader' => true,

    /*
    |--------------------------------------------------------------------------
    | Hide Default Locale in URL
    |--------------------------------------------------------------------------
    */
    'hideDefaultLocaleInURL' => false,

    /*
    |--------------------------------------------------------------------------
    | Default Locale
    |--------------------------------------------------------------------------
    */
    'defaultLocale' => 'en',

  

];
