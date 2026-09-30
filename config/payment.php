<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Method
    |--------------------------------------------------------------------------
    */
    'default' => env('PAYMENT_DEFAULT_METHOD', 'cash_on_delivery'),

    /*
    |--------------------------------------------------------------------------
    | Cash On Delivery Configuration
    |--------------------------------------------------------------------------
    */
    'cod' => [
        'enabled' => env('PAYMENT_COD_ENABLED', true),
        'canonical_method' => 'cash_on_delivery',
    ],

    /*
    |--------------------------------------------------------------------------
    | Online Payment Gateway (Disabled until real credentials are provided)
    |--------------------------------------------------------------------------
    */
    'online' => [
        'enabled' => env('PAYMENT_GATEWAY_ENABLED', false),
        'provider' => env('PAYMENT_GATEWAY_PROVIDER', null),
        'api_key' => env('PAYMENT_GATEWAY_API_KEY', null),
        'secret' => env('PAYMENT_GATEWAY_SECRET', null),
        'store_id' => env('PAYMENT_GATEWAY_STORE_ID', null),
        'store_password' => env('PAYMENT_GATEWAY_STORE_PASSWORD', null),
    ],
];
