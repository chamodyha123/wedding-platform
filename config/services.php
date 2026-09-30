<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'payhere' => [
        'mode' => env('PAYHERE_MODE', 'sandbox'),
        'merchant_id' => env('PAYHERE_MERCHANT_ID'),
        'merchant_secret' => env('PAYHERE_MERCHANT_SECRET'),
        'app_id' => env('PAYHERE_APP_ID'),
        'app_secret' => env('PAYHERE_APP_SECRET'),
        'return_url' => env('PAYHERE_RETURN_URL'),
        'cancel_url' => env('PAYHERE_CANCEL_URL'),
        'notify_url' => env('PAYHERE_NOTIFY_URL'),
        'currency' => env('PAYHERE_CURRENCY', 'LKR'),
        'retrieval' => [
            'sandbox' => [
                'token_url' => 'https://sandbox.payhere.lk/merchant/v1/oauth/token',
                'search_url' => 'https://sandbox.payhere.lk/merchant/v1/payment/search',
            ],
            'live' => [
                'token_url' => 'https://www.payhere.lk/merchant/v1/oauth/token',
                'search_url' => 'https://www.payhere.lk/merchant/v1/payment/search',
            ],
        ],
    ],

];
