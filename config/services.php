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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'identity' => [
        'openid_configuration' => env('IDENTITY_OPENID_CONFIGURATION'),
        'client_id' => env('IDENTITY_CLIENT_ID'),
        'client_secret' => env('IDENTITY_CLIENT_SECRET'),
        'redirect' => env('IDENTITY_CALLBACK_URL'),
    ],

    'attsrv' => [
        'url' => env('ATTSRV_URL'),
        /** I HATE THIS I HATE THIS I HATE THIS */
        'cookies' => [
            'AUTH' => env('ATTSRV_AUTH_COOKIE'),
            'JWT' => env('ATTSRV_JWT_COOKIE'),
            'domain' => env('ATTSRV_COOKIE_DOMAIN'),
        ],
    ],

];
