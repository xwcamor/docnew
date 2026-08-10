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
    | Consultas a SUNAT y RENIEC — solo Peru
    |--------------------------------------------------------------------------
    |
    | Rellenan solas la razon social al teclear un RUC y el nombre al teclear un
    | DNI, como hacia el sistema anterior. Sin token no se consulta nada y todo
    | se escribe a mano: NO es un error y no bloquea ningun alta.
    |
    | Hay DOS proveedores y no hablan igual. Ni la URL ni los nombres de los
    | campos coinciden, asi que apuntar el token de uno al otro devuelve un 401
    | y parece que «la API no funciona»:
    |
    |   decolecta     https://api.decolecta.com   /v1/reniec/dni  → first_name, first_last_name…
    |                 token `sk_...`
    |   apis_net_pe   https://api.apis.net.pe     /v2/reniec/dni  → nombres, apellidoPaterno…
    |
    | Por defecto Decolecta, que es el que se usaba en tenkofiz. Se cambia con
    | `PERU_LOOKUP_PROVIDER`. Las variables `APIS_NET_PE_*` siguen valiendo para
    | no romper un `.env` ya escrito.
    */
    'peru_lookup' => [
        'provider' => env('PERU_LOOKUP_PROVIDER', 'decolecta'),
        // En blanco se usa la del proveedor. Solo hace falta para un espejo o
        // para apuntar a un doble en las pruebas.
        'url'      => env('PERU_LOOKUP_URL', env('APIS_NET_PE_URL')),
        'token'    => env('PERU_LOOKUP_TOKEN', env('APIS_NET_PE_TOKEN')),
        'timeout'  => env('PERU_LOOKUP_TIMEOUT', env('APIS_NET_PE_TIMEOUT', 12)),
    ],
];
