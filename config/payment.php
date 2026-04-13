<?php

return [

    /*
    | The list of all the providers and their configurations
    |
    */

    'payplus' => [
        
        'address'       => env('PAYPLUS_ADDRESS'),
        'api_key'       => env('PAYPLUS_API_KEY'),
        'secret_key'    => env('PAYPLUS_SECRET_KEY'),
        'page_address'    => env('PAYPLUS_PAYMENT_PAGE_ADDRESS'),
        'page_uuid'       => env('PAYPLUS_PAGE_UUID'),
        'currency_code'   => env('PAYPLUS_CURRENCY_CODE', 'ILS'),
        'expiry_datetime' => (int) env('PAYPLUS_LINK_EXPIRY_MINUTES', 30),

    ],


    
    'paypal' => [
        
        'address'   => env('PAYPAL_ADDRESS'),
        'token'     => env('PAYPAL_TOKEN'),

    ],

];
