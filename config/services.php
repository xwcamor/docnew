<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],    


    /*
    |--------------------------------------------------------------------------
    | apis.net.pe — consulta de RUC en SUNAT
    |--------------------------------------------------------------------------
    |
    | Lo que el sistema anterior usaba para autorrellenar la razon social al
    | teclear un RUC. Sin `APIS_NET_PE_TOKEN` la consulta no se hace y el
    | formulario simplemente se rellena a mano: no es un error.
    */
    'apis_net_pe' => [
        'url'     => env('APIS_NET_PE_URL', 'https://api.apis.net.pe'),
        'token'   => env('APIS_NET_PE_TOKEN'),
        'timeout' => env('APIS_NET_PE_TIMEOUT', 6),
    ],
];
