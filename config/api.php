<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Middleware Bearer Token
    |--------------------------------------------------------------------------
    |
    | This token is the expected bearer token from clients which is used to
    | validate the authentication. Connection will be prohibited if none or
    | the wrong token is provided by the user. When using this token a check
    | for null must be performed in case the configuration file misses the entry.
    */

    'api_middleware_bearer_token' => env('API_MIDDLEWARE_BEARER_TOKEN', null),
];
