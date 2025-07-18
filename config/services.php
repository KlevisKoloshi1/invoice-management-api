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

    'fiscalization' => [
        'url' => env('FISCALIZATION_URL', 'https://elif12.2rmlab.com/live/api'),
        'db_config' => env('FISCALIZATION_DB_CONFIG', 'elif_config'),
        'company_db_name' => env('FISCALIZATION_COMPANY_DB_NAME', ''),
        'hardware_id' => env('FISCALIZATION_HARDWARE_ID', ''),
        'user_token' => env('FISCALIZATION_USER_TOKEN', ''),
        'user_id' => env('FISCALIZATION_USER_ID', ''),
        'username' => env('FISCALIZATION_USERNAME', ''),
        'password' => env('FISCALIZATION_PASSWORD', ''),
        // Default values for fiscalization API required fields
        'default_city_id' => env('FISCALIZATION_DEFAULT_CITY_ID', 1),
        'default_warehouse_id' => env('FISCALIZATION_DEFAULT_WAREHOUSE_ID', 1),
        'default_automatic_payment_method_id' => env('FISCALIZATION_DEFAULT_AUTOMATIC_PAYMENT_METHOD_ID', 0),
        'default_currency_id' => env('FISCALIZATION_DEFAULT_CURRENCY_ID', 1),
        'default_cash_register_id' => env('FISCALIZATION_DEFAULT_CASH_REGISTER_ID', 1),
        'default_fiscal_invoice_type_id' => env('FISCALIZATION_DEFAULT_FISCAL_INVOICE_TYPE_ID', 4),
        'default_fiscal_profile_id' => env('FISCALIZATION_DEFAULT_FISCAL_PROFILE_ID', 1),
    ],

];
