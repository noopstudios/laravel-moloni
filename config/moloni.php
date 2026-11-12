<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Moloni API credentials
    |--------------------------------------------------------------------------
    |
    | Credentials are only ever read from the environment. They are never
    | stored in the database, written to logs or included in exceptions.
    | The email is sent to Moloni's grant endpoint as the "username".
    |
    */

    'client_id' => env('MOLONI_CLIENT_ID'),

    'client_secret' => env('MOLONI_CLIENT_SECRET'),

    'email' => env('MOLONI_EMAIL'),

    'password' => env('MOLONI_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix used to tell this application's records apart in Moloni. Required
    | to create customers: their number is {prefix}{yy}-{key}.
    |
    */

    'prefix' => env('MOLONI_PREFIX'),

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    */

    'base_url' => env('MOLONI_BASE_URL', 'https://api.moloni.pt/v1/'),

    'timeout' => (int) env('MOLONI_TIMEOUT', 15),

    'connect_timeout' => (int) env('MOLONI_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */

    'queue_connection' => env('MOLONI_QUEUE_CONNECTION', 'database'),

];
