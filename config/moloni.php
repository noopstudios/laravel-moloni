<?php

use Noop\Moloni\Tax\DefaultTaxRule;

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
    | Customer updates
    |--------------------------------------------------------------------------
    |
    | Which existing Moloni customers a document may update with its billing
    | data (name, address, postal code, city, country and email).
    |
    | "own": only customers whose number this application generated for that
    |        customer. Any other customer found by VAT number is used as it is
    |        stored, so a buyer cannot rewrite another company's data just by
    |        typing its (public) VAT number.
    | "all": any customer found by VAT number or customer number.
    |
    | Moloni's generic final-consumer customer (9999) is never updated.
    |
    */

    'customer_updates' => env('MOLONI_CUSTOMER_UPDATES', 'own'),

    /*
    |--------------------------------------------------------------------------
    | HTTP client
    |--------------------------------------------------------------------------
    */

    'base_url' => env('MOLONI_BASE_URL', 'https://api.moloni.pt/v1/'),

    // Seconds, capped at 30 (timeout) and 10 (connect_timeout): the issuing job's time guard
    // assumes short requests, so larger values are lowered.
    'timeout' => (int) env('MOLONI_TIMEOUT', 15),

    'connect_timeout' => (int) env('MOLONI_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */

    'queue_connection' => env('MOLONI_QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Pro-formas
    |--------------------------------------------------------------------------
    |
    | Days a pro-forma stays valid: its expiration date in Moloni.
    |
    */

    'proforma_valid_days' => (int) env('MOLONI_PROFORMA_VALID_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Tax rule
    |--------------------------------------------------------------------------
    |
    | The Noop\Moloni\Tax\TaxRule implementation a checkout uses to decide the
    | VAT rate and exemption of a customer before asking for payment. The
    | package never applies it when issuing. Rebind the interface in the
    | container to replace it altogether.
    |
    */

    'tax_rule' => DefaultTaxRule::class,

    /*
    |--------------------------------------------------------------------------
    | VIES
    |--------------------------------------------------------------------------
    |
    | Optional validation of EU VAT numbers against the European Commission's
    | VIES service. When disabled, an EU business is charged VAT.
    |
    */

    'vies' => [
        'enabled' => (bool) env('MOLONI_VIES_ENABLED', false),
        'base_url' => env('MOLONI_VIES_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api'),
        'timeout' => (int) env('MOLONI_VIES_TIMEOUT', 5),
    ],

];
