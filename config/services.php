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
        'url' => 'https://elif12.2rmlab.com/live/api',
        'db_config' => env('FISCALIZATION_DB_CONFIG', 'elif_config'),
        'company_db_name' => env('FISCALIZATION_COMPANY_DB_NAME', ''),
        'hardware_id' => env('FISCALIZATION_HARDWARE_ID', ''),
        'user_token' => env('FISCALIZATION_USER_TOKEN', ''),
        'user_id' => env('FISCALIZATION_USER_ID', ''),
        'username' => env('FISCALIZATION_USERNAME', ''),
    ],

];
